<?php
declare(strict_types=1);

/**
 * BalanceForecaster — Previsão inteligente de término de saldo.
 *
 * Combina múltiplos sinais para gerar uma projeção mais confiável:
 *   - Série histórica de gasto dos últimos 14 dias (com pesos)
 *   - Gasto intraday de hoje (projetado para 24h)
 *   - Número de anúncios ativos (detecta aumento/redução de volume)
 *   - Tendência (ritmo está acelerando ou desacelerando?)
 *
 * Todos os valores monetários em "centavos" da moeda (padrão Meta).
 */
final class BalanceForecaster
{
    public const TREND_ACCELERATING = 'accelerating';
    public const TREND_STABLE       = 'stable';
    public const TREND_DECELERATING = 'decelerating';

    private MetaAdsClient $client;

    public function __construct(MetaAdsClient $client)
    {
        $this->client = $client;
    }

    /**
     * Gera projeção completa para uma ad account.
     *
     * @param string $adAccountId sem "act_"
     * @param ?float $balanceCents saldo atual em centavos (já conhecido via getAccount)
     * @return array{
     *   balance_cents: ?float,
     *   spend_today_cents: float,
     *   spend_today_projected_cents: float,
     *   spend_avg_3d_cents: float,
     *   spend_avg_7d_cents: float,
     *   spend_avg_14d_cents: float,
     *   spend_weighted_daily_cents: float,
     *   active_ads: ?int,
     *   trend: string,
     *   trend_pct: float,
     *   runway_days: ?float,
     *   runway_hours: ?float,
     *   depletion_date: ?string,
     *   depletion_day_human: ?string,
     *   confidence: string,
     *   recommended_topup_cents: ?float,
     *   series: array<int, array{date:string, spend_cents:float}>
     * }
     */
    public function forecast(string $adAccountId, ?float $balanceCents): array
    {
        // 1) Série dos últimos 14 dias (dia a dia)
        $series = $this->client->getDailySpendSeries($adAccountId, 14);

        // 2) Gasto de hoje até agora (intraday)
        $todayCents = $this->client->getTodaySpendCents($adAccountId);

        // 3) Anúncios ativos (informação de contexto)
        $activeAds = null;
        try {
            $activeAds = $this->client->getActiveAdsCount($adAccountId);
        } catch (Throwable $e) {
            // não-fatal
        }

        // 4) Médias em janelas diferentes
        $avg3  = $this->avgLastN($series, 3);
        $avg7  = $this->avgLastN($series, 7);
        $avg14 = $this->avgLastN($series, 14);

        // 5) Média ponderada — dias recentes pesam mais
        //    peso: últimos 3d = 3, 7d (excl. 3d) = 2, 14d (excl. 7d) = 1
        $weighted = $this->weightedDaily($series);

        // 6) Projeção do gasto do dia INTEIRO com base no ritmo intraday
        $todayProjected = $this->projectTodayFullDay($todayCents);

        // 7) Tendência (compara média 3d vs 14d)
        [$trend, $trendPct] = $this->detectTrend($avg3, $avg14);

        // 8) Ritmo "efetivo" — o maior entre projeção do dia e média ponderada
        //    (segurança: se hoje está mais acelerado, usa isso pra projeção)
        $effectiveDaily = max($weighted, $todayProjected);

        // 9) Runway
        $runwayDays = null;
        $runwayHours = null;
        $depletionDate = null;
        $depletionHuman = null;
        if ($balanceCents !== null && $effectiveDaily > 0) {
            // saldo restante após descontar o que já foi gasto hoje
            $remaining = max(0.0, $balanceCents - $todayCents);
            $runwayDays  = $remaining / $effectiveDaily;
            $runwayHours = $runwayDays * 24;
            $depletion   = (new DateTime())->modify(sprintf('+%d hours', (int) round($runwayHours)));
            $depletionDate  = $depletion->format('Y-m-d H:i:s');
            $depletionHuman = $this->humanizeDate($depletion, $runwayDays);
        }

        // 10) Nível de confiança da previsão
        $confidence = $this->confidenceLevel($series, $activeAds, $todayCents);

        // 11) Recomendação de recarga — 10 dias de gasto efetivo, arredondado
        $recommendedTopup = null;
        if ($effectiveDaily > 0) {
            $raw = $effectiveDaily * 10; // 10 dias de runway
            $recommendedTopup = $this->roundUpToNice($raw);
        }

        return [
            'balance_cents'               => $balanceCents,
            'spend_today_cents'           => $todayCents,
            'spend_today_projected_cents' => $todayProjected,
            'spend_avg_3d_cents'          => $avg3,
            'spend_avg_7d_cents'          => $avg7,
            'spend_avg_14d_cents'         => $avg14,
            'spend_weighted_daily_cents'  => $weighted,
            'active_ads'                  => $activeAds,
            'trend'                       => $trend,
            'trend_pct'                   => $trendPct,
            'runway_days'                 => $runwayDays,
            'runway_hours'                => $runwayHours,
            'depletion_date'              => $depletionDate,
            'depletion_day_human'         => $depletionHuman,
            'confidence'                  => $confidence,
            'recommended_topup_cents'     => $recommendedTopup,
            'series'                      => $series,
        ];
    }

