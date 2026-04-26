<?php
require_once __DIR__ . '/app/bootstrap.php';
require_auth();

$hasForecast = column_exists('meta_accounts', 'forecast_runway_days');

if ($hasForecast) {
    $accounts = Db::all(
        'SELECT ma.*, c.name AS client_name, c.whatsapp_group_name
         FROM meta_accounts ma
         JOIN clients c ON c.id = ma.client_id
         WHERE ma.active = 1
         ORDER BY
           CASE WHEN ma.forecast_runway_days IS NULL THEN 1 ELSE 0 END,
           ma.forecast_runway_days ASC,
           c.name, ma.account_name'
    );
} else {
    $accounts = Db::all(
        'SELECT ma.*, c.name AS client_name, c.whatsapp_group_name,
                NULL AS forecast_runway_days, NULL AS forecast_depletion_at,
                NULL AS forecast_daily_cents, NULL AS forecast_today_cents,
                NULL AS forecast_trend, NULL AS forecast_active_ads,
                NULL AS forecast_confidence
         FROM meta_accounts ma
         JOIN clients c ON c.id = ma.client_id
         WHERE ma.active = 1
         ORDER BY c.name, ma.account_name'
    );
}

$criticalCount = 0;
foreach ($accounts as $a) {
    if ($a['forecast_runway_days'] !== null && (float)$a['forecast_runway_days'] < 3) $criticalCount++;
}

$lastRun = null;
$alerts24h = 0;
$recentAlerts = [];
try {
    $lastRun = Db::one('SELECT * FROM check_runs ORDER BY id DESC LIMIT 1');
} catch (Throwable $e) {}
try {
    if (table_exists('alerts_log')) {
        $alerts24h = (int) Db::scalar('SELECT COUNT(*) FROM alerts_log WHERE sent_at >= NOW() - INTERVAL 24 HOUR');
        $recentAlerts = Db::all(
            'SELECT a.*, ma.account_name, ma.ad_account_id, c.name AS client_name
             FROM alerts_log a
             JOIN meta_accounts ma ON ma.id = a.meta_account_id
             JOIN clients c ON c.id = ma.client_id
             ORDER BY a.sent_at DESC LIMIT 10'
        );
    }
} catch (Throwable $e) {}

$statusLabels = [
    1   => ['label' => 'Ativa',      'cls' => 'status-active'],
    2   => ['label' => 'Desativada', 'cls' => 'status-paused'],
    3   => ['label' => 'Não paga',   'cls' => 'status-blocked'],
    7   => ['label' => 'Pendente',   'cls' => 'status-warning'],
    9   => ['label' => 'Em análise', 'cls' => 'status-warning'],
    101 => ['label' => 'Fechada',    'cls' => 'status-blocked'],
];

$alertTypeLabel = [
    'low_balance'     => ['icon' => 'bi-cash-coin',            'color' => 'badge-orange'],
    'spend_cap_near'  => ['icon' => 'bi-exclamation-triangle', 'color' => 'badge-orange'],
    'account_blocked' => ['icon' => 'bi-slash-circle',         'color' => 'badge-red'],
    'no_funding'      => ['icon' => 'bi-credit-card-2-front',  'color' => 'badge-red'],
    'roas_drop'       => ['icon' => 'bi-graph-down-arrow',     'color' => 'badge-orange'],
    'cpa_spike'       => ['icon' => 'bi-currency-dollar',      'color' => 'badge-red'],
    'ctr_drop'        => ['icon' => 'bi-hand-thumbs-down',     'color' => 'badge-orange'],
    'ad_disapproved'  => ['icon' => 'bi-x-octagon',            'color' => 'badge-red'],
    'budget_overpace' => ['icon' => 'bi-fire',                 'color' => 'badge-red'],
];

$title = 'Dashboard · SALDO WEB';
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Dashboard</h1>
    <p class="page-subtitle">Visão geral das contas monitoradas</p>
  </div>
  <form method="post" action="<?= e(base_url('run_check.php')) ?>">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <button class="btn btn-primary">
      <i class="bi bi-arrow-clockwise"></i> Verificar agora
    </button>
  </form>
</div>

