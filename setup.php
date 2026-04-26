<?php
declare(strict_types=1);
/**
 * SALDO WEB — Setup unificado
 * ============================
 * - Roda TODAS as migrações (schema + migrations/*.sql)
 * - Cria/reseta admin
 * - Diagnóstico completo
 *
 * APAGUE ESTE ARQUIVO DEPOIS DE USAR!
 */
header('Content-Type: text/html; charset=UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('APP_ROOT', __DIR__);
$baseUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($baseUrl === '/' || $baseUrl === '.') $baseUrl = '';
define('BASE_URL', $baseUrl);

/* ---------- Proteção: exige token na URL ou login admin ---------- */
$SETUP_TOKEN_FILE = __DIR__ . '/app/config/.setup_token';
if (!file_exists($SETUP_TOKEN_FILE)) {
    $tok = bin2hex(random_bytes(16));
    @mkdir(dirname($SETUP_TOKEN_FILE), 0755, true);
    @file_put_contents($SETUP_TOKEN_FILE, $tok);
    @chmod($SETUP_TOKEN_FILE, 0600);
}
$expectedToken = trim((string) @file_get_contents($SETUP_TOKEN_FILE));
$providedToken = $_GET['token'] ?? $_POST['token'] ?? '';

// Se já há admin cadastrado e existe sessão dele, permite acesso direto
$loggedAsAdmin = false;
if (file_exists(__DIR__ . '/app/config/config.php')) {
    @session_start();
    $loggedAsAdmin = !empty($_SESSION['admin']);
}

if (!$loggedAsAdmin && !hash_equals($expectedToken, (string) $providedToken)) {
    http_response_code(403);
    echo '<!doctype html><meta charset=utf-8><title>Acesso negado</title>';
    echo '<style>body{font-family:-apple-system,sans-serif;background:#f2f2f7;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}';
    echo '.box{background:#fff;border-radius:16px;padding:32px;max-width:520px;box-shadow:0 4px 24px rgba(0,0,0,.08)}';
    echo 'code{background:#f8f8f8;padding:8px 12px;border-radius:6px;font-family:monospace;display:block;margin-top:8px;word-break:break-all}</style>';
    echo '<div class=box><h2>🔒 setup.php protegido</h2>';
    echo '<p>Para acessar, anexe o token na URL:</p>';
    echo '<code>setup.php?token=' . htmlspecialchars($expectedToken) . '</code>';
    echo '<p style="font-size:13px;color:#666;margin-top:16px">Token salvo em <code style="display:inline;padding:1px 6px">app/config/.setup_token</code> — acessível só via SSH/FTP. Apague o arquivo após o uso para invalidar.</p>';
    echo '<p style="font-size:13px;color:#666;margin-top:8px">Ou faça login normalmente em <a href="login.php">login.php</a> e volte aqui.</p>';
    echo '</div>';
    exit;
}

$configFile = APP_ROOT . '/app/config/config.php';
$hasConfig = file_exists($configFile);
$pdo = null;
$log = [];
$errors = [];
$msg = null;
$debug = [];

if ($hasConfig) {
    $CONFIG = require $configFile;
    try {
        $cfg = $CONFIG['db'];
        $pdo = new PDO(
            "mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4",
            $cfg['username'], $cfg['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (Throwable $e) {
        $errors[] = 'Erro ao conectar no banco: ' . $e->getMessage();
    }
}

// ─── Função para executar SQL ───────────────────────────────
function runSql(PDO $pdo, string $path, array &$log): array {
    $errs = [];
    $raw = file_get_contents($path);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $stmts = array_filter(array_map('trim', explode(';', $raw)));
    foreach ($stmts as $stmt) {
        $clean = trim(preg_replace('/--[^\n]*\n?/', '', $stmt));
        if ($clean === '') continue;
        try {
            $pdo->exec($stmt);
            $log[] = '✅ ' . substr(trim($stmt), 0, 90);
        } catch (Throwable $e) {
            $m = $e->getMessage();
            $skip = ['already exists','Duplicate column name','Duplicate key name','Duplicate entry'];
            foreach ($skip as $s) {
                if (stripos($m, $s) !== false) {
                    $log[] = '⏭️ ' . substr(trim($stmt), 0, 70);
                    continue 2;
                }
            }
            $errs[] = basename($path) . ': ' . $m;
            $log[] = '❌ ' . $m;
        }
    }
    return $errs;
}

// ─── AÇÃO: Rodar migrações ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($POST_op = ($_POST['op'] ?? '')) !== '') {

    if ($POST_op === 'migrate' && $pdo) {
        // Schema
        $schemaFile = APP_ROOT . '/sql/schema.sql';
        if (file_exists($schemaFile)) {
            $log[] = '═══ schema.sql ═══';
            $errors = array_merge($errors, runSql($pdo, $schemaFile, $log));
        }
        // Migrations
        $migsDir = APP_ROOT . '/sql/migrations';
        if (is_dir($migsDir)) {
            $migrations = glob($migsDir . '/*.sql') ?: [];
            sort($migrations);
            foreach ($migrations as $mig) {
                $log[] = '';
                $log[] = '═══ ' . basename($mig) . ' ═══';
                $errors = array_merge($errors, runSql($pdo, $mig, $log));
            }
        }
        $msg = empty($errors) ? '✅ Migrações executadas com sucesso!' : '⚠️ Houve ' . count($errors) . ' erro(s) — veja o log abaixo.';
    }

    if ($POST_op === 'admin' && $pdo) {
        $user = trim($_POST['username'] ?? '');
        $pass = $_POST['password'] ?? '';
        if (strlen($user) < 3) {
            $msg = '❌ Usuário precisa ter pelo menos 3 caracteres.';
        } elseif (strlen($pass) < 6) {
            $msg = '❌ Senha precisa ter pelo menos 6 caracteres.';
        } else {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $verifyTest = password_verify($pass, $hash);

            $existing = $pdo->prepare('SELECT id FROM admin WHERE username = ?');
            $existing->execute([$user]);
            $row = $existing->fetch();

            if ($row) {
                $st = $pdo->prepare('UPDATE admin SET password_hash = ? WHERE id = ?');
                $st->execute([$hash, $row['id']]);
                $msg = "✅ Senha atualizada para <b>{$user}</b>!";
            } else {
                $st = $pdo->prepare('INSERT INTO admin (username, password_hash) VALUES (?, ?)');
                $st->execute([$user, $hash]);
                $msg = "✅ Admin <b>{$user}</b> criado!";
            }

            // Verificação
            $check = $pdo->prepare('SELECT password_hash FROM admin WHERE username = ?');
            $check->execute([$user]);
            $savedHash = $check->fetchColumn();
            $verifyOk = password_verify($pass, $savedHash);
            if (!$verifyOk) {
                $msg .= ' <span style="color:#c00">⚠️ Verificação falhou — hash pode estar truncado!</span>';
            }
        }
    }
}

// ─── Diagnóstico ────────────────────────────────────────────
$diag = [];
$diag[] = ['ok' => version_compare(PHP_VERSION, '8.0.0', '>='), 'label' => 'PHP ' . PHP_VERSION, 'detail' => version_compare(PHP_VERSION, '8.0.0', '>=') ? 'OK' : 'Requer 8.0+'];
$diag[] = ['ok' => extension_loaded('pdo_mysql'), 'label' => 'PDO MySQL', 'detail' => extension_loaded('pdo_mysql') ? 'OK' : 'Extensão não instalada'];
$diag[] = ['ok' => extension_loaded('curl'), 'label' => 'cURL', 'detail' => extension_loaded('curl') ? 'OK' : 'Extensão não instalada'];
$diag[] = ['ok' => extension_loaded('openssl'), 'label' => 'OpenSSL', 'detail' => extension_loaded('openssl') ? 'OK' : 'Extensão não instalada'];
$diag[] = ['ok' => $hasConfig, 'label' => 'config.php', 'detail' => $hasConfig ? 'Encontrado' : 'Não encontrado — rode install.php primeiro'];
$diag[] = ['ok' => (bool)$pdo, 'label' => 'Banco de dados', 'detail' => $pdo ? 'Conectado' : 'Sem conexão'];

if ($pdo) {
    $tables = ['settings','admin','clients','meta_accounts','alerts_log','balance_snapshots','check_runs'];
    $optTables = ['ad_insights','campaign_insights','report_schedules','report_log'];
    $missingCore = [];
    foreach ($tables as $t) {
        $r = $pdo->query("SHOW TABLES LIKE '{$t}'")->fetch();
        if (!$r) $missingCore[] = $t;
    }
    $diag[] = [
        'ok' => empty($missingCore),
        'label' => 'Tabelas principais (' . count($tables) . ')',
        'detail' => empty($missingCore) ? 'Todas presentes' : 'Faltando: ' . implode(', ', $missingCore),
    ];

    $missingOpt = [];
    foreach ($optTables as $t) {
        $r = $pdo->query("SHOW TABLES LIKE '{$t}'")->fetch();
        if (!$r) $missingOpt[] = $t;
    }
    $diag[] = [
        'ok' => empty($missingOpt),
        'label' => 'Tabelas de performance (' . count($optTables) . ')',
        'detail' => empty($missingOpt) ? 'Todas presentes' : 'Faltando: ' . implode(', ', $missingOpt) . ' — rode as migrações',
    ];

    $r = $pdo->query("SHOW COLUMNS FROM meta_accounts LIKE 'forecast_runway_days'")->fetch();
    $diag[] = [
        'ok' => (bool)$r,
        'label' => 'Colunas forecast',
        'detail' => $r ? 'Migration 002 aplicada' : 'Não aplicada — rode as migrações',
    ];

    $admins = $pdo->query('SELECT id, username FROM admin')->fetchAll();
    $diag[] = [
        'ok' => count($admins) > 0,
        'label' => 'Admin(s) cadastrado(s)',
        'detail' => count($admins) > 0 ? implode(', ', array_column($admins, 'username')) : 'Nenhum — crie um abaixo',
    ];

    $metaToken = $pdo->query("SELECT value FROM settings WHERE key_name='meta_system_user_token'")->fetchColumn();
    $diag[] = [
        'ok' => !empty($metaToken),
        'label' => 'Meta System User Token',
        'detail' => !empty($metaToken) ? 'Configurado (' . strlen($metaToken) . ' chars)' : 'Vazio — configure em Configurações',
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SALDO WEB — Setup</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f2f2f7;min-height:100vh;padding:24px 16px}
  .wrap{max-width:700px;margin:0 auto}
  .card{background:#fff;border-radius:16px;padding:24px 28px;box-shadow:0 2px 20px rgba(0,0,0,.08);margin-bottom:16px}
  h1{font-size:1.4rem;font-weight:800;margin-bottom:4px}
  h2{font-size:1rem;font-weight:700;margin-bottom:12px}
  .sub{font-size:.85rem;color:#6e6e73;margin-bottom:16px}
  label{display:block;font-size:.85rem;font-weight:600;margin:.6rem 0 .25rem}
  input,select{width:100%;padding:.55rem .8rem;border:1.5px solid #d1d1d6;border-radius:10px;font-size:.92rem}
  input:focus{outline:none;border-color:#007AFF;box-shadow:0 0 0 3px rgba(0,122,255,.12)}
  .btn{display:inline-block;padding:.65rem 1.4rem;background:#007AFF;color:#fff;border:none;border-radius:10px;font-size:.92rem;font-weight:600;cursor:pointer;text-decoration:none}
  .btn:hover{background:#0056b3}
  .btn-green{background:#34c759}
  .btn-green:hover{background:#28a745}
  .btn-sm{padding:.4rem 1rem;font-size:.82rem}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .msg{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:.88rem;font-weight:500}
  .msg-ok{background:#d4edda;color:#155724}
  .msg-err{background:#f8d7da;color:#721c24}
  .msg-warn{background:#fff3cd;color:#856404}
  table{width:100%;border-collapse:collapse;font-size:.85rem}
  tr{border-bottom:1px solid #f2f2f7} tr:last-child{border:none}
  td{padding:.55rem .3rem;vertical-align:middle}
  .ico{width:28px;text-align:center;font-size:.95rem}
  .lbl{font-weight:600;color:#1c1c1e}
  .det{color:#6e6e73;font-size:.78rem;margin-top:.1rem}
  pre{background:#1e1e1e;color:#d4d4d4;padding:14px;border-radius:10px;font-size:.72rem;overflow-x:auto;line-height:1.7;white-space:pre-wrap;word-break:break-word;max-height:400px;overflow-y:auto;margin-top:12px}
  .warn{background:#fff3cd;color:#856404;padding:12px;border-radius:10px;font-size:.82rem;margin-top:16px;text-align:center}
</style>
</head>
<body>
<div class="wrap">

<!-- Header -->
<div class="card">
  <h1>🔧 SALDO WEB — Setup</h1>
  <p class="sub">Migrações, admin e diagnóstico — tudo em um lugar.</p>
  <?php if ($msg): ?>
    <div class="msg <?= strpos($msg, '✅') !== false ? 'msg-ok' : (strpos($msg, '⚠') !== false ? 'msg-warn' : 'msg-err') ?>"><?= $msg ?></div>
  <?php endif; ?>
</div>

<?php if (!$hasConfig): ?>
<div class="card">
  <h2>❌ Configuração não encontrada</h2>
  <p>Acesse <a href="<?= htmlspecialchars(BASE_URL) ?>/install.php">install.php</a> primeiro para configurar o banco de dados.</p>
</div>
<?php else: ?>

<!-- 1. Migrações -->
<div class="card">
  <h2>1️⃣ Rodar Migrações</h2>
  <p class="sub">Cria todas as tabelas e colunas no banco. Seguro para rodar várias vezes — pula o que já existe.</p>
  <form method="post">
    <input type="hidden" name="op" value="migrate">
    <button class="btn btn-green">▶️ Executar todas as migrações</button>
  </form>
  <?php if (!empty($log)): ?>
    <pre><?= htmlspecialchars(implode("\n", $log)) ?></pre>
  <?php endif; ?>
</div>

<!-- 2. Admin -->
<div class="card">
  <h2>2️⃣ Criar / Resetar Admin</h2>
  <p class="sub">Crie um novo admin ou redefina a senha de um existente.</p>
  <form method="post">
    <input type="hidden" name="op" value="admin">
    <div class="grid">
      <div>
        <label>Usuário</label>
        <input type="text" name="username" value="vitor17_saldoweb" required>
      </div>
      <div>
        <label>Senha</label>
        <input type="text" name="password" required minlength="6" placeholder="digite aqui (visível)">
      </div>
    </div>
    <div style="margin-top:14px">
      <button class="btn">Criar / Resetar Senha</button>
    </div>
  </form>
</div>

<!-- 3. Diagnóstico -->
<div class="card">
  <h2>3️⃣ Diagnóstico</h2>
  <table>
  <?php foreach ($diag as $d): ?>
    <tr>
      <td class="ico"><?= $d['ok'] ? '✅' : '❌' ?></td>
      <td>
        <div class="lbl"><?= htmlspecialchars($d['label']) ?></div>
        <div class="det"><?= htmlspecialchars($d['detail']) ?></div>
      </td>
    </tr>
  <?php endforeach; ?>
  </table>
</div>

<!-- Link pro login -->
<div class="card" style="text-align:center">
  <a href="<?= htmlspecialchars(BASE_URL) ?>/login.php" class="btn">Ir para o Login →</a>
</div>

<?php endif; ?>

<div class="warn">⚠️ <b>APAGUE este arquivo (setup.php)</b> do servidor depois de usar — é um risco de segurança!</div>

</div>
</body>
</html>
