<?php
require_once __DIR__ . '/app/bootstrap.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = csrf_check($_POST['csrf'] ?? null);
    if (!$csrfOk && !empty($_SESSION['csrf'])) {
        $error = 'Token CSRF inválido. Recarregue a página e tente novamente.';
    } else {
        $u = trim($_POST['username'] ?? '');
        $p = $_POST['password'] ?? '';
        try {
            $row = Db::one('SELECT * FROM admin WHERE username = ?', [$u]);
        } catch (Throwable $e) {
            $row = null;
            $error = 'Banco de dados não inicializado. Rode install.php primeiro.';
        }
        if ($row && password_verify($p, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin'] = ['id' => (int) $row['id'], 'username' => $row['username']];
            Db::exec('UPDATE admin SET last_login_at = NOW() WHERE id = ?', [$row['id']]);
            header('Location: ' . base_url('index.php'));
            exit;
        }
        if (!$error) $error = 'Usuário ou senha inválidos.';
    }
}

$needAdmin = false;
try { $needAdmin = !Db::one('SELECT id FROM admin LIMIT 1'); } catch (Throwable $e) { $needAdmin = true; }

$title = 'Entrar · SALDO WEB';
ob_start();
?>
<div style="width:100%;max-width:400px">

  <div style="text-align:center;margin-bottom:32px">
    <div style="width:64px;height:64px;background:linear-gradient(135deg,#007AFF 0%,#5856D6 100%);border-radius:18px;display:inline-flex;align-items:center;justify-content:center;font-size:28px;box-shadow:0 8px 24px rgba(0,122,255,.3);margin-bottom:16px;color:#fff"><i class="bi bi-wallet2"></i></div>
    <h1 style="font-size:26px;font-weight:800;color:var(--text-1);letter-spacing:-.5px;margin:0 0 4px">SALDO WEB</h1>
    <p style="font-size:14px;color:var(--text-4);margin:0">Monitor de saldo Meta Ads</p>
  </div>

  <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);padding:32px">

    <?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:20px">
      <i class="bi bi-exclamation-octagon-fill"></i>
      <span><?= e($error) ?></span>
    </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="form-group">
        <label class="form-label" for="username">Usuário</label>
        <input type="text" id="username" name="username" class="form-control"
               placeholder="seu_usuario" required autofocus>
      </div>

      <div class="form-group" style="margin-bottom:24px">
        <label class="form-label" for="password">Senha</label>
        <input type="password" id="password" name="password" class="form-control"
               placeholder="senha" required>
      </div>

      <button type="submit" class="btn btn-primary btn-xl" style="width:100%">
        Entrar
      </button>
    </form>
  </div>

  <?php if ($needAdmin): ?>
  <div class="alert alert-warning" style="margin-top:16px;font-size:13px;display:flex;gap:10px;align-items:flex-start">
    <i class="bi bi-exclamation-triangle-fill" style="font-size:18px"></i>
    <div>Nenhum admin cadastrado. Execute <code style="font-family:monospace;background:rgba(0,0,0,.06);padding:1px 5px;border-radius:4px">php cron/create_admin.php</code> no terminal do cPanel ou rode <a href="<?= e(base_url('install.php')) ?>">install.php</a>.</div>
  </div>
  <?php endif; ?>

</div>
<?php
$content = ob_get_clean();
require APP_DIR . '/views/layout.php';
