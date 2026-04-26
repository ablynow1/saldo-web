<?php
declare(strict_types=1);

/**
 * Cliente da Evolution API (WhatsApp).
 * Docs: https://doc.evolution-api.com/
 */
final class WhatsAppClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $instance;

    public function __construct(string $baseUrl, string $apiKey, string $instance)
    {
        $this->baseUrl  = rtrim($baseUrl, '/');
        $this->apiKey   = $apiKey;
        $this->instance = $instance;
    }

    public static function fromSettings(): self
    {
        $base = Db::getSetting('evolution_base_url');
        $key  = Db::getSetting('evolution_api_key');
        $inst = Db::getSetting('evolution_instance');
        if (!$base || !$key || !$inst) {
            throw new RuntimeException('Configuração da Evolution API incompleta.');
        }
        return new self($base, $key, $inst);
    }

    private function headers(): array
    {
        return ['apikey: ' . $this->apiKey];
    }

    /**
     * Envia mensagem de texto para um destino (grupo ou número).
     * Para grupos, passar o JID no formato "120363xxxxxxxxxxx@g.us".
     *
     * @return array{ok:bool, status:int, body:mixed}
     */
    public function sendText(string $to, string $message): array
    {
        $url = $this->baseUrl . '/message/sendText/' . rawurlencode($this->instance);
        $payload = [
            'number'       => $to,
            'text'         => $message,
            'delay'        => 0,
            'linkPreview'  => false,
        ];
        $resp = HttpClient::postJson($url, $payload, $this->headers());
        return [
            'ok'     => $resp['status'] >= 200 && $resp['status'] < 300,
            'status' => $resp['status'],
            'body'   => $resp['body'] ?? $resp['raw'],
        ];
    }

    /**
     * Lista grupos da instância. Usado pelo painel para o admin selecionar
     * qual grupo associar a cada cliente.
     *
     * @return array<int,array{id:string,subject:string,size:?int}>
     */
    public function listGroups(): array
    {
        $url = $this->baseUrl . '/group/fetchAllGroups/' . rawurlencode($this->instance) . '?getParticipants=false';
        $resp = HttpClient::get($url, $this->headers());
        if ($resp['status'] >= 400) {
            throw new RuntimeException('Evolution API: falha ao listar grupos (HTTP ' . $resp['status'] . ')');
        }
        $rows = $resp['body'] ?? [];
        $out = [];
        foreach ($rows as $g) {
            $out[] = [
                'id'      => $g['id'] ?? '',
                'subject' => $g['subject'] ?? '(sem nome)',
                'size'    => $g['size'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Verifica se a instância está conectada.
     */
    public function connectionStatus(): array
    {
        $url = $this->baseUrl . '/instance/connectionState/' . rawurlencode($this->instance);
        $resp = HttpClient::get($url, $this->headers());
        return [
            'ok'     => $resp['status'] >= 200 && $resp['status'] < 300,
            'state'  => $resp['body']['instance']['state'] ?? ($resp['body']['state'] ?? 'unknown'),
            'status' => $resp['status'],
            'body'   => $resp['body'],
        ];
    }
}
