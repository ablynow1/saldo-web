<?php
declare(strict_types=1);

/**
 * Avalia snapshot da conta Meta Ads e decide se deve disparar alerta.
 *
 * Todos os valores monetários estão em "centavos" da moeda da conta
 * (mesma convenção que a Graph API devolve para balance/amount_spent/spend_cap).
 */
final class AlertEngine
{
    // Alertas de saldo (originais)
    public const TYPE_LOW_BALANCE      = 'low_balance';
    public const TYPE_SPEND_CAP_NEAR   = 'spend_cap_near';
    public const TYPE_ACCOUNT_BLOCKED  = 'account_blocked';
    public const TYPE_NO_FUNDING       = 'no_funding';

    // Alertas de performance (novos)
    public const TYPE_ROAS_DROP        = 'roas_drop';
    public const TYPE_CPA_SPIKE        = 'cpa_spike';
    public const TYPE_CTR_DROP         = 'ctr_drop';
    public const TYPE_AD_DISAPPROVED   = 'ad_disapproved';
    public const TYPE_BUDGET_OVERPACE  = 'budget_overpace';

    private int $cooldownHours;

    public function __construct(?int $cooldownHours = null)
    {
        $this->cooldownHours = $cooldownHours ?? (int) (Db::getSetting('alert_cooldown_hours') ?? 6);
    }

    /**
     * Avalia alertas de conta: status + saldo (pré-pago) + cap (pós-pago).
     * Usa BalanceForecaster se fornecido (monitoramento inteligente).
     *
     * @param array $account              linha da tabela meta_accounts + joined clients
     * @param array $snapshot             dados retornados por MetaAdsClient::getAccount()
     * @param ?array $forecast            resultado de BalanceForecaster::forecast() — opcional
     * @return array<int,array{type:string,severity:string,message:string}>
     */
    public function evaluate(array $account, array $snapshot, ?array $forecast = null): array
    {
        $alerts = [];
        $name   = $account['account_name'] ?: ('Conta ' . $account['ad_account_id']);
        $client = $account['client_name'] ?? 'Cliente';
        $curr   = $snapshot['currency'] ?: 'BRL';

        // 1) Status da conta
        $status = $snapshot['account_status'];
        if (in_array($status, [2, 3, 7, 9, 101, 102], true)) {
            $statusMap = [
                2   => 'DESATIVADA',
                3   => 'SEM FUNDOS',
                7   => 'EM REVISÃO DE RISCO',
                9   => 'REPROVADA',
                101 => 'FECHADA',
                102 => 'EXCEDEU LIMITE DE GASTO',
            ];
            $alerts[] = [
                'type'     => $status === 3 ? self::TYPE_NO_FUNDING : self::TYPE_ACCOUNT_BLOCKED,
                'severity' => 'critical',
                'message'  => sprintf(
                    "🚨 *ALERTA CRÍTICO — %s*\n\nA conta *%s* está com status: *%s*.\n\nAs campanhas estão paradas ou prestes a parar. Ação imediata necessária.",
                    $client, $name,
                    $statusMap[$status] ?? ('STATUS ' . $status)
                ),
            ];
        }

        // 2) Pré-pago: monitoramento inteligente com forecaster
        if ($account['account_type'] === 'prepaid'
            && $snapshot['balance'] !== null
            && $forecast !== null
            && $forecast['runway_days'] !== null
        ) {
            $alert = $this->buildSmartBalanceAlert($account, $snapshot, $forecast);
            if ($alert) $alerts[] = $alert;
        }

        // 3) Pós-pago: % do spend_cap atingido
        if ($account['account_type'] === 'postpaid'
            && !empty($snapshot['spend_cap'])
            && $snapshot['amount_spent'] !== null
        ) {
            $cap = (float) $snapshot['spend_cap'];
            $spent = (float) $snapshot['amount_spent'];
            if ($cap > 0) {
                $pct = ($spent / $cap) * 100;
                $limite = (float) $account['threshold_spend_cap_pct'];
                if ($pct >= $limite) {
                    $remaining = max(0.0, $cap - $spent);
                    $etaText = '';
                    if ($forecast && $forecast['spend_weighted_daily_cents'] > 0) {
                        $daysUntilCap = $remaining / $forecast['spend_weighted_daily_cents'];
                        $etaText = sprintf("\nEstimativa para atingir 100%%: *%.1f dias*", $daysUntilCap);
                    }
                    $alerts[] = [
                        'type'     => self::TYPE_SPEND_CAP_NEAR,
                        'severity' => $pct >= 95 ? 'critical' : 'warning',
                        'message'  => sprintf(
                            "⚠️ *Limite de gasto — %s*\n\nConta: *%s*\nGasto atual: *%s* (*%.1f%%* do limite)\nLimite: *%s*%s\n\nAs campanhas podem ser pausadas automaticamente ao atingir 100%%.",
                            $client, $name,
                            self::fmtMoney($spent, $curr),
                            $pct,
                            self::fmtMoney($cap, $curr),
                            $etaText
                        ),
                    ];
                }
            }
        }

        return $alerts;
    }

