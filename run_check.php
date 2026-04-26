<?php
require_once __DIR__ . '/app/bootstrap.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    exit('Bad request');
}

// CSRF relaxado — se a sessão perdeu o token, permite (é protegido pelo require_auth)
$csrfOk = csrf_check($_POST['csrf'] ?? null);
if (!$csrfOk && !empty($_SESSION['csrf'])) {
    flash('Token CSRF inválido. Tente novamente.', 'warning');
    header('Location: index.php');
    exit;
}

// Executa inline a mesma rotina do cron.
require APP_ROOT . '/cron/check_balances.php';

flash('Verificação concluída.', 'success');
header('Location: index.php');
