<?php
/**
 * fix_insights.php — Limpeza de dados de insights inflacionados.
 *
 * Bug histórico: chamadas a collectAdInsights/collectCampaignInsights com
 * `time_range` cobrindo múltiplos dias, sem `time_increment=1`, faziam a
 * Meta API retornar UMA linha agregada de todo o período. Como o unique
 * key é (entity_id, date_start), essa linha sobrescrevia o registro
 * diário do primeiro dia do range com o total agregado — inflando o SUM
 * em qualquer query de período (relatório semanal, painel, etc.).
 *
 * Este script:
 *   1) Deleta de ad_insights e campaign_insights todas as linhas com
 *      date_start != date_stop (rows agregadas corruptas).
 *   2) Re-coleta dia-a-dia os últimos N dias (default: 30) para todas
 *      as contas com collect_insights = 1, usando date_preset='custom'
 *      com since=until=mesmo_dia (garante linha por dia).
 *
 * Acesso: GET /fix_insights.php  (requer auth)
 *         POST /fix_insights.php?confirm=1  (executa de fato)
 */

require_once __DIR__ . '/app/bootstrap.php';
require_auth();

$days       = max(1, min(90, (int) ($_REQUEST['days'] ?? 30)));
$confirm    = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_REQUEST['confirm'] ?? '') === '1');
$csrfOk     = $confirm ? csrf_check($_POST['csrf'] ?? null) : true;

$preview = [
    'ad_insights_corrupt'       => (int) Db::scalar('SELECT COUNT(*) FROM ad_insights WHERE date_start != date_stop'),
    'campaign_insights_corrupt' => (int) Db::scalar('SELECT COUNT(*) FROM campaign_insights WHERE date_start != date_stop'),
    'ad_insights_total'         => (int) Db::scalar('SELECT COUNT(*) FROM ad_insights'),
    'campaign_insights_total'   => (int) Db::scalar('SELECT COUNT(*) FROM campaign_insights'),
];

$result = null;

if ($confirm && $csrfOk) {
    $deletedAd   = (int) Db::exec('DELETE FROM ad_insights       WHERE date_start != date_stop');
    $deletedCamp = (int) Db::exec('DELETE FROM campaign_insights WHERE date_start != date_stop');

    // Re-coleta dia-a-dia
    $accounts = Db::all(
        'SELECT ma.*, c.name AS client_name
         FROM meta_accounts ma JOIN clients c ON c.id = ma.client_id
         WHERE ma.active = 1 AND c.active = 1 AND ma.collect_insights = 1'
    );

    $log       = [];
    $collected = 0;
    $errors    = 0;

    try {
        $insights = InsightsClient::fromSettings();
    } catch (Throwable $e) {
        $log[] = ['ok' => false, 'msg' => 'Token Meta não configurado: ' . $e->getMessage()];
        $accounts = [];
    }

    $startedAt = microtime(true);

    foreach ($accounts as $acc) {
        $accId   = (int) $acc['id'];
        $accName = $acc['client_name'] . ' — ' . ($acc['account_name'] ?: $acc['ad_account_id']);

        for ($i = $days; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} day"));
            try {
                $r1 = $insights->collectAdInsights($accId, $acc['ad_account_id'], 'custom', $d, $d);
                $r2 = $insights->collectCampaignInsights($accId, $acc['ad_account_id'], 'custom', $d, $d);
                $collected += ($r1['collected'] ?? 0) + ($r2['collected'] ?? 0);
                $errors    += ($r1['errors'] ?? 0);
            } catch (Throwable $e) {
                $errors++;
                $log[] = ['ok' => false, 'msg' => "{$accName} [{$d}]: " . $e->getMessage()];
            }
        }
        $log[] = ['ok' => true, 'msg' => "{$accName}: re-coletado " . ($days + 1) . " dia(s)"];
    }

    $elapsed = round(microtime(true) - $startedAt, 1);

    $result = [
        'deleted_ad'         => $deletedAd,
        'deleted_camp'       => $deletedCamp,
        'recollected_rows'   => $collected,
        'errors'             => $errors,
        'elapsed_seconds'    => $elapsed,
        'accounts_processed' => count($accounts),
        'log'                => $log,
    ];

    flash("Limpeza concluída: {$deletedAd} ad_insights + {$deletedCamp} campaign_insights deletados, {$collected} linhas re-coletadas em {$elapsed}s.", $errors > 0 ? 'warning' : 'success');
}

