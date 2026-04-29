<?php
declare(strict_types=1);

/**
 * Monta as mensagens de relatório formatadas para WhatsApp.
 *
 * ARQUITETURA — Live API, sem cache:
 * ──────────────────────────────────────────────────────────────────────
 * Todos os relatórios buscam dados DIRETAMENTE da Meta API via
 * InsightsClient::fetchAccountReport(). Isso garante:
 *
 *   1. Paridade 100% com o Gerenciador de Anúncios (mesmos números,
 *      mesma janela de atribuição, mesmo `omni_purchase`).
 *   2. Zero dependência do cache (`ad_insights`, `campaign_insights`),
 *      eliminando qualquer possibilidade de desync entre o que está
 *      no banco e o que o cliente vê na Meta.
 *   3. Nenhuma operação manual ("rodar fix") nunca mais é necessária.
 *
 * O cache do banco continua sendo usado SOMENTE pelo dashboard de
 * performance (performance.php) — que prioriza velocidade sobre
 * frescor absoluto e exibe um aviso de "última coleta em XX:XX".
 *
 * WhatsApp suporta: *negrito*, _itálico_, ~tachado~, `monoespaçado`
 */
final class ReportBuilder
{
    private InsightsClient $insights;

    public function __construct(InsightsClient $insights)
    {
        $this->insights = $insights;
    }

    public static function fromSettings(): self
    {
        return new self(InsightsClient::fromSettings());
    }

    // ═════════════════════════ RELATÓRIO DIÁRIO ═════════════════════════

    /**
     * Relatório diário de uma conta. Padrão: ontem.
     * Para hoje passe $date = date('Y-m-d') — vai buscar o parcial.
     */
    public function buildDailyCreativeReport(array $account, ?string $date = null): string
    {
        $date      = $date ?? date('Y-m-d', strtotime('-1 day'));
        $dateLabel = date('d/m/Y', strtotime($date));

        $report = $this->safeFetch($account, $date, $date);

        $isToday   = $date === date('Y-m-d');
        $titleIcon = '📊';
        $title     = $isToday ? 'Resumo Parcial de Hoje' : 'Resumo Diário';
        $periodTxt = $dateLabel;

        return $this->renderReport($account, $report, [
            'icon'         => $titleIcon,
            'title'        => $title,
            'period_label' => $periodTxt,
            'section_label'=> 'Resultado do Dia',
            'empty_text'   => $isToday
                ? 'Nenhuma impressão registrada hoje ainda. Volte mais tarde.'
                : "Nenhuma impressão registrada em {$dateLabel}.",
        ]);
    }

    // ═════════════════════════ RELATÓRIO SEMANAL ═════════════════════════

    /**
     * Resumo semanal. Padrão: últimos 7 dias completos (ontem - 7).
     */
    public function buildWeeklySummary(array $account, ?string $weekStart = null): string
    {
        $weekEnd   = date('Y-m-d', strtotime('-1 day'));
        $weekStart = $weekStart ?? date('Y-m-d', strtotime('-7 days'));

        $report = $this->safeFetch($account, $weekStart, $weekEnd);

        return $this->renderReport($account, $report, [
            'icon'          => '📅',
            'title'         => 'Resumo Semanal',
            'period_label'  => date('d/m', strtotime($weekStart)) . ' a ' . date('d/m', strtotime($weekEnd)),
            'section_label' => 'Resultado da Semana',
            'empty_text'    => 'Nenhuma impressão ou gasto registrado nos últimos 7 dias. Verifique se as campanhas estão ativas.',
        ]);
    }

    // ═════════════════════════ RELATÓRIO SEMANAL AVANÇADO ═════════════════════════

