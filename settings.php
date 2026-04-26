<?php
require_once __DIR__ . '/app/bootstrap.php';
require_auth();

$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = csrf_check($_POST['csrf'] ?? null);
    if (!$csrfOk && !empty($_SESSION['csrf'])) {
        flash('Token CSRF inválido. Recarregue e tente novamente.', 'warning');
        header('Location: settings.php');
        exit;
    }
    $op = $_POST['op'] ?? '';

    if ($op === 'save') {
        $token = trim($_POST['meta_system_user_token'] ?? '');
        if ($token !== '' && $token !== '***') {
            Db::setSetting('meta_system_user_token', $token, true);
        }
        Db::setSetting('meta_api_version', trim($_POST['meta_api_version'] ?? 'v19.0'));

        // Facebook App (OAuth oficial)
        Db::setSetting('fb_app_id', trim($_POST['fb_app_id'] ?? ''));
        $fbSecret = trim($_POST['fb_app_secret'] ?? '');
        if ($fbSecret !== '' && $fbSecret !== '***') {
            Db::setSetting('fb_app_secret', $fbSecret, true);
        }

        Db::setSetting('evolution_base_url', trim($_POST['evolution_base_url'] ?? ''));
        $evoKey = trim($_POST['evolution_api_key'] ?? '');
        if ($evoKey !== '' && $evoKey !== '***') {
            Db::setSetting('evolution_api_key', $evoKey, true);
        }
        Db::setSetting('evolution_instance', trim($_POST['evolution_instance'] ?? ''));
        Db::setSetting('admin_whatsapp', preg_replace('/\D/', '', $_POST['admin_whatsapp'] ?? ''));
        Db::setSetting('report_approval', isset($_POST['report_approval']) ? '1' : '0');
        Db::setSetting('site_url', trim($_POST['site_url'] ?? ''));
        Db::setSetting('alert_cooldown_hours', (string) max(1, (int) ($_POST['alert_cooldown_hours'] ?? 6)));
        flash('Configurações salvas com sucesso.', 'success');
        header('Location: settings.php');
        exit;
    }

    if ($op === 'test_meta') {
        try {
            $c = MetaAdsClient::fromSettings();
            $list = $c->listAdAccounts();
            $testResult = ['type' => 'success', 'text' => 'Meta OK — ' . count($list) . ' conta(s) acessível(is).'];
        } catch (Throwable $e) {
            $testResult = ['type' => 'danger', 'text' => 'Meta: ' . $e->getMessage()];
        }
    }

    if ($op === 'test_wa') {
        try {
            $c = WhatsAppClient::fromSettings();
            $st = $c->connectionStatus();
            $ok = $st['ok'] && in_array(strtolower($st['state'] ?? ''), ['open', 'connected']);
            $testResult = [
                'type' => $ok ? 'success' : 'warning',
                'text' => 'Evolution: state = ' . ($st['state'] ?? 'n/a'),
            ];
        } catch (Throwable $e) {
            $testResult = ['type' => 'danger', 'text' => 'Evolution: ' . $e->getMessage()];
        }
    }

    if ($op === 'test_wa_send') {
        $jid = trim($_POST['test_jid'] ?? '');
        try {
            $c = WhatsAppClient::fromSettings();
            $r = $c->sendText($jid, 'Teste SALDO WEB — ' . date('d/m/Y H:i'));
            $testResult = [
                'type' => $r['ok'] ? 'success' : 'danger',
                'text' => ($r['ok'] ? 'Mensagem enviada' : 'Falha') . ' — HTTP ' . $r['status'],
            ];
        } catch (Throwable $e) {
            $testResult = ['type' => 'danger', 'text' => $e->getMessage()];
        }
    }
}

$token     = Db::getSetting('meta_system_user_token');
$evoKey    = Db::getSetting('evolution_api_key');
$fbAppId   = Db::getSetting('fb_app_id');
$fbSecret  = Db::getSetting('fb_app_secret');

// URL de redirect que o usuário precisa cadastrar no painel do Facebook Dev
$schema   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$redirect = $schema . '://' . $host . BASE_URL . '/oauth_fb.php';

$title = 'Configurações · SALDO WEB';
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">Configurações</h1>
    <p class="page-subtitle">Integrações Meta Ads, WhatsApp e alertas</p>
  </div>
