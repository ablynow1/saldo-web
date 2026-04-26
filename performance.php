<?php
require_once __DIR__ . '/app/bootstrap.php';
require_auth();

$accountId = (int) ($_GET['account'] ?? 0);
$period    = $_GET['period'] ?? '7d';

$periodMap = [
    '1d'  => ['label' => 'Ontem',   'since' => date('Y-m-d', strtotime('-1 day')),   'until' => date('Y-m-d', strtotime('-1 day'))],
    '7d'  => ['label' => '7 dias',  'since' => date('Y-m-d', strtotime('-7 days')),  'until' => date('Y-m-d', strtotime('-1 day'))],
    '14d' => ['label' => '14 dias', 'since' => date('Y-m-d', strtotime('-14 days')), 'until' => date('Y-m-d', strtotime('-1 day'))],
    '30d' => ['label' => '30 dias', 'since' => date('Y-m-d', strtotime('-30 days')), 'until' => date('Y-m-d', strtotime('-1 day'))],
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

/**
 * Sparkline SVG generator.
 */
function lux_spark(array $values, string $rgb = '255,255,255'): string {
    if (count($values) < 2) $values = [3,2,4,3,5,4,6,5,7,6,8,9];
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

// Spark series from real chart data (or fallback)
$sparkSpend = []; $sparkRoas = []; $sparkConv = [];
foreach ($chartData as $r) {
    $sparkSpend[] = $r['spend'];
    $sparkRoas[]  = $r['roas'];
    $sparkConv[]  = $r['conv'];
}

$title = 'Performance · SALDO WEB';
ob_start();
?>

<!-- ═══════════════════ HERO ═══════════════════ -->
<div class="lux-hero">
  <div class="lux-hero-l">
    <h1 class="lux-hero-title">Performance</h1>
    <div class="lux-hero-meta">
      <?php if ($account): ?>
        <span class="lux-chip"><i class="bi bi-person"></i> <?= e($account['client_name']) ?></span>
        <span class="lux-chip"><i class="bi bi-link-45deg"></i> <?= e($account['account_name'] ?: $account['ad_account_id']) ?></span>
      <?php endif; ?>
      <span class="lux-chip"><i class="bi bi-calendar3"></i> <?= e($p['label']) ?></span>
    </div>
  </div>
  <div class="lux-hero-actions">
    <select class="form-select form-select-sm" style="width:auto;min-width:220px"
            onchange="window.location='performance.php?account='+this.value+'&period=<?= e($period) ?>'">
      <?php foreach ($accounts as $a): ?>
        <option value="<?= (int)$a['id'] ?>" <?= $a['id']==$accountId?'selected':'' ?>>
          <?= e($a['client_name'].' — '.($a['account_name']?:$a['ad_account_id'])) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="lux-segmented">
      <?php foreach ($periodMap as $key => $pi): ?>
        <a href="performance.php?account=<?= $accountId ?>&period=<?= $key ?>"
           class="lux-segmented-item <?= $key===$period?'active':'' ?>"><?= $pi['label'] ?></a>
      <?php endforeach; ?>
    </div>
    <form method="post" action="<?= e(base_url('run_collect.php')) ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button class="btn btn-secondary btn-sm" title="Coletar dados de ontem"><i class="bi bi-arrow-clockwise"></i> Coletar</button>
    </form>
  </div>
</div>

<?php if (!$account): ?>
<div class="lux-empty">
  <div class="lux-empty-icon"><i class="bi bi-link-45deg"></i></div>
  <div class="lux-empty-title">Nenhuma conta disponível</div>
  <div class="lux-empty-desc">Cadastre uma conta Meta Ads para começar a visualizar campanhas e métricas em tempo real.</div>
  <a href="accounts.php" class="btn btn-primary" style="margin-top:6px"><i class="bi bi-plus-lg"></i> Adicionar conta</a>
</div>
<?php else: ?>

<?php $curr = $account['currency'] ?: 'BRL'; ?>

<?php
$roas = (float)($summary['roas'] ?? 0);
$roasCls = $roas >= 4 ? 'good' : ($roas >= 2 ? '' : ($roas > 0 ? 'crit' : ''));
?>

<!-- ═══════════════════ HERO KPIs (3) ═══════════════════ -->
<div class="lux-grid lux-grid-3">

  <div class="lux-kpi hero">
    <?= lux_spark($sparkSpend ?: [10,12,9,15,14,18,16,22], '255,255,255') ?>
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">Gasto total</span>
        <div class="lux-kpi-icon"><i class="bi bi-cash-stack"></i></div>
      </div>
      <div class="lux-kpi-value"><?= $fmtM((float)($summary['spend']??0)) ?></div>
      <div class="lux-kpi-sub">no período · <?= e($p['label']) ?></div>
    </div>
  </div>

  <div class="lux-kpi hero">
    <?= lux_spark($sparkSpend ?: [4,5,6,5,8,7,9,11], '74,222,128') ?>
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">Receita</span>
        <div class="lux-kpi-icon"><i class="bi bi-graph-up-arrow"></i></div>
      </div>
      <div class="lux-kpi-value"><?= $fmtM((float)($summary['revenue']??0)) ?></div>
      <div class="lux-kpi-sub">faturamento atribuído</div>
    </div>
  </div>

  <div class="lux-kpi hero">
    <?= lux_spark($sparkRoas ?: [1.2,2,1.8,2.5,3,2.8,3.5,4], $roas>=4?'74,222,128':($roas>=2?'255,255,255':'248,113,113')) ?>
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">ROAS</span>
        <div class="lux-kpi-icon"><i class="bi bi-bullseye"></i></div>
      </div>
      <div class="lux-kpi-value <?= $roasCls ?>"><?= $roas > 0 ? number_format($roas, 2) . 'x' : '—' ?></div>
      <div class="lux-kpi-sub <?= $roas >= 2 ? 'up' : ($roas > 0 ? 'down' : '') ?>"><?= $roas >= 4 ? 'performance excepcional' : ($roas >= 2 ? 'performance saudável' : ($roas > 0 ? 'abaixo do esperado' : 'sem dados')) ?></div>
    </div>
  </div>

</div>

<!-- ═══════════════════ SECONDARY KPIs (5) ═══════════════════ -->
<div class="lux-grid lux-grid-5" style="margin-top:14px">

  <div class="lux-kpi">
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">Conversões</span>
        <div class="lux-kpi-icon"><i class="bi bi-check-circle"></i></div>
      </div>
      <div class="lux-kpi-value sm"><?= $fmtN($summary['conversions']??0, 0) ?></div>
    </div>
  </div>

  <div class="lux-kpi">
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">CPA</span>
        <div class="lux-kpi-icon"><i class="bi bi-tag"></i></div>
      </div>
      <div class="lux-kpi-value sm"><?= $fmtM((float)($summary['cpa']??0)) ?></div>
    </div>
  </div>

  <div class="lux-kpi">
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">CTR</span>
        <div class="lux-kpi-icon"><i class="bi bi-cursor"></i></div>
      </div>
      <div class="lux-kpi-value sm"><?= $fmtN($summary['ctr']??0) ?>%</div>
    </div>
  </div>

  <div class="lux-kpi">
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">Cliques</span>
        <div class="lux-kpi-icon"><i class="bi bi-hand-index"></i></div>
      </div>
      <div class="lux-kpi-value sm"><?= $fmtN($summary['clicks']??0, 0) ?></div>
    </div>
  </div>

  <div class="lux-kpi">
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">Impressões</span>
        <div class="lux-kpi-icon"><i class="bi bi-eye"></i></div>
      </div>
      <div class="lux-kpi-value sm"><?= number_format((int)($summary['impressions']??0),0,',','.') ?></div>
    </div>
  </div>

</div>

<!-- ═══════════════════ DAILY CHART ═══════════════════ -->
<?php if (!empty($chartData)): ?>
<div class="lux-section">
  <h2 class="lux-section-title">
    Evolução diária
    <span class="lux-section-title-meta"><?= count($chartData) ?> dias</span>
  </h2>
</div>
<div class="lux-card">
  <canvas id="chartPerf" height="86"></canvas>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  const data = <?= json_encode($chartData) ?>;
  const labels = data.map(r => r.date);

  // Dark luxury theme defaults
  Chart.defaults.font.family = "'Inter', -apple-system, sans-serif";
  Chart.defaults.color = 'rgba(255,255,255,0.45)';
  Chart.defaults.borderColor = 'rgba(255,255,255,0.05)';

  new Chart(document.getElementById('chartPerf'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        {
          label: 'Gasto',
          data: data.map(r => r.spend),
          backgroundColor: 'rgba(255,255,255,0.10)',
          borderColor: 'rgba(255,255,255,0.35)',
          borderWidth: 1,
          borderRadius: 6,
          yAxisID: 'y',
          hoverBackgroundColor: 'rgba(255,255,255,0.18)'
        },
        {
          label: 'ROAS',
          data: data.map(r => r.roas),
          type: 'line',
          borderColor: 'rgba(74,222,128,0.85)',
          backgroundColor: 'rgba(74,222,128,0.08)',
          tension: 0.38,
          yAxisID: 'y2',
          fill: true,
          pointBackgroundColor: '#4ADE80',
          pointBorderColor: 'rgba(8,10,15,1)',
          pointBorderWidth: 2,
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
        legend: {
          position: 'top',
          align: 'end',
          labels: {
            font: { family: "'Inter', sans-serif", size: 11, weight: '600' },
            color: 'rgba(255,255,255,0.65)',
            usePointStyle: true,
            boxWidth: 8,
            padding: 14
          }
        },
        tooltip: {
          backgroundColor: 'rgba(13,15,22,0.95)',
          titleColor: 'rgba(255,255,255,0.95)',
          bodyColor: 'rgba(255,255,255,0.75)',
          borderColor: 'rgba(255,255,255,0.10)',
          borderWidth: 1,
          padding: 12,
          cornerRadius: 10,
          titleFont: { weight: '700', size: 12 },
          bodyFont: { size: 12 },
          boxPadding: 6
        }
      },
      scales: {
        x: {
          grid: { display: false, drawBorder: false },
          ticks: { font: { size: 11 }, color: 'rgba(255,255,255,0.40)' }
        },
        y: {
          position: 'left',
          title: { display: true, text: 'Gasto (<?= $curr ?>)', font: { size: 10, weight: '600' }, color: 'rgba(255,255,255,0.30)' },
          grid: { color: 'rgba(255,255,255,0.04)', drawBorder: false },
          border: { display: false },
          ticks: { font: { size: 11 }, color: 'rgba(255,255,255,0.40)' }
        },
        y2: {
          position: 'right',
          title: { display: true, text: 'ROAS', font: { size: 10, weight: '600' }, color: 'rgba(255,255,255,0.30)' },
          grid: { drawOnChartArea: false, drawBorder: false },
          border: { display: false },
          ticks: { font: { size: 11 }, color: 'rgba(255,255,255,0.40)' }
        }
      }
    }
  });
})();
</script>
<?php endif; ?>