    // ─────────────────────────── cálculos ───────────────────────────

    /** Média dos últimos N dias da série (em centavos). 0 se vazio. */
    private function avgLastN(array $series, int $n): float
    {
        $slice = array_slice($series, -$n);
        if (empty($slice)) return 0.0;
        $sum = 0.0;
        foreach ($slice as $r) $sum += (float) $r['spend_cents'];
        return $sum / count($slice);
    }

    /** Média ponderada: últimos 3d peso 3, dias 4-7 peso 2, dias 8-14 peso 1. */
    private function weightedDaily(array $series): float
    {
        if (empty($series)) return 0.0;
        $total = count($series);
        $sum = 0.0;
        $weightSum = 0.0;
        foreach ($series as $i => $r) {
            // índice 0 = mais antigo; último = mais recente
            $daysAgo = $total - 1 - $i;
            $w = $daysAgo < 3 ? 3 : ($daysAgo < 7 ? 2 : 1);
            $sum += $r['spend_cents'] * $w;
            $weightSum += $w;
        }
        return $weightSum > 0 ? $sum / $weightSum : 0.0;
    }

    /**
     * Projeta o gasto do dia inteiro com base no que já foi gasto até agora.
     * Usa o fuso configurado no PHP. Horas decorridas mínimo = 1 pra evitar divisão.
     */
    private function projectTodayFullDay(float $todayCents): float
    {
        if ($todayCents <= 0) return 0.0;
        // hora atual em fração do dia (0.0 a 1.0)
        $now = new DateTime('now');
        $secondsElapsed = $now->getTimestamp() - strtotime(date('Y-m-d 00:00:00'));
        $fraction = max(0.05, min(1.0, $secondsElapsed / 86400)); // mín 5% pra não explodir de manhã cedo
        return $todayCents / $fraction;
    }

    /** Compara média dos 3 últimos dias vs média de 14d. Retorna [trend, pct]. */
    private function detectTrend(float $avg3, float $avg14): array
    {
        if ($avg14 <= 0) return [self::TREND_STABLE, 0.0];
        $pct = (($avg3 - $avg14) / $avg14) * 100;
        if ($pct > 20)  return [self::TREND_ACCELERATING, $pct];
        if ($pct < -20) return [self::TREND_DECELERATING, $pct];
        return [self::TREND_STABLE, $pct];
    }

    /** Avalia a confiança da previsão com base em quantidade e qualidade dos dados. */
    private function confidenceLevel(array $series, ?int $activeAds, float $todayCents): string
    {
        $daysWithData = 0;
        foreach ($series as $r) if ($r['spend_cents'] > 0) $daysWithData++;

        if ($daysWithData >= 7 && $activeAds !== null && $activeAds > 0) return 'high';
        if ($daysWithData >= 3) return 'medium';
        return 'low';
    }

    /** Arredonda pra cima em múltiplos "bonitos" (50, 100, 500, 1000). */
    private function roundUpToNice(float $cents): float
    {
        $reais = $cents / 100;
        if ($reais < 100)   $step = 50;
        elseif ($reais < 500)  $step = 100;
        elseif ($reais < 2000) $step = 250;
        elseif ($reais < 10000) $step = 500;
        else $step = 1000;
        $rounded = ceil($reais / $step) * $step;
        return $rounded * 100;
    }

    /** "amanhã 14h", "daqui a 3 dias", "hoje à noite" etc. */
    private function humanizeDate(DateTime $when, float $runwayDays): string
    {
        $now    = new DateTime();
        $diffH  = ($when->getTimestamp() - $now->getTimestamp()) / 3600;
        $hour   = (int) $when->format('G');
        $period = $hour < 6 ? 'madrugada' : ($hour < 12 ? 'manhã' : ($hour < 18 ? 'tarde' : 'noite'));

        if ($diffH < 0)  return 'AGORA (saldo no negativo)';
        if ($diffH < 6)  return "em ~" . round($diffH, 1) . "h (hoje à $period)";
        if ($when->format('Y-m-d') === date('Y-m-d')) return "hoje à $period (" . $when->format('H\\hi') . ')';
        if ($when->format('Y-m-d') === date('Y-m-d', strtotime('+1 day'))) return "amanhã à $period (" . $when->format('H\\hi') . ')';

        $days = (int) round($runwayDays);
        return "em $days dias — " . $when->format('d/m') . " à $period";
    }
}