    /**
     * Resumo semanal avançado — mesma base, mais detalhamento de funil.
     */
    public function buildAdvancedWeeklyReport(array $account, ?string $weekStart = null): string
    {
        $weekEnd   = date('Y-m-d', strtotime('-1 day'));
        $weekStart = $weekStart ?? date('Y-m-d', strtotime('-7 days'));

        $report = $this->safeFetch($account, $weekStart, $weekEnd);

        $clientName = $account['client_name'] ?? $account['name'] ?? 'Cliente';
        $accName    = $account['account_name'] ?: ('Conta ' . $account['ad_account_id']);
        $currency   = $account['currency'] ?: 'BRL';
        $period     = date('d/m', strtotime($weekStart)) . ' a ' . date('d/m', strtotime($weekEnd));

        $s = $report['summary'];
        if (($s['impressions'] ?? 0) == 0) {
            return "📊 *Relatório Semanal Avançado — {$clientName}*\n_{$accName}_\n_{$period}_\n\nNenhuma impressão ou gasto registrado nos últimos 7 dias. Verifique se as campanhas estão ativas.";
        }

        $lines = [];
        $lines[] = "📊 *Relatório Semanal Avançado — {$clientName}*";
        $lines[] = "_{$accName}_";
        $lines[] = "_{$period}_";
        $lines[] = '';
        $lines[] = '💰 *Resultado Geral da Semana*';
        $lines[] = '• Gasto: *' . $this->fmtMoney($s['spend'], $currency) . '*';
        $lines[] = '• Receita: *' . $this->fmtMoney($s['conversion_value'], $currency) . '*';
        if ($s['roas'] > 0) $lines[] = '• ROAS: *' . number_format($s['roas'], 2) . 'x*';
        $lines[] = '• Conversões: *' . $s['conversions'] . '*';
        if ($s['cpa'] > 0) $lines[] = '• CPA Médio: *' . $this->fmtMoney($s['cpa'], $currency) . '*';

        $lines[] = '';
        $lines[] = '🌪️ *Métricas de Funil*';
        $lines[] = '🔹 *Topo (Atração)*';
        $lines[] = '• CPM: *' . $this->fmtMoney($s['cpm'], $currency) . '*';
        $lines[] = '• CPC: *' . $this->fmtMoney($s['cpc'], $currency) . '*';
        $lines[] = '• CTR: *' . number_format($s['ctr'], 2) . '%*';
        $lines[] = '• Visualizações de Página: *' . $s['landing_page_views'] . '*';
        $lines[] = '';
        $lines[] = '🔸 *Meio (Consideração)*';
        $lines[] = '• Adições ao Carrinho: *' . $s['adds_to_cart'] . '*';
        $lines[] = '• Checkouts Iniciados: *' . $s['initiates_checkout'] . '*';
        $lines[] = '';
        $lines[] = '🎯 *Fundo (Conversão)*';
        $lines[] = '• Compras (Conversões): *' . $s['conversions'] . '*';

        if (!empty($report['campaigns'])) {
            $lines[] = '';
            $lines[] = '📈 *Performance por Campanha*';
            foreach ($report['campaigns'] as $c) {
                if ($c['impressions'] == 0) continue;
                $lines[] = $this->renderCampaignBlock($c, $currency);
            }
        }

        $lines[] = '';
        $lines[] = '_Saldo WEB · ' . date('d/m H:i') . '_';
        return implode("\n", $lines);
    }

    // ═════════════════════════ RELATÓRIO MENSAL ═════════════════════════

    /**
     * Resumo do mês anterior. Útil para fechamento mensal.
     */
    public function buildMonthlySummary(array $account, ?string $monthStart = null): string
    {
        if ($monthStart) {
            $start = $monthStart;
            $end   = date('Y-m-t', strtotime($monthStart));
        } else {
            $start = date('Y-m-01', strtotime('first day of last month'));
            $end   = date('Y-m-t', strtotime('last day of last month'));
        }

        $report = $this->safeFetch($account, $start, $end);

        return $this->renderReport($account, $report, [
            'icon'          => '🗓',
            'title'         => 'Resumo Mensal',
            'period_label'  => date('m/Y', strtotime($start)) . ' (' . date('d/m', strtotime($start)) . ' a ' . date('d/m', strtotime($end)) . ')',
            'section_label' => 'Resultado do Mês',
            'empty_text'    => 'Nenhuma impressão registrada no período.',
        ]);
    }

    // ═════════════════════════ RELATÓRIO PERSONALIZADO ═════════════════════════

    /**
     * Relatório para qualquer período de/até.
     */
    public function buildCustomReport(array $account, string $dateFrom, string $dateTo): string
    {
        $report = $this->safeFetch($account, $dateFrom, $dateTo);

        $labelFrom = date('d/m/Y', strtotime($dateFrom));
        $labelTo   = date('d/m/Y', strtotime($dateTo));
        $period    = $dateFrom === $dateTo ? $labelFrom : "{$labelFrom} a {$labelTo}";

        return $this->renderReport($account, $report, [
            'icon'          => '🗓️',
            'title'         => 'Relatório Personalizado',
            'period_label'  => $period,
            'section_label' => 'Resultado do Período',
            'empty_text'    => 'Nenhuma impressão registrada neste período.',
        ]);
    }

    // ═════════════════════════ PRÉVIA DE FIM DE SEMANA ═════════════════════════