</div>

<?php if ($testResult): ?>
<div class="alert alert-<?= e($testResult['type']) ?>" style="margin-bottom:20px">
  <span><?= e($testResult['text']) ?></span>
</div>
<?php endif; ?>

<form method="post">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="op" value="save">

  <!-- Facebook App (OAuth oficial) -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-header">
      <span class="card-header-title"><i class="bi bi-facebook"></i> Facebook App (OAuth oficial)</span>
      <span style="font-size:12px;color:var(--text-4)">developers.facebook.com</span>
    </div>
    <div class="card-body">
      <div class="alert alert-info" style="margin-bottom:16px;font-size:13px">
        Crie um app em <strong>developers.facebook.com</strong> &rarr; adicione o produto <strong>Facebook Login</strong> e
        cadastre o Valid OAuth Redirect URI abaixo. Depois cole App ID e App Secret aqui.
        <div style="margin-top:8px"><strong>Redirect URI:</strong>
          <code style="user-select:all"><?= e($redirect) ?></code>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group" style="margin:0">
          <label class="form-label" for="fb_app_id">App ID</label>
          <input id="fb_app_id" name="fb_app_id" class="form-control" style="font-family:monospace"
                 value="<?= e($fbAppId ?: '') ?>" placeholder="1234567890123456">
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label" for="fb_app_secret">App Secret</label>
          <input id="fb_app_secret" name="fb_app_secret" class="form-control" style="font-family:monospace"
                 placeholder="<?= $fbSecret ? '••••••• (deixe em branco para manter)' : 'xxxxxxxxxxxxx' ?>">
        </div>
      </div>
    </div>
  </div>

  <!-- Meta Ads System User Token (legado / fallback) -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-header">
      <span class="card-header-title"><i class="bi bi-key-fill"></i> Meta Ads API — System User Token</span>
      <span style="font-size:12px;color:var(--text-4)">Opcional (fallback)</span>
    </div>
    <div class="card-body">
      <div class="form-group">
        <label class="form-label" for="meta_token">System User Token</label>
        <input id="meta_token" name="meta_system_user_token" class="form-control" style="font-family:monospace"
               placeholder="<?= $token ? '••••••• (deixe em branco para manter o atual)' : 'EAAxxxxxx...' ?>">
        <div class="form-hint">Use só se não for usar OAuth. Escopos: <code>ads_read</code>, <code>business_management</code>, <code>read_insights</code>.</div>
      </div>
      <div style="max-width:180px">
        <label class="form-label" for="meta_api_version">Versão da API</label>
        <input id="meta_api_version" name="meta_api_version" class="form-control"
               value="<?= e(Db::getSetting('meta_api_version') ?: 'v19.0') ?>">
      </div>
    </div>
  </div>

  <!-- WhatsApp -->
  <div class="card" style="margin-bottom:16px">
    <div class="card-header">
      <span class="card-header-title"><i class="bi bi-whatsapp"></i> WhatsApp — Evolution API</span>
      <span style="font-size:12px;color:var(--text-4)">Self-hosted</span>
    </div>
    <div class="card-body">
      <div class="form-group">
        <label class="form-label" for="evo_url">Base URL</label>
        <input id="evo_url" name="evolution_base_url" class="form-control" style="font-family:monospace"
               placeholder="https://evo.seudominio.com"
               value="<?= e(Db::getSetting('evolution_base_url') ?: '') ?>">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group" style="margin:0">
          <label class="form-label" for="evo_key">API Key</label>
          <input id="evo_key" name="evolution_api_key" class="form-control" style="font-family:monospace"
                 placeholder="<?= $evoKey ? '••••••• (deixe em branco para manter)' : 'sua_api_key' ?>">
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label" for="evo_instance">Nome da instância</label>
          <input id="evo_instance" name="evolution_instance" class="form-control"
                 placeholder="saldoweb"
                 value="<?= e(Db::getSetting('evolution_instance') ?: '') ?>">
        </div>
      </div>
    </div>
  </div>

  <!-- Alertas -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <span class="card-header-title"><i class="bi bi-bell"></i> Configuração de Alertas</span>
    </div>
    <div class="card-body">
      <div style="max-width:220px">
        <label class="form-label" for="cooldown">Cooldown entre alertas (horas)</label>
        <input id="cooldown" type="number" min="1" name="alert_cooldown_hours" class="form-control"
               value="<?= e(Db::getSetting('alert_cooldown_hours') ?: '6') ?>">
        <div class="form-hint">Evita spam — mesmo tipo de alerta só é enviado novamente após este intervalo.</div>
      </div>
    </div>
  </div>

  <!-- Aprovação de Relatórios -->
  <div class="card" style="margin-bottom:20px">
    <div class="card-header">
      <span class="card-header-title"><i class="bi bi-shield-check"></i> Aprovação de Relatórios</span>
    </div>
    <div class="card-body">
      <div class="form-group">
        <label class="form-check" style="margin-bottom:16px">
          <input type="checkbox" name="report_approval" class="form-check-input"
                 <?= Db::getSetting('report_approval') !== '0' ? 'checked' : '' ?>>
          <span class="form-check-label" style="font-weight:600">Ativar aprovação antes de enviar ao cliente</span>
        </label>
        <div style="font-size:13px;color:var(--text-3);margin-bottom:16px;padding-left:28px">
          Antes de enviar ao grupo do cliente, o sistema manda uma prévia no seu WhatsApp com links <strong>Aprovar / Cancelar</strong>.
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group" style="margin:0">
          <label class="form-label">Seu WhatsApp (recebe as aprovações)</label>
          <input name="admin_whatsapp" class="form-control" style="font-family:monospace"
                 placeholder="11999998888"
                 value="<?= e(Db::getSetting('admin_whatsapp') ?: '') ?>">
          <div class="form-hint">DDD + número, sem 55. Ex: 11999998888</div>
        </div>
        <div class="form-group" style="margin:0">
          <label class="form-label">URL do site (para os links de aprovação)</label>
          <input name="site_url" class="form-control"
                 placeholder="https://lventerprise.com.br/saldoweb"
                 value="<?= e(Db::getSetting('site_url') ?: 'https://lventerprise.com.br/saldoweb') ?>">
          <div class="form-hint">Usado para montar o link de aprovação no WhatsApp.</div>
        </div>
      </div>
    </div>
  </div>

  <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-save"></i> Salvar configurações</button>
