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
$alertsHourly = array_fill(0, 24, 0);
try { $lastRun = Db::one('SELECT * FROM check_runs ORDER BY id DESC LIMIT 1'); } catch (Throwable $e) {}
try {
    if (table_exists('alerts_log')) {
        $alerts24h = (int) Db::scalar('SELECT COUNT(*) FROM alerts_log WHERE sent_at >= NOW() - INTERVAL 24 HOUR');
        $recentAlerts = Db::all(
            'SELECT a.*, ma.account_name, ma.ad_account_id, c.name AS client_name
             FROM alerts_log a
             JOIN meta_accounts ma ON ma.id = a.meta_account_id
             JOIN clients c ON c.id = ma.client_id
             ORDER BY a.sent_at DESC LIMIT 8'
        );
        $hourly = Db::all(
            'SELECT HOUR(sent_at) AS h, COUNT(*) AS n
             FROM alerts_log
             WHERE sent_at >= NOW() - INTERVAL 24 HOUR
             GROUP BY HOUR(sent_at)'
        );
        foreach ($hourly as $row) { $alertsHourly[(int)$row['h']] = (int)$row['n']; }
    }
} catch (Throwable $e) {}

/**
 * Sparkline SVG generator — outputs a luxe inline SVG path.
 * @param array $values  numeric series
 * @param string $rgb    "r,g,b"
 */
function lux_spark(array $values, string $rgb = '255,255,255'): string {
    if (count($values) < 2) {
        // Fallback: subtle decorative wave
        $values = [3,2,4,3,5,4,6,5,7,6,8,9];
    }
    $w = 240; $h = 80;
    $max = max($values); $min = min($values);
    $range = $max - $min ?: 1;
    $count = count($values);
    $points = [];
    foreach ($values as $i => $v) {
        $x = ($i / ($count - 1)) * $w;
        $y = $h - (($v - $min) / $range) * ($h * 0.65) - ($h * 0.18);
        $points[] = round($x, 2) . ',' . round($y, 2);
    }
    $linePath = 'M' . implode(' L', $points);
    $fillPath = $linePath . " L{$w},{$h} L0,{$h} Z";
    $id = 'spk' . substr(md5(implode(',', $values).$rgb), 0, 8);
    $svg  = '<svg viewBox="0 0 '.$w.' '.$h.'" preserveAspectRatio="none" class="lux-kpi-spark">';
    $svg .= '<defs><linearGradient id="'.$id.'" x1="0" x2="0" y1="0" y2="1">';
    $svg .= '<stop offset="0%" stop-color="rgb('.$rgb.')" stop-opacity="0.32"/>';
    $svg .= '<stop offset="100%" stop-color="rgb('.$rgb.')" stop-opacity="0"/>';
    $svg .= '</linearGradient></defs>';
    $svg .= '<path d="'.$fillPath.'" fill="url(#'.$id.')"/>';
    $svg .= '<path d="'.$linePath.'" fill="none" stroke="rgb('.$rgb.')" stroke-opacity="0.55" stroke-width="1.4" stroke-linejoin="round" stroke-linecap="round"/>';
    $svg .= '</svg>';
    return $svg;
}

$statusLabels = [
    1   => ['label' => 'Ativa',      'cls' => 'live'],
    2   => ['label' => 'Desativada', 'cls' => 'dim'],
    3   => ['label' => 'Não paga',   'cls' => 'crit'],
    7   => ['label' => 'Pendente',   'cls' => 'warn'],
    9   => ['label' => 'Em análise', 'cls' => 'warn'],
    101 => ['label' => 'Fechada',    'cls' => 'crit'],
];

$alertTypeLabel = [
    'low_balance'     => ['icon' => 'bi-cash-coin',            'sev' => 'warn',  'label' => 'Saldo baixo'],
    'spend_cap_near'  => ['icon' => 'bi-exclamation-triangle', 'sev' => 'warn',  'label' => 'Cap próximo'],
    'account_blocked' => ['icon' => 'bi-slash-circle',         'sev' => 'crit',  'label' => 'Conta bloqueada'],
    'no_funding'      => ['icon' => 'bi-credit-card-2-front',  'sev' => 'crit',  'label' => 'Sem método de pagamento'],
    'roas_drop'       => ['icon' => 'bi-graph-down-arrow',     'sev' => 'warn',  'label' => 'Queda de ROAS'],
    'cpa_spike'       => ['icon' => 'bi-fire',                 'sev' => 'crit',  'label' => 'Pico de CPA'],
    'ctr_drop'        => ['icon' => 'bi-arrow-down-circle',    'sev' => 'warn',  'label' => 'Queda de CTR'],
    'ad_disapproved'  => ['icon' => 'bi-x-octagon',            'sev' => 'crit',  'label' => 'Anúncio reprovado'],
    'budget_overpace' => ['icon' => 'bi-speedometer2',         'sev' => 'crit',  'label' => 'Orçamento acelerado'],
];

