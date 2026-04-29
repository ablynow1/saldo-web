<?php
declare(strict_types=1);

/**
 * Cliente de métricas de performance da Meta Marketing API.
 *
 * IMPORTANTE — Paridade com o Gerenciador de Anúncios da Meta
 * ──────────────────────────────────────────────────────────────────
 * Para que SPEND, CONVERSÕES, ROAS e CPA batam EXATAMENTE com o que
 * o cliente vê no Gerenciador (Ads Manager), três configurações são
 * essenciais e estão aplicadas em todas as chamadas de insights:
 *
 *   1. use_unified_attribution_setting = true
 *      Faz a API respeitar a janela de atribuição configurada na
 *      campanha/conta — exatamente o mesmo que o gerenciador usa.
 *
 *   2. action_attribution_windows = ['default']
 *      Garante que o número de conversões e o conversion_value
 *      reflitam a janela padrão da conta (geralmente 7d_click,1d_view).
 *
 *   3. Resolução de tipo de evento por PRIORIDADE (não por SOMA)
 *      O gerenciador mostra UM número consolidado por evento. A API
 *      retorna múltiplos action_types para o "mesmo" evento (ex.:
 *      `omni_purchase`, `purchase`, `offsite_conversion.fb_pixel_purchase`).
 *      Se somarmos todos, duplicamos. A regra correta é PRIORIDADE:
 *        omni_<event>  →  <event>  →  offsite_conversion.fb_pixel_<event>
 *                      →  onsite_conversion.<event>_grouped
 *      Pega o primeiro disponível e ignora os demais.
 *
 * Conversões: o tipo de evento vem do setting 'conversion_event'
 * (padrão: 'purchase'). Contas de leads devem mudar para 'lead'.
 */
final class InsightsClient
{
    private string $token;
    private string $apiVersion;
    private string $conversionEvent;
    /** @var string[] Janelas de atribuição (ex: ['default'], ['7d_click','1d_view']) */
    private array $attributionWindows;

    public function __construct(
        string $token,
        string $apiVersion = 'v19.0',
        string $conversionEvent = 'purchase',
        array  $attributionWindows = ['default']
    ) {
        $this->token              = $token;
        $this->apiVersion         = $apiVersion;
        $this->conversionEvent    = $conversionEvent;
        $this->attributionWindows = $attributionWindows ?: ['default'];
    }

    public static function fromSettings(): self
    {
        $token = Db::getSetting('meta_system_user_token');
        $ver   = Db::getSetting('meta_api_version') ?: 'v19.0';
        $conv  = Db::getSetting('conversion_event') ?: 'purchase';
        $attr  = Db::getSetting('attribution_windows') ?: 'default';
        if (!$token) throw new RuntimeException('System User Token não configurado.');

        $windows = array_values(array_filter(array_map('trim', explode(',', $attr))));
        return new self($token, $ver, $conv, $windows);
    }

    /**
     * Coleta insights por anúncio (nível "ad") de uma conta, para um período.
     * Salva/atualiza na tabela ad_insights.
     *
     * Auto-cleanup: ao iniciar, deleta linhas com date_start != date_stop
     * (registros agregados de versões antigas que inflavam SUM em queries
     * de período).
     *
     * @param int    $metaAccountDbId  ID interno (tabela meta_accounts)
     * @param string $adAccountId      ID da conta no Meta (sem "act_")
     * @param string $datePreset       'yesterday', 'today', 'last_7d', 'last_30d'
     *                                 ou 'custom' (use $since/$until)
     * @param string|null $since       YYYY-MM-DD se $datePreset='custom'
     * @param string|null $until       YYYY-MM-DD se $datePreset='custom'
     * @return array{collected:int, skipped:int, errors:int}
     */
    public function collectAdInsights(
        int $metaAccountDbId,
        string $adAccountId,
        string $datePreset = 'yesterday',
        ?string $since = null,
        ?string $until = null
    ): array {
        // Auto-cleanup de rows agregadas corruptas (idempotente, custo desprezível)
        $this->cleanupCorruptedRows($metaAccountDbId);
        $fields = implode(',', [
            'ad_id', 'ad_name', 'adset_id', 'adset_name', 'campaign_id', 'campaign_name',
            'spend', 'impressions', 'clicks', 'reach',
            'actions', 'action_values',
            'date_start', 'date_stop',
        ]);

        $params = $this->baseParams($fields, 'ad', 500);
        $this->applyDateRange($params, $datePreset, $since, $until);

        $url = sprintf(
            'https://graph.facebook.com/%s/act_%s/insights?%s',
            $this->apiVersion,
            urlencode($adAccountId),
            http_build_query($params)
        );

        $collected = 0;
        $skipped   = 0;
        $errors    = 0;
        $nextPage  = $url;

        while ($nextPage) {
            $resp = HttpClient::get($nextPage);
            if ($resp['status'] >= 400) {
                $errors++;
                break;
            }
            $rows     = $resp['body']['data'] ?? [];
            $nextPage = $resp['body']['paging']['next'] ?? null;

            foreach ($rows as $row) {
                try {
                    $metrics = $this->parseRow($row);
                    $this->upsertAdInsight($metaAccountDbId, $row, $metrics);
                    $collected++;
                } catch (Throwable $e) {
                    $errors++;
                }
            }
        }

        // Busca thumbs em batch (máx 50 por requisição)
        $this->enrichThumbnails($metaAccountDbId, $adAccountId, $since ?? date('Y-m-d', strtotime('-1 day')));

        return compact('collected', 'skipped', 'errors');
    }

