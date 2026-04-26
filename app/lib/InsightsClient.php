<?php
declare(strict_types=1);

/**
 * Cliente de métricas de performance da Meta Marketing API.
 *
 * Coleta dados de campanhas e anúncios (ROAS, CPA, CTR, etc.)
 * e salva nas tabelas ad_insights e campaign_insights.
 *
 * Conversões: identifica o tipo de conversão pelo setting 'conversion_event'
 * (padrão: 'purchase'). Contas de leads devem mudar para 'lead'.
 */
final class InsightsClient
{
    private string $token;
    private string $apiVersion;
    private string $conversionEvent;

    public function __construct(string $token, string $apiVersion = 'v19.0', string $conversionEvent = 'purchase')
    {
        $this->token           = $token;
        $this->apiVersion      = $apiVersion;
        $this->conversionEvent = $conversionEvent;
    }

    public static function fromSettings(): self
    {
        $token = Db::getSetting('meta_system_user_token');
        $ver   = Db::getSetting('meta_api_version') ?: 'v19.0';
        $conv  = Db::getSetting('conversion_event') ?: 'purchase';
        if (!$token) throw new RuntimeException('System User Token não configurado.');
        return new self($token, $ver, $conv);
    }

    /**
     * Coleta insights por anúncio (nível "ad") de uma conta, para um período.
     * Salva/atualiza na tabela ad_insights.
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
        $fields = implode(',', [
            'ad_id', 'ad_name', 'adset_id', 'adset_name', 'campaign_id', 'campaign_name',
            'spend', 'impressions', 'clicks', 'reach',
            'actions', 'action_values',
            'date_start', 'date_stop',
        ]);

        $params = ['fields' => $fields, 'level' => 'ad', 'access_token' => $this->token, 'limit' => 500];
        if ($datePreset === 'custom' && $since && $until) {
            $params['time_range'] = json_encode(['since' => $since, 'until' => $until]);
        } else {
            $params['date_preset'] = $datePreset;
        }

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
        $params = ['fields' => $fields, 'level' => 'campaign', 'access_token' => $this->token, 'limit' => 200];
        if ($datePreset === 'custom' && $since && $until) {
            $params['time_range'] = json_encode(['since' => $since, 'until' => $until]);
        } else {
            $params['date_preset'] = $datePreset;
        }

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

    // ───────────────────────────────── helpers privados ─────────────────────────────────

    private function parseRow(array $row): array
    {
        $spend = (float) ($row['spend'] ?? 0);

        // Soma todos os actions do tipo de conversão configurado
        $actions    = $row['actions'] ?? [];
        $actValues  = $row['action_values'] ?? [];

        $conversions      = 0;
        $conversionValue  = 0.0;
        $leads            = 0;
        $landingPageViews = 0;
        $addsToCart       = 0;
        $initiatesCheckout = 0;

        foreach ($actions as $a) {
            $t = $a['action_type'] ?? '';
            $v = (float) ($a['value'] ?? 0);
            if ($t === $this->conversionEvent || $t === "offsite_conversion.fb_pixel_{$this->conversionEvent}") {
                $conversions += (int) round($v);
            }
            if ($t === 'lead' || $t === 'onsite_conversion.lead_grouped') {
                $leads += (int) round($v);
            }
            if ($t === 'landing_page_view' || $t === 'onsite_conversion.landing_page_view' || $t === 'offsite_conversion.fb_pixel_custom') { // FB Pixel view content ou LPV
                $landingPageViews += (int) round($v);
            }
            if ($t === 'add_to_cart' || $t === 'offsite_conversion.fb_pixel_add_to_cart') {
                $addsToCart += (int) round($v);
            }
            if ($t === 'initiate_checkout' || $t === 'offsite_conversion.fb_pixel_initiate_checkout') {
                $initiatesCheckout += (int) round($v);
            }
        }
        foreach ($actValues as $a) {
            $t = $a['action_type'] ?? '';
            if ($t === $this->conversionEvent || $t === "offsite_conversion.fb_pixel_{$this->conversionEvent}") {
                $conversionValue += (float) ($a['value'] ?? 0);
            }
        }

        $impressions = (int) ($row['impressions'] ?? 0);
        $clicks      = (int) ($row['clicks'] ?? 0);

        $ctr  = $impressions > 0 ? ($clicks / $impressions) * 100 : 0;
        $cpc  = $clicks > 0 ? $spend / $clicks : 0;
        $cpa  = $conversions > 0 ? $spend / $conversions : 0;
        $roas = $spend > 0 && $conversionValue > 0 ? $conversionValue / $spend : 0;
        $cpp  = $impressions > 0 ? ($spend / $impressions) * 1000 : 0;
        $cpm  = $cpp;

        return compact('conversions', 'conversionValue', 'leads', 'landingPageViews', 'addsToCart', 'initiatesCheckout', 'ctr', 'cpc', 'cpa', 'roas', 'cpp', 'cpm');
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