<div class="grid grid-4" style="margin-bottom:24px">

  <div class="metric-card">
    <div class="top-row">
      <div>
        <div class="metric-label">Contas ativas</div>
        <div class="metric-value"><?= count($accounts) ?></div>
      </div>
      <div class="metric-icon accent-blue"><i class="bi bi-link-45deg"></i></div>
    </div>
    <div class="metric-sub">Meta Ads monitoradas</div>
  </div>

  <div class="metric-card">
    <div class="top-row">
      <div>
        <div class="metric-label">Última verificação</div>
        <div class="metric-value sm"><?= $lastRun ? e(date('H:i', strtotime($lastRun['finished_at'] ?? $lastRun['started_at']))) : '—' ?></div>
      </div>
      <div class="metric-icon accent-green"><i class="bi bi-clock-history"></i></div>
    </div>
    <div class="metric-sub"><?= $lastRun ? e(date('d/m/Y', strtotime($lastRun['finished_at'] ?? $lastRun['started_at']))) : 'Nunca executado' ?></div>
  </div>

  <div class="metric-card">
    <div class="top-row">
      <div>
        <div class="metric-label">Alertas (24h)</div>
        <div class="metric-value <?= $alerts24h > 0 ? 'sm' : '' ?>" style="<?= $alerts24h > 0 ? 'color:var(--orange)' : '' ?>"><?= $alerts24h ?></div>
      </div>
      <div class="metric-icon accent-orange"><i class="bi bi-bell-fill"></i></div>
    </div>
    <div class="metric-sub">disparados nas últimas 24h</div>
  </div>

  <div class="metric-card">
    <div class="top-row">
      <div>
        <div class="metric-label">Saldo crítico</div>
        <div class="metric-value sm" style="<?= $criticalCount > 0 ? 'color:var(--red)' : '' ?>"><?= $criticalCount ?></div>
      </div>
      <div class="metric-icon <?= $criticalCount > 0 ? 'accent-red' : 'accent-green' ?>"><i class="bi <?= $criticalCount > 0 ? 'bi-hourglass-split' : 'bi-check-circle-fill' ?>"></i></div>
    </div>
    <div class="metric-sub"><?= $criticalCount > 0 ? 'conta(s) com &lt; 3 dias de saldo' : 'todas com runway saudável' ?></div>
  </div>

</div>

