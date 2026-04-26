<?php
require_once __DIR__ . '/app/bootstrap.php';
require_auth();

$accountId = (int) ($_GET['account'] ?? 0);
$period    = $_GET['period'] ?? '7d';
$sortBy    = in_array($_GET['sort'] ?? '', ['roas','cpa','ctr','spend','conversions']) ? $_GET['sort'] : 'roas';
$sortDir   = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$filter    = $_GET['filter'] ?? 'all';

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

$creatives = [];
if ($account && table_exists('ad_insights')) {
    $allowedSort = ['roas'=>'roas','cpa'=>'cpa','ctr'=>'ctr','spend'=>'spend','conversions'=>'conversions'];
    $orderCol = $allowedSort[$sortBy] ?? 'roas';
    $orderDir = $sortDir === 'asc' ? 'ASC' : 'DESC';

    // Construímos uma única HAVING com todos os filtros (MySQL não aceita 2 HAVINGs).
    $havingExtra = '';
    $statusParams = [$accountId, $since, $until];
    if ($filter === 'disapproved') {
        $havingExtra = " AND MAX(effective_status) = 'DISAPPROVED'";
    } elseif ($filter === 'active') {
        $havingExtra = " AND MAX(effective_status) = 'ACTIVE'";
    } elseif ($filter === 'winners') {
        $havingExtra = ' AND roas >= 3';
    } elseif ($filter === 'losers') {
        $havingExtra = ' AND ((roas < 1) OR (cpa > 100))';
    }

    $creatives = Db::all(
        "SELECT ad_id, ad_name, campaign_name, adset_name,
                MAX(thumbnail_url) AS thumbnail_url,
                MAX(effective_status) AS effective_status,
                SUM(spend) AS spend,
                SUM(impressions) AS impressions,
                SUM(clicks) AS clicks,
                SUM(conversions) AS conversions,
                SUM(conversion_value) AS revenue,
                SUM(leads) AS leads,
                (SUM(clicks)/NULLIF(SUM(impressions),0))*100 AS ctr,
                SUM(spend)/NULLIF(SUM(clicks),0) AS cpc,
                SUM(spend)/NULLIF(SUM(conversions),0) AS cpa,
                SUM(conversion_value)/NULLIF(SUM(spend),0) AS roas
         FROM ad_insights
         WHERE meta_account_id = ? AND date_start >= ? AND date_stop <= ?
         GROUP BY ad_id, ad_name, campaign_name, adset_name
         HAVING spend > 0{$havingExtra}
         ORDER BY {$orderCol} {$orderDir}
         LIMIT 60",
        $statusParams
    );
}

$fmtM = fn(?float $v) => $v !== null && $v > 0
    ? (($account['currency'] ?? 'BRL') === 'BRL' ? 'R$ '.number_format($v,2,',','.') : number_format($v,2).' '.($account['currency']??''))
    : '—';

$title = 'Criativos · SALDO WEB';
ob_start();
?>

<!-- Page Header -->
<div class="page-header">
  <div>
    <h1 class="page-title">🏆 Galeria de Criativos</h1>
    <p class="page-subtitle">Ranking visual dos melhores e piores anúncios</p>
  </div>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <select class="form-select form-select-sm" style="width:auto;min-width:200px"
            onchange="window.location='creatives.php?account='+this.value+'&period=<?= e($period) ?>&sort=<?= e($sortBy) ?>&dir=<?= e($sortDir) ?>'">
      <?php foreach ($accounts as $a): ?>
        <option value="<?= (int)$a['id'] ?>" <?= $a['id']==$accountId?'selected':'' ?>>
          <?= e($a['client_name'].' — '.($a['account_name']?:$a['ad_account_id'])) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="segmented">
      <?php foreach ($periodMap as $key => $pi): ?>
        <a href="creatives.php?account=<?= $accountId ?>&period=<?= $key ?>&sort=<?= $sortBy ?>&dir=<?= $sortDir ?>&filter=<?= $filter ?>"
           class="segmented-item <?= $key===$period?'active':'' ?>"><?= $pi['label'] ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Filter + Sort Bar -->
<div class="filter-bar">
  <?php
  $filters = ['all'=>'Todos','winners'=>'🏅 Vencedores','losers'=>'💀 Pausar','active'=>'✅ Ativos','disapproved'=>'🚫 Reprovados'];
  foreach ($filters as $fkey => $flabel):
  ?>
    <a href="creatives.php?account=<?= $accountId ?>&period=<?= $period ?>&sort=<?= $sortBy ?>&dir=<?= $sortDir ?>&filter=<?= $fkey ?>"
       class="btn btn-sm <?= $fkey===$filter ? 'btn-primary' : 'btn-secondary' ?>"><?= $flabel ?></a>
  <?php endforeach; ?>

  <div style="margin-left:auto;display:flex;gap:6px;align-items:center">
    <span style="font-size:12px;color:var(--text-4);font-weight:600">Ordenar:</span>
    <?php
    $sorts = ['roas'=>'ROAS','cpa'=>'CPA','spend'=>'Gasto','ctr'=>'CTR','conversions'=>'Conv.'];
    foreach ($sorts as $skey => $slabel):
      $dir = ($skey===$sortBy && $sortDir==='desc') ? 'asc' : 'desc';
      $active = $skey === $sortBy;
    ?>
      <a href="creatives.php?account=<?= $accountId ?>&period=<?= $period ?>&sort=<?= $skey ?>&dir=<?= $dir ?>&filter=<?= $filter ?>"
         class="btn btn-sm <?= $active ? 'btn-secondary' : 'btn-secondary' ?>"
         style="<?= $active ? 'font-weight:700;color:var(--text-1)' : 'color:var(--text-4)' ?>">
        <?= $slabel ?><?= $active ? ($sortDir==='desc'?' ↓':' ↑') : '' ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (empty($creatives)): ?>
