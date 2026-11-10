<?php
/**
 * Encrypts bot tokens at rest (libsodium secretbox).
 * APP_KEY: any long random string (keep it secret, never change it after launch).
 */
final class Crypto
{
    private static function key(): string
    {
        $key = App::env('APP_KEY');
        if ($key === null || strlen($key) < 16) {
            throw new RuntimeException('APP_KEY is missing or too short');
        }
        return hash('sha256', $key, true);
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(string $encoded): ?string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($box, $nonce, self::key());
        return $plain === false ? null : $plain;
    }
}