<!-- ═══════════════════ CAMPAIGNS ═══════════════════ -->
<?php if (!empty($campaigns)): ?>
<div class="lux-section">
  <h2 class="lux-section-title">
    Campanhas
    <span class="lux-section-title-meta"><?= count($campaigns) ?> ativas</span>
  </h2>
</div>
<div class="lux-table-wrap">
  <table class="lux-table">
    <thead>
      <tr>
        <th>Campanha</th>
        <th class="lux-td-r">Gasto</th>
        <th class="lux-td-r">Receita</th>
        <th class="lux-td-r">ROAS</th>
        <th class="lux-td-r">Conv.</th>
        <th class="lux-td-r">CPA</th>
        <th class="lux-td-r">CTR</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($campaigns as $c):
      $rv = (float)($c['roas']??0);
      $rcls = $rv>=4?'good':($rv>=2?'':($rv>0?'bad':'dim'));
    ?>
      <tr>
        <td style="max-width:300px">
          <div class="lux-td-primary" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($c['campaign_name'] ?: $c['campaign_id']) ?></div>
        </td>
        <td class="lux-td-r"><span class="lux-td-num strong"><?= $fmtM((float)$c['spend']) ?></span></td>
        <td class="lux-td-r"><span class="lux-td-num"><?= $fmtM((float)($c['revenue']??0)) ?></span></td>
        <td class="lux-td-r">
          <?php if ($rv > 0): ?>
            <span class="lux-td-num <?= $rcls ?>"><?= number_format($rv,2) ?>x</span>
          <?php else: ?>
            <span class="lux-td-num dim">—</span>
          <?php endif; ?>
        </td>
        <td class="lux-td-r"><span class="lux-td-num"><?= (int)($c['conversions']??0) ?: '—' ?></span></td>
        <td class="lux-td-r"><span class="lux-td-num"><?= $fmtM((float)($c['cpa']??0)) ?></span></td>
        <td class="lux-td-r"><span class="lux-td-num"><?= $fmtN($c['ctr']??0) ?>%</span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ═══════════════════ TOP ADS ═══════════════════ -->
