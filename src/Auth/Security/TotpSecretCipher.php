<?php

declare(strict_types=1);

namespace App\Auth\Security;

/**
 * Encrypts TOTP secrets at rest (sodium secretbox, key derived from
 * APP_SECRET): a database dump alone must not allow generating valid
 * one-time codes.
 *
 * Static on purpose: the User entity needs to decrypt inside
 * getTotpAuthenticationConfiguration(), where no service can be injected.
 * The key derivation stays deterministic per APP_SECRET — rotating the app
 * secret invalidates stored TOTP secrets (users re-enroll).
 */
final class TotpSecretCipher
{
    private const PREFIX = 'enc-v1:';

    public static function encrypt(string $plainSecret): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox($plainSecret, $nonce, self::key()));
    }

    public static function decrypt(string $storedSecret): ?string
    {
        if (!str_starts_with($storedSecret, self::PREFIX)) {
            return null;
        }

        $raw = base64_decode(substr($storedSecret, \strlen(self::PREFIX)), true);
        if (false === $raw || \strlen($raw) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::key(),
        );

        return false !== $plain ? $plain : null;
    }

    private static function key(): string
    {
        $appSecret = (string) ($_ENV['APP_SECRET'] ?? $_SERVER['APP_SECRET'] ?? '');
        if ('' === $appSecret) {
            throw new \RuntimeException('APP_SECRET is required to cipher TOTP secrets.');
        }

        return sodium_crypto_generichash($appSecret.'|totp-secret', length: \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