// Pre-compute decorative sparklines from real signals when possible
$sparkAccounts = [];
foreach ($accounts as $a) {
    $sparkAccounts[] = $a['forecast_runway_days'] !== null ? max(0.5, (float)$a['forecast_runway_days']) : 5;
}
if (count($sparkAccounts) < 2) $sparkAccounts = [3,4,5,4,6,5,7,8];
$sparkAlerts = $alertsHourly;
$sparkCritical = [];
$ths = [10,8,6,4,3,2,5,3,2,4,3,1,2,$criticalCount ?: 0];
foreach ($ths as $v) $sparkCritical[] = $v;

$title = 'Dashboard · SALDO WEB';
ob_start();
?>

<!-- ═══════════════════ HERO ═══════════════════ -->
<div class="lux-hero">
  <div class="lux-hero-l">
    <h1 class="lux-hero-title">Dashboard</h1>
    <div class="lux-hero-meta">
      <span class="lux-chip live"><i class="bi bi-broadcast"></i> Sistema operando</span>
      <?php if ($lastRun): ?>
        <span class="lux-chip"><i class="bi bi-clock-history"></i> última verificação <?= e(date('H:i', strtotime($lastRun['finished_at'] ?? $lastRun['started_at']))) ?></span>
      <?php endif; ?>
      <span class="lux-chip"><i class="bi bi-link-45deg"></i> <?= count($accounts) ?> conta<?= count($accounts)===1?'':'s' ?></span>
    </div>
  </div>
  <div class="lux-hero-actions">
    <form method="post" action="<?= e(base_url('run_check.php')) ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button class="btn btn-primary"><i class="bi bi-arrow-clockwise"></i> Verificar agora</button>
    </form>
  </div>
</div>

<!-- ═══════════════════ KPI STRIP ═══════════════════ -->
<div class="lux-grid lux-grid-4">

  <div class="lux-kpi">
    <?= lux_spark($sparkAccounts, '147,197,253') ?>
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">Contas ativas</span>
        <div class="lux-kpi-icon"><i class="bi bi-link-45deg"></i></div>
      </div>
      <div class="lux-kpi-value"><?= count($accounts) ?></div>
      <div class="lux-kpi-sub">monitoradas em tempo real</div>
    </div>
  </div>

  <div class="lux-kpi">
    <?= lux_spark([2,3,2,4,3,5,4,6,5,7,6,8], '255,255,255') ?>
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">Última verificação</span>
        <div class="lux-kpi-icon"><i class="bi bi-activity"></i></div>
      </div>
      <div class="lux-kpi-value sm"><?= $lastRun ? e(date('H:i', strtotime($lastRun['finished_at'] ?? $lastRun['started_at']))) : '—' ?></div>
      <div class="lux-kpi-sub"><?= $lastRun ? e(date('d/m/Y', strtotime($lastRun['finished_at'] ?? $lastRun['started_at']))) : 'nunca executado' ?></div>
    </div>
  </div>

  <div class="lux-kpi">
    <?= lux_spark($sparkAlerts, $alerts24h>0?'251,191,36':'255,255,255') ?>
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">Alertas (24h)</span>
        <div class="lux-kpi-icon"><i class="bi bi-bell"></i></div>
      </div>
      <div class="lux-kpi-value <?= $alerts24h > 0 ? 'warn' : '' ?>"><?= $alerts24h ?></div>
      <div class="lux-kpi-sub <?= $alerts24h > 0 ? 'warn' : '' ?>"><?= $alerts24h > 0 ? 'disparados nas últimas 24h' : 'nenhum alerta no período' ?></div>
    </div>
  </div>

  <div class="lux-kpi">
    <?= lux_spark($sparkCritical, $criticalCount>0?'248,113,113':'74,222,128') ?>
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">Saldo crítico</span>
        <div class="lux-kpi-icon"><i class="bi <?= $criticalCount>0 ? 'bi-hourglass-split' : 'bi-shield-check' ?>"></i></div>
      </div>
      <div class="lux-kpi-value <?= $criticalCount > 0 ? 'crit' : 'good' ?>"><?= $criticalCount ?></div>
      <div class="lux-kpi-sub <?= $criticalCount > 0 ? 'down' : 'up' ?>"><?= $criticalCount > 0 ? 'conta(s) com runway < 3 dias' : 'todas com runway saudável' ?></div>
    </div>
  </div>

