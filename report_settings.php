<?php
require_once __DIR__ . '/app/bootstrap.php';
require_auth();

$clientId = (int) ($_GET['client'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? null) && table_exists('report_schedules')) {
    $op = $_POST['op'] ?? '';

    if ($op === 'save_schedules') {
        $cId = (int) $_POST['client_id'];
        $types = ['daily_creatives', 'weekly_summary', 'advanced_weekly_summary', 'monthly_summary', 'weekend_forecast'];
        foreach ($types as $type) {
            $enabled = isset($_POST["enabled_{$type}"]) ? 1 : 0;
            $hour    = max(0, min(23, (int) ($_POST["hour_{$type}"] ?? 8)));
            $minute  = max(0, min(59, (int) ($_POST["minute_{$type}"] ?? 0)));
            $day     = isset($_POST["day_{$type}"]) ? max(1, min(31, (int) $_POST["day_{$type}"])) : null;

            $existing = Db::one('SELECT id FROM report_schedules WHERE client_id = ? AND report_type = ?', [$cId, $type]);
            if ($existing) {
                Db::update('report_schedules', [
                    'enabled' => $enabled, 'send_hour' => $hour,
                    'send_minute' => $minute, 'send_day' => $day,
                ], 'id = ?', [(int) $existing['id']]);
            } else {
                Db::insert('report_schedules', [
                    'client_id' => $cId, 'report_type' => $type,
                    'enabled' => $enabled, 'send_hour' => $hour,
                    'send_minute' => $minute, 'send_day' => $day,
                ]);
            }
        }

        if (!empty($_POST['account_thresholds'])) {
            foreach ($_POST['account_thresholds'] as $aId => $thresh) {
                Db::update('meta_accounts', [
                    'alert_roas_min' => $thresh['roas'] !== '' ? (float) $thresh['roas'] : null,
                    'alert_cpa_max'  => $thresh['cpa']  !== '' ? (float) $thresh['cpa']  : null,
                    'alert_ctr_min'  => $thresh['ctr']  !== '' ? (float) $thresh['ctr']  : null,
                    'collect_insights' => isset($thresh['collect']) ? 1 : 0,
                ], 'id = ? AND client_id = ?', [(int) $aId, $cId]);
            }
        }

        flash('Configurações salvas!', 'success');
        header('Location: report_settings.php?client=' . $cId);
        exit;
    }

    if ($op === 'preview') {
        header('Content-Type: application/json');
        $cId      = (int) $_POST['client_id'];
        $type     = $_POST['report_type'] ?? 'daily_creatives';
        $dateFrom = trim($_POST['date_from'] ?? '') ?: date('Y-m-d', strtotime('-7 days'));
        $dateTo   = trim($_POST['date_to']   ?? '') ?: date('Y-m-d', strtotime('-1 day'));
        $client   = Db::one('SELECT * FROM clients WHERE id = ?', [$cId]);
        if (!$client || !$client['whatsapp_group_jid']) {
            if (ob_get_level()) ob_clean();
            echo json_encode(['ok' => false, 'error' => 'Cliente inválido ou sem grupo.']);
            exit;
        }
        $rb       = ReportBuilder::fromSettings();
        $insights = null;
        if ($type === 'daily_today' || $type === 'weekly_summary') {
            try { $insights = InsightsClient::fromSettings(); } catch (Throwable $e) {}
        }
        $accounts = Db::all(
            'SELECT ma.*, ? AS client_name FROM meta_accounts ma WHERE client_id = ? AND active = 1',
            [$client['name'], $cId]
        );
        $msgs = [];
        $today = date('Y-m-d');
        foreach ($accounts as $acc) {
            if ($insights) {
                try {
                    if ($type === 'daily_today') {
                        $insights->collectAdInsights((int)$acc['id'], $acc['ad_account_id'], 'custom', $today, $today);
                        $insights->collectCampaignInsights((int)$acc['id'], $acc['ad_account_id'], 'custom', $today, $today);
                    } elseif ($type === 'weekly_summary') {
                        $weekStart = date('Y-m-d', strtotime('-7 days'));
                        $weekEnd   = date('Y-m-d', strtotime('-1 day'));
                        $insights->collectAdInsights((int)$acc['id'], $acc['ad_account_id'], 'custom', $weekStart, $weekEnd);
                        $insights->collectCampaignInsights((int)$acc['id'], $acc['ad_account_id'], 'custom', $weekStart, $weekEnd);
                    }
                } catch (Throwable $e) {}
            }
            $msg = match ($type) {
                'daily_creatives' => $rb->buildDailyCreativeReport($acc),
                'daily_today'     => $rb->buildDailyCreativeReport($acc, date('Y-m-d')),
                'weekly_summary'  => $rb->buildWeeklySummary($acc),
                'personalizado'   => $rb->buildCustomReport($acc, $dateFrom, $dateTo),
                'weekend_forecast'=> $rb->buildWeekendForecast($acc),
                default           => null,
            };
            if ($msg) $msgs[] = $msg;
        }
        if (empty($msgs)) {
            if (ob_get_level()) ob_clean();
            echo json_encode(['ok' => false, 'error' => 'Nenhuma conta ativa ou sem dados para gerar.']);
            exit;
        }
        if (ob_get_level()) ob_clean();
        echo json_encode(['ok' => true, 'messages' => $msgs]);
        exit;
    }

    // ── send_now: envia diretamente pro grupo (sem aprovação) ──────────────────
    if ($op === 'send_now') {
        $cId      = (int) $_POST['client_id'];
        $type     = $_POST['report_type'] ?? 'daily_creatives';
        $dateFrom = trim($_POST['date_from'] ?? '') ?: date('Y-m-d', strtotime('-7 days'));
        $dateTo   = trim($_POST['date_to']   ?? '') ?: date('Y-m-d', strtotime('-1 day'));
        $client   = Db::one('SELECT * FROM clients WHERE id = ?', [$cId]);

        if ($client && $client['whatsapp_group_jid']) {
            try {
                $rb       = ReportBuilder::fromSettings();
                $wa       = WhatsAppClient::fromSettings();
                $accounts = Db::all(
                    'SELECT ma.*, ? AS client_name FROM meta_accounts ma WHERE client_id = ? AND active = 1',
                    [$client['name'], $cId]
                );
                $sent = 0;
                foreach ($accounts as $acc) {
                    $msg = match ($type) {
                        'daily_creatives' => $rb->buildDailyCreativeReport($acc),
                        'daily_today'     => $rb->buildDailyCreativeReport($acc, date('Y-m-d')),
                        'weekly_summary'  => $rb->buildWeeklySummary($acc),
                        'personalizado'   => $rb->buildCustomReport($acc, $dateFrom, $dateTo),
                        'weekend_forecast'=> $rb->buildWeekendForecast($acc),
                        default           => null,
                    };
                    if (!$msg) continue;
                    $r = $wa->sendText($client['whatsapp_group_jid'], $msg);
                    if ($r['ok']) {
                        Db::insert('report_log', [
                            'client_id'   => $cId,
                            'report_type' => $type,
                            'message'     => $msg,
                            'sent_ok'     => 1,
                        ]);
                        $sent++;
                    }
                }
                flash("Relatório enviado direto para o grupo ({$sent} conta(s)).", 'success');
            } catch (Throwable $e) {
                flash('Erro: ' . $e->getMessage(), 'danger');
            }
        } else {
            flash('Cliente sem grupo de WhatsApp configurado.', 'warning');
        }
        header('Location: report_settings.php?client=' . $cId);
        exit;
    }

    // ── test_schedule: SEMPRE manda pro WhatsApp do admin para aprovação ───────
    if ($op === 'test_schedule') {
        $cId      = (int) $_POST['client_id'];
        $type     = $_POST['report_type'] ?? 'daily_creatives';
        $dateFrom = trim($_POST['date_from'] ?? '') ?: date('Y-m-d', strtotime('-7 days'));
        $dateTo   = trim($_POST['date_to']   ?? '') ?: date('Y-m-d', strtotime('-1 day'));
        $client   = Db::one('SELECT * FROM clients WHERE id = ?', [$cId]);

        $adminWa  = Db::getSetting('admin_whatsapp') ?: null;
        $siteUrl  = rtrim(Db::getSetting('site_url') ?: ('https://' . ($_SERVER['HTTP_HOST'] ?? '') . BASE_URL), '/');
        $hasQueue = table_exists('report_queue');

        if (!$adminWa) {
            flash('⚠️ Configure seu WhatsApp em Configurações → "Seu WhatsApp (recebe as aprovações)".', 'warning');
            header('Location: report_settings.php?client=' . $cId); exit;
        }
        if (!$siteUrl) {
            flash('⚠️ Configure a URL do site em Configurações para que os links de aprovação funcionem.', 'warning');
            header('Location: report_settings.php?client=' . $cId); exit;
        }
        if (!$hasQueue) {
            flash('⚠️ Execute setup.php para criar a tabela report_queue antes de usar o fluxo de aprovação.', 'warning');
            header('Location: report_settings.php?client=' . $cId); exit;
        }
        if (!$client || !$client['whatsapp_group_jid']) {
            flash('Cliente sem grupo de WhatsApp configurado.', 'warning');
            header('Location: report_settings.php?client=' . $cId); exit;
        }

        try {
            $rb       = ReportBuilder::fromSettings();
            $wa       = WhatsAppClient::fromSettings();
            $accounts = Db::all(
                'SELECT ma.*, ? AS client_name FROM meta_accounts ma WHERE client_id = ? AND active = 1',
                [$client['name'], $cId]
            );

            $typeLabels = [
                'daily_creatives' => 'Resumo Diário (ontem)',
                'daily_today'     => 'Resumo Hoje',
                'weekly_summary'  => 'Resumo Semanal',
                'personalizado'   => 'Personalizado (' . date('d/m', strtotime($dateFrom)) . ' a ' . date('d/m', strtotime($dateTo)) . ')',
                'weekend_forecast'=> 'Prévia Fim de Semana',
            ];

            $sent = 0;
            foreach ($accounts as $acc) {
                $msg = match ($type) {
                    'daily_creatives' => $rb->buildDailyCreativeReport($acc),
                    'daily_today'     => $rb->buildDailyCreativeReport($acc, date('Y-m-d')),
                    'weekly_summary'  => $rb->buildWeeklySummary($acc),
                    'personalizado'   => $rb->buildCustomReport($acc, $dateFrom, $dateTo),
                    'weekend_forecast'=> $rb->buildWeekendForecast($acc),
                    default           => null,
                };
                if (!$msg) continue;

                $token = bin2hex(random_bytes(16));
                Db::insert('report_queue', [
                    'client_id'       => $cId,
                    'meta_account_id' => (int)$acc['id'],
                    'report_type'     => $type,
                    'group_jid'       => $client['whatsapp_group_jid'],
                    'message'         => $msg,
                    'approve_token'   => $token,
                    'status'          => 'test_pending',
                ]);

                $approveUrl = $siteUrl . '/approve.php?token=' . $token . '&action=approve';
                $rejectUrl  = $siteUrl . '/approve.php?token=' . $token . '&action=reject';

                $approvalMsg = implode("\n", [
                    '🧪 *Teste de Relatório — Aprovação*',
                    '',
                    '*Cliente:* ' . $client['name'],
                    '*Tipo:* ' . ($typeLabels[$type] ?? $type),
                    '*Conta:* ' . ($acc['account_name'] ?: $acc['ad_account_id']),
                    '',
                    '📄 *Mensagem Completa:*',
                    $msg,
                    '',
                    '✅ *APROVAR — envia pro grupo do cliente AGORA:*',
                    $approveUrl,
                    '',
                    '❌ *CANCELAR — descarta:*',
                    $rejectUrl,
                ]);

                $dest = preg_replace('/\D/', '', $adminWa);
                if (!str_starts_with($dest, '55')) $dest = '55' . $dest;
                $r = $wa->sendText($dest . '@s.whatsapp.net', $approvalMsg);
                if ($r['ok']) $sent++;
            }

            flash("🧪 Teste enviado para o seu WhatsApp ({$sent} mensagem(s)). Clique em ✅ APROVAR para disparar pro grupo.", 'success');
        } catch (Throwable $e) {
            flash('Erro: ' . $e->getMessage(), 'danger');
        }
        header('Location: report_settings.php?client=' . $cId);
        exit;
    }
}

