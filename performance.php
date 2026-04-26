<?php
require_once __DIR__ . '/app/bootstrap.php';
require_auth();

$accountId = (int) ($_GET['account'] ?? 0);
$period    = $_GET['period'] ?? '7d';

$periodMap = [
    '1d'  => ['label' => 'Ontem',        'since' => date('Y-m-d', strtotime('-1 day')),   'until' => date('Y-m-d', strtotime('-1 day'))],
    '7d'  => ['label' => '7 dias',       'since' => date('Y-m-d', strtotime('-7 days')),  'until' => date('Y-m-d', strtotime('-1 day'))],
    '14d' => ['label' => '14 dias',      'since' => date('Y-m-d', strtotime('-14 days')), 'until' => date('Y-m-d', strtotime('-1 day'))],
    '30d' => ['label' => '30 dias',      'since' => date('Y-m-d', strtotime('-30 days')), 'until' => date('Y-m-d', strtotime('-1 day'))],
];
$p     = $periodMap[$period] ?? $periodMap['7d'];
$since = $p['since'];
$until = $p['until'];

$accounts = Db::all(
    'SELECT ma.id, ma.ad_account_id, ma.account_name, ma.currency, c.name AS client_name
     FROM meta_accounts ma JOIN clients c ON c.id = ma.client_id
     WHERE ma.active = 1 ORDER BY c.name, ma.account_name'
);

if (!$accountId && !empty($accounts)) $accountId = (int) $accounts[0]['id'];
$account = $accountId ? Db::one('SELECT ma.*, c.name AS client_name FROM meta_accounts ma JOIN clients c ON c.id=ma.client_id WHERE ma.id=?', [$accountId]) : null;

$campaigns = $topAds = $summary = $chartData = [];

$hasInsights = table_exists('ad_insights') && table_exists('campaign_insights');

if ($account && $hasInsights) {
    $curr = $account['currency'] ?: 'BRL';

    $summary = Db::one(
        'SELECT SUM(spend) AS spend, SUM(impressions) AS impressions, SUM(clicks) AS clicks,
                SUM(conversions) AS conversions, SUM(conversion_value) AS revenue,
                SUM(leads) AS leads,
                (SUM(clicks)/NULLIF(SUM(impressions),0))*100 AS ctr,
                SUM(spend)/NULLIF(SUM(conversions),0) AS cpa,
                SUM(conversion_value)/NULLIF(SUM(spend),0) AS roas
         FROM ad_insights WHERE meta_account_id = ? AND date_start >= ? AND date_stop <= ?',
        [$accountId, $since, $until]
    );

    $campaigns = Db::all(
        'SELECT campaign_id, campaign_name,
                SUM(spend) AS spend, SUM(impressions) AS impressions,
                SUM(clicks) AS clicks, SUM(conversions) AS conversions,
                SUM(conversion_value) AS revenue, SUM(leads) AS leads,
                (SUM(clicks)/NULLIF(SUM(impressions),0))*100 AS ctr,
                SUM(spend)/NULLIF(SUM(conversions),0) AS cpa,
                SUM(conversion_value)/NULLIF(SUM(spend),0) AS roas
         FROM campaign_insights
         WHERE meta_account_id = ? AND date_start >= ? AND date_stop <= ?
         GROUP BY campaign_id, campaign_name
         ORDER BY spend DESC LIMIT 20',
        [$accountId, $since, $until]
    );

    $topAds = Db::all(
        'SELECT ad_id, ad_name, campaign_name, adset_name,
                SUM(spend) AS spend, SUM(impressions) AS impressions,
                SUM(clicks) AS clicks, SUM(conversions) AS conversions,
                SUM(conversion_value) AS revenue,
                (SUM(clicks)/NULLIF(SUM(impressions),0))*100 AS ctr,
                SUM(spend)/NULLIF(SUM(conversions),0) AS cpa,
                SUM(conversion_value)/NULLIF(SUM(spend),0) AS roas,
                MAX(effective_status) AS effective_status,
                MAX(thumbnail_url) AS thumbnail_url
         FROM ad_insights
         WHERE meta_account_id = ? AND date_start >= ? AND date_stop <= ?
         GROUP BY ad_id, ad_name, campaign_name, adset_name
         HAVING spend > 0
         ORDER BY roas DESC LIMIT 10',
        [$accountId, $since, $until]
    );

    $chartRows = Db::all(
        'SELECT date_start, SUM(spend) AS spend, SUM(conversions) AS conversions,
                SUM(conversion_value)/NULLIF(SUM(spend),0) AS roas
         FROM ad_insights
         WHERE meta_account_id = ? AND date_start >= ? AND date_stop <= ?
         GROUP BY date_start ORDER BY date_start',
        [$accountId, $since, $until]
    );
    foreach ($chartRows as $r) {
        $chartData[] = [
            'date'  => date('d/m', strtotime($r['date_start'])),
            'spend' => round((float) $r['spend'], 2),
            'roas'  => round((float) $r['roas'], 2),
            'conv'  => (int) $r['conversions'],
        ];
    }
} elseif ($account) {
    $summary = ['spend'=>0,'impressions'=>0,'clicks'=>0,'conversions'=>0,'revenue'=>0,'leads'=>0,'ctr'=>0,'cpa'=>0,'roas'=>0];
}

