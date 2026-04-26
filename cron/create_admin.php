<?php
declare(strict_types=1);
/**
 * Cria/atualiza o usuário admin inicial.
 * Uso (CLI): php cron/create_admin.php <usuario> <senha>
 */
require_once __DIR__ . '/../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Use via CLI (SSH do cPanel).');
}

$user = $argv[1] ?? null;
$pass = $argv[2] ?? null;
if (!$user || !$pass) {
    fwrite(STDERR, "Uso: php cron/create_admin.php <usuario> <senha>\n");
    exit(1);
}

$hash = password_hash($pass, PASSWORD_BCRYPT);
$existing = Db::one('SELECT id FROM admin WHERE username = ?', [$user]);
if ($existing) {
    Db::exec('UPDATE admin SET password_hash = ? WHERE id = ?', [$hash, $existing['id']]);
    echo "Senha atualizada para $user\n";
} else {
    Db::insert('admin', ['username' => $user, 'password_hash' => $hash]);
    echo "Admin $user criado.\n";
}
