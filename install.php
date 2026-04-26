<?php
declare(strict_types=1);
/**
 * SALDO WEB - Instalador web
 * Acesse este arquivo no navegador UMA VEZ apos o upload.
 * Ele cria as tabelas, roda todas as migracoes e configura o admin.
 * Ao final se auto-trava e nunca mais pode ser executado.
 */
header('Content-Type: text/html; charset=UTF-8');

define('APP_ROOT',    __DIR__);
// Detecta subdiretório (ex: /saldoweb) para funcionar em subpasta ou raiz
$baseUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($baseUrl === '/' || $baseUrl === '.') $baseUrl = '';
define('BASE_URL', $baseUrl);
define('LOCK_FILE',   APP_ROOT . '/app/config/.installed');
define('CONFIG_FILE', APP_ROOT . '/app/config/config.php');
define('SCHEMA_FILE', APP_ROOT . '/sql/schema.sql');
define('MIGS_DIR',    APP_ROOT . '/sql/migrations');

$errors = [];
$done   = false;
$log    = [];

/* -- ja instalado ------------------------------------------ */
if (file_exists(LOCK_FILE)) {
    die('<!doctype html><html lang="pt-br"><head><meta charset="utf-8">
    <title>Instalado</title></head><body>
    <div style="font-family:-apple-system,sans-serif;max-width:480px;margin:4rem auto;
         padding:2rem;background:#fff;border-radius:12px;box-shadow:0 2px 16px rgba(0,0,0,.1);
         text-align:center">
    <div style="font-size:3rem">&#x26D4;</div>
    <h2 style="color:#c00">Instalacao ja realizada</h2>
    <p>Para reinstalar, apague o arquivo <code>app/config/.installed</code>
    pelo gerenciador de arquivos do cPanel.</p>
    <a href="' . BASE_URL . '/login.php" style="display:inline-block;margin-top:1rem;padding:.7rem 1.8rem;
    background:#007AFF;color:#fff;text-decoration:none;border-radius:8px">
    Ir para o login &rarr;</a>
    </div></body></html>');
}

/* -- helper: executa arquivo SQL --------------------------- */
function runSqlFile(PDO $pdo, string $path, array &$log): array
{
    $errs = [];
    $raw  = file_get_contents($path);
    // Remove BOM se houver
    $raw  = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $stmts = array_filter(array_map('trim', explode(';', $raw)));

    foreach ($stmts as $stmt) {
        // Pula blocos que so tem comentarios
        $clean = trim(preg_replace('/--[^\n]*\n?/', '', $stmt));
        if ($clean === '') continue;

        try {
            $pdo->exec($stmt);
            $log[] = '[OK] ' . substr(trim($stmt), 0, 80);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            // Ignora erros de "ja existe" -- normais em re-runs
            $skip = [
                'already exists',
                'Duplicate column name',
                'Duplicate key name',
                'Duplicate entry',
            ];
            foreach ($skip as $s) {
                if (stripos($msg, $s) !== false) {
                    $log[] = '[SKIP] ' . substr(trim($stmt), 0, 60);
                    continue 2;
                }
            }
            $errs[] = basename($path) . ': ' . $msg;
            $log[]  = '[ERRO] ' . $msg;
            break;
        }
    }
    return $errs;
}

/* -- processamento do formulario --------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost    = trim($_POST['db_host']    ?? 'localhost');
    $dbName    = trim($_POST['db_name']    ?? '');
    $dbUser    = trim($_POST['db_user']    ?? '');
    $dbPass    =       $_POST['db_pass']   ?? '';
    $appUrl    = rtrim(trim($_POST['app_url'] ?? ''), '/');
    $adminUser = trim($_POST['admin_user'] ?? 'admin');
    $adminPass =       $_POST['admin_pass']?? '';

    if (!$dbName)                $errors[] = 'Informe o nome do banco de dados.';
    if (!$dbUser)                $errors[] = 'Informe o usuario do banco.';
    if (!$appUrl)                $errors[] = 'Informe a URL do site.';
    if (strlen($adminPass) < 8)  $errors[] = 'A senha do admin precisa ter pelo menos 8 caracteres.';

    $pdo = null;
    if (empty($errors)) {
        try {
            $pdo = new PDO(
                "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
                $dbUser, $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $log[] = '[OK] Conectado ao banco de dados';
        } catch (Throwable $e) {
            $errors[] = 'Erro ao conectar no banco: ' . $e->getMessage();
        }
    }

    if (empty($errors) && $pdo) {
        $log[] = '--- schema.sql ---';
        $errors = array_merge($errors, runSqlFile($pdo, SCHEMA_FILE, $log));
    }

    if (empty($errors) && $pdo) {
        $migrations = glob(MIGS_DIR . '/*.sql') ?: [];
        sort($migrations);
        foreach ($migrations as $mig) {
            $log[] = '--- ' . basename($mig) . ' ---';
            $migErrors = runSqlFile($pdo, $mig, $log);
            $errors = array_merge($errors, $migErrors);
            if (!empty($migErrors)) break;
        }
    }

    if (empty($errors) && $pdo) {
        // Admin
        $hash = password_hash($adminPass, PASSWORD_BCRYPT);
        $st   = $pdo->prepare(
            'INSERT INTO admin (username, password_hash) VALUES (?,?)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
        );
        $st->execute([$adminUser, $hash]);
        $log[] = "[OK] Usuario admin '{$adminUser}' criado";

        // config.php
        $appKey = base64_encode(random_bytes(32));
        $cfg    = "<?php\nreturn [\n"
            . "    'db' => [\n"
            . "        'host'     => '" . addslashes($dbHost) . "',\n"
            . "        'port'     => 3306,\n"
            . "        'database' => '" . addslashes($dbName) . "',\n"
            . "        'username' => '" . addslashes($dbUser) . "',\n"
            . "        'password' => '" . addslashes($dbPass) . "',\n"
            . "        'charset'  => 'utf8mb4',\n"
            . "    ],\n"
            . "    'app_key'  => '" . $appKey . "',\n"
            . "    'app_url'  => '" . addslashes($appUrl) . "',\n"
            . "    'timezone' => 'America/Sao_Paulo',\n"
            . "];\n";

        if (!is_dir(APP_ROOT . '/app/config')) {
            mkdir(APP_ROOT . '/app/config', 0755, true);
        }
        file_put_contents(CONFIG_FILE, $cfg);
        $log[] = '[OK] config.php gerado';

        file_put_contents(LOCK_FILE, date('Y-m-d H:i:s'));
        $log[] = '[OK] Instalacao travada (.installed)';

        $done = true;
    }
}

$phpBin  = PHP_BINARY ?: '/usr/bin/php';
$cronCmd = $phpBin . ' ' . APP_ROOT . '/cron/check_balances.php';
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SALDO WEB - Instalacao</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
       background:#f2f2f7;min-height:100vh;padding:2rem 1rem}
  .box{max-width:560px;margin:0 auto}
  .card{background:#fff;border-radius:16px;padding:2rem;
        box-shadow:0 2px 20px rgba(0,0,0,.08);margin-bottom:1rem}
  h1{font-size:1.6rem;font-weight:700;margin-bottom:.25rem}
  .sub{color:#6e6e73;font-size:.9rem;margin-bottom:1.5rem}
  .section-title{font-size:.78rem;font-weight:600;color:#6e6e73;
                 text-transform:uppercase;letter-spacing:.05em;margin:1.2rem 0 .5rem}
  label{display:block;font-size:.85rem;font-weight:600;margin:.9rem 0 .3rem}
  input{width:100%;padding:.6rem .85rem;border:1.5px solid #d1d1d6;border-radius:10px;
        font-size:.95rem;transition:border .15s}
  input:focus{outline:none;border-color:#007AFF;box-shadow:0 0 0 3px rgba(0,122,255,.12)}
  .btn{display:block;width:100%;padding:.8rem;background:#007AFF;color:#fff;border:none;
       border-radius:10px;font-size:1rem;font-weight:600;cursor:pointer;margin-top:1.5rem;
       transition:background .15s}
  .btn:hover{background:#0062cc}
  .err-box{background:#fff2f2;border:1.5px solid #ffb3b3;color:#c00;padding:.9rem 1rem;
            border-radius:10px;margin-bottom:1rem;font-size:.88rem;line-height:1.6}
  hr{border:none;border-top:1px solid #f2f2f7;margin:1.3rem 0}
  .ok-header{text-align:center;padding:1rem 0 1.5rem}
  .ok-header h2{font-size:1.5rem;color:#34c759;margin-bottom:.3rem}
  .ok-header p{color:#6e6e73;font-size:.9rem}
  .checklist{list-style:none;padding:0}
  .checklist li{display:flex;align-items:flex-start;gap:.7rem;padding:.6rem 0;
                border-bottom:1px solid #f2f2f7;font-size:.9rem}
  .checklist li:last-child{border:none}
  .num{width:24px;height:24px;border-radius:50%;background:#007AFF;color:#fff;
       font-size:.75rem;font-weight:700;display:flex;align-items:center;
       justify-content:center;flex-shrink:0;margin-top:.1rem}
  .done .num{background:#34c759}
  .cron-box{background:#1c1c1e;color:#30d158;border-radius:10px;padding:.9rem 1rem;
            font-family:"SF Mono",Consolas,monospace;font-size:.78rem;word-break:break-all;
            margin:.5rem 0;line-height:1.7}
  .badge-r{display:inline-block;background:#ff3b30;color:#fff;font-size:.72rem;
           padding:.15rem .45rem;border-radius:5px;margin-left:.3rem}
  .badge-g{display:inline-block;background:#34c759;color:#fff;font-size:.72rem;
           padding:.15rem .45rem;border-radius:5px;margin-left:.3rem}
  details summary{cursor:pointer;color:#6e6e73;font-size:.82rem;margin-top:1rem;
                  user-select:none}
  .log-box{background:#f9f9f9;border-radius:8px;padding:.75rem;margin-top:.5rem;
           font-family:monospace;font-size:.76rem;color:#333;max-height:220px;
           overflow-y:auto;line-height:1.7;white-space:pre-wrap}
</style>
</head>
<body>
<div class="box">

<?php if ($done): ?>

<div class="card">
  <div class="ok-header">
    <div style="font-size:3rem">&#x1F389;</div>
    <h2>Instalacao concluida!</h2>
    <p>Banco configurado, todas as tabelas criadas, admin pronto.</p>
  </div>

  <div class="section-title">O que fazer agora</div>
  <ul class="checklist">
    <li class="done">
      <span class="num">&#x2713;</span>
      <span>Banco, tabelas e migracoes aplicados <span class="badge-g">feito</span></span>
    </li>
    <li>
      <span class="num">2</span>
      <div>
        <strong>Delete o <code>install.php</code></strong> pelo gerenciador de arquivos do cPanel
        <span class="badge-r">seguranca</span><br>
        <small style="color:#6e6e73">Caminho no cPanel: <code>public_html/install.php</code></small>
      </div>
    </li>
    <li>
      <span class="num">3</span>
      <div>
        <strong>Configure o Cron Job</strong> no cPanel &rarr; Cron Jobs<br>
        <small style="color:#6e6e73">Frequencia: <code>0 * * * *</code> (toda hora)</small>
        <div class="cron-box"><?= htmlspecialchars($cronCmd) ?></div>
        <small style="color:#6e6e73">
          Se der erro, troque <code><?= htmlspecialchars(basename($phpBin)) ?></code> por
          <code>/usr/local/bin/php</code> ou <code>/usr/bin/php8.1</code>
        </small>
      </div>
    </li>
    <li>
      <span class="num">4</span>
      <div>
        <strong>Configure os tokens</strong> no painel &rarr; Configuracoes<br>
        <small style="color:#6e6e73">Meta System User Token + Evolution API URL e Key</small>
      </div>
    </li>
    <li>
      <span class="num">5</span>
      <div>
        <strong>Verifique o sistema</strong> apos o login<br>
        <small style="color:#6e6e73">Acesse <code>/ping.php</code> para diagnostico rapido de tudo</small>
      </div>
    </li>
  </ul>

  <?php if (!empty($log)): ?>
  <details>
    <summary>Ver log de instalacao (<?= count($log) ?> linhas)</summary>
    <div class="log-box"><?= htmlspecialchars(implode("\n", $log)) ?></div>
  </details>
  <?php endif; ?>

  <a href="<?= htmlspecialchars(BASE_URL) ?>/login.php" class="btn" style="margin-top:1.5rem;text-align:center;text-decoration:none;
     display:block">Ir para o login &rarr;</a>
</div>

<?php else: ?>

<div class="card">
  <h1>&#x1F4B0; SALDO WEB</h1>
  <p class="sub">Preencha os campos abaixo. O instalador cria o banco, tabelas e admin automaticamente.</p>

  <?php if (!empty($errors)): ?>
  <div class="err-box">
    <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
  </div>
  <?php endif; ?>

  <form method="post" autocomplete="off">

    <div class="section-title">Banco de dados</div>
    <small style="color:#6e6e73;font-size:.82rem;line-height:1.5;display:block;margin-bottom:.4rem">
      Crie o banco no cPanel &rarr; <strong>MySQL Databases</strong> antes de continuar.
      Nome e usuario ficam no formato <code>seulogin_nomebanco</code>.
    </small>

    <label>Host do banco</label>
    <input name="db_host"
           value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>"
           placeholder="localhost">

    <label>Nome do banco</label>
    <input name="db_name"
           value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>"
           placeholder="seulogin_saldoweb" required>

    <label>Usuario do banco</label>
    <input name="db_user"
           value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>"
           placeholder="seulogin_saldoweb" required>

    <label>Senha do banco</label>
    <input type="password" name="db_pass">

    <hr>
    <div class="section-title">Painel de controle</div>

    <label>URL do site <small style="font-weight:400;color:#6e6e73">(sem barra no final)</small></label>
    <input name="app_url"
           value="<?= htmlspecialchars($_POST['app_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? ''))) ?>"
           placeholder="https://seudominio.com.br" required>

    <label>Usuario admin</label>
    <input name="admin_user"
           value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin') ?>" required>

    <label>Senha admin <small style="font-weight:400;color:#6e6e73">(minimo 8 caracteres)</small></label>
    <input type="password" name="admin_pass" required minlength="8">

    <button type="submit" class="btn">Instalar agora &rarr;</button>
  </form>
</div>

<div class="card" style="font-size:.82rem;color:#6e6e73;text-align:center;padding:1rem 2rem">
  Cria as tabelas, roda as migracoes SQL e gera o config.php. Sem phpMyAdmin.
</div>

<?php endif; ?>
</div>
</body>
</html>