    /**
     * Coleta insights por campanha.
     */
    public function collectCampaignInsights(
        int $metaAccountDbId,
        string $adAccountId,
        string $datePreset = 'yesterday',
        ?string $since = null,
        ?string $until = null
    ): array {
        $fields = 'campaign_id,campaign_name,spend,impressions,clicks,actions,action_values,date_start,date_stop';

        $params = $this->baseParams($fields, 'campaign', 200);
        $this->applyDateRange($params, $datePreset, $since, $until);

        $url = sprintf(
            'https://graph.facebook.com/%s/act_%s/insights?%s',
            $this->apiVersion,
            urlencode($adAccountId),
            http_build_query($params)
        );

        $resp = HttpClient::get($url);
        if ($resp['status'] >= 400) {
            throw new RuntimeException('Meta insights error: ' . ($resp['body']['error']['message'] ?? 'HTTP ' . $resp['status']));
        }

        $collected = 0;
        foreach (($resp['body']['data'] ?? []) as $row) {
            $metrics = $this->parseRow($row);
            $this->upsertCampaignInsight($metaAccountDbId, $row, $metrics);
            $collected++;
        }
        return ['collected' => $collected];
    }

    /**
     * Busca top N anúncios por ROAS para uma conta em um período.
     */
    public function getTopAdsByRoas(int $metaAccountDbId, string $date, int $limit = 5): array
    {
        return Db::all(
            'SELECT * FROM ad_insights
             WHERE meta_account_id = ? AND date_start = ? AND spend > 0
             ORDER BY roas DESC LIMIT ?',
            [$metaAccountDbId, $date, $limit]
        );
    }

    /**
     * Busca top N anúncios por CPA (piores = CPA mais alto).
     */
    public function getWorstAdsByCpa(int $metaAccountDbId, string $date, int $limit = 3): array
    {
        return Db::all(
            'SELECT * FROM ad_insights
             WHERE meta_account_id = ? AND date_start = ? AND conversions > 0 AND spend > 0
             ORDER BY cpa DESC LIMIT ?',
            [$metaAccountDbId, $date, $limit]
        );
    }

    /**
     * Resumo de performance da conta num período.
     */
    public function getAccountSummary(int $metaAccountDbId, string $since, string $until): array
    {
        $row = Db::one(
            'SELECT
               SUM(spend)            AS total_spend,
               SUM(impressions)      AS total_impressions,
               SUM(clicks)           AS total_clicks,
               SUM(conversions)      AS total_conversions,
               SUM(conversion_value) AS total_revenue,
               AVG(roas)             AS avg_roas,
               (SUM(clicks)/NULLIF(SUM(impressions),0))*100 AS avg_ctr,
               SUM(spend)/NULLIF(SUM(conversions),0) AS avg_cpa
             FROM ad_insights
             WHERE meta_account_id = ? AND date_start >= ? AND date_stop <= ?',
            [$metaAccountDbId, $since, $until]
        );
        return $row ?? [];
    }

