<?php
declare(strict_types=1);

/**
 * Monta as mensagens de relatório formatadas para WhatsApp.
 * O WhatsApp suporta: *negrito*, _itálico_, ~tachado~, `monoespaçado`
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

    // ─────────────────────────── RELATÓRIO DIÁRIO DE CRIATIVOS ───────────────────────────

    /**
     * Gera relatório diário de criativos para uma conta.
     * Inclui: top 3 por ROAS, bottom 3 por CPA, resumo do dia, comparativo vs semana passada.
     *
     * @param array $account   Linha da tabela meta_accounts (com client_name)
     * @param string $date     Data do relatório (padrão: ontem)
     */
    public function buildDailyCreativeReport(array $account, ?string $date = null): string
    {
        $date       = $date ?? date('Y-m-d', strtotime('-1 day'));
        $dateLabel  = date('d/m/Y', strtotime($date));
        $accId      = (int) $account['id'];
        $clientName = $account['client_name'] ?? $account['name'] ?? 'Cliente';
        $accName    = $account['account_name'] ?: ('Conta ' . $account['ad_account_id']);
        $currency   = $account['currency'] ?: 'BRL';

        // Funnel totals from campaign_insights (only campaigns with impressions)
        $funnel = Db::one(
            'SELECT
                SUM(impressions) AS imp, SUM(clicks) AS clk, SUM(spend) AS spend,
                SUM(landing_page_views) AS lpv,
                SUM(adds_to_cart) AS atc,
                SUM(initiates_checkout) AS ic,
                SUM(conversions) AS conv,
                SUM(conversion_value) AS revenue
             FROM campaign_insights
             WHERE meta_account_id = ? AND date_start = ? AND date_stop = ? AND impressions > 0',
            [$accId, $date, $date]
        );

        $spend = (float) ($funnel['spend'] ?? 0);
        $imp   = (int) ($funnel['imp'] ?? 0);

        if ($imp == 0) {
            $diaExtenso = ($date === date('Y-m-d')) ? 'hoje' : 'ontem';
            return sprintf(
                "📊 *Resumo Diário — %s*\n\n%s · %s\n\nNenhuma impressão ou gasto registrado para %s. Verifique se as campanhas estão ativas.",
                $clientName, $accName, $dateLabel, $diaExtenso
            );
        }

        $revenue = (float) ($funnel['revenue'] ?? 0);
        $roas    = $spend > 0 ? $revenue / $spend : 0;
        $convs   = (int)   ($funnel['conv'] ?? 0);
        $cpa     = $convs > 0 ? $spend / $convs : 0;
        $clk     = (int)   ($funnel['clk'] ?? 0);
        $cpc     = $clk > 0 ? $spend / $clk : 0;
        $ctr     = $imp > 0 ? ($clk / $imp) * 100 : 0;

        // Todas as campanhas que imprimiram no dia
        $campaigns = Db::all(
            'SELECT campaign_name,
                    SUM(impressions) AS imp, SUM(clicks) AS clk, SUM(spend) AS spend,
                    SUM(landing_page_views) AS lpv, SUM(adds_to_cart) AS atc,
                    SUM(initiates_checkout) AS ic, SUM(conversions) AS conv,
                    SUM(conversion_value) AS revenue
             FROM campaign_insights
             WHERE meta_account_id = ? AND date_start = ? AND date_stop = ? AND impressions > 0
             GROUP BY campaign_name
             ORDER BY spend DESC',
            [$accId, $date, $date]
        );

        $lines = [];
        $lines[] = "📊 *Resumo Diário — {$clientName}*";
        $lines[] = "_{$accName} · {$dateLabel}_";
        $lines[] = '';
        $lines[] = '💰 *Resultado do Dia*';
        $lines[] = '• Gasto: *' . $this->fmtMoney($spend, $currency) . '*';
        $lines[] = '• Receita (Valor de Conversão): *' . $this->fmtMoney($revenue, $currency) . '*';
        if ($roas > 0) $lines[] = '• ROAS: *' . number_format($roas, 2) . 'x*';
        $lines[] = '• Compras Realizadas: *' . $convs . '*';
        if ($cpa > 0) $lines[] = '• CPA: *' . $this->fmtMoney($cpa, $currency) . '*';
        $lines[] = '• Cliques: *' . $clk . '*';
        $lines[] = '• CPC: *' . $this->fmtMoney($cpc, $currency) . '*';
        $lines[] = '• CTR: *' . number_format($ctr, 2) . '%*';
        $lines[] = '• Visualizações de Página: *' . ($funnel['lpv'] ?? 0) . '*';
        $lines[] = '• Adições ao Carrinho: *' . ($funnel['atc'] ?? 0) . '*';
        $lines[] = '• Checkouts Iniciados: *' . ($funnel['ic'] ?? 0) . '*';

        if (!empty($campaigns)) {
            $lines[] = '';
            $lines[] = '📈 *Performance por Campanha*';
            foreach ($campaigns as $c) {
                $cSpend = (float)($c['spend'] ?? 0);
                $cRev   = (float)($c['revenue'] ?? 0);
                $cRoas  = $cSpend > 0 ? $cRev / $cSpend : 0;
                $cConvs = (int)($c['conv'] ?? 0);
                $cCpa   = $cConvs > 0 ? $cSpend / $cConvs : 0;
                $cImp   = (int)($c['imp'] ?? 0);
                $cClk   = (int)($c['clk'] ?? 0);
                $cCtr   = $cImp > 0 ? ($cClk / $cImp) * 100 : 0;
                $cCpc   = $cClk > 0 ? $cSpend / $cClk : 0;

                $lines[] = "• *" . self::truncate($c['campaign_name'] ?? 'Campanha Desconhecida', 50) . "*";
                $lines[] = '   Gasto: ' . $this->fmtMoney($cSpend, $currency) . ' · Receita: ' . $this->fmtMoney($cRev, $currency) . ' · ROAS: ' . number_format($cRoas, 2) . 'x';
                $lines[] = '   Cliques: ' . $cClk . ' · CPC: ' . $this->fmtMoney($cCpc, $currency) . ' · CTR: ' . number_format($cCtr, 2) . '%';
                $lines[] = '   Vis. Página: ' . ($c['lpv'] ?? 0) . ' · Carrinho: ' . ($c['atc'] ?? 0) . ' · Checkout: ' . ($c['ic'] ?? 0) . ' · Compras: ' . $cConvs;
                $lines[] = ''; // Add a blank line between campaigns for readability
            }
        }

        $lines[] = '_Saldo WEB · ' . date('H:i', time()) . '_';

        return implode("\n", $lines);
    }

    // ──────────────────────────── RELATÓRIO SEMANAL ────────────────────────────

    public function buildWeeklySummary(array $account, ?string $weekStart = null): string
    {
        $weekStart = $weekStart ?? date('Y-m-d', strtotime('-7 days'));
        $weekEnd   = date('Y-m-d', strtotime('-1 day'));

        $accId      = (int) $account['id'];
        $clientName = $account['client_name'] ?? $account['name'];
        $accName    = $account['account_name'] ?: ('Conta ' . $account['ad_account_id']);
        $currency   = $account['currency'] ?: 'BRL';

        $funnel = Db::one(
            'SELECT
                SUM(impressions) AS imp, SUM(clicks) AS clk, SUM(spend) AS spend,
                SUM(landing_page_views) AS lpv,
                SUM(adds_to_cart) AS atc,
                SUM(initiates_checkout) AS ic,
                SUM(conversions) AS conv,
                SUM(conversion_value) AS revenue
             FROM campaign_insights
             WHERE meta_account_id = ? AND date_start >= ? AND date_stop <= ? AND impressions > 0',
            [$accId, $weekStart, $weekEnd]
        );

        $spend = (float) ($funnel['spend'] ?? 0);
        $imp   = (int) ($funnel['imp'] ?? 0);

        if ($imp == 0) {
            return sprintf(
                "📅 *Resumo Semanal — %s*\n\n%s · %s a %s\n\nNenhuma impressão ou gasto registrado nos últimos 7 dias. Verifique se as campanhas estão ativas.",
                $clientName, $accName, date('d/m', strtotime($weekStart)), date('d/m', strtotime($weekEnd))
            );
        }

        $revenue = (float) ($funnel['revenue'] ?? 0);
        $roas    = $spend > 0 ? $revenue / $spend : 0;
        $convs   = (int)   ($funnel['conv'] ?? 0);
        $cpa     = $convs > 0 ? $spend / $convs : 0;
        $clk     = (int)   ($funnel['clk'] ?? 0);
        $cpc     = $clk > 0 ? $spend / $clk : 0;
        $ctr     = $imp > 0 ? ($clk / $imp) * 100 : 0;

        $campaigns = Db::all(
            'SELECT campaign_name,
                    SUM(impressions) AS imp, SUM(clicks) AS clk, SUM(spend) AS spend,
                    SUM(landing_page_views) AS lpv, SUM(adds_to_cart) AS atc,
                    SUM(initiates_checkout) AS ic, SUM(conversions) AS conv,
                    SUM(conversion_value) AS revenue
             FROM campaign_insights
             WHERE meta_account_id = ? AND date_start >= ? AND date_stop <= ? AND impressions > 0
             GROUP BY campaign_name
             ORDER BY spend DESC',
            [$accId, $weekStart, $weekEnd]
        );

        $lines = [];
        $lines[] = "📅 *Resumo Semanal — {$clientName}*";
        $lines[] = "_{$accName}_";
        $lines[] = '_' . date('d/m', strtotime($weekStart)) . ' a ' . date('d/m', strtotime($weekEnd)) . '_';
        $lines[] = '';
        $lines[] = '💰 *Resultado da Semana*';
        $lines[] = '• Gasto: *' . $this->fmtMoney($spend, $currency) . '*';
        $lines[] = '• Receita (Valor de Conversão): *' . $this->fmtMoney($revenue, $currency) . '*';
        if ($roas > 0) $lines[] = '• ROAS: *' . number_format($roas, 2) . 'x*';
        $lines[] = '• Compras Realizadas: *' . $convs . '*';
        if ($cpa > 0) $lines[] = '• CPA: *' . $this->fmtMoney($cpa, $currency) . '*';
        $lines[] = '• Cliques: *' . $clk . '*';
        $lines[] = '• CPC: *' . $this->fmtMoney($cpc, $currency) . '*';
        $lines[] = '• CTR: *' . number_format($ctr, 2) . '%*';
        $lines[] = '• Visualizações de Página: *' . ($funnel['lpv'] ?? 0) . '*';
        $lines[] = '• Adições ao Carrinho: *' . ($funnel['atc'] ?? 0) . '*';
        $lines[] = '• Checkouts Iniciados: *' . ($funnel['ic'] ?? 0) . '*';

        if (!empty($campaigns)) {
            $lines[] = '';
            $lines[] = '📈 *Performance por Campanha*';
            foreach ($campaigns as $c) {
                $cSpend = (float)($c['spend'] ?? 0);
                $cRev   = (float)($c['revenue'] ?? 0);
                $cRoas  = $cSpend > 0 ? $cRev / $cSpend : 0;
                $cConvs = (int)($c['conv'] ?? 0);
                $cCpa   = $cConvs > 0 ? $cSpend / $cConvs : 0;
                $cImp   = (int)($c['imp'] ?? 0);
                $cClk   = (int)($c['clk'] ?? 0);
                $cCtr   = $cImp > 0 ? ($cClk / $cImp) * 100 : 0;
                $cCpc   = $cClk > 0 ? $cSpend / $cClk : 0;

                $lines[] = "• *" . self::truncate($c['campaign_name'] ?? 'Campanha Desconhecida', 50) . "*";
                $lines[] = '   Gasto: ' . $this->fmtMoney($cSpend, $currency) . ' · Receita: ' . $this->fmtMoney($cRev, $currency) . ' · ROAS: ' . number_format($cRoas, 2) . 'x';
                $lines[] = '   Cliques: ' . $cClk . ' · CPC: ' . $this->fmtMoney($cCpc, $currency) . ' · CTR: ' . number_format($cCtr, 2) . '%';
                $lines[] = '   Vis. Página: ' . ($c['lpv'] ?? 0) . ' · Carrinho: ' . ($c['atc'] ?? 0) . ' · Checkout: ' . ($c['ic'] ?? 0) . ' · Compras: ' . $cConvs;
                $lines[] = ''; // Add a blank line between campaigns for readability
            }
        }

        $lines[] = '_Saldo WEB · ' . date('H:i', time()) . '_';

        return implode("\n", $lines);
    }

    // ──────────────────────────── RELATÓRIO SEMANAL AVANÇADO ────────────────────────────

    public function buildAdvancedWeeklyReport(array $account, ?string $weekStart = null): string
    {
        $weekStart = $weekStart ?? date('Y-m-d', strtotime('-7 days'));
        $weekEnd   = date('Y-m-d', strtotime('-1 day'));

        $accId      = (int) $account['id'];
        $clientName = $account['client_name'] ?? $account['name'];
        $accName    = $account['account_name'] ?: ('Conta ' . $account['ad_account_id']);
        $currency   = $account['currency'] ?: 'BRL';

        // Overall week summary
        $curr = $this->insights->getAccountSummary($accId, $weekStart, $weekEnd);

        // Funnel totals from campaign_insights
        $funnel = Db::one(
            'SELECT
                SUM(impressions) AS imp, SUM(clicks) AS clk, SUM(spend) AS spend,
                SUM(landing_page_views) AS lpv,
                SUM(adds_to_cart) AS atc,
                SUM(initiates_checkout) AS ic,
                SUM(conversions) AS conv
             FROM campaign_insights
             WHERE meta_account_id = ? AND date_start >= ? AND date_stop <= ?',
            [$accId, $weekStart, $weekEnd]
        );

        $cpm = ($funnel['imp'] ?? 0) > 0 ? (($funnel['spend'] ?? 0) / $funnel['imp']) * 1000 : 0;
        $cpc = ($funnel['clk'] ?? 0) > 0 ? ($funnel['spend'] ?? 0) / $funnel['clk'] : 0;
        $ctr = ($funnel['imp'] ?? 0) > 0 ? (($funnel['clk'] ?? 0) / $funnel['imp']) * 100 : 0;

        $campaigns = Db::all(
            'SELECT campaign_name,
                    SUM(impressions) AS imp, SUM(clicks) AS clk, SUM(spend) AS spend,
                    SUM(landing_page_views) AS lpv, SUM(adds_to_cart) AS atc,
                    SUM(initiates_checkout) AS ic, SUM(conversions) AS conv,
                    SUM(conversion_value) AS revenue
             FROM campaign_insights
             WHERE meta_account_id = ? AND date_start >= ? AND date_stop <= ? AND impressions > 0
             GROUP BY campaign_name
             ORDER BY spend DESC',
            [$accId, $weekStart, $weekEnd]
        );

        $lines = [];
        $lines[] = "📊 *Relatório Semanal Avançado — {$clientName}*";
        $lines[] = "_{$accName}_";
        $lines[] = '_' . date('d/m', strtotime($weekStart)) . ' a ' . date('d/m', strtotime($weekEnd)) . '_';
        $lines[] = '';
        $lines[] = '💰 *Resultado Geral da Semana*';
        $lines[] = '• Gasto: *' . $this->fmtMoney((float)($curr['total_spend']??0), $currency) . '*';
        $lines[] = '• Receita: *' . $this->fmtMoney((float)($curr['total_revenue']??0), $currency) . '*';
        if (($curr['avg_roas'] ?? 0) > 0) {
            $lines[] = '• ROAS: *' . number_format((float)$curr['avg_roas'], 2) . 'x*';
        }
        $lines[] = '• Conversões: *' . ($curr['total_conversions'] ?? 0) . '*';
        if (($curr['avg_cpa'] ?? 0) > 0) {
            $lines[] = '• CPA Médio: *' . $this->fmtMoney((float)$curr['avg_cpa'], $currency) . '*';
        }

        $lines[] = '';
        $lines[] = '🌪️ *Métricas de Funil*';
        $lines[] = '🔹 *Topo (Atração)*';
        $lines[] = '• CPM: *' . $this->fmtMoney($cpm, $currency) . '*';
        $lines[] = '• CPC: *' . $this->fmtMoney($cpc, $currency) . '*';
        $lines[] = '• CTR: *' . number_format($ctr, 2) . '%*';
        $lines[] = '• Visualizações de Página: *' . ($funnel['lpv'] ?? 0) . '*';
        $lines[] = '';
        $lines[] = '🔸 *Meio (Consideração)*';
        $lines[] = '• Adições ao Carrinho: *' . ($funnel['atc'] ?? 0) . '*';
        $lines[] = '• Checkouts Iniciados: *' . ($funnel['ic'] ?? 0) . '*';
        $lines[] = '';
        $lines[] = '🎯 *Fundo (Conversão)*';
        $lines[] = '• Compras (Conversões): *' . ($funnel['conv'] ?? 0) . '*';

        if (!empty($campaigns)) {
            $lines[] = '';
            $lines[] = '📈 *Performance por Campanha*';
            foreach ($campaigns as $c) {
                $cSpend = (float)($c['spend'] ?? 0);
                $cRev   = (float)($c['revenue'] ?? 0);
                $cRoas  = $cSpend > 0 ? $cRev / $cSpend : 0;
                $cConvs = (int)($c['conv'] ?? 0);
                $cCpa   = $cConvs > 0 ? $cSpend / $cConvs : 0;
                $cImp   = (int)($c['imp'] ?? 0);
                $cClk   = (int)($c['clk'] ?? 0);
                $cCtr   = $cImp > 0 ? ($cClk / $cImp) * 100 : 0;
                $cCpc   = $cClk > 0 ? $cSpend / $cClk : 0;

                $lines[] = "• *" . self::truncate($c['campaign_name'] ?? 'Campanha Desconhecida', 50) . "*";
                $lines[] = '   Gasto: ' . $this->fmtMoney($cSpend, $currency) . ' · Receita: ' . $this->fmtMoney($cRev, $currency) . ' · ROAS: ' . number_format($cRoas, 2) . 'x';
                $lines[] = '   Cliques: ' . $cClk . ' · CPC: ' . $this->fmtMoney($cCpc, $currency) . ' · CTR: ' . number_format($cCtr, 2) . '%';
                $lines[] = '   Vis. Página: ' . ($c['lpv'] ?? 0) . ' · Carrinho: ' . ($c['atc'] ?? 0) . ' · Checkout: ' . ($c['ic'] ?? 0) . ' · Compras: ' . $cConvs;
                $lines[] = ''; // Add a blank line
            }
        }

        $lines[] = '';
        $lines[] = '_Saldo WEB · ' . date('d/m H:i') . '_';
        return implode("\n", $lines);
    }

    // ──────────────────────────── RELATÓRIO PERSONALIZADO (período livre) ────────────────────────────

    /**
     * Gera relatório para qualquer período: de $dateFrom até $dateTo.
     * Mesmo formato do diário, mas com o intervalo configurado.
     */
    public function buildCustomReport(array $account, string $dateFrom, string $dateTo): string
    {
        $accId      = (int) $account['id'];
        $clientName = $account['client_name'] ?? $account['name'] ?? 'Cliente';
        $accName    = $account['account_name'] ?: ('Conta ' . $account['ad_account_id']);
        $currency   = $account['currency'] ?: 'BRL';

        $labelFrom = date('d/m/Y', strtotime($dateFrom));
        $labelTo   = date('d/m/Y', strtotime($dateTo));
        $periodLabel = $dateFrom === $dateTo ? $labelFrom : "{$labelFrom} a {$labelTo}";

        $funnel = Db::one(
            'SELECT
                SUM(impressions) AS imp, SUM(clicks) AS clk, SUM(spend) AS spend,
                SUM(landing_page_views) AS lpv,
                SUM(adds_to_cart) AS atc,
                SUM(initiates_checkout) AS ic,
                SUM(conversions) AS conv,
                SUM(conversion_value) AS revenue
             FROM campaign_insights
             WHERE meta_account_id = ? AND date_start >= ? AND date_stop <= ? AND impressions > 0',
            [$accId, $dateFrom, $dateTo]
        );

        $spend = (float) ($funnel['spend'] ?? 0);
        $imp   = (int)   ($funnel['imp']   ?? 0);

        if ($imp == 0) {
            return "🗓️ *Relatório Personalizado — {$clientName}*\n\n_{$accName} · {$periodLabel}_\n\nNenhuma impressão registrada neste período.";
        }

        $revenue = (float) ($funnel['revenue'] ?? 0);
        $roas    = $spend > 0 ? $revenue / $spend : 0;
        $convs   = (int)   ($funnel['conv']    ?? 0);
        $cpa     = $convs > 0 ? $spend / $convs : 0;
        $clk     = (int)   ($funnel['clk']     ?? 0);
        $cpc     = $clk   > 0 ? $spend / $clk  : 0;
        $ctr     = $imp   > 0 ? ($clk / $imp) * 100 : 0;

        $campaigns = Db::all(
            'SELECT campaign_name,
                    SUM(impressions) AS imp, SUM(clicks) AS clk, SUM(spend) AS spend,
                    SUM(landing_page_views) AS lpv, SUM(adds_to_cart) AS atc,
                    SUM(initiates_checkout) AS ic, SUM(conversions) AS conv,
                    SUM(conversion_value) AS revenue
             FROM campaign_insights
             WHERE meta_account_id = ? AND date_start >= ? AND date_stop <= ? AND impressions > 0
             GROUP BY campaign_name
             ORDER BY spend DESC',
            [$accId, $dateFrom, $dateTo]
        );

        $lines = [];
        $lines[] = "🗓️ *Relatório Personalizado — {$clientName}*";
        $lines[] = "_{$accName}_";
        $lines[] = "_{$periodLabel}_";
        $lines[] = '';
        $lines[] = '💰 *Resultado do Período*';
        $lines[] = '• Gasto: *' . $this->fmtMoney($spend, $currency) . '*';
        $lines[] = '• Receita (Valor de Conversão): *' . $this->fmtMoney($revenue, $currency) . '*';
        if ($roas > 0) $lines[] = '• ROAS: *' . number_format($roas, 2) . 'x*';
        $lines[] = '• Compras Realizadas: *' . $convs . '*';
        if ($cpa > 0) $lines[] = '• CPA: *' . $this->fmtMoney($cpa, $currency) . '*';
        $lines[] = '• Cliques: *' . $clk . '*';
        $lines[] = '• CPC: *' . $this->fmtMoney($cpc, $currency) . '*';
        $lines[] = '• CTR: *' . number_format($ctr, 2) . '%*';
        $lines[] = '• Visualizações de Página: *' . ($funnel['lpv'] ?? 0) . '*';
        $lines[] = '• Adições ao Carrinho: *' . ($funnel['atc'] ?? 0) . '*';
        $lines[] = '• Checkouts Iniciados: *' . ($funnel['ic'] ?? 0) . '*';

        if (!empty($campaigns)) {
            $lines[] = '';
            $lines[] = '📈 *Performance por Campanha*';
            foreach ($campaigns as $c) {
                $cSpend = (float)($c['spend'] ?? 0);
                $cRev   = (float)($c['revenue'] ?? 0);
                $cRoas  = $cSpend > 0 ? $cRev / $cSpend : 0;
                $cConvs = (int)($c['conv'] ?? 0);
                $cCpa   = $cConvs > 0 ? $cSpend / $cConvs : 0;
                $cImp   = (int)($c['imp'] ?? 0);
                $cClk   = (int)($c['clk'] ?? 0);
                $cCtr   = $cImp > 0 ? ($cClk / $cImp) * 100 : 0;
                $cCpc   = $cClk > 0 ? $cSpend / $cClk : 0;

                $lines[] = '• *' . self::truncate($c['campaign_name'] ?? 'Campanha Desconhecida', 50) . '*';
                $lines[] = '   Gasto: ' . $this->fmtMoney($cSpend, $currency) . ' · Receita: ' . $this->fmtMoney($cRev, $currency) . ' · ROAS: ' . number_format($cRoas, 2) . 'x';
                $lines[] = '   Cliques: ' . $cClk . ' · CPC: ' . $this->fmtMoney($cCpc, $currency) . ' · CTR: ' . number_format($cCtr, 2) . '%';
                $lines[] = '   Vis. Página: ' . ($c['lpv'] ?? 0) . ' · Carrinho: ' . ($c['atc'] ?? 0) . ' · Checkout: ' . ($c['ic'] ?? 0) . ' · Compras: ' . $cConvs;
                $lines[] = '';
            }
        }

        $lines[] = '_Saldo WEB · ' . date('H:i', time()) . '_';
        return implode("\n", $lines);
    }

    // ──────────────────────────── PRÉVIA DE FIM DE SEMANA ────────────────────────────

    /**
     * Gera prévia de fim de semana — enviada toda sexta-feira.
     * Mostra projeção de gasto sábado+domingo, ROAS da semana e criativos top ativos.
     */
    public function buildWeekendForecast(array $account, ?string $referenceDate = null): string
    {
        $today      = $referenceDate ?? date('Y-m-d');
        $weekStart  = date('Y-m-d', strtotime('monday this week', strtotime($today)));
        $accId      = (int) $account['id'];
        $clientName = $account['client_name'] ?? $account['name'] ?? 'Cliente';
        $accName    = $account['account_name'] ?: ('Conta ' . $account['ad_account_id']);
        $currency   = $account['currency'] ?: 'BRL';

        // Métricas da semana até hoje (seg–sex)
        $weekSummary = $this->insights->getAccountSummary($accId, $weekStart, $today);

        // Média diária dos últimos 7 dias para projetar o final de semana
        $last7 = $this->insights->getAccountSummary(
            $accId,
            date('Y-m-d', strtotime('-7 days', strtotime($today))),
            $today
        );
        $days7     = 7;
        $avgDaily  = (float) ($last7['total_spend'] ?? 0) / $days7;
        $projected = $avgDaily * 2; // sábado + domingo

        // Top 3 criativos ativos desta semana
        $topAds = Db::all(
            'SELECT ad_id, ad_name, campaign_name,
                    SUM(spend) AS spend, SUM(conversions) AS conversions,
                    SUM(conversion_value)/NULLIF(SUM(spend),0) AS roas,
                    MAX(effective_status) AS effective_status
             FROM ad_insights
             WHERE meta_account_id = ? AND date_start >= ? AND date_stop <= ?
               AND effective_status = \'ACTIVE\'
             GROUP BY ad_id, ad_name, campaign_name
             HAVING spend > 0
             ORDER BY roas DESC LIMIT 3',
            [$accId, $weekStart, $today]
        );

        $spend   = (float) ($weekSummary['total_spend'] ?? 0);
        $roas    = (float) ($weekSummary['avg_roas'] ?? 0);
        $convs   = (int)   ($weekSummary['total_conversions'] ?? 0);
        $revenue = (float) ($weekSummary['total_revenue'] ?? 0);

        $lines = [];
        $lines[] = "🔮 *Prévia do Fim de Semana — {$clientName}*";
        $lines[] = "_{$accName}_";
        $lines[] = '_' . date('d/m', strtotime($weekStart)) . ' a hoje_';
        $lines[] = '';
        $lines[] = '📈 *Semana até agora*';
        $lines[] = '• Gasto: *' . $this->fmtMoney($spend, $currency) . '*';
        if ($revenue > 0) $lines[] = '• Receita: *' . $this->fmtMoney($revenue, $currency) . '*';
        if ($roas > 0)    $lines[] = '• ROAS: *' . number_format($roas, 2) . 'x*';
        if ($convs > 0)   $lines[] = '• Conversões: *' . $convs . '*';

        $lines[] = '';
        $lines[] = '🗓 *Projeção do final de semana (sáb + dom)*';
        $lines[] = '• Média diária (7d): *' . $this->fmtMoney($avgDaily, $currency) . '*';
        $lines[] = '• Gasto estimado: *~' . $this->fmtMoney($projected, $currency) . '*';

        if (!empty($topAds)) {
            $lines[] = '';
            $lines[] = '🏆 *Criativos ativos para monitorar*';
            foreach ($topAds as $i => $ad) {
                $medal = ['🥇', '🥈', '🥉'][$i] ?? '▫️';
                $name  = self::truncate($ad['ad_name'] ?? 'Anúncio', 55);
                $lines[] = "{$medal} {$name}";
                if ((float)$ad['roas'] > 0) {
                    $lines[] = '   ROAS: *' . number_format((float)$ad['roas'], 2) . 'x* · Gasto: ' . $this->fmtMoney((float)$ad['spend'], $currency);
                }
            }
        }

        $lines[] = '';
        $lines[] = '_Tenha um bom fim de semana! 🚀_';
        $lines[] = '_Saldo WEB · ' . date('d/m H:i') . '_';

        return implode("\n", $lines);
    }

    // ──────────────────────────── HELPERS ────────────────────────────

    private function fmtMoney(float $value, string $currency): string
    {
        // Valores vêm já em unidades reais da moeda (euros, reais, etc.) — não em centavos
        if ($currency === 'BRL') {
            return 'R$ ' . number_format($value, 2, ',', '.');
        }
        return number_format($value, 2) . ' ' . $currency;
    }

    /**
     * Gera sufixo com variação percentual colorido para WhatsApp.
     * $inverse = true: subida é ruim (ex: CPA)
     */
    private static function delta(?float $pct, bool $higherIsBetter = true, bool $inverse = false): string
    {
        if ($pct === null || $pct === 0.0) return '';
        $going_up = $pct > 0;
        $good     = $higherIsBetter ? $going_up : !$going_up;
        if ($inverse) $good = !$good;
        $arrow = $going_up ? '▲' : '▼';
        $emoji = $good ? '✅' : '🔴';
        return " {$emoji} {$arrow}" . number_format(abs($pct), 1) . '%';
    }

    private static function truncate(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }
}
