<?php
require_once __DIR__ . '/app/bootstrap.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

if (!csrf_check($_POST['csrf'] ?? null) && !empty($_SESSION['csrf'])) {
    flash('Token CSRF inválido.', 'warning');
    header('Location: performance.php');
    exit;
}

$dateOverride = trim($_POST['date'] ?? '');
if ($dateOverride && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOverride)) {
    $dateOverride = '';
}

// Roda a coleta e captura os logs
$logLines  = [];
$collected = 0;
$errors    = 0;
$startedAt = microtime(true);

try {
    $insights = InsightsClient::fromSettings();
} catch (Throwable $e) {
    flash('Token Meta não configurado: ' . $e->getMessage(), 'danger');
    header('Location: performance.php');
    exit;
}

$datePreset = $dateOverride ? 'custom' : 'yesterday';
$since = $dateOverride ?: null;
$until = $dateOverride ?: null;

$accounts = Db::all(
    'SELECT ma.*, c.name AS client_name
     FROM meta_accounts ma
     JOIN clients c ON c.id = ma.client_id
     WHERE ma.active = 1 AND c.active = 1 AND ma.collect_insights = 1'
);

if (empty($accounts)) {
    flash('Nenhuma conta com coleta de insights ativada. Ative em Relatórios → configurações por conta.', 'warning');
    header('Location: performance.php');
    exit;
}

foreach ($accounts as $acc) {
    try {
        $r1 = $insights->collectAdInsights((int)$acc['id'], $acc['ad_account_id'], $datePreset, $since, $until);
        $r2 = $insights->collectCampaignInsights((int)$acc['id'], $acc['ad_account_id'], $datePreset, $since, $until);
        $collected += $r1['collected'] + $r2['collected'];
        $errors    += $r1['errors'];
        $logLines[] = ['ok' => true,  'msg' => "{$acc['client_name']} — {$acc['ad_account_id']}: {$r1['collected']} ads, {$r2['collected']} campanhas"];
    } catch (Throwable $e) {
        $errors++;
        $logLines[] = ['ok' => false, 'msg' => "{$acc['account_name']} — {$acc['ad_account_id']}: " . $e->getMessage()];
    }
}

$elapsed = round(microtime(true) - $startedAt, 1);
$dateLabel = $dateOverride ?: date('d/m/Y', strtotime('-1 day')) . ' (ontem)';

$title = 'Coleta de Insights · SALDO WEB';
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">📥 Coleta de Insights</h1>
    <p class="page-subtitle">Concluída em <?= $elapsed ?>s · <?= $dateLabel ?></p>
  </div>
  <a href="<?= e(base_url('performance.php')) ?>" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Ver performance</a>
</div>

<!-- Resumo -->
<div class="grid grid-4" style="margin-bottom:24px">
  <div class="metric-card">
    <div class="top-row">
      <div>
        <div class="metric-label">Linhas coletadas</div>
        <div class="metric-value"><?= $collected ?></div>
      </div>
      <div class="metric-icon accent-green"><i class="bi bi-database-fill-check"></i></div>
    </div>
    <div class="metric-sub">ads + campanhas</div>
  </div>
  <div class="metric-card">
    <div class="top-row">
      <div>
        <div class="metric-label">Contas processadas</div>
        <div class="metric-value"><?= count($accounts) ?></div>
      </div>
      <div class="metric-icon accent-blue"><i class="bi bi-link-45deg"></i></div>
    </div>
    <div class="metric-sub">com insights ativos</div>
  </div>
  <div class="metric-card">
    <div class="top-row">
      <div>
        <div class="metric-label">Erros</div>
        <div class="metric-value sm" style="<?= $errors > 0 ? 'color:var(--red)' : '' ?>"><?= $errors ?></div>
      </div>
      <div class="metric-icon <?= $errors > 0 ? 'accent-red' : 'accent-green' ?>"><i class="bi <?= $errors > 0 ? 'bi-exclamation-triangle' : 'bi-check-circle-fill' ?>"></i></div>
    </div>
    <div class="metric-sub"><?= $errors > 0 ? 'veja o log abaixo' : 'tudo ok' ?></div>
  </div>
  <div class="metric-card">
    <div class="top-row">
      <div>
        <div class="metric-label">Tempo</div>
        <div class="metric-value sm"><?= $elapsed ?>s</div>
      </div>
      <div class="metric-icon accent-purple"><i class="bi bi-stopwatch-fill"></i></div>
    </div>
    <div class="metric-sub">para concluir</div>
  </div>
</div>

<!-- Log -->
<div class="card" style="margin-bottom:24px">
  <div class="card-header">
    <span class="card-header-title">Log de execução</span>
  </div>
  <div style="padding:16px;display:flex;flex-direction:column;gap:8px">
    <?php foreach ($logLines as $l): ?>
      <div style="display:flex;align-items:center;gap:10px;font-size:13px">
        <span style="font-size:16px"><?= $l['ok'] ? '✅' : '❌' ?></span>
        <span style="color:<?= $l['ok'] ? 'var(--text-2)' : 'var(--red)' ?>"><?= e($l['msg']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Coletar outro período -->
<div class="card">
  <div class="card-header">
    <span class="card-header-title">Reprocessar um dia específico</span>
  </div>
  <div class="card-body">
    <form method="post" style="display:flex;gap:10px;align-items:flex-end">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div>
        <label class="form-label" style="font-size:12px">Data (YYYY-MM-DD)</label>
        <input type="date" name="date" class="form-control" style="width:200px"
               max="<?= date('Y-m-d', strtotime('-1 day')) ?>"
               value="<?= e($dateOverride ?: date('Y-m-d', strtotime('-1 day'))) ?>">
      </div>
      <button type="submit" class="btn btn-primary"><i class="bi bi-arrow-clockwise"></i> Coletar esta data</button>
    </form>
    <div class="form-hint">Útil para reprocessar um dia que falhou ou buscar dados históricos.</div>
  </div>
</div>

<?php
$content = ob_get_clean();
require APP_DIR . '/views/layout.php';