$title = 'Reparar Insights · SALDO WEB';
ob_start();
?>

<div class="lux-hero">
  <div class="lux-hero-l">
    <h1 class="lux-hero-title">Reparar Insights</h1>
    <div class="lux-hero-meta">
      <span class="lux-chip"><i class="bi bi-tools"></i> Limpeza de dados inflacionados</span>
    </div>
  </div>
  <div class="lux-hero-actions">
    <a href="<?= e(base_url('performance.php')) ?>" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left"></i> Voltar</a>
  </div>
</div>

<?php if (!$result): ?>

<div class="lux-section">
  <h2 class="lux-section-title">Diagnóstico</h2>
</div>

<div class="lux-grid lux-grid-4">
  <div class="lux-kpi">
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">Linhas corruptas (ad)</span>
        <div class="lux-kpi-icon"><i class="bi bi-exclamation-octagon"></i></div>
      </div>
      <div class="lux-kpi-value <?= $preview['ad_insights_corrupt'] > 0 ? 'crit' : 'good' ?>"><?= $preview['ad_insights_corrupt'] ?></div>
      <div class="lux-kpi-sub">de <?= number_format($preview['ad_insights_total'], 0, ',', '.') ?> totais</div>
    </div>
  </div>
  <div class="lux-kpi">
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">Linhas corruptas (campanha)</span>
        <div class="lux-kpi-icon"><i class="bi bi-exclamation-octagon"></i></div>
      </div>
      <div class="lux-kpi-value <?= $preview['campaign_insights_corrupt'] > 0 ? 'crit' : 'good' ?>"><?= $preview['campaign_insights_corrupt'] ?></div>
      <div class="lux-kpi-sub">de <?= number_format($preview['campaign_insights_total'], 0, ',', '.') ?> totais</div>
    </div>
  </div>
  <div class="lux-kpi">
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">Total ad_insights</span>
        <div class="lux-kpi-icon"><i class="bi bi-database"></i></div>
      </div>
      <div class="lux-kpi-value sm"><?= number_format($preview['ad_insights_total'], 0, ',', '.') ?></div>
    </div>
  </div>
  <div class="lux-kpi">
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">Total campaign_insights</span>
        <div class="lux-kpi-icon"><i class="bi bi-database"></i></div>
      </div>
      <div class="lux-kpi-value sm"><?= number_format($preview['campaign_insights_total'], 0, ',', '.') ?></div>
    </div>
  </div>
</div>

<div class="lux-section">
  <h2 class="lux-section-title">Execução</h2>
</div>

