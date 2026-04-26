<?php
require_once __DIR__ . '/app/bootstrap.php';
require_auth();

$sent = null;
$preview = null;
$error = null;

$accounts = Db::all(
    'SELECT ma.*, c.name AS client_name
     FROM meta_accounts ma JOIN clients c ON c.id = ma.client_id
     WHERE ma.active = 1 ORDER BY c.name, ma.account_name'
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? null)) {
    $to      = preg_replace('/\D/', '', $_POST['to'] ?? '');
    $type    = $_POST['report_type'] ?? 'daily_creatives';
    $accId   = (int) ($_POST['account_id'] ?? 0);

    if (!$to) {
        $error = 'Informe o número de destino.';
    } else {
        try {
            $wa  = WhatsAppClient::fromSettings();
            $rb  = ReportBuilder::fromSettings();
            $acc = $accId ? Db::one(
                'SELECT ma.*, c.name AS client_name FROM meta_accounts ma
                 JOIN clients c ON c.id = ma.client_id WHERE ma.id = ?', [$accId]
            ) : null;

            if (!$acc && !empty($accounts)) $acc = $accounts[0];

            if ($acc) {
                $preview = match ($type) {
                    'daily_creatives'  => $rb->buildDailyCreativeReport($acc),
                    'weekly_summary'   => $rb->buildWeeklySummary($acc),
                    'weekend_forecast' => $rb->buildWeekendForecast($acc),
                    default            => "🧪 *Teste SALDO WEB*\n\nSe você recebeu essa mensagem, a integração com WhatsApp está funcionando!\n\n_" . date('d/m/Y H:i') . "_",
                };
            } else {
                $preview = "🧪 *Teste SALDO WEB*\n\nSe você recebeu essa mensagem, a integração com WhatsApp está funcionando!\n\n_" . date('d/m/Y H:i') . "_";
            }

            // Formata número: adiciona 55 se não tiver, remove o 9º dígito se necessário
            if (!str_starts_with($to, '55')) $to = '55' . $to;
            $dest = $to . '@s.whatsapp.net';

            $r = $wa->sendText($dest, $preview);
            $sent = $r['ok'];
            if (!$r['ok']) {
                $error = 'Evolution API retornou erro: ' . json_encode($r['body']);
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$title = 'Teste WhatsApp · SALDO WEB';
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">🧪 Teste de WhatsApp</h1>
    <p class="page-subtitle">Envie uma mensagem de teste direto pro seu número antes de ativar os disparos</p>
  </div>
  <a href="<?= e(base_url('report_settings.php')) ?>" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Relatórios</a>
</div>

<?php if ($sent === true): ?>
<div class="alert alert-success" style="margin-bottom:20px">
  <i class="bi bi-check-circle-fill"></i> <strong>Mensagem enviada!</strong> Verifique o WhatsApp.
</div>
<?php elseif ($error): ?>
<div class="alert alert-danger" style="margin-bottom:20px">
  <i class="bi bi-x-circle-fill"></i> <strong>Erro:</strong> <?= e($error) ?>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

  <!-- Formulário -->
  <div class="card">
    <div class="card-header"><span class="card-header-title">Configurar envio</span></div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <div class="form-group">
          <label class="form-label">Número de destino</label>
          <input name="to" class="form-control" style="font-family:monospace"
                 placeholder="11999998888" value="<?= e($_POST['to'] ?? '') ?>"
                 required>
          <div class="form-hint">Só números, com DDD. Sem 55, sem +. Ex: 11999998888</div>
        </div>

        <div class="form-group">
          <label class="form-label">Tipo de relatório</label>
          <select name="report_type" class="form-select">
            <option value="ping" <?= ($_POST['report_type']??'ping')==='ping'?'selected':'' ?>>🧪 Ping simples (só testa a conexão)</option>
            <option value="daily_creatives" <?= ($_POST['report_type']??'')==='daily_creatives'?'selected':'' ?>>📊 Relatório diário de criativos</option>
            <option value="weekly_summary" <?= ($_POST['report_type']??'')==='weekly_summary'?'selected':'' ?>>📅 Resumo semanal</option>
            <option value="weekend_forecast" <?= ($_POST['report_type']??'')==='weekend_forecast'?'selected':'' ?>>🔮 Prévia de fim de semana</option>
          </select>
        </div>

        <?php if (!empty($accounts)): ?>
        <div class="form-group">
          <label class="form-label">Conta Meta (para gerar o relatório)</label>
          <select name="account_id" class="form-select">
            <?php foreach ($accounts as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= ((int)($_POST['account_id']??0))==$a['id']?'selected':'' ?>>
                <?= e($a['client_name'] . ' — ' . ($a['account_name'] ?: $a['ad_account_id'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary" style="width:100%">
          <i class="bi bi-whatsapp"></i> Enviar agora
        </button>
      </form>
    </div>
  </div>

  <!-- Preview -->
  <div class="card">
    <div class="card-header"><span class="card-header-title">Preview da mensagem</span></div>
    <div class="card-body">
      <?php if ($preview): ?>
        <div style="background:var(--surface-2);border-radius:var(--radius-sm);padding:16px;font-family:monospace;font-size:12.5px;white-space:pre-wrap;color:var(--text-2);line-height:1.7;max-height:500px;overflow-y:auto"><?= e($preview) ?></div>
      <?php else: ?>
        <div style="color:var(--text-4);font-size:13px;text-align:center;padding:40px 0">
          Envie uma mensagem para ver o preview aqui.
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php
$content = ob_get_clean();
require APP_DIR . '/views/layout.php';
