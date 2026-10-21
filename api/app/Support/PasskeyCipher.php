<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class PasskeyCipher
{
    private const CIPHER = 'aes-256-gcm';

    private const IV_LENGTH = 12;

    private const TAG_LENGTH = 16;

    private string $key;

    public function __construct()
    {
        $configured = config('passkeys.encryption_key');
        if (! is_string($configured) || trim($configured) === '') {
            // Recover cleanly if a cached config predates the passkeys config file.
            $configured = env('PASSKEY_ENCRYPTION_KEY');
        }
        $configured = trim((string) $configured);
        $this->key = $this->decodeKey($configured);
    }

    public function encryptPasskey(string $plain): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plain,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH,
        );

        if ($ciphertext === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new RuntimeException('Unable to encrypt examination passkey.');
        }

        return implode(':', [
            base64_encode($iv),
            base64_encode($tag),
            base64_encode($ciphertext),
        ]);
    }

    public function decryptPasskey(string $encrypted): string
    {
        $parts = explode(':', $encrypted);
        if (count($parts) !== 3) {
            return $this->decryptLegacyPasskey($encrypted);
        }

        [$encodedIv, $encodedTag, $encodedCiphertext] = $parts;
        $iv = base64_decode($encodedIv, true);
        $tag = base64_decode($encodedTag, true);
        $ciphertext = base64_decode($encodedCiphertext, true);

        if (
            $iv === false || strlen($iv) !== self::IV_LENGTH
            || $tag === false || strlen($tag) !== self::TAG_LENGTH
            || $ciphertext === false
        ) {
            throw new RuntimeException('Examination passkey decryption failed.');
        }

        $plain = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plain === false) {
            throw new RuntimeException('Examination passkey decryption failed.');
        }

        return $plain;
    }

    public function isCurrentFormat(string $encrypted): bool
    {
        return count(explode(':', $encrypted)) === 3;
    }

    private function decodeKey(string $configured): string
    {
        if (preg_match('/\A[0-9a-fA-F]{64}\z/', $configured) === 1) {
            $key = hex2bin($configured);
        } else {
            $base64 = str_starts_with($configured, 'base64:')
                ? substr($configured, 7)
                : $configured;
            $key = base64_decode($base64, true);
        }

        if ($configured === '' || $key === false || strlen($key) !== 32) {
            throw new RuntimeException(
                'PASSKEY_ENCRYPTION_KEY must contain exactly 32 bytes encoded as base64 or 64 hexadecimal characters.'
            );
        }

        if (! function_exists('openssl_encrypt') || ! function_exists('openssl_decrypt')) {
            throw new RuntimeException('The OpenSSL PHP extension is required to encrypt examination passkeys.');
        }

        return $key;
    }

    private function decryptLegacyPasskey(string $encrypted): string
    {
        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException|RuntimeException) {
            throw new RuntimeException('Examination passkey decryption failed.');
        }
    }
}
