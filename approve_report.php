<?php
require_once __DIR__ . '/app/bootstrap.php';

$token  = $_GET['token'] ?? '';
$action = $_GET['action'] ?? '';

if (empty($token) || !in_array($action, ['approve', 'reject'])) {
    http_response_code(400);
    exit('Link inválido ou expirado.');
}

$report = Db::one('SELECT * FROM pending_reports WHERE token = ? AND status = "pending"', [$token]);

if (!$report) {
    http_response_code(404);
    echo "<!doctype html><html><head><meta charset=utf-8><meta name='viewport' content='width=device-width, initial-scale=1'><title>Relatório</title>
    <style>body{font-family:sans-serif;text-align:center;padding:50px;background:#f9f9f9;}</style></head><body>
    <h2>⚠️ Ops!</h2><p>Este relatório já foi processado ou o link é inválido.</p>
    </body></html>";
    exit;
}

if ($action === 'reject') {
    Db::exec('UPDATE pending_reports SET status = "rejected" WHERE id = ?', [$report['id']]);
    echo "<!doctype html><html><head><meta charset=utf-8><meta name='viewport' content='width=device-width, initial-scale=1'><title>Relatório Cancelado</title>
    <style>body{font-family:sans-serif;text-align:center;padding:50px;background:#fff5f5;color:#c53030;}</style></head><body>
    <h2>❌ Relatório Cancelado!</h2><p>O disparo deste relatório foi impedido.</p>
    </body></html>";
    exit;
}

if ($action === 'approve') {
    try {
        $wa = WhatsAppClient::fromSettings();
        $res = $wa->sendText($report['whatsapp_group_jid'], $report['message']);

        if ($res['ok']) {
            Db::exec('UPDATE pending_reports SET status = "approved" WHERE id = ?', [$report['id']]);
            Db::insert('report_log', [
                'client_id'   => $report['client_id'],
                'report_type' => $report['report_type'],
                'message'     => 'Aprovado e enviado manualmente. ' . mb_substr($report['message'], 0, 100) . '...',
                'sent_ok'     => 1,
            ]);

            echo "<!doctype html><html><head><meta charset=utf-8><meta name='viewport' content='width=device-width, initial-scale=1'><title>Relatório Aprovado</title>
            <style>body{font-family:sans-serif;text-align:center;padding:50px;background:#f0fff4;color:#2f855a;}</style></head><body>
            <h2>✅ Sucesso!</h2><p>O relatório foi aprovado e disparado com sucesso para o grupo.</p>
            </body></html>";
            exit;
        } else {
            throw new Exception('A API do WhatsApp não retornou sucesso.');
        }
    } catch (Throwable $e) {
        Db::insert('report_log', [
            'client_id'   => $report['client_id'],
            'report_type' => $report['report_type'],
            'message'     => 'Erro ao aprovar: ' . $e->getMessage(),
            'sent_ok'     => 0,
        ]);
        echo "<!doctype html><html><head><meta charset=utf-8><meta name='viewport' content='width=device-width, initial-scale=1'><title>Erro</title>
        <style>body{font-family:sans-serif;text-align:center;padding:50px;background:#fff5f5;color:#c53030;}</style></head><body>
        <h2>❌ Erro ao enviar</h2><p>Houve uma falha na conexão com a Evolution API. Detalhes: " . htmlspecialchars($e->getMessage()) . "</p>
        </body></html>";
        exit;
    }
}
