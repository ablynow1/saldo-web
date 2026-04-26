<?php
declare(strict_types=1);

final class Crypto
{
    private static ?string $key = null;
    private const CIPHER = 'aes-256-gcm';

    public static function init(string $base64Key): void
    {
        $raw = base64_decode($base64Key, true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('app_key inválida: precisa ser 32 bytes em base64');
        }
        self::$key = $raw;
    }

    public static function encrypt(string $plaintext): string
    {
        self::ensureKey();
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, self::CIPHER, self::$key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new RuntimeException('Falha ao criptografar');
        }
        return base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $payload): string
    {
        self::ensureKey();
        if ($payload === '') return '';
        $bin = base64_decode($payload, true);
        if ($bin === false || strlen($bin) < 28) {
            // Payload corrompido ou não criptografado — retorna vazio em vez de quebrar a página
            return '';
        }
        $iv  = substr($bin, 0, 12);
        $tag = substr($bin, 12, 16);
        $ct  = substr($bin, 28);
        $pt  = @openssl_decrypt($ct, self::CIPHER, self::$key, OPENSSL_RAW_DATA, $iv, $tag);
        // Se a chave mudou (reinstalação), devolve vazio — o usuário precisa reconfigurar o token
        return $pt === false ? '' : $pt;
    }

    private static function ensureKey(): void
    {
        if (self::$key === null) {
            throw new RuntimeException('Crypto não inicializado');
        }
    }
}