<div class="lux-card">
  <div class="alert alert-warning" style="margin-bottom:18px">
    <i class="bi bi-exclamation-triangle-fill" style="font-size:14px;flex-shrink:0;margin-top:2px"></i>
    <div>
      <strong>O que este script faz:</strong>
      <ol style="margin:8px 0 0 0;padding-left:20px;line-height:1.7">
        <li>Deleta <code><?= $preview['ad_insights_corrupt'] ?></code> linhas de <code>ad_insights</code> com <code>date_start != date_stop</code> (totais agregados que inflam o SUM)</li>
        <li>Deleta <code><?= $preview['campaign_insights_corrupt'] ?></code> linhas de <code>campaign_insights</code> com o mesmo problema</li>
        <li>Re-coleta DIA A DIA os últimos <code>N</code> dias (configurável abaixo) para cada conta com <code>collect_insights = 1</code>, garantindo uma linha por dia</li>
      </ol>
      <div style="margin-top:8px">Os números do gerenciador da Meta passam a bater exatamente após esta operação. Tempo estimado: ~2 segundos por conta por dia.</div>
    </div>
  </div>

  <form method="post" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="confirm" value="1">
    <div>
      <label class="form-label" for="days">Re-coletar últimos N dias</label>
      <input id="days" type="number" name="days" min="1" max="90" value="<?= $days ?>" class="form-control" style="width:160px">
      <div class="form-hint">Recomendado: 30 dias. Máximo: 90.</div>
    </div>
    <button type="submit" class="btn btn-primary"
            onclick="return confirm('Confirma a limpeza e re-coleta? Esta operação é segura mas demora alguns minutos para muitas contas.')">
      <i class="bi bi-magic"></i> Executar limpeza e re-coleta
    </button>
  </form>
</div>

<?php else: ?>

<div class="lux-section">
  <h2 class="lux-section-title">Resultado</h2>
</div>

<div class="lux-grid lux-grid-4">
  <div class="lux-kpi">
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">Linhas deletadas (ad)</span>
        <div class="lux-kpi-icon"><i class="bi bi-trash"></i></div>
      </div>
      <div class="lux-kpi-value good"><?= number_format($result['deleted_ad'], 0, ',', '.') ?></div>
    </div>
  </div>
  <div class="lux-kpi">
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">Linhas deletadas (campanha)</span>
        <div class="lux-kpi-icon"><i class="bi bi-trash"></i></div>
      </div>
      <div class="lux-kpi-value good"><?= number_format($result['deleted_camp'], 0, ',', '.') ?></div>
    </div>
  </div>
  <div class="lux-kpi">
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">Linhas re-coletadas</span>
        <div class="lux-kpi-icon"><i class="bi bi-arrow-clockwise"></i></div>
      </div>
      <div class="lux-kpi-value sm"><?= number_format($result['recollected_rows'], 0, ',', '.') ?></div>
      <div class="lux-kpi-sub"><?= $result['accounts_processed'] ?> conta(s) · <?= $result['elapsed_seconds'] ?>s</div>
    </div>
  </div>
  <div class="lux-kpi">
    <div class="lux-kpi-content">
      <div class="lux-kpi-header">
        <span class="lux-kpi-label">Erros</span>
        <div class="lux-kpi-icon"><i class="bi bi-bug"></i></div>
      </div>
      <div class="lux-kpi-value <?= $result['errors'] > 0 ? 'crit' : 'good' ?>"><?= $result['errors'] ?></div>
    </div>
  </div>
</div>

<div class="lux-section">
  <h2 class="lux-section-title">Log de execução</h2>
</div>

<div class="lux-card">
  <div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
    <?php foreach ($result['log'] as $l): ?>
      <div style="display:flex;gap:10px;align-items:center">
        <i class="bi <?= $l['ok'] ? 'bi-check-circle' : 'bi-x-circle' ?>" style="color:<?= $l['ok'] ? '#4ADE80' : '#F87171' ?>;font-size:14px"></i>
        <span style="color:<?= $l['ok'] ? 'rgba(255,255,255,0.75)' : '#F87171' ?>"><?= e($l['msg']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div style="display:flex;gap:10px;margin-top:24px">
  <a href="<?= e(base_url('performance.php')) ?>" class="btn btn-primary">
    <i class="bi bi-graph-up-arrow"></i> Ver Performance
  </a>
  <a href="<?= e(base_url('fix_insights.php')) ?>" class="btn btn-secondary">
    <i class="bi bi-arrow-clockwise"></i> Recarregar diagnóstico
  </a>
</div>

<?php endif; ?>

<?php
$content = ob_get_clean();
require APP_DIR . '/views/layout.php';