$hasReportTables = table_exists('report_schedules') && table_exists('report_log');
$hasAlertCols = column_exists('meta_accounts', 'alert_roas_min');

$clients = Db::all('SELECT * FROM clients ORDER BY name');
$client  = $clientId ? Db::one('SELECT * FROM clients WHERE id = ?', [$clientId]) : null;

$schedules = [];
if ($client && $hasReportTables) {
    $rows = Db::all('SELECT * FROM report_schedules WHERE client_id = ?', [$clientId]);
    foreach ($rows as $r) $schedules[$r['report_type']] = $r;
}

$accounts = $client
    ? Db::all('SELECT * FROM meta_accounts WHERE client_id = ? ORDER BY account_name', [$clientId])
    : [];

$reportTypes = [
    'daily_creatives' => [
        'label'     => 'Resumo diário',
        'icon'      => '📊',
        'desc'      => 'Enviado todo dia — resultado do dia anterior, todas as campanhas com impressão.',
        'has_day'   => false,
    ],
    'weekly_summary'  => [
        'label'     => 'Resumo semanal',
        'icon'      => '📅',
        'desc'      => 'Comparativo dos últimos 7 dias, métricas de funil e performance por campanha.',
        'has_day'   => true,
        'day_label' => 'Dia da semana (1=seg, 7=dom)',
    ],
    'monthly_summary' => [
        'label'     => 'Resumo mensal',
        'icon'      => '📆',
        'desc'      => 'Resultados do mês anterior com todas as campanhas.',
        'has_day'   => true,
        'day_label' => 'Dia do mês (1–28)',
    ],
    'weekend_forecast'=> [
        'label'     => 'Prévia de fim de semana',
        'icon'      => '🔮',
        'desc'      => 'Enviado toda sexta com estimativa de gasto no fim de semana.',
        'has_day'   => false,
    ],
    'personalizado'   => [
        'label'     => 'Personalizado',
        'icon'      => '🗓️',
        'desc'      => 'Gera relatório para os últimos N dias. Configure o período abaixo.',
        'has_day'   => true,
        'day_label' => 'Período (últimos N dias)',
    ],
];

