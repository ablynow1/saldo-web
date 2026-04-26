<?php
require_once __DIR__ . '/app/bootstrap.php';
require_auth();

$action = $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = csrf_check($_POST['csrf'] ?? null);
    if (!$csrfOk && !empty($_SESSION['csrf'])) {
        flash('Token CSRF inválido. Recarregue e tente novamente.', 'warning');
        header('Location: clients.php');
        exit;
    }

    if (($_POST['op'] ?? '') === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $data = [
            'name'                => trim($_POST['name'] ?? ''),
            'whatsapp_group_jid'  => trim($_POST['whatsapp_group_jid'] ?? '') ?: null,
            'whatsapp_group_name' => trim($_POST['whatsapp_group_name'] ?? '') ?: null,
            'active'              => isset($_POST['active']) ? 1 : 0,
        ];
        if ($data['name'] === '') {
            flash('Nome obrigatório.', 'danger');
        } elseif ($id > 0) {
            Db::update('clients', $data, 'id = ?', [$id]);
            flash('Cliente atualizado.', 'success');
        } else {
            Db::insert('clients', $data);
            flash('Cliente criado.', 'success');
        }
        header('Location: clients.php');
        exit;
    }
    if (($_POST['op'] ?? '') === 'delete') {
        Db::exec('DELETE FROM clients WHERE id = ?', [(int) $_POST['id']]);
        flash('Cliente removido.', 'success');
        header('Location: clients.php');
        exit;
    }
}

$editing = null;
if ($action === 'edit' && !empty($_GET['id'])) {
    $editing = Db::one('SELECT * FROM clients WHERE id = ?', [(int) $_GET['id']]);
}
if ($action === 'new') {
    $editing = ['id' => 0, 'name' => '', 'whatsapp_group_jid' => '', 'whatsapp_group_name' => '', 'active' => 1];
}

$groups = [];
if ($editing) {
    try {
        $wa = WhatsAppClient::fromSettings();
        $groups = $wa->listGroups();
    } catch (Throwable $e) {}
}

$clients = Db::all('SELECT c.*, (SELECT COUNT(*) FROM meta_accounts ma WHERE ma.client_id = c.id) AS accounts_count FROM clients c ORDER BY c.name');

$title = 'Clientes · SALDO WEB';
ob_start();
?>

<?php if ($editing): ?>

<div class="page-header">
  <div>
    <h1 class="page-title"><?= $editing['id'] ? 'Editar Cliente' : 'Novo Cliente' ?></h1>
    <p class="page-subtitle">Gerencie o grupo de WhatsApp e configurações do cliente</p>
  </div>
  <a href="<?= e(base_url('clients.php')) ?>" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Voltar</a>
</div>

<div class="card" style="max-width:640px">
  <div class="card-header">
    <span class="card-header-title"><?= $editing['id'] ? 'Dados do cliente' : 'Criar novo cliente' ?></span>
  </div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="op" value="save">
      <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">

      <div class="form-group">
        <label class="form-label" for="name">Nome do cliente</label>
        <input id="name" name="name" class="form-control" placeholder="Ex: Empresa XYZ"
               value="<?= e($editing['name']) ?>" required>
      </div>

      <div class="form-group">
        <label class="form-label">Grupo de WhatsApp</label>
        <?php if (!empty($groups)): ?>
          <select name="whatsapp_group_jid" id="grp" class="form-select">
            <option value="">— sem grupo —</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= e($g['id']) ?>" data-name="<?= e($g['subject']) ?>"
                <?= $editing['whatsapp_group_jid'] === $g['id'] ? 'selected' : '' ?>>
                <?= e($g['subject']) ?> <?= $g['size'] ? "({$g['size']} membros)" : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" name="whatsapp_group_name" id="grpName" value="<?= e($editing['whatsapp_group_name']) ?>">
          <div class="form-hint">Selecione o grupo onde os alertas serão enviados.</div>
          <script>
            document.getElementById('grp').addEventListener('change', function(e){
              var o = e.target.selectedOptions[0];
              document.getElementById('grpName').value = o ? (o.dataset.name || '') : '';
            });
          </script>
        <?php else: ?>
          <input name="whatsapp_group_jid" class="form-control" style="font-family:monospace"
                 placeholder="120363xxxxxxxxxxx@g.us"
                 value="<?= e($editing['whatsapp_group_jid']) ?>">
          <input name="whatsapp_group_name" class="form-control" style="margin-top:8px"
                 placeholder="Nome do grupo (opcional)"
                 value="<?= e($editing['whatsapp_group_name']) ?>">
          <div class="form-hint"><i class="bi bi-exclamation-triangle"></i> Evolution API não configurada — informe o JID do grupo manualmente.</div>
        <?php endif; ?>
      </div>

      <div class="form-group" style="margin-bottom:24px">
        <label class="form-check">
          <input type="checkbox" name="active" class="form-check-input" <?= $editing['active'] ? 'checked' : '' ?>>
          <span class="form-check-label">Cliente ativo</span>
        </label>
      </div>

      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Salvar</button>
        <a href="<?= e(base_url('clients.php')) ?>" class="btn btn-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?php else: ?>

