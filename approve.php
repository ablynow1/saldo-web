<?php
/**
 * Página de aprovação de relatório via link no WhatsApp.
 * Acesso: /approve.php?token=xxx&action=approve|reject
 * Não requer login — o token é o mecanismo de segurança.
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

define('APP_ROOT', __DIR__);
$baseUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($baseUrl === '/' || $baseUrl === '.') $baseUrl = '';
define('BASE_URL', $baseUrl);

$configFile = APP_ROOT . '/app/config/config.php';
if (!file_exists($configFile)) { http_response_code(503); exit('Sistema não configurado.'); }
$CONFIG = require $configFile;

spl_autoload_register(fn($c) => is_file(APP_ROOT.'/app/lib/'.$c.'.php') && require_once APP_ROOT.'/app/lib/'.$c.'.php');
Db::init($CONFIG['db']);
Crypto::init($CONFIG['app_key']);

$token  = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
$action = $_GET['action'] === 'reject' ? 'reject' : 'approve';

$row = $token ? Db::one(
    "SELECT rq.*, c.name AS client_name, c.whatsapp_group_jid
     FROM report_queue rq
     JOIN clients c ON c.id = rq.client_id
     WHERE rq.approve_token = ?",
    [$token]
) : null;

$result = 'invalid';
$title  = '';
$detail = '';

if (!$row) {
    $result = 'invalid';
    $title  = 'Link inválido';
    $detail = 'Este link não existe ou já foi usado.';
} elseif ($row['status'] !== 'pending') {
    $result = 'already';
    $title  = $row['status'] === 'approved' ? '✅ Já aprovado' : '❌ Já cancelado';
    $detail = 'Este relatório já foi ' . ($row['status'] === 'approved' ? 'aprovado e enviado' : 'cancelado') . '.';
} elseif (strtotime($row['created_at']) < (time() - 86400)) {
    Db::exec("UPDATE report_queue SET status='expired', resolved_at=NOW() WHERE id=?", [(int)$row['id']]);
    $result = 'expired';
    $title  = '⏰ Link expirado';
    $detail = 'Links de aprovação são válidos por 24 horas.';
} elseif ($action === 'reject') {
    Db::exec("UPDATE report_queue SET status='rejected', resolved_at=NOW() WHERE id=?", [(int)$row['id']]);
    $result = 'rejected';
    $title  = '❌ Relatório cancelado';
    $detail = 'O relatório para <strong>' . htmlspecialchars($row['client_name']) . '</strong> foi cancelado e não será enviado.';
} else {
    // Aprovar
    if ($row['status'] === 'test_pending') {
        // Se for um teste manual disparado pelo painel, envia na hora
        try {
            $wa = WhatsAppClient::fromSettings();
            $r  = $wa->sendText($row['group_jid'], $row['message']);
            if ($r['ok']) {
                Db::exec("UPDATE report_queue SET status='approved', resolved_at=NOW() WHERE id=?", [(int)$row['id']]);
                Db::insert('report_log', [
                    'client_id'   => (int)$row['client_id'],
                    'report_type' => $row['report_type'],
                    'message'     => $row['message'],
                    'sent_ok'     => 1,
                ]);
                $result = 'approved';
                $title  = '✅ Teste enviado!';
                $detail = 'O teste foi enviado para o grupo <strong>' . htmlspecialchars($row['client_name']) . '</strong> com sucesso.';
            } else {
                $result = 'error';
                $title  = '⚠️ Erro ao enviar';
                $detail = 'A mensagem não foi entregue. Erro: ' . htmlspecialchars(json_encode($r['body']));
            }
        } catch (Throwable $e) {
            $result = 'error';
            $title  = '⚠️ Erro ao enviar';
            $detail = htmlspecialchars($e->getMessage());
        }
    } else {
        // Se for agendado, apenas aprova e será enviado no horário configurado
        Db::exec("UPDATE report_queue SET status='approved', resolved_at=NOW() WHERE id=?", [(int)$row['id']]);
        $result = 'approved';
        $title  = '✅ Relatório aprovado!';
        $detail = 'O relatório foi aprovado e será enviado para <strong>' . htmlspecialchars($row['client_name']) . '</strong> no horário agendado.';
    }
}

$colors = [
    'approved' => ['bg' => '#EDFBF2', 'border' => '#34C759', 'btn' => '#34C759'],
    'rejected' => ['bg' => '#F2F2F7', 'border' => '#8E8E93', 'btn' => '#8E8E93'],
    'expired'  => ['bg' => '#FFF4E5', 'border' => '#FF9500', 'btn' => '#FF9500'],
    'error'    => ['bg' => '#FFEBEA', 'border' => '#FF3B30', 'btn' => '#FF3B30'],
    'invalid'  => ['bg' => '#FFEBEA', 'border' => '#FF3B30', 'btn' => '#FF3B30'],
    'already'  => ['bg' => '#EBF3FF', 'border' => '#007AFF', 'btn' => '#007AFF'],
];
$c = $colors[$result] ?? $colors['invalid'];
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Aprovação · SALDO WEB</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: #F2F2F7;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
  }
  .card {
    background: <?= $c['bg'] ?>;
    border: 2px solid <?= $c['border'] ?>;
    border-radius: 20px;
    padding: 40px 32px;
    max-width: 420px;
    width: 100%;
    text-align: center;
    box-shadow: 0 8px 32px rgba(0,0,0,.10);
  }
  .icon { font-size: 56px; margin-bottom: 16px; }
  h1 { font-size: 22px; font-weight: 700; color: #1C1C1E; margin-bottom: 12px; }
  p  { font-size: 15px; color: #3A3A3C; line-height: 1.6; margin-bottom: 24px; }
  .btn {
    display: inline-block;
    background: <?= $c['btn'] ?>;
    color: #fff;
    padding: 12px 28px;
    border-radius: 12px;
    text-decoration: none;
    font-weight: 600;
    font-size: 15px;
  }
  .preview {
    background: rgba(0,0,0,.04);
    border-radius: 12px;
    padding: 14px 16px;
    text-align: left;
    font-size: 13px;
    color: #3A3A3C;
    white-space: pre-wrap;
    line-height: 1.6;
    max-height: 200px;
    overflow-y: auto;
    margin-bottom: 20px;
  }
  .brand { font-size: 12px; color: #8E8E93; margin-top: 20px; }
</style>
</head>
<body>
<div class="card">
  <div class="icon"><?= $result === 'approved' ? '✅' : ($result === 'rejected' ? '🚫' : ($result === 'expired' ? '⏰' : '⚠️')) ?></div>
  <h1><?= $title ?></h1>
  <p><?= $detail ?></p>

  <?php if ($result === 'approved' && $row): ?>
    <div class="preview"><?= htmlspecialchars(mb_substr($row['message'], 0, 400)) . (mb_strlen($row['message']) > 400 ? '…' : '') ?></div>
  <?php endif; ?>

  <a href="<?= htmlspecialchars(BASE_URL . '/report_settings.php') ?>" class="btn">Ver painel</a>
  <div class="brand">SALDO WEB · <?= date('d/m/Y H:i') ?></div>
</div>
</body>
</html>
