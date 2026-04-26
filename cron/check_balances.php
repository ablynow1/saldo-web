<?php
declare(strict_types=1);

/**
 * Executa um ciclo de verificação em todas as meta_accounts ativas.
 * Agendar no cPanel: 0 * * * * /usr/bin/php /home/USUARIO/cron/check_balances.php
 *
 * Fluxo:
 *   1. Busca dados da conta (saldo, status, cap)
 *   2. Para contas pré-pagas: roda BalanceForecaster para previsão inteligente
 *   3. Persiste snapshot + cache na meta_accounts
 *   4. Avalia alertas (saldo/status + performance) e envia via WhatsApp
 */

if (!defined('APP_ROOT')) {
    require_once __DIR__ . '/../app/bootstrap.php';
}

set_time_limit(300);

$runId = Db::insert('check_runs', ['started_at' => date('Y-m-d H:i:s')]);
$log = [];
$errors = 0;
$alertsFired = 0;
$accountsChecked = 0;

try {
    $meta = MetaAdsClient::fromSettings();
} catch (Throwable $e) {
    Db::update('check_runs', [
        'finished_at' => date('Y-m-d H:i:s'),
        'errors' => 1,
        'log' => 'Meta não configurado: ' . $e->getMessage(),
    ], 'id = ?', [$runId]);
    if (PHP_SAPI === 'cli') fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

try {
    $wa = WhatsAppClient::fromSettings();
} catch (Throwable $e) {
    $wa = null;
    $log[] = 'WhatsApp não configurado: ' . $e->getMessage();
}

$engine     = new AlertEngine();
$forecaster = new BalanceForecaster($meta);

$accounts = Db::all(
    'SELECT ma.*, c.name AS client_name, c.whatsapp_group_jid, c.active AS client_active
     FROM meta_accounts ma
     JOIN clients c ON c.id = ma.client_id
     WHERE ma.active = 1 AND c.active = 1'
);

foreach ($accounts as $acc) {
    $accountsChecked++;
    try {
        $snap = $meta->getAccount($acc['ad_account_id']);

        // Se o tipo era "unknown", tenta detectar
        if ($acc['account_type'] === 'unknown') {
            $detected = MetaAdsClient::detectAccountType($snap['raw']);
            if ($detected !== 'unknown') {
                $acc['account_type'] = $detected;
            }
        }

        // Forecast só pra pré-pago (ou unknown que tenha saldo)
        $forecast = null;
        if ($acc['account_type'] === 'prepaid' && $snap['balance'] !== null) {
            try {
                $forecast = $forecaster->forecast($acc['ad_account_id'], $snap['balance']);
            } catch (Throwable $e) {
                $log[] = "[forecast] {$acc['ad_account_id']}: " . $e->getMessage();
            }
        }

        // Persistir snapshot
        Db::insert('balance_snapshots', [
            'meta_account_id' => (int) $acc['id'],
            'balance'         => $snap['balance'],
            'spend_cap'       => $snap['spend_cap'],
            'amount_spent'    => $snap['amount_spent'],
            'account_status'  => $snap['account_status'],
            'raw_json'        => json_encode([
                'account'  => $snap['raw'],
                'forecast' => $forecast,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        // Atualizar cache na tabela meta_accounts (incluindo previsão)
        $update = [
            'last_balance'        => $snap['balance'],
            'last_spend_cap'      => $snap['spend_cap'],
            'last_amount_spent'   => $snap['amount_spent'],
            'last_account_status' => $snap['account_status'],
            'last_checked_at'     => date('Y-m-d H:i:s'),
            'currency'            => $snap['currency'] ?: $acc['currency'],
        ];
        // Sempre persistir o tipo detectado (caso tenha sido auto-detectado a partir de unknown)
        $update['account_type'] = $acc['account_type'];

        // Campos de previsão (se o schema suportar — ver migrations/002)
        if ($forecast) {
            $update['forecast_runway_days']   = $forecast['runway_days'];
            $update['forecast_depletion_at']  = $forecast['depletion_date'];
            $update['forecast_daily_cents']   = $forecast['spend_weighted_daily_cents'];
            $update['forecast_today_cents']   = $forecast['spend_today_cents'];
            $update['forecast_trend']         = $forecast['trend'];
            $update['forecast_active_ads']    = $forecast['active_ads'];
            $update['forecast_confidence']    = $forecast['confidence'];
            $update['forecast_updated_at']    = date('Y-m-d H:i:s');
        }

        try {
            Db::update('meta_accounts', $update, 'id = ?', [(int) $acc['id']]);
        } catch (Throwable $e) {
            // Se as colunas de forecast não existem ainda, cai no fallback
            $fallback = array_diff_key($update, array_flip([
                'forecast_runway_days','forecast_depletion_at','forecast_daily_cents',
                'forecast_today_cents','forecast_trend','forecast_active_ads',
                'forecast_confidence','forecast_updated_at',
            ]));
            Db::update('meta_accounts', $fallback, 'id = ?', [(int) $acc['id']]);
            $log[] = "[schema] Rode sql/migrations/002_forecast.sql para ativar monitoramento inteligente.";
        }

        // Avaliar alertas de saldo/status — passando o forecast pro motor
        $toFire = $engine->evaluate($acc, $snap, $forecast);

        // Avaliar alertas de performance (se há thresholds configurados)
        if (($acc['alert_roas_min'] ?? null) !== null || ($acc['alert_cpa_max'] ?? null) !== null || ($acc['alert_ctr_min'] ?? null) !== null) {
            try {
                $perfAlerts = $engine->evaluatePerformance($acc);
                $toFire = array_merge($toFire, $perfAlerts);
            } catch (Throwable $e) {
                $log[] = "[perf-alert] {$acc['ad_account_id']}: " . $e->getMessage();
            }
        }

        foreach ($toFire as $alert) {
            if ($engine->isInCooldown((int) $acc['id'], $alert['type'])) {
                $log[] = "[cooldown] {$acc['ad_account_id']} {$alert['type']}";
                continue;
            }

            $sentOk = false;
            $response = null;
            if ($wa && !empty($acc['whatsapp_group_jid'])) {
                try {
                    $r = $wa->sendText($acc['whatsapp_group_jid'], $alert['message']);
                    $sentOk = $r['ok'];
                    $response = json_encode($r['body'], JSON_UNESCAPED_UNICODE);
                } catch (Throwable $e) {
                    $response = $e->getMessage();
                }
            } else {
                $response = 'sem_whatsapp_ou_grupo';
            }

            Db::insert('alerts_log', [
                'meta_account_id'   => (int) $acc['id'],
                'alert_type'        => $alert['type'],
                'severity'          => $alert['severity'],
                'message'           => $alert['message'],
                'sent_ok'           => $sentOk ? 1 : 0,
                'provider_response' => $response,
            ]);
            if ($sentOk) $alertsFired++;
            $log[] = ($sentOk ? '[OK] ' : '[FAIL] ') . "{$acc['ad_account_id']} {$alert['type']}";
        }

    } catch (Throwable $e) {
        $errors++;
        $log[] = "[erro] {$acc['ad_account_id']}: " . $e->getMessage();
    }
}

Db::update('check_runs', [
    'finished_at'      => date('Y-m-d H:i:s'),
    'accounts_checked' => $accountsChecked,
    'alerts_fired'     => $alertsFired,
    'errors'           => $errors,
    'log'              => implode("\n", $log),
], 'id = ?', [$runId]);

if (PHP_SAPI === 'cli') {
    echo "Checked: $accountsChecked | Alerts: $alertsFired | Errors: $errors\n";
}