    /**
     * Busca métricas do dia de ontem e do mesmo dia na semana passada para comparativo.
     */
    public function getDayComparison(int $metaAccountDbId): array
    {
        $yesterday   = date('Y-m-d', strtotime('-1 day'));
        $lastWeek    = date('Y-m-d', strtotime('-8 days'));

        $today = $this->getAccountSummary($metaAccountDbId, $yesterday, $yesterday);
        $prev  = $this->getAccountSummary($metaAccountDbId, $lastWeek, $lastWeek);

        $delta = function (?float $now, ?float $before): ?float {
            if (!$before) return null;
            return (($now - $before) / $before) * 100;
        };

        return [
            'yesterday' => $today,
            'same_day_last_week' => $prev,
            'delta_spend'  => $delta((float) ($today['total_spend'] ?? 0), (float) ($prev['total_spend'] ?? 0)),
            'delta_roas'   => $delta((float) ($today['avg_roas'] ?? 0), (float) ($prev['avg_roas'] ?? 0)),
            'delta_cpa'    => $delta((float) ($today['avg_cpa'] ?? 0), (float) ($prev['avg_cpa'] ?? 0)),
            'delta_ctr'    => $delta((float) ($today['avg_ctr'] ?? 0), (float) ($prev['avg_ctr'] ?? 0)),
        ];
    }

    /**
     * BUSCA LIVE — não toca em DB.
     *
     * Faz a chamada à Meta API para o período e retorna os dados agregados
     * exatamente como o Gerenciador de Anúncios mostra. Use isto para
     * geração de relatórios (Dia / Semana / Mês / Personalizado) — assim
     * os números batem 100% com o que o cliente vê na Meta, sem dependência
     * de cache que pode estar dessincronizado.
     *
     * @param string $adAccountId  ID da conta sem "act_"
     * @param string $since        YYYY-MM-DD
     * @param string $until        YYYY-MM-DD
     * @return array{
     *   summary: array,
     *   campaigns: array<int, array>,
     *   period: array{since:string, until:string}
     * }
     */
    public function fetchAccountReport(string $adAccountId, string $since, string $until): array
    {
        $fields = 'campaign_id,campaign_name,spend,impressions,clicks,reach,actions,action_values';

        // SEM time_increment — queremos a agregação do período (igual ao gerenciador)
        $params = [
            'fields'                          => $fields,
            'level'                           => 'campaign',
            'access_token'                    => $this->token,
            'limit'                           => 500,
            'time_range'                      => json_encode(['since' => $since, 'until' => $until]),
            'use_unified_attribution_setting' => 'true',
            'action_attribution_windows'      => json_encode($this->attributionWindows),
        ];

        $url = sprintf(
            'https://graph.facebook.com/%s/act_%s/insights?%s',
            $this->apiVersion,
            urlencode($adAccountId),
            http_build_query($params)
        );

        $campaigns = [];
        $sum = [
            'spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'reach' => 0,
            'conversions' => 0, 'conversion_value' => 0.0, 'leads' => 0,
            'landing_page_views' => 0, 'adds_to_cart' => 0, 'initiates_checkout' => 0,
        ];

        $nextPage = $url;
        while ($nextPage) {
            $resp = HttpClient::get($nextPage);
            if ($resp['status'] >= 400) {
                throw new RuntimeException('Meta insights error: ' . ($resp['body']['error']['message'] ?? 'HTTP ' . $resp['status']));
            }
            $rows     = $resp['body']['data']           ?? [];
            $nextPage = $resp['body']['paging']['next'] ?? null;

            foreach ($rows as $row) {
                $m = $this->parseRow($row);

                $cSpend = (float) ($row['spend'] ?? 0);
                $cImp   = (int)   ($row['impressions'] ?? 0);
                $cClk   = (int)   ($row['clicks'] ?? 0);

                $cCtr = $cImp > 0 ? ($cClk / $cImp) * 100 : 0;
                $cCpc = $cClk > 0 ? $cSpend / $cClk : 0;
                $cCpm = $cImp > 0 ? ($cSpend / $cImp) * 1000 : 0;
                $cCpa = $m['conversions'] > 0 ? $cSpend / $m['conversions'] : 0;
                $cRoas = $cSpend > 0 && $m['conversionValue'] > 0 ? $m['conversionValue'] / $cSpend : 0;

                $campaigns[] = [
                    'campaign_id'        => $row['campaign_id'] ?? null,
                    'campaign_name'      => $row['campaign_name'] ?? 'Sem nome',
                    'spend'              => $cSpend,
                    'impressions'        => $cImp,
                    'clicks'             => $cClk,
                    'reach'              => (int) ($row['reach'] ?? 0),
                    'conversions'        => $m['conversions'],
                    'conversion_value'   => $m['conversionValue'],
                    'leads'              => $m['leads'],
                    'landing_page_views' => $m['landingPageViews'],
                    'adds_to_cart'       => $m['addsToCart'],
                    'initiates_checkout' => $m['initiatesCheckout'],
                    'ctr'                => $cCtr,
                    'cpc'                => $cCpc,
                    'cpm'                => $cCpm,
                    'cpa'                => $cCpa,
                    'roas'               => $cRoas,
                ];

                $sum['spend']              += $cSpend;
                $sum['impressions']        += $cImp;
                $sum['clicks']             += $cClk;
                $sum['reach']              += (int) ($row['reach'] ?? 0);
                $sum['conversions']        += $m['conversions'];
                $sum['conversion_value']   += $m['conversionValue'];
                $sum['leads']              += $m['leads'];
                $sum['landing_page_views'] += $m['landingPageViews'];
                $sum['adds_to_cart']       += $m['addsToCart'];
                $sum['initiates_checkout'] += $m['initiatesCheckout'];
            }
        }

        // Métricas derivadas no nível total (igual o gerenciador faz)
        $sum['ctr']  = $sum['impressions'] > 0 ? ($sum['clicks'] / $sum['impressions']) * 100 : 0;
        $sum['cpc']  = $sum['clicks']      > 0 ? $sum['spend'] / $sum['clicks'] : 0;
        $sum['cpm']  = $sum['impressions'] > 0 ? ($sum['spend'] / $sum['impressions']) * 1000 : 0;
        $sum['cpa']  = $sum['conversions'] > 0 ? $sum['spend'] / $sum['conversions'] : 0;
        $sum['roas'] = ($sum['spend'] > 0 && $sum['conversion_value'] > 0)
            ? $sum['conversion_value'] / $sum['spend']
            : 0;

        // Ordena campanhas por spend desc (igual o gerenciador)
        usort($campaigns, fn($a, $b) => $b['spend'] <=> $a['spend']);

        return [
            'summary'   => $sum,
            'campaigns' => $campaigns,
            'period'    => ['since' => $since, 'until' => $until],
        ];
    }