    /**
     * Prévia de fim de semana — sexta antes de sair do ar.
     */
    public function buildWeekendForecast(array $account, ?string $referenceDate = null): string
    {
        $today      = $referenceDate ?? date('Y-m-d');
        $weekStart  = date('Y-m-d', strtotime('monday this week', strtotime($today)));
        $clientName = $account['client_name'] ?? $account['name'] ?? 'Cliente';
        $accName    = $account['account_name'] ?: ('Conta ' . $account['ad_account_id']);
        $currency   = $account['currency'] ?: 'BRL';

        $weekReport = $this->safeFetch($account, $weekStart, $today);
        $last7Start = date('Y-m-d', strtotime('-7 days', strtotime($today)));
        $last7      = $this->safeFetch($account, $last7Start, $today);

        $s         = $weekReport['summary'];
        $avgDaily  = (float) ($last7['summary']['spend'] ?? 0) / 7;
        $projected = $avgDaily * 2;

        // Top 3 ads ativos
        $topAds = [];
        try {
            $topAds = $this->insights->fetchTopAds($account['ad_account_id'], $weekStart, $today, 3);
        } catch (Throwable $e) { /* ignora */ }

        $lines = [];
        $lines[] = "🔮 *Prévia do Fim de Semana — {$clientName}*";
        $lines[] = "_{$accName}_";
        $lines[] = '_' . date('d/m', strtotime($weekStart)) . ' a hoje_';
        $lines[] = '';
        $lines[] = '📈 *Semana até agora*';
        $lines[] = '• Gasto: *' . $this->fmtMoney($s['spend'], $currency) . '*';
        if ($s['conversion_value'] > 0) $lines[] = '• Receita: *' . $this->fmtMoney($s['conversion_value'], $currency) . '*';
        if ($s['roas'] > 0)             $lines[] = '• ROAS: *' . number_format($s['roas'], 2) . 'x*';
        if ($s['conversions'] > 0)      $lines[] = '• Conversões: *' . $s['conversions'] . '*';

        $lines[] = '';
        $lines[] = '🗓 *Projeção do final de semana (sáb + dom)*';
        $lines[] = '• Média diária (7d): *' . $this->fmtMoney($avgDaily, $currency) . '*';
        $lines[] = '• Gasto estimado: *~' . $this->fmtMoney($projected, $currency) . '*';

        if (!empty($topAds)) {
            $lines[] = '';
            $lines[] = '🏆 *Criativos para monitorar*';
            foreach ($topAds as $i => $ad) {
                if (($ad['effective_status'] ?? '') !== 'ACTIVE') continue;
                $medal = ['🥇', '🥈', '🥉'][$i] ?? '▫️';
                $name  = self::truncate($ad['ad_name'] ?? 'Anúncio', 55);
                $lines[] = "{$medal} {$name}";
                if ($ad['roas'] > 0) {
                    $lines[] = '   ROAS: *' . number_format($ad['roas'], 2) . 'x* · Gasto: ' . $this->fmtMoney($ad['spend'], $currency);
                }
            }
        }

        $lines[] = '';
        $lines[] = '_Tenha um bom fim de semana! 🚀_';
        $lines[] = '_Saldo WEB · ' . date('d/m H:i') . '_';

        return implode("\n", $lines);
    }

    // ═════════════════════════ INTERNALS ═════════════════════════

    /**
     * Faz fetch da API com fallback estruturado em caso de erro.
     */
    private function safeFetch(array $account, string $since, string $until): array
    {
        try {
            return $this->insights->fetchAccountReport($account['ad_account_id'], $since, $until);
        } catch (Throwable $e) {
            return [
                'summary'   => $this->emptySummary(),
                'campaigns' => [],
                'period'    => ['since' => $since, 'until' => $until],
                'error'     => $e->getMessage(),
            ];
        }
    }

    private function emptySummary(): array
    {
        return [
            'spend'              => 0.0,
            'impressions'        => 0,
            'clicks'             => 0,
            'reach'              => 0,
            'conversions'        => 0,
            'conversion_value'   => 0.0,
            'leads'              => 0,
            'landing_page_views' => 0,
            'adds_to_cart'       => 0,
            'initiates_checkout' => 0,
            'ctr' => 0, 'cpc' => 0, 'cpm' => 0, 'cpa' => 0, 'roas' => 0,
        ];
    }