<div class="empty-state">
  <div class="empty-icon">🖼️</div>
  <div class="empty-title"><?= $account ? 'Nenhum criativo encontrado' : 'Selecione uma conta' ?></div>
  <div class="empty-desc">
    <?php if ($account): ?>
      Nenhum criativo para os filtros selecionados.<br>
      <code style="font-family:monospace;font-size:12px;background:var(--surface-3);padding:4px 8px;border-radius:6px;display:inline-block;margin-top:8px">php cron/collect_insights.php</code>
    <?php else: ?>
      Selecione uma conta Meta Ads para visualizar os criativos.
    <?php endif; ?>
  </div>
</div>
<?php else: ?>

<div class="grid grid-creatives">
<?php foreach ($creatives as $cr):
  $roas   = (float)($cr['roas']??0);
  $cpa    = (float)($cr['cpa']??0);
  $spend  = (float)($cr['spend']??0);
  $status = $cr['effective_status'] ?? '';

  $badge     = '';
  $cardClass = '';
  if ($status === 'DISAPPROVED') {
      $badge     = '<span class="badge badge-red">🚫 Reprovado</span>';
      $cardClass = 'is-loser';
  } elseif ($roas >= 4) {
      $badge     = '<span class="badge badge-green">🏆 Top</span>';
      $cardClass = 'is-winner';
  } elseif ($roas >= 2) {
      $badge     = '<span class="badge badge-blue">✅ Bom</span>';
  } elseif ($roas > 0 && $roas < 1) {
      $badge     = '<span class="badge badge-red">💀 Pausar</span>';
      $cardClass = 'is-loser';
  } elseif ($spend > 0 && $roas === 0.0 && (float)($cr['conversions']??0) == 0) {
      $badge     = '<span class="badge badge-orange">⚠️ Sem conv.</span>';
  }

  $roasVal  = $roas>0 ? number_format($roas,2).'x' : '—';
  $roasCls  = $roas>=4 ? 'good' : ($roas>=2 ? 'ok' : ($roas>0&&$roas<1 ? 'bad' : ''));
?>
<div class="creative-card <?= $cardClass ?>">
  <!-- Thumbnail -->
  <div class="creative-thumb" style="height:160px;position:relative">
    <?php if ($cr['thumbnail_url']): ?>
      <img src="<?= e($cr['thumbnail_url']) ?>" alt="criativo"
           onerror="this.parentNode.innerHTML='<div style=\'display:flex;align-items:center;justify-content:center;height:100%;font-size:32px;color:var(--text-5)\'>🖼️</div>'">
    <?php else: ?>
      <div style="display:flex;align-items:center;justify-content:center;height:100%;font-size:32px;color:var(--text-5)">🖼️</div>
    <?php endif; ?>
    <?php if ($badge): ?>
      <div class="creative-badge-overlay"><?= $badge ?></div>
    <?php endif; ?>
  </div>
  <!-- Info -->
  <div class="creative-meta">
    <div class="creative-name" title="<?= e($cr['ad_name']??'') ?>"><?= e($cr['ad_name'] ?: 'Sem nome') ?></div>
    <div class="creative-camp" title="<?= e($cr['campaign_name']??'') ?>"><?= e($cr['campaign_name'] ?: '—') ?></div>
    <div class="creative-kpis">
      <div class="kpi-pill">
        <div class="kpi-pill-label">ROAS</div>
        <div class="kpi-pill-value <?= $roasCls ?>"><?= $roasVal ?></div>
      </div>
      <div class="kpi-pill">
        <div class="kpi-pill-label">CPA</div>
        <div class="kpi-pill-value"><?= $fmtM($cpa) ?></div>
      </div>
      <div class="kpi-pill">
        <div class="kpi-pill-label">Gasto</div>
        <div class="kpi-pill-value"><?= $fmtM($spend) ?></div>
      </div>
      <div class="kpi-pill">
        <div class="kpi-pill-label">CTR</div>
        <div class="kpi-pill-value"><?= $cr['ctr']>0?number_format((float)$cr['ctr'],2).'%':'—' ?></div>
      </div>
    </div>
    <div style="display:flex;justify-content:space-between;margin-top:10px">
      <span style="font-size:11.5px;color:var(--text-4)">Conv: <strong style="color:var(--text-2)"><?= (int)($cr['conversions']??0) ?: '—' ?></strong></span>
      <span style="font-size:11.5px;color:var(--text-4)">Imp: <strong style="color:var(--text-2)"><?= number_format((int)($cr['impressions']??0),0,',','.') ?></strong></span>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>
<?php
$content = ob_get_clean();
require APP_DIR . '/views/layout.php';