<div class="page-header">
  <div>
    <h1 class="page-title">Clientes</h1>
    <p class="page-subtitle"><?= count($clients) ?> cliente<?= count($clients) !== 1 ? 's' : '' ?> cadastrado<?= count($clients) !== 1 ? 's' : '' ?></p>
  </div>
  <a href="<?= e(base_url('clients.php')) ?>?action=new" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Novo cliente</a>
</div>

<?php if (empty($clients)): ?>
<div class="empty-state">
  <div class="empty-icon"><i class="bi bi-people-fill" style="font-size:32px;color:var(--text-4)"></i></div>
  <div class="empty-title">Nenhum cliente ainda</div>
  <div class="empty-desc">Crie um cliente para associar contas Meta Ads e grupos de WhatsApp.</div>
  <a href="<?= e(base_url('clients.php')) ?>?action=new" class="btn btn-primary" style="margin-top:4px">Criar primeiro cliente</a>
</div>
<?php else: ?>
<div class="card">
  <div class="table-wrap" style="border:none;border-radius:0;box-shadow:none">
    <table>
      <thead>
        <tr>
          <th>Nome</th>
          <th>Grupo WhatsApp</th>
          <th style="text-align:center">Contas</th>
          <th style="text-align:center">Status</th>
          <th style="text-align:right">Ações</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($clients as $c): ?>
        <tr>
          <td class="td-primary"><?= e($c['name']) ?></td>
          <td>
            <?php if ($c['whatsapp_group_name'] || $c['whatsapp_group_jid']): ?>
              <div style="font-size:13px;color:var(--text-2)"><i class="bi bi-people"></i> <?= e($c['whatsapp_group_name'] ?: '—') ?></div>
              <div class="td-mono"><?= e($c['whatsapp_group_jid'] ?: '') ?></div>
            <?php else: ?>
              <span style="color:var(--text-5);font-size:13px">Não configurado</span>
            <?php endif; ?>
          </td>
          <td style="text-align:center">
            <span class="badge badge-blue"><?= (int) $c['accounts_count'] ?> conta<?= (int)$c['accounts_count'] !== 1 ? 's' : '' ?></span>
          </td>
          <td style="text-align:center">
            <?php if ($c['active']): ?>
              <span class="status-dot status-active">Ativo</span>
            <?php else: ?>
              <span class="status-dot status-paused">Inativo</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right">
            <div style="display:flex;gap:6px;justify-content:flex-end">
              <a href="<?= e(base_url('clients.php')) ?>?action=edit&id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-secondary"><i class="bi bi-pencil"></i></a>
              <form method="post" onsubmit="return confirm('Remover cliente e suas contas?')">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="op" value="delete">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
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
<?php endif; ?>

<?php
$content = ob_get_clean();
require APP_DIR . '/views/layout.php';
