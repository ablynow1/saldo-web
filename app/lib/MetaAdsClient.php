<?php
declare(strict_types=1);

/**
 * Cliente da Marketing API do Meta (Facebook).
 *
 * Usa um System User Token com escopos ads_read + business_management.
 * Docs: https://developers.facebook.com/docs/marketing-api/reference/ad-account/
 */
final class MetaAdsClient
{
    private string $token;
    private string $apiVersion;

    public function __construct(string $token, string $apiVersion = 'v19.0')
    {
        $this->token      = $token;
        $this->apiVersion = $apiVersion;
    }

    public static function fromSettings(): self
    {
        // Prefere token OAuth (Facebook Login) — obtido via oauth_fb.php.
        // Fallback: System User Token legado (Configurações).
        $token = Db::getSetting('fb_user_access_token') ?: Db::getSetting('meta_system_user_token');
        $ver   = Db::getSetting('meta_api_version') ?: 'v19.0';
        if (!$token) {
            throw new RuntimeException('Nenhum token Meta configurado. Conecte uma conta via OAuth em Contas ou cadastre um System User Token.');
        }
        return new self($token, $ver);
    }

    /**
     * Lista todas as Ad Accounts acessíveis pelo token (via /me/adaccounts).
     * @return array<int,array{id:string,name:?string,currency:?string,account_status:?int}>
     */
    public function listAdAccounts(): array
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/me/adaccounts?fields=%s&access_token=%s&limit=200',
            $this->apiVersion,
            urlencode('id,account_id,name,currency,account_status'),
            urlencode($this->token)
        );
        $resp = HttpClient::get($url);
        $this->assertOk($resp);

        $out = [];
        foreach (($resp['body']['data'] ?? []) as $row) {
            $out[] = [
                'id'             => $row['account_id'] ?? str_replace('act_', '', $row['id'] ?? ''),
                'name'           => $row['name'] ?? null,
                'currency'       => $row['currency'] ?? null,
                'account_status' => isset($row['account_status']) ? (int) $row['account_status'] : null,
            ];
        }
        return $out;
    }

    /**
     * Busca dados da conta de anúncios.
     *
     * Valores monetários retornados em "centavos" da moeda (convenção do Graph).
     *
     * @return array{
     *   id:string, name:?string, currency:?string, account_status:?int,
     *   balance:?float, amount_spent:?float, spend_cap:?float,
     *   funding_source_details:?array, disable_reason:?int,
     *   account_type:'prepaid'|'postpaid'|'unknown',
     *   raw:array
     * }
     */
    public function getAccount(string $adAccountId): array
    {
        $fields = 'name,account_status,currency,balance,amount_spent,spend_cap,'
                . 'funding_source_details,disable_reason,age';
        $url = sprintf(
            'https://graph.facebook.com/%s/act_%s?fields=%s&access_token=%s',
            $this->apiVersion,
            urlencode($adAccountId),
            urlencode($fields),
            urlencode($this->token)
        );
        $resp = HttpClient::get($url);
        $this->assertOk($resp);

        $b = $resp['body'];
        return [
            'id'                     => $adAccountId,
            'name'                   => $b['name'] ?? null,
            'currency'               => $b['currency'] ?? null,
            'account_status'         => isset($b['account_status']) ? (int) $b['account_status'] : null,
            'balance'                => isset($b['balance']) ? (float) $b['balance'] : null,
            'amount_spent'           => isset($b['amount_spent']) ? (float) $b['amount_spent'] : null,
            'spend_cap'              => isset($b['spend_cap']) ? (float) $b['spend_cap'] : null,
            'funding_source_details' => $b['funding_source_details'] ?? null,
            'disable_reason'         => isset($b['disable_reason']) ? (int) $b['disable_reason'] : null,
            'account_type'           => self::detectAccountType($b),
            'raw'                    => $b,
        ];
    }

    /**
     * Gasto médio diário dos últimos N dias (via insights).
     * Retorna em "centavos" da moeda da conta.
     */
    public function getAverageDailySpend(string $adAccountId, int $days = 7): ?float
    {
        $since = date('Y-m-d', strtotime("-{$days} days"));
        $until = date('Y-m-d', strtotime('-1 day'));
        $range = json_encode(['since' => $since, 'until' => $until]);

        $url = sprintf(
            'https://graph.facebook.com/%s/act_%s/insights?fields=spend&time_range=%s&time_increment=1&access_token=%s',
            $this->apiVersion,
            urlencode($adAccountId),
            urlencode($range),
            urlencode($this->token)
        );
        $resp = HttpClient::get($url);
        if ($resp['status'] >= 400) {
            return null;
        }
        $rows = $resp['body']['data'] ?? [];
        if (empty($rows)) return 0.0;

        $sum = 0.0;
        $n = 0;
        foreach ($rows as $r) {
            if (isset($r['spend'])) {
                $sum += (float) $r['spend'];
                $n++;
            }
        }
        if ($n === 0) return 0.0;
        // insights retorna spend como string em unidades inteiras da moeda (ex: "123.45" reais).
        // convertemos para centavos para bater com balance/amount_spent.
        return ($sum / $n) * 100;
    }

    /**
     * Série de gasto diário dos últimos N dias (ordenada do mais antigo ao mais recente).
     * Retorna [['date'=>'YYYY-MM-DD','spend_cents'=>float], ...].
     * Dias sem gasto aparecem com 0 (preenchidos).
     */
    public function getDailySpendSeries(string $adAccountId, int $days = 14): array
    {
        $since = date('Y-m-d', strtotime("-{$days} days"));
        $until = date('Y-m-d', strtotime('-1 day'));
        $range = json_encode(['since' => $since, 'until' => $until]);

        $url = sprintf(
            'https://graph.facebook.com/%s/act_%s/insights?fields=spend&time_range=%s&time_increment=1&access_token=%s',
            $this->apiVersion,
            urlencode($adAccountId),
            urlencode($range),
            urlencode($this->token)
        );
        $resp = HttpClient::get($url);
        if ($resp['status'] >= 400) return [];

        $byDate = [];
        foreach (($resp['body']['data'] ?? []) as $r) {
            $d = $r['date_start'] ?? null;
            if ($d) $byDate[$d] = isset($r['spend']) ? ((float) $r['spend']) * 100 : 0.0;
        }

        // preencher dias sem dados com 0
        $out = [];
        $cursor = new DateTime($since);
        $end    = new DateTime($until);
        while ($cursor <= $end) {
            $ds = $cursor->format('Y-m-d');
            $out[] = ['date' => $ds, 'spend_cents' => $byDate[$ds] ?? 0.0];
            $cursor->modify('+1 day');
        }
        return $out;
    }

    /**
     * Gasto de HOJE (intraday) — em centavos.
     * Usa date_preset=today que devolve gasto acumulado desde 00:00 na tz da conta.
     */
    public function getTodaySpendCents(string $adAccountId): float
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/act_%s/insights?fields=spend&date_preset=today&access_token=%s',
            $this->apiVersion,
            urlencode($adAccountId),
            urlencode($this->token)
        );
        $resp = HttpClient::get($url);
        if ($resp['status'] >= 400) return 0.0;
        $rows = $resp['body']['data'] ?? [];
        if (empty($rows)) return 0.0;
        $spend = (float) ($rows[0]['spend'] ?? 0);
        return $spend * 100; // centavos
    }

    /**
     * Número de anúncios com effective_status = ACTIVE na conta.
     * Usa summary=true + limit=0 pra só pegar a contagem sem baixar tudo.
     */
    public function getActiveAdsCount(string $adAccountId): ?int
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/act_%s/ads?effective_status=%s&limit=1&summary=true&access_token=%s',
            $this->apiVersion,
            urlencode($adAccountId),
            urlencode('["ACTIVE"]'),
            urlencode($this->token)
        );
        $resp = HttpClient::get($url);
        if ($resp['status'] >= 400) return null;
        $total = $resp['body']['summary']['total_count'] ?? null;
        return $total !== null ? (int) $total : null;
    }

    /**
     * Heurística: se há spend_cap configurado OU funding_source é cartão de crédito = postpaid.
     * Pré-pago via boleto/PIX aparece como funding_source_details.type com strings tipo "PAYPAL" ou "Unsettled".
     * Nem sempre é determinístico — quando em dúvida, "unknown".
     */
    public static function detectAccountType(array $raw): string
    {
        $fsd = $raw['funding_source_details'] ?? null;
        $type = is_array($fsd) ? strtoupper((string) ($fsd['type'] ?? '')) : '';

        // type=1 = cartão, type=2 = faturamento, type=9 = pré-pago (varia ao longo do tempo na API)
        if ($type !== '') {
            $prepaidHints  = ['PREPAID', 'EXTENDED_CREDIT', 'BOLETO', 'PIX'];
            $postpaidHints = ['CREDIT_CARD', 'BANK', 'BILL', 'INVOICE'];
            foreach ($prepaidHints as $h) {
                if (str_contains($type, $h)) return 'prepaid';
            }
            foreach ($postpaidHints as $h) {
                if (str_contains($type, $h)) return 'postpaid';
            }
        }
        if (!empty($raw['spend_cap']) && (float) $raw['spend_cap'] > 0) {
            return 'postpaid';
        }
        return 'unknown';
    }

    private function assertOk(array $resp): void
    {
        if ($resp['status'] >= 400 || !empty($resp['body']['error'])) {
            $msg = $resp['body']['error']['message'] ?? ($resp['error'] ?? 'Erro na Marketing API');
            throw new RuntimeException('Meta API: ' . $msg);
        }
    }
}