$title = 'Relatórios · SALDO WEB';
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Relatórios Agendados</h1>
    <p class="page-subtitle">Configure quando e o que enviar para cada cliente</p>
  </div>
</div>

<div style="display:grid;grid-template-columns:220px 1fr;gap:20px;align-items:start">

  <!-- Client Sidebar -->
  <div>
    <div style="font-size:10.5px;font-weight:700;color:var(--text-4);letter-spacing:.6px;text-transform:uppercase;padding:0 4px;margin-bottom:8px">Clientes</div>
    <div class="list-group">
      <?php foreach ($clients as $c): ?>
        <a href="report_settings.php?client=<?= (int)$c['id'] ?>"
           class="list-group-item <?= $c['id']==$clientId?'active':'' ?>"
           style="display:flex;align-items:center;justify-content:space-between">
          <span><?= e($c['name']) ?></span>
          <?php if (!$c['whatsapp_group_jid']): ?>
            <span class="badge badge-orange" style="font-size:10px">sem grupo</span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Right Panel -->
  <div>
    <?php if (!$client): ?>
    <div class="empty-state" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius)">
      <div class="empty-icon">📋</div>
      <div class="empty-title">Selecione um cliente</div>
      <div class="empty-desc">Escolha um cliente à esquerda para configurar os relatórios agendados.</div>
    </div>
    <?php elseif (!$hasReportTables): ?>
    <div class="empty-state" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius)">
      <div class="empty-icon">🔧</div>
      <div class="empty-title">Migrações pendentes</div>
      <div class="empty-desc">Execute <a href="setup.php">setup.php</a> para criar as tabelas de relatórios.</div>
    </div>
    <?php else: ?>

    <!-- Send Now -->
    <div class="card" style="margin-bottom:16px">
      <div class="card-header">
        <span class="card-header-title">Enviar agora</span>
        <?php if (!$client['whatsapp_group_jid']): ?>
          <span style="font-size:12px;color:var(--orange)">⚠️ Grupo de WhatsApp não configurado</span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
          <?php foreach (['daily_today' => '☀️ Hoje', 'daily_creatives' => '📊 Ontem', 'weekly_summary' => '📅 Semanal', 'weekend_forecast' => '🔮 Fim de semana'] as $type => $label): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="op" value="send_now">
              <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">
              <input type="hidden" name="report_type" value="<?= $type ?>">
              <button class="btn btn-secondary btn-sm"
                      <?= !$client['whatsapp_group_jid'] ? 'disabled title="Configure o grupo de WhatsApp primeiro"' : '' ?>
                      onclick="showPreview(this, event)">
                <?= $label ?>
              </button>
            </form>
          <?php endforeach; ?>
        </div>

        <!-- Envio personalizado por período -->
        <div style="border-top:1px solid var(--border);padding-top:12px">
          <div style="font-size:11px;font-weight:700;color:var(--text-4);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">🗓️ Personalizado</div>
          <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="send_now">
            <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">
            <input type="hidden" name="report_type" value="personalizado">
            <div>
              <div style="font-size:11px;color:var(--text-4);margin-bottom:4px">De</div>
              <input type="date" name="date_from" class="form-control form-control-sm"
                     value="<?= date('Y-m-d', strtotime('-7 days')) ?>">
            </div>
            <div>
              <div style="font-size:11px;color:var(--text-4);margin-bottom:4px">Até</div>
              <input type="date" name="date_to" class="form-control form-control-sm"
                     value="<?= date('Y-m-d', strtotime('-1 day')) ?>">
            </div>
            <button class="btn btn-secondary btn-sm"
                    <?= !$client['whatsapp_group_jid'] ? 'disabled title="Configure o grupo de WhatsApp primeiro"' : '' ?>
                    onclick="showPreview(this, event)">
              Ver prévia e enviar
            </button>
          </form>
        </div>
      </div>
    </div>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="op" value="save_schedules">
      <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">

      <!-- Schedules -->
      <div class="card" style="margin-bottom:16px">
        <div class="card-header">
          <span class="card-header-title">Agendamentos automáticos</span>
        </div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:12px">
          <?php foreach ($reportTypes as $type => $info):
            $s = $schedules[$type] ?? ['enabled'=>0,'send_hour'=>8,'send_minute'=>0,'send_day'=>null,'last_sent_at'=>null];
          ?>
          <div style="background:var(--surface-2);border-radius:var(--radius);padding:16px">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
              <div style="flex:1;min-width:220px">
                <label class="form-check" style="margin-bottom:4px">
                  <input type="checkbox" class="form-check-input" id="en_<?= $type ?>" name="enabled_<?= $type ?>"
                         <?= $s['enabled']?'checked':'' ?>>
                  <span class="form-check-label" style="font-size:14px;font-weight:700">
                    <?= $info['icon'] ?> <?= $info['label'] ?>
                  </span>
                </label>
                <div style="font-size:12px;color:var(--text-4);padding-left:28px"><?= $info['desc'] ?></div>
                <?php if ($s['last_sent_at']): ?>
                  <div style="font-size:11.5px;color:var(--text-5);padding-left:28px;margin-top:4px">
                    Último envio: <?= e(date('d/m H:i', strtotime($s['last_sent_at']))) ?>
                  </div>
                <?php endif; ?>
              </div>
              <div style="display:flex;gap:10px;align-items:flex-end;flex-shrink:0;flex-wrap:wrap">
                <div>
                  <div style="font-size:11px;font-weight:600;color:var(--text-4);margin-bottom:5px">Horário</div>
                  <div style="display:flex;align-items:center;gap:4px">
                    <input type="number" min="0" max="23" name="hour_<?= $type ?>" class="form-control form-control-sm"
                           value="<?= (int)$s['send_hour'] ?>" style="width:62px;text-align:center">
                    <span style="color:var(--text-4);font-weight:700">:</span>
                    <input type="number" min="0" max="59" name="minute_<?= $type ?>" class="form-control form-control-sm"
                           value="<?= (int)$s['send_minute'] ?>" style="width:62px;text-align:center">
                  </div>
                </div>
                <?php if (!empty($info['has_day'])): ?>
                <div>
                  <div style="font-size:11px;font-weight:600;color:var(--text-4);margin-bottom:5px"><?= $info['day_label'] ?></div>
                  <input type="number" min="1" max="31" name="day_<?= $type ?>" class="form-control form-control-sm"
                         value="<?= $s['send_day'] ?? 1 ?>" style="width:70px;text-align:center">
                </div>
                <?php endif; ?>
                <div style="align-self:flex-end">
                  <button type="button"
                          class="btn btn-sm"
                          style="background:var(--surface);border:1px solid var(--border);color:var(--text-2);white-space:nowrap"
                          title="Gera uma prévia e envia para o seu WhatsApp para aprovação"
                          onclick="testSchedule('<?= $type ?>', this)"
                          <?= !$client['whatsapp_group_jid'] ? 'disabled' : '' ?>>
                    🧪 Testar
                  </button>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Per-account Thresholds -->
      <?php if (!empty($accounts) && $hasAlertCols): ?>
      <div class="card" style="margin-bottom:16px">
        <div class="card-header">
          <span class="card-header-title">Alertas de performance por conta</span>
        </div>
        <div class="table-wrap" style="border:none;border-radius:0;box-shadow:none">
          <table>
            <thead>
              <tr>
                <th>Conta</th>
                <th style="text-align:center">ROAS mín.</th>
                <th style="text-align:center">CPA máx.</th>
                <th style="text-align:center">CTR mín. (%)</th>
                <th style="text-align:center">Coletar insights</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($accounts as $acc): ?>
              <tr>
                <td>
                  <div style="font-weight:600;font-size:13px"><?= e($acc['account_name'] ?: $acc['ad_account_id']) ?></div>
                  <div class="td-mono">act_<?= e($acc['ad_account_id']) ?></div>
                </td>
                <td style="text-align:center">
                  <input type="number" step="0.1" min="0"
                         name="account_thresholds[<?= (int)$acc['id'] ?>][roas]"
                         class="form-control form-control-sm" style="width:90px;margin:0 auto;text-align:center"
                         value="<?= $acc['alert_roas_min'] !== null ? e((string)$acc['alert_roas_min']) : '' ?>"
                         placeholder="ex: 2.0">
                </td>
                <td style="text-align:center">
                  <input type="number" step="1" min="0"
                         name="account_thresholds[<?= (int)$acc['id'] ?>][cpa]"
                         class="form-control form-control-sm" style="width:90px;margin:0 auto;text-align:center"
                         value="<?= $acc['alert_cpa_max'] !== null ? e((string)$acc['alert_cpa_max']) : '' ?>"
                         placeholder="ex: 50">
                </td>
                <td style="text-align:center">
                  <input type="number" step="0.1" min="0"
                         name="account_thresholds[<?= (int)$acc['id'] ?>][ctr]"
                         class="form-control form-control-sm" style="width:90px;margin:0 auto;text-align:center"
                         value="<?= $acc['alert_ctr_min'] !== null ? e((string)$acc['alert_ctr_min']) : '' ?>"
                         placeholder="ex: 1.0">
                </td>
                <td style="text-align:center">
                  <input type="checkbox"
                         name="account_thresholds[<?= (int)$acc['id'] ?>][collect]"
                         class="form-check-input" <?= $acc['collect_insights']?'checked':'' ?>>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <button type="submit" class="btn btn-primary">Salvar configurações</button>
    </form>

    <!-- Send History -->
    <?php
    $repLog = $hasReportTables ? Db::all('SELECT * FROM report_log WHERE client_id = ? ORDER BY sent_at DESC LIMIT 20', [$clientId]) : [];
    if (!empty($repLog)):
    ?>
    <div class="card" style="margin-top:20px">
      <div class="card-header">
        <span class="card-header-title">Histórico de envios</span>
      </div>
      <div>
      <?php foreach ($repLog as $r): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-bottom:1px solid var(--border)">
          <div style="display:flex;align-items:center;gap:10px">
            <span style="font-size:18px"><?= $r['sent_ok'] ? '✅' : '❌' ?></span>
            <span class="badge badge-gray"><?= e($r['report_type']) ?></span>
          </div>
          <span class="td-mono"><?= e(date('d/m H:i', strtotime($r['sent_at']))) ?></span>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div><!-- /right -->