$fmtM = fn(?float $v) => $v !== null && $v > 0
    ? (($account['currency'] ?? 'BRL') === 'BRL' ? 'R$ '.number_format($v,2,',','.') : number_format($v,2).' '.($account['currency']??''))
    : '—';
$fmtN = fn($v, int $dec = 2) => $v ? number_format((float)$v, $dec) : '—';

$title = 'Performance · SALDO WEB';
ob_start();
?>

<!-- Page Header -->
<div class="page-header">
  <div>
    <h1 class="page-title">📈 Performance</h1>
    <p class="page-subtitle">Campanhas e métricas de anúncios</p>
  </div>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <form method="post" action="<?= e(base_url('run_collect.php')) ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button class="btn btn-secondary btn-sm" title="Coleta dados de ontem da API Meta"><i class="bi bi-arrow-clockwise"></i> Coletar dados</button>
    </form>
    <select class="form-select form-select-sm" style="width:auto;min-width:200px"
            onchange="window.location='performance.php?account='+this.value+'&period=<?= e($period) ?>'">
      <?php foreach ($accounts as $a): ?>
        <option value="<?= (int)$a['id'] ?>" <?= $a['id']==$accountId?'selected':'' ?>>
          <?= e($a['client_name'].' — '.($a['account_name']?:$a['ad_account_id'])) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="segmented">
      <?php foreach ($periodMap as $key => $pi): ?>
        <a href="performance.php?account=<?= $accountId ?>&period=<?= $key ?>"
           class="segmented-item <?= $key===$period?'active':'' ?>"><?= $pi['label'] ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if (!$account): ?>
<div class="empty-state">
  <div class="empty-icon">🔗</div>
  <div class="empty-title">Nenhuma conta disponível</div>
  <div class="empty-desc">Cadastre uma conta Meta Ads para visualizar as métricas.</div>
  <a href="accounts.php" class="btn btn-primary" style="margin-top:4px">Adicionar conta</a>
</div>
<?php else: ?>
<?php $curr = $account['currency'] ?: 'BRL'; ?>