    /**
     * Busca os top N criativos (level=ad) por ROAS para um período.
     * Usado para destacar os melhores anúncios em relatórios.
     */
    public function fetchTopAds(string $adAccountId, string $since, string $until, int $limit = 5): array
    {
        $fields = 'ad_id,ad_name,campaign_name,spend,impressions,clicks,actions,action_values,effective_status';

        $params = [
            'fields'                          => $fields,
            'level'                           => 'ad',
            'access_token'                    => $this->token,
            'limit'                           => 500,
            'time_range'                      => json_encode(['since' => $since, 'until' => $until]),
            'use_unified_attribution_setting' => 'true',
            'action_attribution_windows'      => json_encode($this->attributionWindows),
            'filtering'                       => json_encode([
                ['field' => 'spend', 'operator' => 'GREATER_THAN', 'value' => 0]
            ]),
        ];

        $url = sprintf(
            'https://graph.facebook.com/%s/act_%s/insights?%s',
            $this->apiVersion, urlencode($adAccountId), http_build_query($params)
        );

        $ads = [];
        $resp = HttpClient::get($url);
        if ($resp['status'] >= 400) return [];

        foreach (($resp['body']['data'] ?? []) as $row) {
            $m = $this->parseRow($row);
            $spend = (float) ($row['spend'] ?? 0);
            $roas  = $spend > 0 && $m['conversionValue'] > 0 ? $m['conversionValue'] / $spend : 0;
            $ads[] = [
                'ad_id'             => $row['ad_id'] ?? null,
                'ad_name'           => $row['ad_name'] ?? '—',
                'campaign_name'     => $row['campaign_name'] ?? '—',
                'spend'             => $spend,
                'conversions'       => $m['conversions'],
                'conversion_value'  => $m['conversionValue'],
                'roas'              => $roas,
                'effective_status'  => $row['effective_status'] ?? null,
            ];
        }

        usort($ads, fn($a, $b) => $b['roas'] <=> $a['roas']);
        return array_slice($ads, 0, $limit);
    }

    // ───────────────────────────────── helpers privados ─────────────────────────────────