</div><!-- /grid -->

<!-- Modal Preview -->
<div id="previewModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center; padding:20px;">
  <div style="background:var(--surface); border-radius:var(--radius); width:100%; max-width:600px; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 10px 30px rgba(0,0,0,0.2);">
    <div style="padding:16px 20px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0; font-size:16px;">Confirmar Envio</h3>
      <button type="button" onclick="closePreview()" style="background:none; border:none; cursor:pointer; font-size:20px; color:var(--text-3);">&times;</button>
    </div>
    <div style="padding:20px; overflow-y:auto; flex:1;">
      <div style="font-size:14px; margin-bottom:12px; color:var(--text-3);">Mensagem que será enviada:</div>
      <pre id="previewContent" style="background:var(--surface-2); padding:16px; border-radius:var(--radius); font-size:13px; font-family:monospace; white-space:pre-wrap; border:1px solid var(--border); margin:0;"></pre>
    </div>
    <div style="padding:16px 20px; border-top:1px solid var(--border); display:flex; justify-content:flex-end; gap:10px; background:var(--surface-2); border-bottom-left-radius:var(--radius); border-bottom-right-radius:var(--radius);">
      <button type="button" class="btn btn-secondary" onclick="closePreview()">Cancelar</button>
      <button type="button" class="btn btn-primary" id="btnConfirmSend" onclick="submitRealForm()">Confirmar Envio</button>
    </div>
  </div>
