<?php
declare(strict_types=1);

final class HttpClient
{
    public static function get(string $url, array $headers = [], int $timeout = 20): array
    {
        return self::request('GET', $url, null, $headers, $timeout);
    }

    public static function postJson(string $url, array $body, array $headers = [], int $timeout = 20): array
    {
        $headers[] = 'Content-Type: application/json';
        return self::request('POST', $url, json_encode($body, JSON_UNESCAPED_UNICODE), $headers, $timeout);
    }

    public static function request(string $method, string $url, ?string $body, array $headers, int $timeout): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $rawResp = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err     = curl_error($ch);
        // curl_close() deprecated since PHP 8.0 (no-op) — removed.

        if ($rawResp === false) {
            return ['status' => 0, 'body' => null, 'raw' => null, 'error' => $err];
        }
        $decoded = json_decode((string) $rawResp, true);
        return [
            'status' => $status,
            'body'   => $decoded,
            'raw'    => $rawResp,
            'error'  => null,
        ];
    }
}
