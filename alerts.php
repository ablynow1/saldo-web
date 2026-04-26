<?php
require_once __DIR__ . '/app/bootstrap.php';
require_auth();

$alerts = [];
try {
    if (table_exists('alerts_log')) {
        $alerts = Db::all(
            'SELECT a.*, ma.account_name, ma.ad_account_id, c.name AS client_name
             FROM alerts_log a
             JOIN meta_accounts ma ON ma.id = a.meta_account_id
             JOIN clients c ON c.id = ma.client_id
             ORDER BY a.sent_at DESC LIMIT 200'
        );
    }
} catch (Throwable $e) {
    $alerts = [];
    flash('Falha ao carregar alertas: ' . $e->getMessage(), 'warning');
}

$alertMeta = [
    'low_balance'     => ['icon' => 'bi-cash-coin',           'color' => 'badge-orange', 'label' => 'Saldo baixo'],
    'spend_cap_near'  => ['icon' => 'bi-exclamation-triangle','color' => 'badge-orange', 'label' => 'Limite próximo'],
    'account_blocked' => ['icon' => 'bi-slash-circle',        'color' => 'badge-red',    'label' => 'Conta bloqueada'],
    'no_funding'      => ['icon' => 'bi-credit-card-2-front', 'color' => 'badge-red',    'label' => 'Sem pagamento'],
    'roas_drop'       => ['icon' => 'bi-graph-down-arrow',    'color' => 'badge-orange', 'label' => 'Queda de ROAS'],
    'cpa_spike'       => ['icon' => 'bi-currency-dollar',     'color' => 'badge-red',    'label' => 'CPA alto'],
    'ctr_drop'        => ['icon' => 'bi-hand-thumbs-down',    'color' => 'badge-orange', 'label' => 'CTR caiu'],
    'ad_disapproved'  => ['icon' => 'bi-x-octagon',           'color' => 'badge-red',    'label' => 'Ad reprovado'],
    'budget_overpace' => ['icon' => 'bi-fire',                'color' => 'badge-red',    'label' => 'Overpace'],
];

$title = 'Alertas · SALDO WEB';
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Histórico de Alertas</h1>
    <p class="page-subtitle"><?= count($alerts) ?> alerta<?= count($alerts) !== 1 ? 's' : '' ?> nos registros</p>
  </div>
</div>

<?php if (empty($alerts)): ?>
<div class="empty-state">
  <div class="empty-icon"><i class="bi bi-bell-slash" style="font-size:32px;color:var(--text-4)"></i></div>
  <div class="empty-title">Nenhum alerta ainda</div>
  <div class="empty-desc">Os alertas disparados para os clientes aparecerão aqui com o histórico completo.</div>
</div>
<?php else: ?>

<div class="card">
  <div class="table-wrap" style="border:none;border-radius:0;box-shadow:none">
    <table>
      <thead>
        <tr>
          <th>Quando</th>
          <th>Cliente</th>
          <th>Conta</th>
          <th>Tipo</th>
          <th>Severidade</th>
          <th style="text-align:center">Entrega</th>
          <th style="text-align:right">Detalhe</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($alerts as $a):
        $am = $alertMeta[$a['alert_type']] ?? ['icon' => 'bi-info-circle', 'color' => 'badge-gray', 'label' => $a['alert_type']];
        $sev = $a['severity'] ?? 'info';
        $sevCls = $sev === 'critical' ? 'badge-red' : ($sev === 'warning' ? 'badge-orange' : 'badge-gray');
      ?>
        <tr>
          <td class="td-mono" style="white-space:nowrap">
            <?= e(date('d/m', strtotime($a['sent_at']))) ?>
            <span style="color:var(--text-4)"> <?= e(date('H:i', strtotime($a['sent_at']))) ?></span>
          </td>
          <td class="td-primary"><?= e($a['client_name']) ?></td>
          <td>
            <div style="font-size:13px;color:var(--text-2)"><?= e($a['account_name'] ?: '—') ?></div>
            <div class="td-mono"><?= e($a['ad_account_id']) ?></div>
          </td>
          <td>
            <span class="badge <?= $am['color'] ?>"><i class="bi <?= $am['icon'] ?>"></i> <?= e($am['label']) ?></span>
          </td>
          <td>
            <span class="badge <?= $sevCls ?>"><?= e($sev) ?></span>
          </td>
          <td style="text-align:center;font-size:18px">
            <?= !empty($a['sent_ok']) ? '<i class="bi bi-check-circle-fill" style="color:#34c759"></i>' : '<i class="bi bi-x-circle-fill" style="color:#ff3b30"></i>' ?>
          </td>
          <td style="text-align:right">
            <button class="btn btn-sm btn-secondary"
                    data-bs-toggle="modal" data-bs-target="#m<?= (int) $a['id'] ?>">
              Ver msg
            </button>

            <div class="modal fade" id="m<?= (int) $a['id'] ?>" tabindex="-1">
              <div class="modal-dialog modal-lg">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title"><i class="bi <?= $am['icon'] ?>"></i> <?= e($am['label']) ?> — <?= e($a['client_name']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <div style="background:var(--surface-3);border-radius:var(--radius);padding:16px;font-family:monospace;font-size:13px;white-space:pre-wrap;line-height:1.6"><?= e($a['message'] ?? '') ?></div>
                    <?php if (!empty($a['provider_response'])): ?>
                      <div style="margin-top:16px">
                        <div style="font-size:11px;font-weight:700;color:var(--text-4);letter-spacing:.5px;text-transform:uppercase;margin-bottom:8px">Resposta do provedor</div>
                        <div style="background:var(--surface-3);border-radius:var(--radius);padding:12px;font-family:monospace;font-size:12px;color:var(--text-3);white-space:pre-wrap"><?= e(substr((string) $a['provider_response'], 0, 800)) ?></div>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require APP_DIR . '/views/layout.php';