<!-- KPI Cards -->
<div class="grid grid-4" style="margin-bottom:24px">
  <?php
  $roas = (float)($summary['roas']??0);
  $roasCls = $roas >= 4 ? 'color:var(--green)' : ($roas >= 2 ? 'color:var(--blue)' : ($roas > 0 ? 'color:var(--red)' : ''));
  $kpis = [
    ['Gasto total',   $fmtM((float)($summary['spend']??0)),    'accent-blue',   '💸'],
    ['Receita',       $fmtM((float)($summary['revenue']??0)),  'accent-green',  '💰'],
    ['ROAS',          ($roas>0?number_format($roas,2).'x':'—'),'accent-blue',   '📊', $roasCls],
    ['Conversões',    $fmtN($summary['conversions']??0, 0),    'accent-purple', '🎯'],
    ['CPA',           $fmtM((float)($summary['cpa']??0)),      'accent-orange', '🎯'],
    ['CTR',           $fmtN($summary['ctr']??0).'%',           'accent-blue',   '👁'],
    ['Cliques',       $fmtN($summary['clicks']??0, 0),         'accent-green',  '🖱'],
    ['Impressões',    number_format((int)($summary['impressions']??0),0,',','.'), 'accent-gray', '👀'],
  ];
  foreach ($kpis as $k): ?>
  <div class="metric-card">
    <div class="top-row">
      <div>
        <div class="metric-label"><?= $k[0] ?></div>
        <div class="metric-value sm" style="<?= $k[4] ?? '' ?>"><?= $k[1] ?></div>
      </div>
      <div class="metric-icon <?= $k[2] ?>"><?= $k[3] ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Daily Chart -->
<?php if (!empty($chartData)): ?>
<div class="card" style="margin-bottom:24px">
  <div class="card-header">
    <span class="card-header-title">Evolução diária</span>
  </div>
  <div class="card-body">
    <canvas id="chartPerf" height="70"></canvas>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const data = <?= json_encode($chartData) ?>;
  const labels = data.map(r => r.date);
  Chart.defaults.font.family = "'Inter', sans-serif";
  new Chart(document.getElementById('chartPerf'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        {
          label: 'Gasto',
          data: data.map(r=>r.spend),
          backgroundColor: 'rgba(0,122,255,.15)',
          borderColor: 'rgba(0,122,255,.6)',
          borderWidth: 1.5,
          borderRadius: 6,
          yAxisID: 'y'
        },
        {
          label: 'ROAS',
          data: data.map(r=>r.roas),
          type: 'line',
          borderColor: '#34C759',
          backgroundColor: 'rgba(52,199,89,.08)',
          tension: .35,
          yAxisID: 'y2',
          fill: true,
          pointBackgroundColor: '#34C759',
          pointRadius: 4,
          pointHoverRadius: 6,
          borderWidth: 2.5
        }
      ]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { position: 'top', labels: { font: { family: "'Inter', sans-serif", size: 12, weight: '600' }, usePointStyle: true, boxWidth: 8 } }
      },
      scales: {
        x: { grid: { display: false }, ticks: { font: { size: 12 } } },
        y: {
          position: 'left',
          title: { display: true, text: 'Gasto (<?= $curr ?>)', font: { size: 11, weight: '600' } },
          grid: { color: 'rgba(0,0,0,.04)' },
          ticks: { font: { size: 11 } }
        },
        y2: {
          position: 'right',
          title: { display: true, text: 'ROAS', font: { size: 11, weight: '600' } },
          grid: { drawOnChartArea: false },
          ticks: { font: { size: 11 } }
        }
      }
    }
  });
})();
</script>
<?php endif; ?>

<!-- Campaigns Table -->
<?php if (!empty($campaigns)): ?>
<div class="card" style="margin-bottom:24px">
  <div class="card-header">
    <span class="card-header-title">Campanhas</span>
    <span style="font-size:12px;color:var(--text-4)"><?= count($campaigns) ?> campanhas</span>
  </div>
  <div class="table-wrap" style="border:none;border-radius:0;box-shadow:none">
    <table>
      <thead>
        <tr>
          <th>Campanha</th>
          <th style="text-align:right">Gasto</th>
          <th style="text-align:right">Receita</th>
          <th style="text-align:right">ROAS</th>
          <th style="text-align:right">Conv.</th>
          <th style="text-align:right">CPA</th>
          <th style="text-align:right">CTR</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($campaigns as $c):
        $rv = (float)($c['roas']??0);
        $rc = $rv>=4?'good':($rv>=2?'ok':($rv>0&&$rv<1?'bad':''));
      ?>
        <tr>
          <td style="max-width:280px">
            <div style="font-weight:600;color:var(--text-1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($c['campaign_name'] ?: $c['campaign_id']) ?></div>
          </td>
          <td style="text-align:right;font-weight:600"><?= $fmtM((float)$c['spend']) ?></td>
          <td style="text-align:right"><?= $fmtM((float)($c['revenue']??0)) ?></td>
          <td style="text-align:right">
            <?php if ($rv > 0): ?>
              <span style="font-weight:700;color:<?= $rv>=4?'var(--green)':($rv>=2?'var(--blue)':'var(--red)') ?>"><?= number_format($rv,2) ?>x</span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td style="text-align:right"><?= (int)($c['conversions']??0) ?: '—' ?></td>
          <td style="text-align:right"><?= $fmtM((float)($c['cpa']??0)) ?></td>
          <td style="text-align:right"><?= $fmtN($c['ctr']??0) ?>%</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Top Ads -->
