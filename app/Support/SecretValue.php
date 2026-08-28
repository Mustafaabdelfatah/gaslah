<?php

namespace App\Support;

use RuntimeException;

/**
 * Encrypts the third-party credentials a tenant stores — gateway keys, WhatsApp tokens,
 * SMS keys — so a database dump does not hand someone the ability to charge cards or send
 * messages as the laundry.
 *
 * AES-256-GCM, so a tampered ciphertext fails to decrypt rather than yielding altered
 * plaintext. Values carry an "enc:v1:" prefix, which is what lets the store tell an
 * encrypted value from a legacy plaintext one and re-encrypt it on the next save.
 */
class SecretValue
{
    private const PREFIX = 'enc:v1:';

    private const CIPHER = 'aes-256-gcm';

    /**
     * Bound into the tag, so ciphertext lifted from another system's column will not
     * decrypt here even if it shares the key.
     */
    private const AAD = 'laundry-settings-v1';

    private const IV_BYTES = 12;

    private const TAG_BYTES = 16;

    /**
     * Wrap a value for storage. Empty stays empty, and an already-wrapped value is passed
     * through rather than wrapped twice.
     */
    public static function encrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Re-wrapping would make the value undecryptable at one layer, so a value that is
        // already a valid envelope is left alone. A forged or foreign envelope is not —
        // it is treated as plain text and encrypted, because storing a blob nobody can
        // ever open is worse than storing it wrapped.
        if (self::isEnvelope($value) && self::tryDecrypt($value) !== null) {
            return $value;
        }

        $iv = random_bytes(self::IV_BYTES);
        $tag = '';

        $cipherText = openssl_encrypt(
            $value,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::AAD,
            self::TAG_BYTES,
        );

        if ($cipherText === false) {
            throw new RuntimeException('Unable to encrypt the secret.');
        }

        return self::PREFIX.self::toBase64Url($iv.$tag.$cipherText);
    }

    /**
     * Unwrap a stored value.
     *
     * A value with no envelope is returned as-is: secrets written before encryption was
     * introduced still have to be readable, and they get wrapped on their next save.
     */
    public static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || ! self::isEnvelope($value)) {
            return $value;
        }

        $plain = self::tryDecrypt($value);

        if ($plain === null) {
            throw new RuntimeException('Unable to decrypt the stored secret — it is corrupt or the key changed.');
        }

        return $plain;
    }

    public static function isEnvelope(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private static function tryDecrypt(string $value): ?string
    {
        $raw = self::fromBase64Url(substr($value, strlen(self::PREFIX)));

        if ($raw === null || strlen($raw) <= self::IV_BYTES + self::TAG_BYTES) {
            return null;
        }

        $plain = openssl_decrypt(
            substr($raw, self::IV_BYTES + self::TAG_BYTES),
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_BYTES),
            substr($raw, self::IV_BYTES, self::TAG_BYTES),
            self::AAD,
        );

        return $plain === false ? null : $plain;
    }

    private static function key(): string
    {
        $key = config('services.settings_encryption_key');

        if (empty($key)) {
            throw new RuntimeException('SETTINGS_ENCRYPTION_KEY is required to read or write integration secrets.');
        }

        return hash('sha256', (string) $key, true);
    }

    private static function toBase64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function fromBase64Url(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