</form>

<!-- Ferramentas de teste -->
<div style="margin-top:32px;margin-bottom:8px">
  <div style="font-size:11px;font-weight:700;color:var(--text-4);letter-spacing:.7px;text-transform:uppercase;margin-bottom:12px">Ferramentas de teste</div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px">

    <div class="card">
      <div class="card-body">
        <div style="font-size:14px;font-weight:700;color:var(--text-1);margin-bottom:6px">Testar Meta Ads</div>
        <div style="font-size:12.5px;color:var(--text-4);margin-bottom:14px">Lista as contas acessíveis pelo token configurado.</div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="op" value="test_meta">
          <button class="btn btn-secondary btn-sm"><i class="bi bi-plug"></i> Testar conexão Meta</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <div style="font-size:14px;font-weight:700;color:var(--text-1);margin-bottom:6px">Testar WhatsApp</div>
        <div style="font-size:12.5px;color:var(--text-4);margin-bottom:14px">Consulta o estado da instância Evolution.</div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="op" value="test_wa">
          <button class="btn btn-secondary btn-sm"><i class="bi bi-broadcast"></i> Testar instância</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <div style="font-size:14px;font-weight:700;color:var(--text-1);margin-bottom:6px">Enviar mensagem de teste</div>
        <div style="font-size:12.5px;color:var(--text-4);margin-bottom:14px">Envia um texto para um número ou grupo.</div>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="op" value="test_wa_send">
          <div style="display:flex;gap:0">
            <input name="test_jid" class="form-control form-control-sm"
                   style="border-radius:var(--radius-xs) 0 0 var(--radius-xs);font-family:monospace"
                   placeholder="5511999999999 ou 120363xxx@g.us">
            <button class="btn btn-primary btn-sm" style="border-radius:0 var(--radius-xs) var(--radius-xs) 0;white-space:nowrap">Enviar</button>
          </div>
        </form>
      </div>
    </div>

  </div>
</div>

<?php
$content = ob_get_clean();
require APP_DIR . '/views/layout.php';
