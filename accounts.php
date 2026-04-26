<?php
require_once __DIR__ . '/app/bootstrap.php';
require_auth();

$action = $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = csrf_check($_POST['csrf'] ?? null);
    if (!$csrfOk && !empty($_SESSION['csrf'])) {
        flash('Token CSRF inválido. Recarregue e tente novamente.', 'warning');
        header('Location: accounts.php');
        exit;
    }

    if (($_POST['op'] ?? '') === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $adAccountId = preg_replace('/[^0-9]/', '', $_POST['ad_account_id'] ?? '');
        $data = [
            'client_id'               => (int) $_POST['client_id'],
            'ad_account_id'           => $adAccountId,
            'account_name'            => trim($_POST['account_name'] ?? '') ?: null,
            'account_type'            => in_array($_POST['account_type'] ?? '', ['prepaid','postpaid','unknown'], true) ? $_POST['account_type'] : 'unknown',
            'threshold_days_runway'   => (float) ($_POST['threshold_days_runway'] ?? 2),
            'threshold_spend_cap_pct' => (float) ($_POST['threshold_spend_cap_pct'] ?? 80),
            'active'                  => isset($_POST['active']) ? 1 : 0,
        ];
        try {
            if ($id > 0) {
                Db::update('meta_accounts', $data, 'id = ?', [$id]);
                flash('Conta atualizada.', 'success');
            } else {
                Db::insert('meta_accounts', $data);
                flash('Conta cadastrada.', 'success');
            }
        } catch (Throwable $e) {
            flash('Erro: ' . $e->getMessage(), 'danger');
        }
        header('Location: accounts.php');
        exit;
    }

    if (($_POST['op'] ?? '') === 'delete') {
        Db::exec('DELETE FROM meta_accounts WHERE id = ?', [(int) $_POST['id']]);
        flash('Conta removida.', 'success');
        header('Location: accounts.php');
        exit;
    }

    if (($_POST['op'] ?? '') === 'import') {
        try {
            $meta = MetaAdsClient::fromSettings();
            $list = $meta->listAdAccounts();
            $imported = 0;
            $clientId = (int) $_POST['client_id'];
            foreach ($list as $row) {
                $existing = Db::one('SELECT id FROM meta_accounts WHERE ad_account_id = ?', [$row['id']]);
                if ($existing) continue;
                Db::insert('meta_accounts', [
                    'client_id'     => $clientId,
                    'ad_account_id' => $row['id'],
                    'account_name'  => $row['name'],
                    'currency'      => $row['currency'],
                    'account_type'  => 'unknown',
                    'active'        => 0,
                ]);
                $imported++;
            }
            flash("Importadas {$imported} conta(s) — ative as que quiser monitorar.", 'success');
        } catch (Throwable $e) {
            flash('Erro ao importar: ' . $e->getMessage(), 'danger');
        }
        header('Location: accounts.php');
        exit;
    }
}

$editing = null;
if ($action === 'edit' && !empty($_GET['id'])) {
    $editing = Db::one('SELECT * FROM meta_accounts WHERE id = ?', [(int) $_GET['id']]);
}
if ($action === 'new') {
    $editing = [
        'id' => 0, 'client_id' => 0, 'ad_account_id' => '', 'account_name' => '',
        'account_type' => 'unknown', 'threshold_days_runway' => 2,
        'threshold_spend_cap_pct' => 80, 'active' => 1,
    ];
}

$clients = Db::all('SELECT id, name FROM clients WHERE active = 1 ORDER BY name');
$accounts = Db::all(
    'SELECT ma.*, c.name AS client_name
     FROM meta_accounts ma JOIN clients c ON c.id = ma.client_id
     ORDER BY c.name, ma.account_name'
);

$fbConnected   = (bool) Db::getSetting('fb_user_access_token');
$fbAppReady    = (bool) (Db::getSetting('fb_app_id') && Db::getSetting('fb_app_secret'));
$fbExpiresAt   = (int) (Db::getSetting('fb_user_token_expires_at') ?: 0);

$title = 'Contas Meta · SALDO WEB';
ob_start();
?>

<?php if ($editing): ?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= $editing['id'] ? 'Editar Conta' : 'Nova Conta Meta' ?></h1>
    <p class="page-subtitle">Configure os thresholds de monitoramento</p>
  </div>
  <a href="accounts.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Voltar</a>