</div>

<script>
let currentForm = null;
let pendingTestType = null;
let pendingDateFrom = null;
let pendingDateTo   = null;

function showPreview(btn, event) {
  event.preventDefault();
  currentForm = btn.closest('form');
  pendingTestType = null;

  const formData = new FormData(currentForm);
  formData.set('op', 'preview');

  btn.disabled = true;
  const originalText = btn.innerHTML;
  btn.innerHTML = '⌛...';

  fetch(window.location.href, {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false;
    btn.innerHTML = originalText;

    if (res.ok) {
      document.getElementById('previewContent').innerText = res.messages.join("\n\n--------------------\n\n");
      document.getElementById('btnConfirmSend').innerText = 'Enviar Mensagem';
      document.getElementById('previewModal').style.display = 'flex';
    } else {
      alert(res.error || 'Erro ao gerar preview.');
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = originalText;
    alert('Erro de conexão ao gerar preview.');
  });
}

function testSchedule(type, btn) {
  pendingTestType = type;
  currentForm = null;

  const originalText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '⌛...';

  const clientId = <?= (int)$clientId ?>;
  const formData = new FormData();
  formData.set('csrf', document.querySelector('input[name="csrf"]').value);
  formData.set('op', 'preview');
  formData.set('client_id', clientId);
  formData.set('report_type', type);

  // Para personalizado: calcula datas a partir do campo "Período (N dias)"
  if (type === 'personalizado') {
    const dayInput = document.querySelector('input[name="day_personalizado"]');
    const days = dayInput ? (parseInt(dayInput.value) || 7) : 7;
    const today = new Date();
    const to   = new Date(today); to.setDate(today.getDate() - 1);
    const from = new Date(today); from.setDate(today.getDate() - days);
    formData.set('date_from', from.toISOString().slice(0, 10));
    formData.set('date_to',   to.toISOString().slice(0, 10));
    // Guarda para usar no submit real
    pendingDateFrom = from.toISOString().slice(0, 10);
    pendingDateTo   = to.toISOString().slice(0, 10);
  }

  fetch(window.location.href, {
    method: 'POST',
    body: formData
  })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false;
    btn.innerHTML = originalText;
    if (res.ok) {
      document.getElementById('previewContent').innerText = res.messages.join("\n\n--------------------\n\n");
      document.getElementById('btnConfirmSend').innerText = '🧪 Enviar para meu WhatsApp';
      document.getElementById('previewModal').style.display = 'flex';
    } else {
      alert(res.error || 'Erro ao gerar preview.');
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.innerHTML = originalText;
    alert('Erro de conexão ao gerar preview.');
  });
}