    /**
     * Monta o alerta inteligente de saldo baixo com previsão, tendência e recomendação.
     * Só dispara se runway_days < threshold configurado OU tendência crítica.
     */
    private function buildSmartBalanceAlert(array $account, array $snapshot, array $forecast): ?array
    {
        $name    = $account['account_name'] ?: ('Conta ' . $account['ad_account_id']);
        $client  = $account['client_name'] ?? 'Cliente';
        $curr    = $snapshot['currency'] ?: 'BRL';
        $saldo   = (float) $snapshot['balance'];
        $runway  = (float) $forecast['runway_days'];
        $limite  = (float) $account['threshold_days_runway'];

        // Só alerta se está dentro do threshold (ou negativo)
        if ($runway >= $limite) return null;

        // Severidade dinâmica
        if ($runway < 0.5) {
            $severity = 'critical';
            $headline = '🚨 *SALDO CRÍTICO — campanhas param em horas*';
        } elseif ($runway < 1.5) {
            $severity = 'critical';
            $headline = '🚨 *SALDO VAI ACABAR AMANHÃ*';
        } elseif ($runway < 3) {
            $severity = 'warning';
            $headline = '⚠️ *Saldo baixo — ação necessária*';
        } else {
            $severity = 'warning';
            $headline = '📉 *Saldo abaixo do limite configurado*';
        }

        // Seta de tendência
        $trendIcon = match ($forecast['trend']) {
            'accelerating' => '🔥 acelerando',
            'decelerating' => '🧊 desacelerando',
            default        => '➖ estável',
        };
        $trendPct = (float) $forecast['trend_pct'];
        $trendText = $trendIcon . (abs($trendPct) >= 10 ? sprintf(' (%+.0f%% em 3d)', $trendPct) : '');

        // Linhas opcionais
        $lines = [];
        $lines[] = $headline;
        $lines[] = '';
        $lines[] = "*$client* — conta *$name*";
        $lines[] = '';
        $lines[] = '💰 Saldo atual: *' . self::fmtMoney($saldo, $curr) . '*';

        if ($forecast['spend_today_cents'] > 0) {
            $lines[] = '📅 Já gasto hoje: ' . self::fmtMoney($forecast['spend_today_cents'], $curr)
                . ' (projeção ' . self::fmtMoney($forecast['spend_today_projected_cents'], $curr) . '/dia)';
        }

        $lines[] = '📊 Ritmo diário (3d): ' . self::fmtMoney($forecast['spend_avg_3d_cents'], $curr)
            . ' · ' . $trendText;

        if ($forecast['active_ads'] !== null) {
            $lines[] = '🎯 Anúncios ativos: *' . $forecast['active_ads'] . '*';
        }

        $lines[] = '';
        if ($runway < 0) {
            $lines[] = '⏱ *Autonomia: SALDO NO NEGATIVO*';
        } else {
            $lines[] = sprintf('⏱ Autonomia: *%.1f dias*', $runway);
            if (!empty($forecast['depletion_day_human'])) {
                $lines[] = '🗓 Previsão de término: *' . $forecast['depletion_day_human'] . '*';
            }
        }

        if ($forecast['recommended_topup_cents']) {
            $lines[] = '';
            $lines[] = '💡 Recarga recomendada (10 dias): *'
                . self::fmtMoney($forecast['recommended_topup_cents'], $curr) . '*';
        }

        // Nota de confiança se baixa
        if ($forecast['confidence'] === 'low') {
            $lines[] = '';
            $lines[] = "_ℹ️ Previsão com baixa confiança — poucos dias de histórico._";
        }

        return [
            'type'     => self::TYPE_LOW_BALANCE,
            'severity' => $severity,
            'message'  => implode("\n", $lines),
        ];
    }