    /**
     * Remove rows agregadas corruptas (date_start != date_stop) da conta.
     * Estas rows existem por causa de versões antigas do coletor que chamavam
     * a Meta API sem time_increment=1 em time_range multi-dia, fazendo a API
     * retornar uma linha agregada do período inteiro que era persistida com
     * unique key (entity_id, date_start), corrompendo o snapshot diário.
     */
    private function cleanupCorruptedRows(int $metaAccountDbId): void
    {
        try {
            Db::exec('DELETE FROM ad_insights       WHERE meta_account_id = ? AND date_start != date_stop', [$metaAccountDbId]);
            Db::exec('DELETE FROM campaign_insights WHERE meta_account_id = ? AND date_start != date_stop', [$metaAccountDbId]);
        } catch (Throwable $e) {
            // não bloqueia coleta — só é uma limpeza opportunistic
        }
    }


    /**
     * Parâmetros base aplicados em TODAS as chamadas de insights.
     * Garantem paridade com o que o cliente vê no Gerenciador.
     */
    private function baseParams(string $fields, string $level, int $limit): array
    {
        return [
            'fields'                          => $fields,
            'level'                           => $level,
            'access_token'                    => $this->token,
            'limit'                           => $limit,
            // ↓↓↓ críticos para paridade com o Gerenciador ↓↓↓
            'use_unified_attribution_setting' => 'true',
            'action_attribution_windows'      => json_encode($this->attributionWindows),
        ];
    }

    /**
     * Aplica o intervalo de datas (date_preset OU time_range) ao array de parâmetros.
     *
     * IMPORTANTE — sempre força time_increment=1 quando o range cobre mais de
     * um dia. Sem isso a Meta API retorna UMA linha agregada do período todo,
     * que ao ser persistida com unique key (entity_id, date_start) acabaria
     * sobrescrevendo o registro diário do primeiro dia do range com o total
     * agregado, inflando a SUM(spend) em queries posteriores.
     *
     * Com time_increment=1, a API retorna uma linha por DIA — exatamente
     * o que o esquema (ad_insights / campaign_insights) espera.
     */
    private function applyDateRange(array &$params, string $datePreset, ?string $since, ?string $until): void
    {
        if ($datePreset === 'custom' && $since && $until) {
            $params['time_range'] = json_encode(['since' => $since, 'until' => $until]);
            // Se for um range multi-dia, particiona em linhas diárias
            if ($since !== $until) {
                $params['time_increment'] = 1;
            }
        } else {
            $params['date_preset'] = $datePreset;
            // last_7d, last_14d, last_30d, last_90d → multi-dia → particiona
            if (in_array($datePreset, ['last_7d', 'last_14d', 'last_30d', 'last_90d', 'last_quarter', 'last_year', 'this_month', 'last_month', 'this_week_mon_today', 'last_week_mon_sun', 'this_quarter'], true)) {
                $params['time_increment'] = 1;
            }
        }
    }