<div class="card" style="margin-bottom:24px">
  <div class="card-header">
    <span class="card-header-title">Contas monitoradas</span>
    <a href="<?= e(base_url('accounts.php')) ?>" class="btn btn-secondary btn-sm"><i class="bi bi-plus-lg"></i> Adicionar conta</a>
  </div>
  <?php if (empty($accounts)): ?>
  <div class="empty-state">
    <div class="empty-icon"><i class="bi bi-link-45deg" style="font-size:32px;color:var(--text-4)"></i></div>
    <div class="empty-title">Nenhuma conta cadastrada</div>
    <div class="empty-desc">Adicione uma conta Meta Ads para começar o monitoramento.</div>
    <a href="<?= e(base_url('accounts.php')) ?>" class="btn btn-primary" style="margin-top:4px">Adicionar conta</a>
  </div>
  <?php else: ?>
  <div class="table-wrap" style="border:none;border-radius:0;box-shadow:none">
    <table>
      <thead>
        <tr>
          <th>Cliente / Conta</th>
          <th>Tipo</th>
          <th style="text-align:right">Saldo / Gasto</th>
          <th style="text-align:right">Runway</th>
          <th>Previsão término</th>
          <th style="text-align:center">Ritmo</th>
          <th style="text-align:center">Ads</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $trendIcon = [
        'accelerating' => ['ico' => 'bi-fire',          'lbl' => 'acelerando', 'cls' => 'badge-red'],
        'stable'       => ['ico' => 'bi-arrow-right',   'lbl' => 'estável',    'cls' => 'badge-gray'],
        'decelerating' => ['ico' => 'bi-snow',          'lbl' => 'freando',    'cls' => 'badge-green'],
      ];
      foreach ($accounts as $a):
        $st = (int) $a['last_account_status'];
        $stInfo = $statusLabels[$st] ?? ['label' => $st ?: '—', 'cls' => 'status-paused'];
        $runway = $a['forecast_runway_days'] !== null ? (float) $a['forecast_runway_days'] : null;
        if ($runway === null)       { $rwColor = 'var(--text-5)'; $rwTxt = '—'; }
        elseif ($runway < 1)         { $rwColor = 'var(--red)';    $rwTxt = $runway < 0.5 ? 'horas' : '< 1 dia'; }
        elseif ($runway < 3)         { $rwColor = 'var(--orange)'; $rwTxt = number_format($runway, 1, ',', '.').' dias'; }
        elseif ($runway < 7)         { $rwColor = 'var(--yellow)'; $rwTxt = number_format($runway, 1, ',', '.').' dias'; }
        else                         { $rwColor = 'var(--green)';  $rwTxt = number_format($runway, 0, ',', '.').' dias'; }
        $tr = $trendIcon[$a['forecast_trend'] ?? ''] ?? null;
      ?>
        <tr>
          <td>
            <div class="td-primary"><?= e($a['client_name']) ?></div>
            <div class="td-mono" style="font-size:11px;margin-top:2px;color:var(--text-4)"><?= e($a['account_name'] ?: $a['ad_account_id']) ?> · act_<?= e($a['ad_account_id']) ?></div>
          </td>
          <td>
            <span class="badge badge-<?= e($a['account_type'] ?? 'unknown') ?>"><?= e($a['account_type'] ?? 'unknown') ?></span>
          </td>
          <td style="text-align:right">
            <?php if ($a['account_type'] === 'prepaid'): ?>
              <strong style="color:var(--text-1)">R$ <?= number_format(((float) $a['last_balance']) / 100, 2, ',', '.') ?></strong>
              <?php if ($a['forecast_daily_cents']): ?>
                <div class="td-mono" style="font-size:11px;color:var(--text-4);margin-top:2px">ritmo R$ <?= number_format(((float)$a['forecast_daily_cents'])/100, 0, ',', '.') ?>/dia</div>
              <?php endif; ?>
            <?php else: ?>
              R$ <?= number_format(((float) $a['last_amount_spent']) / 100, 2, ',', '.') ?>
              <?php if ($a['last_spend_cap']): ?>
                <div class="td-mono" style="font-size:11px;color:var(--text-4);margin-top:2px">cap R$ <?= number_format(((float)$a['last_spend_cap'])/100, 0, ',', '.') ?></div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td style="text-align:right">
            <strong style="color:<?= $rwColor ?>"><?= $rwTxt ?></strong>
          </td>
          <td class="td-mono" style="font-size:12px">
            <?php if ($a['forecast_depletion_at']): ?>
              <?= e(date('d/m H:i', strtotime($a['forecast_depletion_at']))) ?>
            <?php else: ?>
              <span style="color:var(--text-5)">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:center">
            <?php if ($tr): ?>
              <span class="badge <?= $tr['cls'] ?>" title="<?= e($tr['lbl']) ?>"><i class="bi <?= $tr['ico'] ?>"></i> <?= e($tr['lbl']) ?></span>
            <?php else: ?>
              <span style="color:var(--text-5)">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:center">
            <?php if ($a['forecast_active_ads'] !== null): ?>
              <strong><?= (int)$a['forecast_active_ads'] ?></strong>
            <?php else: ?>
              <span style="color:var(--text-5)">—</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="status-dot <?= $stInfo['cls'] ?>"><?= $stInfo['label'] ?></span>
            <div class="td-mono" style="font-size:11px;color:var(--text-4);margin-top:2px">
              <?= $a['last_checked_at'] ? e(date('d/m H:i', strtotime($a['last_checked_at']))) : 'nunca' ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-header-title">Alertas recentes</span>
    <a href="<?= e(base_url('alerts.php')) ?>" class="btn btn-ghost btn-sm">Ver todos <i class="bi bi-arrow-right"></i></a>
  </div>
  <?php if (empty($recentAlerts)): ?>
  <div class="empty-state" style="padding:40px 24px">
    <div class="empty-icon"><i class="bi bi-bell-slash" style="font-size:32px;color:var(--text-4)"></i></div>
    <div class="empty-title">Nenhum alerta ainda</div>
    <div class="empty-desc">Os alertas aparecerão aqui quando forem disparados.</div>
  </div>
  <?php else: ?>
  <div style="overflow:hidden">
  <?php foreach ($recentAlerts as $al):
    $at = $alertTypeLabel[$al['alert_type']] ?? ['icon' => 'bi-info-circle', 'color' => 'badge-gray'];
    $sev = $al['severity'] ?? 'info';
  ?>
    <div style="display:flex;align-items:center;gap:14px;padding:13px 20px;border-bottom:1px solid var(--border);transition:background .1s" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
      <div style="width:36px;height:36px;border-radius:var(--radius-xs);background:var(--surface-3);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0"><i class="bi <?= $at['icon'] ?>"></i></div>
      <div style="flex:1;min-width:0">
        <div style="font-size:13.5px;font-weight:600;color:var(--text-1)">
          <?= e($al['client_name']) ?> — <?= e($al['account_name'] ?: $al['ad_account_id']) ?>
        </div>
        <div style="font-size:12px;color:var(--text-4);margin-top:2px">
          <span class="badge <?= $at['color'] ?>"><?= e($al['alert_type']) ?></span>
        </div>
      </div>
      <div style="text-align:right;flex-shrink:0">
        <div style="font-size:12px;color:var(--text-4)"><?= e(date('d/m H:i', strtotime($al['sent_at']))) ?></div>
        <div style="font-size:16px;margin-top:2px"><?= !empty($al['sent_ok']) ? '<i class="bi bi-check-circle-fill" style="color:#34c759"></i>' : '<i class="bi bi-x-circle-fill" style="color:#ff3b30"></i>' ?></div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require APP_DIR . '/views/layout.php';