    /**
     * Renderiza o relatório padrão (Diário / Semanal / Mensal / Personalizado).
     * O formato é único — só muda o título, ícone e label do período.
     *
     * @param array $opts {
     *   icon: string,             // emoji do título
     *   title: string,            // ex: "Resumo Diário"
     *   period_label: string,     // ex: "29/04/2026"
     *   section_label: string,    // ex: "Resultado do Dia"
     *   empty_text: string        // mensagem quando não há impressões
     * }
     */
    private function renderReport(array $account, array $report, array $opts): string
    {
        $clientName = $account['client_name'] ?? $account['name'] ?? 'Cliente';
        $accName    = $account['account_name'] ?: ('Conta ' . $account['ad_account_id']);
        $currency   = $account['currency'] ?: 'BRL';

        $s = $report['summary'];

        // Vazio
        if (($s['impressions'] ?? 0) == 0) {
            $errorTxt = !empty($report['error']) ? "\n\n⚠️ Erro ao buscar dados: " . $report['error'] : '';
            return "{$opts['icon']} *{$opts['title']} — {$clientName}*\n_{$accName} · {$opts['period_label']}_\n\n{$opts['empty_text']}{$errorTxt}";
        }

        $lines = [];
        $lines[] = "{$opts['icon']} *{$opts['title']} — {$clientName}*";
        $lines[] = "_{$accName} · {$opts['period_label']}_";
        $lines[] = '';
        $lines[] = "💰 *{$opts['section_label']}*";
        $lines[] = '• Gasto: *' . $this->fmtMoney($s['spend'], $currency) . '*';
        $lines[] = '• Receita (Valor de Conversão): *' . $this->fmtMoney($s['conversion_value'], $currency) . '*';
        if ($s['roas'] > 0) $lines[] = '• ROAS: *' . number_format($s['roas'], 2) . 'x*';
        $lines[] = '• Compras Realizadas: *' . $s['conversions'] . '*';
        if ($s['cpa'] > 0) $lines[] = '• CPA: *' . $this->fmtMoney($s['cpa'], $currency) . '*';
        $lines[] = '• Cliques: *' . $s['clicks'] . '*';
        $lines[] = '• CPC: *' . $this->fmtMoney($s['cpc'], $currency) . '*';
        $lines[] = '• CTR: *' . number_format($s['ctr'], 2) . '%*';
        $lines[] = '• Visualizações de Página: *' . $s['landing_page_views'] . '*';
        $lines[] = '• Adições ao Carrinho: *' . $s['adds_to_cart'] . '*';
        $lines[] = '• Checkouts Iniciados: *' . $s['initiates_checkout'] . '*';

        // Apenas campanhas com impressões > 0 entram no breakdown
        $activeCampaigns = array_filter($report['campaigns'], fn($c) => $c['impressions'] > 0);
        if (!empty($activeCampaigns)) {
            $lines[] = '';
            $lines[] = '📈 *Performance por Campanha*';
            foreach ($activeCampaigns as $c) {
                $lines[] = $this->renderCampaignBlock($c, $currency);
            }
        }

        $lines[] = '_Saldo WEB · ' . date('H:i') . '_';

        return implode("\n", $lines);
    }

    /**
     * Renderiza o bloco de uma campanha no breakdown.
     */
    private function renderCampaignBlock(array $c, string $currency): string
    {
        $name = self::truncate($c['campaign_name'] ?? 'Campanha Desconhecida', 50);
        $b = [];
        $b[] = "• *{$name}*";
        $b[] = '   Gasto: ' . $this->fmtMoney($c['spend'], $currency)
             . ' · Receita: ' . $this->fmtMoney($c['conversion_value'], $currency)
             . ' · ROAS: ' . number_format($c['roas'], 2) . 'x';
        $b[] = '   Cliques: ' . $c['clicks']
             . ' · CPC: ' . $this->fmtMoney($c['cpc'], $currency)
             . ' · CTR: ' . number_format($c['ctr'], 2) . '%';
        $b[] = '   Vis. Página: ' . $c['landing_page_views']
             . ' · Carrinho: ' . $c['adds_to_cart']
             . ' · Checkout: ' . $c['initiates_checkout']
             . ' · Compras: ' . $c['conversions'];
        $b[] = '';
        return implode("\n", $b);
    }

    // ════════════════════════════ HELPERS ════════════════════════════

    private function fmtMoney(float $value, string $currency): string
    {
        if ($currency === 'BRL') {
            return 'R$ ' . number_format($value, 2, ',', '.');
        }
        return number_format($value, 2) . ' ' . $currency;
    }

    private static function truncate(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
