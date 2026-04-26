<?php
declare(strict_types=1);

/**
 * Coleta métricas de performance (ROAS, CPA, criativos etc.) de todas as contas ativas.
 * Agendar no cPanel: 30 * * * * /usr/bin/php /home/USUARIO/cron/collect_insights.php
 * (roda 2x por dia: meia-noite e meio-dia — ajuste conforme preferir)
 * Para coleta apenas do dia anterior, rode às 01:00.
 *
 * Por padrão coleta os dados de "yesterday". Em situações específicas pode-se
 * passar o parâmetro --date=YYYY-MM-DD para reprocessar um dia específico.
 */

if (!defined('APP_ROOT')) {
    require_once __DIR__ . '/../app/bootstrap.php';
}

set_time_limit(600); // 10 min max

// Permite override de data via CLI: php collect_insights.php --date=2024-01-15
$dateOverride = null;
foreach (($argv ?? []) as $arg) {
    if (str_starts_with($arg, '--date=')) {
        $dateOverride = substr($arg, 7);
    }
}

$datePreset = $dateOverride ? 'custom' : 'yesterday';
$since = $dateOverride;
$until = $dateOverride;

$log = [];
$collected = 0;
$errors    = 0;

try {
    $insights = InsightsClient::fromSettings();
} catch (Throwable $e) {
    if (PHP_SAPI === 'cli') fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$accounts = Db::all(
    'SELECT ma.*, c.name AS client_name
     FROM meta_accounts ma
     JOIN clients c ON c.id = ma.client_id
     WHERE ma.active = 1 AND c.active = 1 AND ma.collect_insights = 1'
);

foreach ($accounts as $acc) {
    try {
        // Insights por anúncio (mais granular — criativos)
        $r1 = $insights->collectAdInsights((int) $acc['id'], $acc['ad_account_id'], $datePreset, $since, $until);
        // Insights por campanha
        $r2 = $insights->collectCampaignInsights((int) $acc['id'], $acc['ad_account_id'], $datePreset, $since, $until);

        $collected += $r1['collected'] + $r2['collected'];
        $errors    += $r1['errors'];
        $log[] = "[OK] {$acc['ad_account_id']}: {$r1['collected']} ads, {$r2['collected']} campaigns";
    } catch (Throwable $e) {
        $errors++;
        $log[] = "[ERR] {$acc['ad_account_id']}: " . $e->getMessage();
    }
}

if (PHP_SAPI === 'cli') {
    echo "Collected: $collected | Errors: $errors\n";
    foreach ($log as $l) echo $l . "\n";
}
