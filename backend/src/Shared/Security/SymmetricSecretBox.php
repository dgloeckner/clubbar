<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Authenticated symmetric encryption for secrets the server must decrypt
 * itself — e.g. a TOTP secret, verified against a live code on every login.
 * This is the opposite trust model from IbanSealedBox: here the server
 * holding the key is the point, not the risk (see ADR-0036's "Alternatives
 * Considered" section for why a server-resident key was rejected for IBANs
 * but is fine here).
 *
 * Sharing HexSecretKey's key validation with IbanSealedBox is what makes
 * this and IbanSealedBox one audited family of primitives instead of two
 * independent hand-rolled implementations of "encrypt a secret at rest"
 * (#437).
 */
class SymmetricSecretBox
{
    private const CIPHERTEXT_PREFIX = 'v1:';

    private string $key;

    public function __construct(string $keyHex, string $envVarName)
    {
        // Fail closed: without sodium there is no weaker fallback. A silent
        // downgrade to homemade crypto is exactly the failure this exists
        // to make loud (mirrors IbanSealedBox's sodium_crypto_box_seal check).
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \RuntimeException(
                'The sodium extension with crypto_secretbox support is required for encrypted secret '
                . 'storage. There is no fallback; enable ext-sodium.'
            );
        }

        $this->key = HexSecretKey::decode($keyHex, $envVarName);
    }

    /**
     * Encrypt a plaintext secret for storage.
     * Format: v1:base64(nonce . ciphertext). The version prefix mirrors
     * IbanSealedBox's convention so ciphertext, legacy formats, and future
     * format changes can always be told apart without guessing.
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return self::CIPHERTEXT_PREFIX . base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt a stored secret.
     *
     * Returns false — never throws — for malformed input, a wrong key, or a
     * tampered ciphertext. Unlike raw AES-256-CBC, secretbox authenticates:
     * tampering is detected and rejected rather than silently producing
     * garbage plaintext.
     */
    public function decrypt(string $stored): string|false
    {
        if (!str_starts_with($stored, self::CIPHERTEXT_PREFIX)) {
            return false;
        }

        $raw = base64_decode(substr($stored, strlen(self::CIPHERTEXT_PREFIX)), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return false;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
    }
}