    // ─────────────────────────── ALERTAS DE PERFORMANCE ───────────────────────────

    /**
     * Avalia métricas de performance do dia (dados da tabela ad_insights).
     * Dispara alertas se ROAS, CPA ou CTR estiverem fora dos thresholds configurados.
     *
     * @param array $account  Linha da tabela meta_accounts (com alert_roas_min, alert_cpa_max, alert_ctr_min)
     * @return array<int,array{type:string,severity:string,message:string}>
     */
    public function evaluatePerformance(array $account): array
    {
        $alerts   = [];
        $accDbId  = (int) $account['id'];
        $name     = $account['account_name'] ?: ('Conta ' . $account['ad_account_id']);
        $client   = $account['client_name'] ?? 'Cliente';
        $currency = $account['currency'] ?: 'BRL';

        $yesterday = date('Y-m-d', strtotime('-1 day'));

        // Métricas agregadas de ontem
        $summary = Db::one(
            'SELECT
               SUM(spend) AS total_spend,
               SUM(impressions) AS total_impressions,
               SUM(clicks) AS total_clicks,
               SUM(conversions) AS total_conversions,
               SUM(conversion_value) AS total_revenue,
               (SUM(clicks)/NULLIF(SUM(impressions),0))*100 AS avg_ctr,
               SUM(spend)/NULLIF(SUM(conversions),0) AS avg_cpa,
               SUM(conversion_value)/NULLIF(SUM(spend),0) AS avg_roas
             FROM ad_insights
             WHERE meta_account_id = ? AND date_start = ?',
            [$accDbId, $yesterday]
        );

        if (!$summary || !$summary['total_spend']) {
            return []; // Sem dados para avaliar
        }

        $roas = (float) ($summary['avg_roas'] ?? 0);
        $cpa  = (float) ($summary['avg_cpa']  ?? 0);
        $ctr  = (float) ($summary['avg_ctr']  ?? 0);

        // 1. ROAS abaixo do mínimo
        $roasMin = isset($account['alert_roas_min']) && $account['alert_roas_min'] !== null
            ? (float) $account['alert_roas_min'] : null;
        if ($roasMin !== null && $roas > 0 && $roas < $roasMin) {
            $alerts[] = [
                'type'     => self::TYPE_ROAS_DROP,
                'severity' => $roas < ($roasMin * 0.5) ? 'critical' : 'warning',
                'message'  => sprintf(
                    "📉 *ROAS baixo - %s*\n\nConta: *%s*\nROAS ontem: *%.2fx*\nMínimo configurado: *%.2fx*\n\n💡 Revise os criativos e segmentação com pior performance.",
                    $client, $name, $roas, $roasMin
                ),
            ];
        }

        // 2. CPA acima do máximo
        $cpaMax = isset($account['alert_cpa_max']) && $account['alert_cpa_max'] !== null
            ? (float) $account['alert_cpa_max'] : null;
        if ($cpaMax !== null && $cpa > 0 && $cpa > $cpaMax) {
            $alerts[] = [
                'type'     => self::TYPE_CPA_SPIKE,
                'severity' => $cpa > ($cpaMax * 1.5) ? 'critical' : 'warning',
                'message'  => sprintf(
                    "💸 *CPA alto - %s*\n\nConta: *%s*\nCPA ontem: *%s*\nMáximo configurado: *%s*\n\n💡 Verifique os criativos com maior custo por aquisição.",
                    $client, $name,
                    self::fmtReal($cpa, $currency),
                    self::fmtReal($cpaMax, $currency)
                ),
            ];
        }

        // 3. CTR abaixo do mínimo
        $ctrMin = isset($account['alert_ctr_min']) && $account['alert_ctr_min'] !== null
            ? (float) $account['alert_ctr_min'] : null;
        if ($ctrMin !== null && $ctr > 0 && $ctr < $ctrMin) {
            $alerts[] = [
                'type'     => self::TYPE_CTR_DROP,
                'severity' => 'warning',
                'message'  => sprintf(
                    "👁️ *CTR baixo - %s*\n\nConta: *%s*\nCTR ontem: *%.2f%%*\nMínimo configurado: *%.2f%%*\n\n💡 Os criativos podem estar perdendo relevância. Considere renovar as peças.",
                    $client, $name, $ctr, $ctrMin
                ),
            ];
        }

        // 4. Anúncios reprovados (checa via API de status dos ads)
        $disapproved = Db::all(
            "SELECT ad_id, ad_name, campaign_name FROM ad_insights
             WHERE meta_account_id = ? AND date_start = ? AND effective_status = 'DISAPPROVED'
             LIMIT 5",
            [$accDbId, $yesterday]
        );
        if (!empty($disapproved)) {
            $adList = implode("\n", array_map(
                fn($a) => '• ' . self::truncate($a['ad_name'] ?? 'Anúncio', 60),
                $disapproved
            ));
            $alerts[] = [
                'type'     => self::TYPE_AD_DISAPPROVED,
                'severity' => 'critical',
                'message'  => sprintf(
                    "🚫 *Anúncio(s) reprovado(s) - %s*\n\nConta: *%s*\n\n%s\n\n💡 Acesse o Gerenciador de Anúncios para ver o motivo e solicitar revisão.",
                    $client, $name, $adList
                ),
            ];
        }

        // 5. Overpacing (gastando mais rápido que o esperado no dia)
        // Estimativa simples: gasto atual no dia vs proporção do dia decorrido
        $hoursElapsed = (int) date('G') ?: 1;
        $paceExpected = ((float) ($summary['total_spend'] ?? 0)) / ($hoursElapsed / 24);
        $dailyBudget  = $this->estimateDailyBudget($accDbId);
        if ($dailyBudget > 0 && $paceExpected > $dailyBudget * 1.2) {
            $alerts[] = [
                'type'     => self::TYPE_BUDGET_OVERPACE,
                'severity' => 'warning',
                'message'  => sprintf(
                    "⚡ *Ritmo de gasto acelerado - %s*\n\nConta: *%s*\nGasto no ritmo atual: *%s/dia*\nOrçamento diário estimado: *%s*\n\n⚠️ A conta pode esgotar o orçamento antes do fim do dia.",
                    $client, $name,
                    self::fmtReal($paceExpected, $currency),
                    self::fmtReal($dailyBudget, $currency)
                ),
            ];
        }

        return $alerts;
    }

