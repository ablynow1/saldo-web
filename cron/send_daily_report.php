<?php
declare(strict_types=1);

/**
 * Envia relatórios agendados com fluxo de APROVAÇÃO por link.
 *
 * Fluxo:
 *   1. Gera a mensagem do relatório
 *   2. Salva na fila (report_queue) com token único
 *   3. Envia pro WhatsApp do ADMIN com links Aprovar/Cancelar
 *   4. Admin clica Aprovar → approve.php envia pro grupo do cliente
 *
 * Agendar: *\/5 * * * * /usr/bin/php /home/USUARIO/cron/send_daily_report.php
 */

if (!defined('APP_ROOT')) {
    require_once __DIR__ . '/../app/bootstrap.php';
}

set_time_limit(120);

try {
    $wa      = WhatsAppClient::fromSettings();
    $reports = ReportBuilder::fromSettings();
} catch (Throwable $e) {
    if (PHP_SAPI === 'cli') fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

// Número do admin (recebe os links de aprovação)
$adminWa       = Db::getSetting('admin_whatsapp') ?: null;
$useApproval   = (bool)(int)(Db::getSetting('report_approval') ?? 1);
$hasQueue      = table_exists('report_queue');

// URL base para os links de aprovação
$schema  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? '';
$baseUrl = $host ? ($schema . '://' . $host . BASE_URL) : Db::getSetting('site_url');

$nowHour   = (int) date('G');
$nowMin    = (int) date('i');
$nowWday   = (int) date('N'); // 1=seg, 7=dom
$nowDay    = (int) date('j');

$fired = 0;

// ── FASE 1: PREPARAÇÃO (00:30 do dia do disparo) ───────────────────────
if ($useApproval && $hasQueue && $adminWa && $baseUrl && $nowHour === 0 && $nowMin >= 30 && $nowMin <= 34) {
    $prepSchedules = Db::all(
        "SELECT rs.*, c.whatsapp_group_jid, c.name AS client_name, c.active AS client_active
         FROM report_schedules rs
         JOIN clients c ON c.id = rs.client_id
         WHERE rs.enabled = 1 AND c.active = 1 AND c.whatsapp_group_jid IS NOT NULL"
    );

    foreach ($prepSchedules as $sched) {
        $type = $sched['report_type'];
        if ($type === 'weekly_summary'   && $nowWday !== (int)($sched['send_day'] ?? 1)) continue;
        if ($type === 'monthly_summary'  && $nowDay  !== (int)($sched['send_day'] ?? 1)) continue;
        if ($type === 'weekend_forecast' && $nowWday !== 5) continue;

        $accounts = Db::all(
            'SELECT ma.*, ? AS client_name FROM meta_accounts ma WHERE ma.client_id = ? AND ma.active = 1',
            [$sched['client_name'], $sched['client_id']]
        );

        foreach ($accounts as $account) {
            // Verifica se já está na fila hoje
            $exists = Db::one(
                "SELECT id FROM report_queue WHERE client_id = ? AND meta_account_id = ? AND report_type = ? AND DATE(created_at) = CURDATE()",
                [$sched['client_id'], $account['id'], $type]
            );
            if ($exists) continue;

            try {
                $msg = match ($type) {
                    'daily_creatives'  => $reports->buildDailyCreativeReport($account),
                    'weekly_summary'   => $reports->buildWeeklySummary($account),
                    'monthly_summary'  => $reports->buildMonthlySummary($account),
                    'weekend_forecast' => $reports->buildWeekendForecast($account),
                    'personalizado'    => $reports->buildCustomReport(
                        $account,
                        date('Y-m-d', strtotime('-' . max(1, (int)($sched['send_day'] ?? 7)) . ' days')),
                        date('Y-m-d', strtotime('-1 day'))
                    ),
                    default            => null,
                };
                if (!$msg) continue;

                $token = bin2hex(random_bytes(16));
                Db::insert('report_queue', [
                    'client_id'       => (int)$sched['client_id'],
                    'meta_account_id' => (int)$account['id'],
                    'report_type'     => $type,
                    'group_jid'       => $sched['whatsapp_group_jid'],
                    'message'         => $msg,
                    'approve_token'   => $token,
                    'status'          => 'pending',
                ]);

                $typeLabels = [
                    'daily_creatives'  => 'Relatório Diário',
                    'weekly_summary'   => 'Resumo Semanal',
                    'monthly_summary'  => 'Resumo Mensal',
                    'weekend_forecast' => 'Prévia Fim de Semana',
                ];

                $approveUrl = $baseUrl . '/approve.php?token=' . $token . '&action=approve';
                $rejectUrl  = $baseUrl . '/approve.php?token=' . $token . '&action=reject';

                $approvalMsg = implode("\n", [
                    '⚠️ *Aprovação de Relatório*',
                    '',
                    '*Cliente:* ' . $sched['client_name'],
                    '*Tipo:* ' . ($typeLabels[$type] ?? $type),
                    '*Envio agendado para:* ' . str_pad((string)$sched['send_hour'], 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$sched['send_minute'], 2, '0', STR_PAD_LEFT),
                    '',
                    '📄 *Mensagem Completa:*',
                    $msg,
                    '',
                    '✅ *APROVAR (envia no horário agendado):*',
                    $approveUrl,
                    '',
                    '❌ *CANCELAR (não envia):*',
                    $rejectUrl,
                    '',
                    '_Se não houver ação, a mensagem será enviada automaticamente no horário agendado._'
                ]);

                $adminDest = preg_replace('/\D/', '', $adminWa);
                if (!str_starts_with($adminDest, '55')) $adminDest = '55' . $adminDest;
                $adminDest .= '@s.whatsapp.net';

                $wa->sendText($adminDest, $approvalMsg);

            } catch (Throwable $e) {
                // Ignore prep errors
            }
        }
    }
}

// ── FASE 2: DISPARO (horário atual) ────────────────────────────────────
$schedules = Db::all(
    "SELECT rs.*, c.whatsapp_group_jid, c.name AS client_name, c.active AS client_active
     FROM report_schedules rs
     JOIN clients c ON c.id = rs.client_id
     WHERE rs.enabled = 1 AND c.active = 1 AND c.whatsapp_group_jid IS NOT NULL
       AND rs.send_hour = ?
       AND rs.send_minute BETWEEN ? AND ?
       AND (rs.last_sent_at IS NULL OR DATE(rs.last_sent_at) < CURDATE())",
    [$nowHour, max(0, $nowMin - 4), $nowMin]
);

foreach ($schedules as $sched) {
    $type = $sched['report_type'];

    if ($type === 'weekly_summary'   && $nowWday !== (int)($sched['send_day'] ?? 1)) continue;
    if ($type === 'monthly_summary'  && $nowDay  !== (int)($sched['send_day'] ?? 1)) continue;
    if ($type === 'weekend_forecast' && $nowWday !== 5) continue;

    $accounts = Db::all(
        'SELECT ma.*, ? AS client_name FROM meta_accounts ma WHERE ma.client_id = ? AND ma.active = 1',
        [$sched['client_name'], $sched['client_id']]
    );

    foreach ($accounts as $account) {
        try {
            if ($useApproval && $hasQueue) {
                // Procura na fila de hoje
                $queueItem = Db::one(
                    "SELECT * FROM report_queue
                     WHERE client_id = ? AND meta_account_id = ? AND report_type = ? AND DATE(created_at) = CURDATE()
                       AND status IN ('pending','approved')
                     ORDER BY id DESC LIMIT 1",
                    [$sched['client_id'], $account['id'], $type]
                );

                if ($queueItem) {
                    if ($queueItem['status'] === 'rejected') {
                        // Ignora se foi rejeitado
                        continue;
                    }
                    
                    // Se estiver 'pending' ou 'approved', nós enviamos
                    $msg = $queueItem['message'];
                    $r = $wa->sendText($sched['whatsapp_group_jid'], $msg);
                    
                    Db::insert('report_log', [
                        'client_id'   => (int)$sched['client_id'],
                        'report_type' => $type,
                        'message'     => $msg,
                        'sent_ok'     => $r['ok'] ? 1 : 0,
                    ]);
                    
                    if ($r['ok']) {
                        $fired++;
                        Db::exec("UPDATE report_queue SET status='sent', resolved_at=NOW() WHERE id=?", [(int)$queueItem['id']]);
                    }
                    continue;
                }
            }

            // Fluxo sem aprovação ou se não encontrou na fila (fallback)
            $msg = match ($type) {
                'daily_creatives'  => $reports->buildDailyCreativeReport($account),
                'weekly_summary'   => $reports->buildWeeklySummary($account),
                'monthly_summary'  => $reports->buildMonthlySummary($account),
                'weekend_forecast' => $reports->buildWeekendForecast($account),
                'personalizado'    => $reports->buildCustomReport(
                    $account,
                    date('Y-m-d', strtotime('-' . max(1, (int)($sched['send_day'] ?? 7)) . ' days')),
                    date('Y-m-d', strtotime('-1 day'))
                ),
                default            => null,
            };
            if (!$msg) continue;

            $r = $wa->sendText($sched['whatsapp_group_jid'], $msg);
            Db::insert('report_log', [
                'client_id'   => (int)$sched['client_id'],
                'report_type' => $type,
                'message'     => $msg,
                'sent_ok'     => $r['ok'] ? 1 : 0,
            ]);
            if ($r['ok']) $fired++;

        } catch (Throwable $e) {
            Db::insert('report_log', [
                'client_id'   => (int)$sched['client_id'],
                'report_type' => $type,
                'message'     => 'ERRO: ' . $e->getMessage(),
                'sent_ok'     => 0,
            ]);
        }
    }

    Db::exec('UPDATE report_schedules SET last_sent_at = NOW() WHERE id = ?', [(int)$sched['id']]);
}

if (PHP_SAPI === 'cli') {
    echo "Reports fired: $fired | Schedules checked for dispatch: " . count($schedules) . "\n";
}