<?php if (!empty($topAds)): ?>
<div class="lux-section">
  <h2 class="lux-section-title">
    Top anúncios por ROAS
    <span class="lux-section-title-meta"><?= e($p['label']) ?> · <?= count($topAds) ?> criativos</span>
  </h2>
</div>
<div class="lux-table-wrap">
  <table class="lux-table">
    <thead>
      <tr>
        <th>Criativo</th>
        <th>Campanha</th>
        <th class="lux-td-r">Gasto</th>
        <th class="lux-td-r">ROAS</th>
        <th class="lux-td-r">CPA</th>
        <th class="lux-td-r">CTR</th>
        <th class="lux-td-r">Conv.</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($topAds as $ad):
      $rv = (float)($ad['roas']??0);
      $rcls = $rv>=4?'good':($rv>=2?'':($rv>0?'bad':'dim'));
      $status = $ad['effective_status'] ?? '';
      $stCls = match($status) { 'ACTIVE'=>'live','PAUSED'=>'dim','DISAPPROVED'=>'crit', default=>'dim' };
      $stLabel = match($status) { 'ACTIVE'=>'Ativo','PAUSED'=>'Pausado','DISAPPROVED'=>'Reprovado', default=>($status?:'—') };
    ?>
      <tr>
        <td>
          <div class="lux-ad">
            <div class="lux-ad-thumb">
              <?php if ($ad['thumbnail_url']): ?>
                <img src="<?= e($ad['thumbnail_url']) ?>" alt="" onerror="this.style.display='none';this.parentNode.innerHTML='<i class=\'bi bi-image\'></i>'">
              <?php else: ?>
                <i class="bi bi-image"></i>
              <?php endif; ?>
            </div>
            <div class="lux-ad-body">
              <div class="lux-ad-name"><?= e($ad['ad_name'] ?? '—') ?></div>
              <?php if (!empty($ad['adset_name'])): ?>
                <div class="lux-ad-camp"><?= e($ad['adset_name']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </td>
        <td>
          <div style="font-size:12px;color:rgba(255,255,255,0.55);max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($ad['campaign_name'] ?? '—') ?></div>
        </td>
        <td class="lux-td-r"><span class="lux-td-num strong"><?= $fmtM((float)$ad['spend']) ?></span></td>
        <td class="lux-td-r">
          <?php if ($rv > 0): ?>
            <span class="lux-td-num <?= $rcls ?>"><?= number_format($rv,2) ?>x</span>
          <?php else: ?>
            <span class="lux-td-num dim">—</span>
          <?php endif; ?>
        </td>
        <td class="lux-td-r"><span class="lux-td-num"><?= $fmtM((float)($ad['cpa']??0)) ?></span></td>
        <td class="lux-td-r"><span class="lux-td-num"><?= $fmtN($ad['ctr']??0) ?>%</span></td>
        <td class="lux-td-r"><span class="lux-td-num"><?= (int)($ad['conversions']??0) ?: '—' ?></span></td>
        <td><span class="lux-dot <?= e($stCls) ?>"><?= e($stLabel) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php if (empty($campaigns) && empty($topAds)): ?>
<div class="lux-empty">
  <div class="lux-empty-icon"><i class="bi bi-bar-chart"></i></div>
  <div class="lux-empty-title">Sem dados para este período</div>
  <div class="lux-empty-desc">Execute a coleta de insights para visualizar campanhas e métricas:<br><code style="margin-top:8px;display:inline-block">php cron/collect_insights.php</code></div>
</div>
<?php endif; ?>

<?php endif; ?>
<?php
$content = ob_get_clean();
require APP_DIR . '/views/layout.php';