    private function estimateDailyBudget(int $metaAccountDbId): float
    {
        // Média dos últimos 7 dias como estimativa do orçamento diário
        $avg = Db::scalar(
            'SELECT AVG(total) FROM (
               SELECT SUM(spend) AS total FROM ad_insights
               WHERE meta_account_id = ? AND date_start >= ?
               GROUP BY date_start
             ) t',
            [$metaAccountDbId, date('Y-m-d', strtotime('-7 days'))]
        );
        return (float) ($avg ?? 0);
    }

    private static function fmtReal(float $value, string $currency): string
    {
        if ($currency === 'BRL') return 'R$ ' . number_format($value, 2, ',', '.');
        return number_format($value, 2) . ' ' . $currency;
    }

    private static function truncate(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    /**
     * Retorna true se já houve alerta do mesmo tipo dentro da janela de cooldown.
     */
    public function isInCooldown(int $metaAccountId, string $type): bool
    {
        $row = Db::one(
            'SELECT sent_at FROM alerts_log
             WHERE meta_account_id = ? AND alert_type = ? AND sent_ok = 1
             ORDER BY sent_at DESC LIMIT 1',
            [$metaAccountId, $type]
        );
        if (!$row) return false;
        $last = strtotime($row['sent_at']);
        return ($last + $this->cooldownHours * 3600) > time();
    }

    private static function fmtMoney(float $cents, string $currency): string
    {
        $val = $cents / 100;
        if (class_exists('NumberFormatter')) {
            $fmt = new NumberFormatter('pt_BR', NumberFormatter::CURRENCY);
            return $fmt->formatCurrency($val, $currency);
        }
        return number_format($val, 2, ',', '.') . ' ' . $currency;
    }
}