<?php if (!empty($topAds)): ?>
<div class="card" style="margin-bottom:24px">
  <div class="card-header">
    <span class="card-header-title">Top anúncios por ROAS</span>
    <span style="font-size:12px;color:var(--text-4)"><?= $p['label'] ?></span>
  </div>
  <div class="table-wrap" style="border:none;border-radius:0;box-shadow:none">
    <table>
      <thead>
        <tr>
          <th>Criativo</th>
          <th>Campanha</th>
          <th style="text-align:right">Gasto</th>
          <th style="text-align:right">ROAS</th>
          <th style="text-align:right">CPA</th>
          <th style="text-align:right">CTR</th>
          <th style="text-align:right">Conv.</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($topAds as $ad):
        $rv = (float)($ad['roas']??0);
        $status = $ad['effective_status'] ?? '';
        $stDot = match($status) { 'ACTIVE'=>'status-active','PAUSED'=>'status-paused','DISAPPROVED'=>'status-blocked', default=>'status-paused' };
        $stLabel = match($status) { 'ACTIVE'=>'Ativo','PAUSED'=>'Pausado','DISAPPROVED'=>'Reprovado', default=>($status?:'-') };
      ?>
        <tr>
          <td style="max-width:240px">
            <div style="display:flex;align-items:center;gap:10px">
              <?php if ($ad['thumbnail_url']): ?>
                <img src="<?= e($ad['thumbnail_url']) ?>" width="36" height="36"
                     style="object-fit:cover;border-radius:var(--radius-xs);flex-shrink:0"
                     onerror="this.style.display='none'">
              <?php else: ?>
                <div style="width:36px;height:36px;background:var(--surface-3);border-radius:var(--radius-xs);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px">🖼️</div>
              <?php endif; ?>
              <div style="font-size:13px;font-weight:600;color:var(--text-1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($ad['ad_name'] ?? '—') ?></div>
            </div>
          </td>
          <td><div style="font-size:12.5px;color:var(--text-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px"><?= e($ad['campaign_name'] ?? '—') ?></div></td>
          <td style="text-align:right;font-weight:600"><?= $fmtM((float)$ad['spend']) ?></td>
          <td style="text-align:right">
            <?php if ($rv > 0): ?>
              <span style="font-weight:700;color:<?= $rv>=4?'var(--green)':($rv>=2?'var(--blue)':'var(--red)') ?>"><?= number_format($rv,2) ?>x</span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td style="text-align:right"><?= $fmtM((float)($ad['cpa']??0)) ?></td>
          <td style="text-align:right"><?= $fmtN($ad['ctr']??0) ?>%</td>
          <td style="text-align:right"><?= (int)($ad['conversions']??0) ?: '—' ?></td>
          <td><span class="status-dot <?= $stDot ?>"><?= $stLabel ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if (empty($campaigns) && empty($topAds)): ?>
<div class="empty-state">
  <div class="empty-icon">📊</div>
  <div class="empty-title">Sem dados para este período</div>
  <div class="empty-desc">Execute a coleta de insights para visualizar as métricas:<br><code style="font-family:monospace;font-size:12px;background:var(--surface-3);padding:4px 8px;border-radius:6px;display:inline-block;margin-top:8px">php cron/collect_insights.php</code></div>
</div>
<?php endif; ?>

<?php endif; ?>
<?php
$content = ob_get_clean();
require APP_DIR . '/views/layout.php';