</div>

<!-- ═══════════════════ ACCOUNTS SECTION ═══════════════════ -->
<div class="lux-section">
  <h2 class="lux-section-title">
    Contas monitoradas
    <span class="lux-section-title-meta"><?= count($accounts) ?> ativas</span>
  </h2>
  <div class="lux-section-actions">
    <a href="<?= e(base_url('accounts.php')) ?>" class="btn btn-secondary btn-sm"><i class="bi bi-plus-lg"></i> Adicionar</a>
  </div>
</div>

<?php if (empty($accounts)): ?>
<div class="lux-empty">
  <div class="lux-empty-icon"><i class="bi bi-link-45deg"></i></div>
  <div class="lux-empty-title">Nenhuma conta cadastrada</div>
  <div class="lux-empty-desc">Adicione uma conta Meta Ads para começar o monitoramento contínuo de saldo, gasto e performance.</div>
  <a href="<?= e(base_url('accounts.php')) ?>" class="btn btn-primary" style="margin-top:6px"><i class="bi bi-plus-lg"></i> Adicionar conta</a>
</div>
<?php else: ?>

<div class="lux-table-wrap">
  <table class="lux-table">
    <thead>
      <tr>
        <th>Cliente · Conta</th>
        <th>Tipo</th>
        <th class="lux-td-r">Saldo · Ritmo</th>
        <th class="lux-td-r">Runway</th>
        <th>Previsão · Tendência</th>
        <th class="lux-td-c">Ads</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $trendInfo = [
      'accelerating' => ['ico' => 'bi-arrow-up-right',   'lbl' => 'acelerando', 'cls' => 'bad'],
      'stable'       => ['ico' => 'bi-arrow-right',      'lbl' => 'estável',    'cls' => 'dim'],
      'decelerating' => ['ico' => 'bi-arrow-down-right', 'lbl' => 'freando',    'cls' => 'good'],
    ];
    foreach ($accounts as $a):
      $st = (int) $a['last_account_status'];
      $stInfo = $statusLabels[$st] ?? ['label' => $st ?: '—', 'cls' => 'dim'];
      $runway = $a['forecast_runway_days'] !== null ? (float) $a['forecast_runway_days'] : null;
      if ($runway === null)        { $rwCls = 'dim';  $rwTxt = '—'; }
      elseif ($runway < 1)         { $rwCls = 'bad';  $rwTxt = $runway < 0.5 ? 'horas' : '< 1 dia'; }
      elseif ($runway < 3)         { $rwCls = 'warn'; $rwTxt = number_format($runway, 1, ',', '.') . ' dias'; }
      elseif ($runway < 7)         { $rwCls = 'warn'; $rwTxt = number_format($runway, 1, ',', '.') . ' dias'; }
      else                         { $rwCls = 'good'; $rwTxt = number_format($runway, 0, ',', '.') . ' dias'; }
      $tr = $trendInfo[$a['forecast_trend'] ?? ''] ?? null;
    ?>
      <tr>
        <td>
          <div class="lux-td-primary"><?= e($a['client_name']) ?></div>
          <div class="lux-td-mono"><?= e($a['account_name'] ?: $a['ad_account_id']) ?> · act_<?= e($a['ad_account_id']) ?></div>
        </td>
        <td>
          <?php if (($a['account_type'] ?? '') === 'prepaid'): ?>
            <span class="lux-chip"><i class="bi bi-coin"></i> Pré-pago</span>
          <?php elseif (($a['account_type'] ?? '') === 'postpaid'): ?>
            <span class="lux-chip"><i class="bi bi-credit-card"></i> Pós-pago</span>
          <?php else: ?>
            <span class="lux-chip">—</span>
          <?php endif; ?>
        </td>
        <td class="lux-td-r">
          <?php if (($a['account_type'] ?? '') === 'prepaid'): ?>
            <div class="lux-td-num strong">R$ <?= number_format(((float)$a['last_balance']) / 100, 2, ',', '.') ?></div>
            <?php if (!empty($a['forecast_daily_cents'])): ?>
              <div class="lux-td-mono">R$ <?= number_format(((float)$a['forecast_daily_cents'])/100, 0, ',', '.') ?>/dia</div>
            <?php endif; ?>
          <?php else: ?>
            <div class="lux-td-num">R$ <?= number_format(((float)$a['last_amount_spent'])/100, 2, ',', '.') ?></div>
            <?php if (!empty($a['last_spend_cap'])): ?>
              <div class="lux-td-mono">cap R$ <?= number_format(((float)$a['last_spend_cap'])/100, 0, ',', '.') ?></div>
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <td class="lux-td-r">
          <span class="lux-td-num strong <?= $rwCls ?>"><?= $rwTxt ?></span>
        </td>
        <td>
          <?php if ($a['forecast_depletion_at']): ?>
            <div class="lux-td-num"><?= e(date('d/m · H:i', strtotime($a['forecast_depletion_at']))) ?></div>
          <?php else: ?>
            <div class="lux-td-num dim">—</div>
          <?php endif; ?>
          <?php if ($tr): ?>
            <div class="lux-td-mono"><i class="bi <?= $tr['ico'] ?>"></i> <?= e($tr['lbl']) ?></div>
          <?php endif; ?>
        </td>
        <td class="lux-td-c">
          <?php if ($a['forecast_active_ads'] !== null): ?>
            <span class="lux-td-num strong"><?= (int)$a['forecast_active_ads'] ?></span>
          <?php else: ?>
            <span class="lux-td-num dim">—</span>
          <?php endif; ?>
        </td>
        <td>
          <span class="lux-dot <?= e($stInfo['cls']) ?>"><?= e($stInfo['label']) ?></span>
          <div class="lux-td-mono"><?= $a['last_checked_at'] ? e(date('d/m · H:i', strtotime($a['last_checked_at']))) : 'nunca' ?></div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<!-- ═══════════════════ RECENT ALERTS ═══════════════════ -->
