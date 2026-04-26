<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Custom error/exception handler — nunca mais tela branca
set_exception_handler(function (Throwable $e) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
        exit(1);
    }
    http_response_code(500);
    $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    $file = htmlspecialchars(basename($e->getFile()), ENT_QUOTES, 'UTF-8');
    $line = $e->getLine();
    echo "<!doctype html><html><head><meta charset=utf-8><title>Erro</title>
    <style>body{font-family:-apple-system,sans-serif;background:#f5f5f7;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
    .box{background:#fff;border-radius:16px;padding:32px;max-width:600px;box-shadow:0 4px 24px rgba(0,0,0,.1)}
    h2{color:#c00;margin:0 0 12px} pre{background:#f8f8f8;padding:12px;border-radius:8px;font-size:13px;overflow-x:auto;color:#333}
    a{color:#007AFF}</style></head><body><div class='box'>
    <h2>⚠️ Erro interno</h2>
    <p><strong>{$file}:{$line}</strong></p>
    <pre>{$msg}</pre>
    <p style='margin-top:16px;font-size:13px;color:#888'>Se o problema persistir, rode <a href='setup.php'>setup.php</a> para verificar as migrações.</p>
    </div></body></html>";
    exit(1);
});
set_error_handler(function (int $errno, string $msg, string $file, int $line) {
    if (!(error_reporting() & $errno)) return false;
    throw new ErrorException($msg, 0, $errno, $file, $line);
});

define('APP_ROOT', dirname(__DIR__));
define('APP_DIR', __DIR__);

// Detecta o subdiretório onde o projeto está hospedado (ex: /saldoweb ou '')
// para que redirects funcionem tanto na raiz do domínio quanto em subpastas.
$baseUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($baseUrl === '/' || $baseUrl === '.') $baseUrl = '';
define('BASE_URL', $baseUrl);

$configFile = APP_DIR . '/config/config.php';
if (!file_exists($configFile)) {
    // Sistema ainda não instalado — redireciona para o instalador.
    if (PHP_SAPI !== 'cli') {
        header('Location: ' . BASE_URL . '/install.php');
        exit;
    }
    exit("config.php não encontrado. Acesse install.php no navegador para configurar.\n");
}
$CONFIG = require $configFile;

date_default_timezone_set($CONFIG['timezone'] ?? 'America/Sao_Paulo');
mb_internal_encoding('UTF-8');

// Garante que o navegador interprete todas as páginas como UTF-8
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/html; charset=UTF-8');
}

spl_autoload_register(function (string $class): void {
    $file = APP_DIR . '/lib/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

Db::init($CONFIG['db']);
Crypto::init($CONFIG['app_key']);

if (session_status() === PHP_SESSION_NONE) {
    // Detecta HTTPS de forma robusta (funciona atrás de proxy/CloudFlare do cPanel)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? 0) == 443);

    // Escopa o cookie de sessão ao subdiretório do projeto para evitar conflito
    // com outras apps no mesmo domínio (ex: outro sistema em /outraapp)
    $cookiePath = BASE_URL !== '' ? BASE_URL . '/' : '/';

    // Nome de sessão único por instalação para evitar colisão com outros apps
    session_name('SALDOWEB_' . substr(md5(BASE_URL), 0, 6));

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $cookiePath,
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    // Fix: diretório de sessões pode não existir no cPanel (ea-php85)
    $sp = session_save_path();
    if ($sp === '' || !is_dir($sp)) {
        if (is_dir('/tmp') && is_writable('/tmp')) {
            session_save_path('/tmp');
        } else {
            $ls = APP_ROOT . '/app/config/sessions';
            if (!is_dir($ls)) @mkdir($ls, 0700, true);
            if (is_dir($ls)) session_save_path($ls);
        }
    }

    session_start();
}

function auth_user(): ?array
{
    return $_SESSION['admin'] ?? null;
}

function require_auth(): void
{
    if (!auth_user()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function base_url(string $path = ''): string
{
    $p = ltrim($path, '/');
    return BASE_URL . ($p !== '' ? '/' . $p : '');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $token): bool
{
    return !empty($token) && hash_equals($_SESSION['csrf'] ?? '', $token);
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(?float $cents, string $currency = 'BRL'): string
{
    if ($cents === null) return '—';
    $val = $cents / 100;
    if (class_exists('NumberFormatter')) {
        $fmt = new NumberFormatter('pt_BR', NumberFormatter::CURRENCY);
        return $fmt->formatCurrency($val, $currency);
    }
    return ($currency === 'BRL' ? 'R$ ' : $currency . ' ') . number_format($val, 2, ',', '.');
}

function flash(?string $msg = null, string $type = 'info'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/**
 * Verifica se uma tabela existe no banco.
 */
function table_exists(string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $r = Db::one("SHOW TABLES LIKE '{$cleanTable}'");
        $cache[$table] = (bool) $r;
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

/**
 * Verifica se uma coluna existe em uma tabela.
 */
function column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = "{$table}.{$column}";
    if (isset($cache[$key])) return $cache[$key];
    try {
        $cleanTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $cleanCol = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        $r = Db::one("SHOW COLUMNS FROM `{$cleanTable}` LIKE '{$cleanCol}'");
        $cache[$key] = (bool) $r;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}