</div>

<div class="card" style="max-width:700px">
  <div class="card-header">
    <span class="card-header-title">Dados da conta</span>
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="op" value="save">
      <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">

      <div class="form-group">
        <label class="form-label" for="client_id">Cliente</label>
        <?php if (empty($clients)): ?>
          <div style="background:var(--orange-bg);border:1px solid var(--orange);border-radius:var(--radius-sm);padding:12px 16px;font-size:13px;color:var(--text-2)">
            <strong>⚠️ Nenhum cliente cadastrado.</strong><br>
            Crie um cliente primeiro antes de adicionar uma conta Meta.<br>
            <a href="<?= e(base_url('clients.php')) ?>?action=new" class="btn btn-primary btn-sm" style="margin-top:10px">
              <i class="bi bi-plus-lg"></i> Criar cliente agora
            </a>
          </div>
          <input type="hidden" name="client_id" value="0">
        <?php else: ?>
          <select id="client_id" name="client_id" class="form-select" required>
            <option value="">— selecione —</option>
            <?php foreach ($clients as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= $editing['client_id']==$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label" for="ad_account_id">ID da conta de anúncios</label>
          <input id="ad_account_id" name="ad_account_id" class="form-control" style="font-family:monospace"
                 placeholder="123456789012345"
                 value="<?= e($editing['ad_account_id']) ?>" required>
          <div class="form-hint">Sem o prefixo <code>act_</code></div>
        </div>
        <div class="form-group">
          <label class="form-label" for="account_name">Nome (opcional)</label>
          <input id="account_name" name="account_name" class="form-control"
                 placeholder="Ex: Conta Principal"
                 value="<?= e($editing['account_name']) ?>">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:18px">
        <div class="form-group" style="margin:0">
          <label class="form-label">Tipo de conta</label>
          <select name="account_type" class="form-select">
            <option value="unknown" <?= $editing['account_type']==='unknown'?'selected':'' ?>>Detectar auto</option>
            <option value="prepaid" <?= $editing['account_type']==='prepaid'?'selected':'' ?>>Pré-pago</option>
            <option value="postpaid" <?= $editing['account_type']==='postpaid'?'selected':'' ?>>Pós-pago</option>
          </select>
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label">Runway mínimo (dias)</label>
          <input type="number" step="0.1" min="0.1" name="threshold_days_runway" class="form-control"
                 value="<?= e((string) $editing['threshold_days_runway']) ?>">
          <div class="form-hint">Pré-pago</div>
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label">Alerta no % do limite</label>
          <input type="number" step="1" min="1" max="100" name="threshold_spend_cap_pct" class="form-control"
                 value="<?= e((string) $editing['threshold_spend_cap_pct']) ?>">
          <div class="form-hint">Pós-pago</div>
        </div>
      </div>

      <div class="form-group" style="margin-bottom:24px">
        <label class="form-check">
          <input type="checkbox" name="active" class="form-check-input" <?= $editing['active']?'checked':'' ?>>
          <span class="form-check-label">Monitorar esta conta</span>
        </label>
      </div>

      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Salvar</button>
        <a href="accounts.php" class="btn btn-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?php else: ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Contas Meta Ads</h1>
    <p class="page-subtitle"><?= count($accounts) ?> conta<?= count($accounts) !== 1 ? 's' : '' ?> cadastrada<?= count($accounts) !== 1 ? 's' : '' ?></p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <?php if (!$fbAppReady): ?>
      <a href="settings.php" class="btn btn-warning"><i class="bi bi-gear"></i> Configurar Facebook App</a>
    <?php elseif (!$fbConnected): ?>
      <a href="oauth_fb.php?start=1" class="btn btn-primary" style="background:#1877F2;border-color:#1877F2">
        <i class="bi bi-facebook"></i> Conectar Facebook
      </a>
    <?php else: ?>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#importModal">
        <i class="bi bi-cloud-download"></i> Importar contas
      </button>
      <a href="oauth_fb.php?start=1" class="btn btn-secondary" title="Reautorizar">
        <i class="bi bi-arrow-repeat"></i> Reconectar
      </a>
    <?php endif; ?>
    <a href="accounts.php?action=new" class="btn btn-secondary"><i class="bi bi-plus-lg"></i> Manual</a>
  </div>
</div>

<?php if ($fbConnected): ?>
  <div class="alert alert-success" style="margin-bottom:16px;display:flex;align-items:center;gap:10px">
    <i class="bi bi-check-circle-fill"></i>
    <span>Facebook conectado.
      <?php if ($fbExpiresAt > 0): ?>
        Token expira em <?= e(date('d/m/Y', $fbExpiresAt)) ?>.
      <?php endif; ?>
    </span>
  </div>
<?php elseif (!$fbAppReady): ?>
  <div class="alert alert-info" style="margin-bottom:16px">
    <i class="bi bi-info-circle"></i> Para conectar contas via OAuth oficial, configure o App ID e App Secret do Facebook em <a href="settings.php">Configurações</a>.
  </div>
<?php endif; ?>

<?php if (empty($accounts)): ?>
<div class="empty-state">
  <div class="empty-icon"><i class="bi bi-link-45deg" style="font-size:32px;color:var(--text-4)"></i></div>
  <div class="empty-title">Nenhuma conta cadastrada</div>
  <div class="empty-desc">Conecte o Facebook e importe as contas do Business Manager, ou adicione manualmente.</div>
  <div style="display:flex;gap:10px;margin-top:8px">
    <?php if ($fbConnected): ?>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#importModal"><i class="bi bi-cloud-download"></i> Importar contas</button>
    <?php elseif ($fbAppReady): ?>
      <a href="oauth_fb.php?start=1" class="btn btn-primary" style="background:#1877F2;border-color:#1877F2"><i class="bi bi-facebook"></i> Conectar Facebook</a>
    <?php endif; ?>
    <a href="accounts.php?action=new" class="btn btn-secondary">Adicionar manualmente</a>
  </div>
</div>
<?php else: ?>
<div class="card">
  <div class="table-wrap" style="border:none;border-radius:0;box-shadow:none">
    <table>
      <thead>
        <tr>
          <th>Cliente</th>
          <th>Conta</th>
          <th>Tipo</th>
          <th>Moeda</th>
          <th>Thresholds</th>
          <th style="text-align:center">Status</th>
          <th style="text-align:right">Ações</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($accounts as $a): ?>
        <tr>
          <td class="td-primary"><?= e($a['client_name']) ?></td>
          <td>
            <div style="font-weight:600;color:var(--text-1)"><?= e($a['account_name'] ?: '—') ?></div>
            <div class="td-mono">act_<?= e($a['ad_account_id']) ?></div>
          </td>
          <td><span class="badge badge-<?= e($a['account_type']) ?>"><?= e($a['account_type']) ?></span></td>
          <td style="color:var(--text-3)"><?= e($a['currency'] ?: '—') ?></td>
          <td>
            <div style="font-size:12px;color:var(--text-4);line-height:1.7">
              <div><?= e((string) $a['threshold_days_runway']) ?>d runway</div>
              <div><?= e((string) $a['threshold_spend_cap_pct']) ?>% cap</div>
            </div>
          </td>
          <td style="text-align:center">
            <?php if ($a['active']): ?>
              <span class="status-dot status-active">Ativo</span>
            <?php else: ?>
              <span class="status-dot status-paused">Pausado</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right">
            <div style="display:flex;gap:6px;justify-content:flex-end">
              <a href="accounts.php?action=edit&id=<?= (int) $a['id'] ?>" class="btn btn-sm btn-secondary"><i class="bi bi-pencil"></i></a>
              <form method="post" onsubmit="return confirm('Remover esta conta?')">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="op" value="delete">
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <button class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="op" value="import">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-cloud-download"></i> Importar do Business Manager</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p style="font-size:13px;color:var(--text-3);margin-bottom:16px">Lista todas as contas de anúncios acessíveis pela conta Facebook conectada e cadastra as novas (desativadas para revisão).</p>
        <div class="form-group">
          <label class="form-label">Cliente padrão</label>
          <select name="client_id" class="form-select" required>
            <option value="">— selecione —</option>
            <?php foreach ($clients as $c): ?>
              <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-hint">Você pode alterar o cliente de cada conta depois, na edição individual.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary">Importar contas</button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>
<?php
$content = ob_get_clean();
require APP_DIR . '/views/layout.php';