<div class="lux-section">
  <h2 class="lux-section-title">
    Alertas recentes
    <?php if ($alerts24h): ?><span class="lux-section-title-meta"><?= $alerts24h ?> nas últimas 24h</span><?php endif; ?>
  </h2>
  <div class="lux-section-actions">
    <a href="<?= e(base_url('alerts.php')) ?>" class="btn btn-ghost btn-sm">Ver todos <i class="bi bi-arrow-right"></i></a>
  </div>
</div>

<?php if (empty($recentAlerts)): ?>
<div class="lux-empty">
  <div class="lux-empty-icon"><i class="bi bi-bell-slash"></i></div>
  <div class="lux-empty-title">Nenhum alerta ainda</div>
  <div class="lux-empty-desc">Quando algo importante acontecer com suas contas, aparecerá aqui em tempo real.</div>
</div>
<?php else: ?>
<div style="padding:0 4px">
  <?php foreach ($recentAlerts as $al):
    $at = $alertTypeLabel[$al['alert_type']] ?? ['icon' => 'bi-info-circle', 'sev' => '', 'label' => $al['alert_type']];
  ?>
    <div class="lux-alert <?= e($at['sev']) ?>">
      <div class="lux-alert-dot"><i class="bi <?= e($at['icon']) ?>"></i></div>
      <div class="lux-alert-body">
        <div class="lux-alert-title"><?= e($al['client_name']) ?> — <?= e($al['account_name'] ?: $al['ad_account_id']) ?></div>
        <div class="lux-alert-meta">
          <span><?= e($at['label']) ?></span>
          <span style="opacity:.5">·</span>
          <span><?= !empty($al['sent_ok']) ? '<i class="bi bi-check-circle" style="color:#4ADE80"></i> entregue' : '<i class="bi bi-x-circle" style="color:#F87171"></i> falhou' ?></span>
        </div>
      </div>
      <div class="lux-alert-time"><?= e(date('d/m · H:i', strtotime($al['sent_at']))) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require APP_DIR . '/views/layout.php';