    /**
     * Extrai métricas de uma linha de insights respeitando a regra de PRIORIDADE
     * (sem somar action_types redundantes — o gerenciador também não soma).
     */
    private function parseRow(array $row): array
    {
        $spend     = (float) ($row['spend'] ?? 0);
        $actions   = $row['actions']        ?? [];
        $values    = $row['action_values']  ?? [];

        $event = $this->conversionEvent;

        // PRIORIDADE — gerenciador mostra o consolidado (omni_*) quando disponível.
        // Se a conta é mais antiga (sem omni), cai no nome do evento puro, depois pixel/onsite.
        $eventPriority = [
            "omni_{$event}",
            $event,
            "offsite_conversion.fb_pixel_{$event}",
            "onsite_conversion.{$event}_grouped",
        ];

        [$conversions, $conversionValue] = $this->resolveAction($actions, $values, $eventPriority);

        // Leads — mesma lógica
        $leadPriority = [
            'omni_lead',
            'lead',
            'offsite_conversion.fb_pixel_lead',
            'onsite_conversion.lead_grouped',
        ];
        [$leads, /* $_ */] = $this->resolveAction($actions, [], $leadPriority);

        // Outros eventos auxiliares (também por prioridade, sem soma duplicada)
        [$landingPageViews, /* $_ */] = $this->resolveAction($actions, [], [
            'omni_landing_page_view',
            'landing_page_view',
            'onsite_conversion.landing_page_view',
        ]);
        [$addsToCart, /* $_ */] = $this->resolveAction($actions, [], [
            'omni_add_to_cart',
            'add_to_cart',
            'offsite_conversion.fb_pixel_add_to_cart',
            'onsite_conversion.add_to_cart_grouped',
        ]);
        [$initiatesCheckout, /* $_ */] = $this->resolveAction($actions, [], [
            'omni_initiated_checkout',
            'initiate_checkout',
            'offsite_conversion.fb_pixel_initiate_checkout',
            'onsite_conversion.initiate_checkout_grouped',
        ]);

        $impressions = (int) ($row['impressions'] ?? 0);
        $clicks      = (int) ($row['clicks'] ?? 0);

        $ctr  = $impressions > 0 ? ($clicks / $impressions) * 100 : 0;
        $cpc  = $clicks > 0 ? $spend / $clicks : 0;
        $cpa  = $conversions > 0 ? $spend / $conversions : 0;
        $roas = $spend > 0 && $conversionValue > 0 ? $conversionValue / $spend : 0;
        $cpp  = $impressions > 0 ? ($spend / $impressions) * 1000 : 0;
        $cpm  = $cpp;

        return [
            'conversions'       => (int) $conversions,
            'conversionValue'   => (float) $conversionValue,
            'leads'             => (int) $leads,
            'landingPageViews'  => (int) $landingPageViews,
            'addsToCart'        => (int) $addsToCart,
            'initiatesCheckout' => (int) $initiatesCheckout,
            'ctr'               => $ctr,
            'cpc'               => $cpc,
            'cpa'               => $cpa,
            'roas'              => $roas,
            'cpp'               => $cpp,
            'cpm'               => $cpm,
        ];
    }

    /**
     * Procura nos arrays de actions/values o PRIMEIRO action_type da lista
     * de prioridade que existir, retornando [count, value]. Não soma —
     * isso evita duplicação (igual ao comportamento do Gerenciador).
     *
     * @param array $actions  Array de objetos {action_type, value, [1d_click, 7d_click, ...]}
     * @param array $values   Array de objetos {action_type, value, ...} para conversion_value
     * @param string[] $priority  Lista ordenada de tipos preferidos
     * @return array{0:float, 1:float}  [count, valueSum]
     */
    private function resolveAction(array $actions, array $values, array $priority): array
    {
        // Constrói índice por action_type para lookup O(1)
        $actionByType = [];
        foreach ($actions as $a) {
            $t = $a['action_type'] ?? '';
            if ($t !== '') $actionByType[$t] = $a;
        }
        $valueByType = [];
        foreach ($values as $v) {
            $t = $v['action_type'] ?? '';
            if ($t !== '') $valueByType[$t] = $v;
        }

        foreach ($priority as $type) {
            if (!isset($actionByType[$type])) continue;
            $count = $this->extractAttributedValue($actionByType[$type]);
            $val   = isset($valueByType[$type]) ? $this->extractAttributedValue($valueByType[$type]) : 0.0;
            return [$count, $val];
        }
        return [0.0, 0.0];
    }

    /**
     * Extrai o valor atribuído de um objeto de action.
     *
     * Quando `use_unified_attribution_setting=true`, a Meta retorna o número
     * agregado direto em `value`. Quando se passa `action_attribution_windows`
     * customizado, ela pode retornar campos extras como `7d_click`, `1d_view`,
     * etc. — nesse caso, somamos essas janelas explicitamente.
     */
    private function extractAttributedValue(array $obj): float
    {
        // Se tiver janelas explícitas configuradas (não 'default'), soma só elas
        if (count($this->attributionWindows) > 0 && $this->attributionWindows !== ['default']) {
            $sum = 0.0;
            $found = false;
            foreach ($this->attributionWindows as $w) {
                if (isset($obj[$w])) {
                    $sum  += (float) $obj[$w];
                    $found = true;
                }
            }
            if ($found) return $sum;
        }
        // Caso padrão: usa o `value` que já vem agregado pelo unified setting
        return (float) ($obj['value'] ?? 0);
    }

