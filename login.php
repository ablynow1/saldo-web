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

<style>
.login-wrap {
  width: 100%;
  max-width: 400px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 24px;
  animation: loginFadeIn 0.4s cubic-bezier(0.16,1,0.3,1);
}
@keyframes loginFadeIn {
  from { opacity: 0; transform: translateY(16px); }
  to   { opacity: 1; transform: translateY(0); }
}
.login-brand {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14px;
  text-align: center;
}
.login-icon {
  width: 60px;
  height: 60px;
  background: #fff;
  border-radius: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 26px;
  color: #000;
  box-shadow:
    0 0 0 1px rgba(255,255,255,0.20),
    0 8px 32px rgba(255,255,255,0.12),
    0 2px 8px rgba(0,0,0,0.4);
}
.login-title {
  font-size: 24px;
  font-weight: 800;
  color: rgba(255,255,255,0.96);
  letter-spacing: -0.6px;
  margin: 0;
  line-height: 1;
}
.login-subtitle {
  font-size: 13.5px;
  color: rgba(255,255,255,0.35);
  margin: 0;
  margin-top: 2px;
}
.login-card {
  width: 100%;
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(255,255,255,0.09);
  border-radius: 20px;
  padding: 32px;
  backdrop-filter: blur(24px) saturate(180%);
  -webkit-backdrop-filter: blur(24px) saturate(180%);
  box-shadow:
    0 4px 24px rgba(0,0,0,0.5),
    inset 0 1px 0 rgba(255,255,255,0.08);
}
.login-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
.login-field:last-of-type { margin-bottom: 24px; }
.login-label {
  font-size: 11px;
  font-weight: 600;
  color: rgba(255,255,255,0.35);
  text-transform: uppercase;
  letter-spacing: 0.7px;
}
.login-input {
  width: 100%;
  padding: 12px 14px;
  font-size: 14px;
  font-family: 'Inter', sans-serif;
  color: rgba(255,255,255,0.95);
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.10);
  border-radius: 10px;
  outline: none;
  transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
  -webkit-appearance: none;
}
.login-input:hover {
  background: rgba(255,255,255,0.07);
  border-color: rgba(255,255,255,0.16);
}
.login-input:focus {
  background: rgba(255,255,255,0.08);
  border-color: rgba(255,255,255,0.35);
  box-shadow: 0 0 0 3px rgba(255,255,255,0.06);
}
.login-input::placeholder { color: rgba(255,255,255,0.20); }
.login-btn {
  width: 100%;
  padding: 13px;
  font-size: 14px;
  font-weight: 700;
  font-family: 'Inter', sans-serif;
  color: #050608;
  background: #fff;
  border: none;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.16s cubic-bezier(0.16,1,0.3,1);
  letter-spacing: -0.2px;
  box-shadow: 0 2px 12px rgba(255,255,255,0.15);
}
.login-btn:hover {
  background: rgba(255,255,255,0.90);
  transform: translateY(-1px);
  box-shadow: 0 4px 20px rgba(255,255,255,0.20);
}
.login-btn:active { transform: scale(0.98); }
.login-error {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  background: rgba(248,113,113,0.10);
  border: 1px solid rgba(248,113,113,0.22);
  border-radius: 10px;
  font-size: 13px;
  color: #F87171;
  margin-bottom: 20px;
  font-weight: 500;
}
.login-warn {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 14px;
  background: rgba(251,191,36,0.08);
  border: 1px solid rgba(251,191,36,0.18);
  border-radius: 10px;
  font-size: 12.5px;
  color: #FBBF24;
  font-weight: 500;
  line-height: 1.5;
  width: 100%;
}
</style>

<div class="login-wrap">

  <div class="login-brand">
    <div class="login-icon">
      <i class="bi bi-wallet2"></i>
    </div>
    <div>
      <h1 class="login-title">SALDO WEB</h1>
      <p class="login-subtitle">Monitor de saldo Meta Ads</p>
    </div>
  </div>

  <div class="login-card">

    <?php if ($error): ?>
    <div class="login-error">
      <i class="bi bi-exclamation-circle-fill" style="font-size:15px;flex-shrink:0"></i>
      <span><?= e($error) ?></span>
    </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="login-field">
        <label class="login-label" for="username">Usuário</label>
        <input type="text" id="username" name="username" class="login-input"
               placeholder="seu_usuario" required autofocus>
      </div>

      <div class="login-field">
        <label class="login-label" for="password">Senha</label>
        <input type="password" id="password" name="password" class="login-input"
               placeholder="••••••••" required>
      </div>

      <button type="submit" class="login-btn">Entrar</button>
    </form>

  </div>

  <?php if ($needAdmin): ?>
  <div class="login-warn">
    <i class="bi bi-exclamation-triangle-fill" style="font-size:15px;flex-shrink:0;margin-top:1px"></i>
    <div>Nenhum admin cadastrado. Execute <code>php cron/create_admin.php</code> no terminal ou acesse <a href="<?= e(base_url('install.php')) ?>" style="color:inherit;text-decoration:underline;text-underline-offset:2px">install.php</a>.</div>
  </div>
  <?php endif; ?>

</div>

<?php
$content = ob_get_clean();
require APP_DIR . '/views/layout.php';
