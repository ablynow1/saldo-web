<?php
declare(strict_types=1);
/**
 * SALDO WEB - Health check
 * Acesse /ping.php apos o deploy para verificar se tudo esta funcionando.
 * Nao requer login. Delete este arquivo apos confirmar que esta OK.
 */
header('Content-Type: text/html; charset=UTF-8');

define('APP_ROOT', __DIR__);
$baseUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($baseUrl === '/' || $baseUrl === '.') $baseUrl = '';
define('BASE_URL', $baseUrl);

$checks = [];

/* 1. config.php existe */
$configFile = APP_ROOT . '/app/config/config.php';
if (file_exists($configFile)) {
    $checks[] = ['ok' => true,  'label' => 'config.php', 'detail' => 'Encontrado'];
    $CONFIG   = @include $configFile;
} else {
    $checks[] = ['ok' => false, 'label' => 'config.php', 'detail' => 'NAO encontrado - rode o install.php'];
    $CONFIG   = null;
}

/* 2. Banco de dados */
$pdo = null;
if ($CONFIG) {
    try {
        $cfg = $CONFIG['db'];
        $pdo = new PDO(
            "mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4",
            $cfg['username'], $cfg['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->query('SELECT 1');
        $checks[] = ['ok' => true, 'label' => 'Banco de dados',
                     'detail' => $cfg['host'] . ' / ' . $cfg['database']];
    } catch (Throwable $e) {
        $checks[] = ['ok' => false, 'label' => 'Banco de dados', 'detail' => $e->getMessage()];
    }
}

/* 3. Tabelas existem */
if ($pdo) {
    $tables  = ['settings','admin','clients','meta_accounts','alerts_log',
                'balance_snapshots','check_runs','ad_insights'];
    $missing = [];
    foreach ($tables as $t) {
        $r = $pdo->query("SHOW TABLES LIKE '{$t}'")->fetch();
        if (!$r) $missing[] = $t;
    }
    $checks[] = empty($missing)
        ? ['ok' => true,  'label' => 'Tabelas do banco',
           'detail' => count($tables) . ' tabelas encontradas']
        : ['ok' => false, 'label' => 'Tabelas do banco',
           'detail' => 'Faltando: ' . implode(', ', $missing)];

    /* 4. Migration 002 (colunas de forecast) */
    $r = $pdo->query("SHOW COLUMNS FROM meta_accounts LIKE 'forecast_runway_days'")->fetch();
    $checks[] = [
        'ok'     => (bool) $r,
        'label'  => 'Migration 002 (forecast)',
        'detail' => $r ? 'Colunas de previsao presentes' : 'Nao aplicada - rode sql/migrations/002_forecast.sql',
    ];

    /* 5. Meta token */
    $metaToken = $pdo->query("SELECT value FROM settings WHERE key_name='meta_system_user_token'")->fetchColumn();
    $checks[]  = [
        'ok'     => !empty($metaToken),
        'label'  => 'Meta System User Token',
        'detail' => !empty($metaToken) ? 'Configurado (' . strlen($metaToken) . ' chars)' : 'Vazio - configure em Configuracoes',
    ];

    /* 6. Evolution API */
    $evUrl = $pdo->query("SELECT value FROM settings WHERE key_name='evolution_base_url'")->fetchColumn();
    $checks[] = [
        'ok'    => !empty($evUrl),
        'label' => 'Evolution API URL',
        'detail' => !empty($evUrl) ? $evUrl : 'Vazio - configure em Configuracoes',
    ];

    /* 7. Ultimo cron */
    $lastRun = $pdo->query("SELECT * FROM check_runs ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($lastRun) {
        $when    = $lastRun['finished_at'] ?? $lastRun['started_at'];
        $elapsed = time() - strtotime($when);
        $hAgo    = round($elapsed / 3600, 1);
        $tooOld  = $elapsed > 7200;
        $checks[] = [
            'ok'    => !$tooOld,
            'label' => 'Ultimo cron executado',
            'detail' => date('d/m/Y H:i', strtotime($when)) . " ({$hAgo}h atras)"
                      . ($tooOld ? ' - cron pode nao estar rodando!' : ''),
        ];
    } else {
        $checks[] = ['ok' => false, 'label' => 'Ultimo cron executado',
                     'detail' => 'Nunca rodou - configure o Cron Job no cPanel'];
    }

    /* 8. Contas cadastradas */
    $nAcc     = (int) $pdo->query("SELECT COUNT(*) FROM meta_accounts WHERE active=1")->fetchColumn();
    $nClients = (int) $pdo->query("SELECT COUNT(*) FROM clients WHERE active=1")->fetchColumn();
    $checks[] = [
        'ok'    => $nAcc > 0,
        'label' => 'Contas monitoradas',
        'detail' => "{$nClients} cliente(s) - {$nAcc} conta(s) Meta Ads ativa(s)"
                  . ($nAcc === 0 ? ' - adicione em Clientes' : ''),
    ];
}

/* PHP checks */
$phpOk  = version_compare(PHP_VERSION, '8.0.0', '>=');
$checks = array_merge([
    ['ok' => $phpOk, 'label' => 'PHP ' . PHP_VERSION,
     'detail' => $phpOk ? 'OK' : 'Requer PHP 8.0+'],
    ['ok' => extension_loaded('pdo_mysql'), 'label' => 'PDO MySQL',
     'detail' => extension_loaded('pdo_mysql') ? 'OK' : 'Extensao nao instalada'],
    ['ok' => extension_loaded('curl'), 'label' => 'cURL',
     'detail' => extension_loaded('curl') ? 'OK' : 'Extensao nao instalada'],
    ['ok' => extension_loaded('mbstring'), 'label' => 'mbstring',
     'detail' => extension_loaded('mbstring') ? 'OK' : 'Extensao nao instalada'],
], $checks);

$allOk   = !in_array(false, array_column($checks, 'ok'), true);
$passing = count(array_filter($checks, fn($c) => $c['ok']));
$total   = count($checks);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SALDO WEB - Health Check</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
       background:#f2f2f7;min-height:100vh;padding:2rem 1rem}
  .box{max-width:600px;margin:0 auto}
  .card{background:#fff;border-radius:16px;padding:1.5rem 2rem;
        box-shadow:0 2px 20px rgba(0,0,0,.08);margin-bottom:1rem}
  h1{font-size:1.3rem;font-weight:700}
  .badge{display:inline-block;padding:.3rem .75rem;border-radius:20px;
         font-size:.82rem;font-weight:600;margin-left:.5rem;vertical-align:middle}
  .ok-b  {background:#d1fae5;color:#065f46}
  .err-b {background:#fee2e2;color:#991b1b}
  .sub{font-size:.85rem;color:#6e6e73;margin-top:.4rem}
  table{width:100%;border-collapse:collapse;font-size:.88rem}
  tr{border-bottom:1px solid #f2f2f7}
  tr:last-child{border:none}
  td{padding:.7rem .4rem;vertical-align:middle}
  .ico{width:28px;text-align:center;font-size:1rem}
  .lbl{font-weight:600;color:#1c1c1e}
  .det{color:#6e6e73;font-size:.8rem;margin-top:.15rem}
  .foot{text-align:center;font-size:.8rem;color:#aeaeb2;margin-top:.5rem}
</style>
</head>
<body>
<div class="box">
  <div class="card">
    <h1>SALDO WEB &mdash; Health Check
      <span class="badge <?= $allOk ? 'ok-b' : 'err-b' ?>">
        <?= $allOk ? 'Tudo OK' : "{$passing}/{$total} OK" ?>
      </span>
    </h1>
    <p class="sub"><?= date('d/m/Y H:i:s') ?> &middot; PHP <?= PHP_VERSION ?></p>
  </div>

  <div class="card">
    <table>
    <?php foreach ($checks as $c): ?>
      <tr>
        <td class="ico"><?= $c['ok'] ? '&#x2705;' : '&#x274C;' ?></td>
        <td>
          <div class="lbl"><?= htmlspecialchars($c['label']) ?></div>
          <div class="det"><?= htmlspecialchars($c['detail']) ?></div>
        </td>
      </tr>
    <?php endforeach; ?>
    </table>
  </div>

  <?php if ($allOk): ?>
  <div class="card" style="text-align:center;color:#34c759;font-weight:600">
    Sistema funcionando. Delete este arquivo quando nao precisar mais.
  </div>
  <?php else: ?>
  <div class="card" style="background:#fff8e1;border:1.5px solid #ffd54f;color:#795548;font-size:.88rem">
    Ha itens com problema. Resolva os &#x274C; acima antes de usar em producao.
  </div>
  <?php endif; ?>

  <p class="foot">
    Delete <code>ping.php</code> do servidor apos verificar.<br>
    <a href="<?= htmlspecialchars(BASE_URL) ?>/login.php" style="color:#007AFF">&#x2190; Ir para o login</a>
  </p>
</div>
</body>
</html>