    private function upsertAdInsight(int $accountDbId, array $row, array $m): void
    {
        $data = [
            'meta_account_id'  => $accountDbId,
            'ad_id'            => $row['ad_id'],
            'ad_name'          => substr($row['ad_name'] ?? '', 0, 500),
            'adset_id'         => $row['adset_id'] ?? null,
            'adset_name'       => isset($row['adset_name']) ? substr($row['adset_name'], 0, 500) : null,
            'campaign_id'      => $row['campaign_id'] ?? null,
            'campaign_name'    => isset($row['campaign_name']) ? substr($row['campaign_name'], 0, 500) : null,
            'date_start'       => $row['date_start'],
            'date_stop'        => $row['date_stop'],
            'spend'            => (float) ($row['spend'] ?? 0),
            'impressions'      => (int) ($row['impressions'] ?? 0),
            'clicks'           => (int) ($row['clicks'] ?? 0),
            'reach'            => (int) ($row['reach'] ?? 0),
            'conversions'      => $m['conversions'],
            'conversion_value' => $m['conversionValue'],
            'leads'            => $m['leads'],
            'ctr'              => $m['ctr'],
            'cpc'              => $m['cpc'],
            'cpa'              => $m['cpa'],
            'roas'             => $m['roas'],
            'cpp'              => $m['cpp'],
            'collected_at'     => date('Y-m-d H:i:s'),
        ];

        $cols   = array_keys($data);
        $vals   = array_values($data);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $colsSql = '`' . implode('`,`', $cols) . '`';

        // ON DUPLICATE KEY: atualiza tudo exceto o id
        $updates = [];
        foreach ($cols as $c) {
            if ($c !== 'meta_account_id' && $c !== 'ad_id' && $c !== 'date_start') {
                $updates[] = "`$c` = VALUES(`$c`)";
            }
        }

        $sql = "INSERT INTO `ad_insights` ($colsSql) VALUES ($placeholders) ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
        Db::pdo()->prepare($sql)->execute($vals);
    }

    private function upsertCampaignInsight(int $accountDbId, array $row, array $m): void
    {
        $cols = [
            'meta_account_id' => $accountDbId,
            'campaign_id'     => $row['campaign_id'],
            'campaign_name'   => isset($row['campaign_name']) ? substr($row['campaign_name'], 0, 500) : null,
            'date_start'      => $row['date_start'],
            'date_stop'       => $row['date_stop'],
            'spend'           => (float) ($row['spend'] ?? 0),
            'impressions'     => (int)  ($row['impressions'] ?? 0),
            'clicks'          => (int)  ($row['clicks'] ?? 0),
            'conversions'     => $m['conversions'],
            'conversion_value'=> $m['conversionValue'],
            'leads'           => $m['leads'],
            'ctr'             => $m['ctr'],
            'cpa'             => $m['cpa'],
            'roas'            => $m['roas'],
            'cpm'             => $m['cpm'],
            'landing_page_views' => $m['landingPageViews'],
            'adds_to_cart'    => $m['addsToCart'],
            'initiates_checkout' => $m['initiatesCheckout'],
            'collected_at'    => date('Y-m-d H:i:s'),
        ];
        $colNames = '`' . implode('`,`', array_keys($cols)) . '`';
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $updates = implode(', ', array_map(fn($c) => "`$c`=VALUES(`$c`)", array_keys($cols)));
        Db::pdo()->prepare("INSERT INTO `campaign_insights` ($colNames) VALUES ($ph) ON DUPLICATE KEY UPDATE $updates")
                 ->execute(array_values($cols));
    }

    private function enrichThumbnails(int $accountDbId, string $adAccountId, string $date): void
    {
        // Busca anúncios sem thumbnail
        $ads = Db::all(
            'SELECT ad_id FROM ad_insights WHERE meta_account_id = ? AND date_start = ? AND thumbnail_url IS NULL LIMIT 50',
            [$accountDbId, $date]
        );
        if (empty($ads)) return;

        $ids = implode(',', array_map(fn($r) => $r['ad_id'], $ads));
        $url = sprintf(
            'https://graph.facebook.com/%s/?ids=%s&fields=creative{thumbnail_url,effective_instagram_media_id}&access_token=%s',
            $this->apiVersion, urlencode($ids), urlencode($this->token)
        );
        $resp = HttpClient::get($url);
        if ($resp['status'] >= 400) return;

        foreach (($resp['body'] ?? []) as $adId => $data) {
            $thumb = $data['creative']['thumbnail_url'] ?? null;
            if ($thumb) {
                Db::exec(
                    'UPDATE ad_insights SET thumbnail_url = ? WHERE ad_id = ? AND date_start = ?',
                    [$thumb, $adId, $date]
                );
            }
        }
    }
}