function closePreview() {
  document.getElementById('previewModal').style.display = 'none';
  currentForm = null;
  pendingTestType = null;
  pendingDateFrom = null;
  pendingDateTo   = null;
}

function submitRealForm() {
  const btnConfirm = document.getElementById('btnConfirmSend');
  btnConfirm.disabled = true;
  btnConfirm.innerText = 'Enviando...';

  if (pendingTestType) {
    // Botão 🧪 Testar — submete op=test_schedule (SEMPRE vai pro WhatsApp do admin)
    const f = document.createElement('form');
    f.method = 'POST';
    f.action = window.location.href;
    const fields = {
      csrf:        document.querySelector('input[name="csrf"]').value,
      op:          'test_schedule',
      client_id:   <?= (int)$clientId ?>,
      report_type: pendingTestType,
      date_from:   pendingDateFrom || '',
      date_to:     pendingDateTo   || '',
    };
    for (const [k, v] of Object.entries(fields)) {
      const i = document.createElement('input');
      i.type = 'hidden'; i.name = k; i.value = v;
      f.appendChild(i);
    }
    document.body.appendChild(f);
    f.submit();
  } else if (currentForm) {
    currentForm.submit();
  }
}
</script>

<?php
$content = ob_get_clean();
require APP_DIR . '/views/layout.php';
