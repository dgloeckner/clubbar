<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Shared\Config\Env;
use RobThree\Auth\TwoFactorAuth;

class TotpService
{
    /** AES-256 takes a 32-byte key, which is 64 hex characters. */
    private const KEY_BYTES = 32;

    private TwoFactorAuth $tfa;
    private string $encryptionKey;

    public function __construct()
    {
        $issuer = Env::get('APP_ENV', 'production') === 'production' ? 'Ruderbar' : 'Ruderbar (dev)';
        $this->tfa = new TwoFactorAuth(
            issuer: $issuer,
            qrcodeprovider: new ChillerlanQrProvider(),
        );
        $this->encryptionKey = self::decodeKey(Env::get('TOTP_ENCRYPTION_KEY'));
    }

    /**
     * Turn the configured hex string into the raw AES-256 key.
     *
     * This is checked rather than assumed because openssl fails *quietly* on a
     * bad key: it pads anything shorter than 32 bytes with NUL. An empty key —
     * which is exactly what `package/index.php` produces when
     * `security.totp_encryption_key` is absent from config.php — would encrypt
     * every secret under 32 NUL bytes, the same key on every such install, and
     * nothing would report a problem. A short or truncated key is the same
     * failure in milder form.
     *
     * The wrong length is therefore an error, not a warning, and the message
     * names the variable so an operator can act on it.
     */
    private static function decodeKey(string $keyHex): string
    {
        $expectedHexLength = self::KEY_BYTES * 2;

        if (strlen($keyHex) !== $expectedHexLength || !ctype_xdigit($keyHex)) {
            throw new \RuntimeException(sprintf(
                'TOTP_ENCRYPTION_KEY must be %d hexadecimal characters (%d bytes); got %d character(s).',
                $expectedHexLength,
                self::KEY_BYTES,
                strlen($keyHex),
            ));
        }

        return hex2bin($keyHex);
    }

    /**
     * Generate a new random TOTP secret.
     */
    public function generateSecret(): string
    {
        return $this->tfa->createSecret();
    }

    /**
     * Return a QR code data URI for display in the setup flow.
     * Label is typically the admin's email address.
     */
    public function getQrCodeUri(string $secret, string $label): string
    {
        return $this->tfa->getQRCodeImageAsDataUri($label, $secret);
    }

    /**
     * Verify a 6-digit TOTP code against a plain-text secret.
     * Allows ±1 time window to account for clock skew (~90 seconds).
     */
    public function verifyCode(string $secret, string $code): bool
    {
        return $this->tfa->verifyCode($secret, $code, 1);
    }

    /**
     * Encrypt a TOTP secret for storage.
     * Format: base64(iv):base64(ciphertext)
     */
    public function encrypt(string $secret): string
    {
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($secret, 'AES-256-CBC', $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv) . ':' . base64_encode($ciphertext);
    }

    /**
     * Decrypt a stored TOTP secret.
     */
    public function decrypt(string $encrypted): string|false
    {
        $parts = explode(':', $encrypted, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$ivB64, $cipherB64] = $parts;
        $iv = base64_decode($ivB64);
        $ciphertext = base64_decode($cipherB64);
        return openssl_decrypt($ciphertext, 'AES-256-CBC', $this->encryptionKey, OPENSSL_RAW_DATA, $iv);
    }
}
