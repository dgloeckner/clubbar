<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Instance\Services\InstanceConfigService;
use App\Shared\Config\Env;
use RobThree\Auth\TwoFactorAuth;

class TotpService
{
    /** AES-256 takes a 32-byte key, which is 64 hex characters. */
    private const KEY_BYTES = 32;

    /**
     * Keys that appear verbatim in this public repository — in the compose
     * files, in db/seed.sql, and formerly in .env.example. They are fine as
     * fixtures and worthless as secrets: `cp .env.example .env` is the
     * documented self-hosting flow, so an operator could reach production with
     * a key any reader of the repo already has (#107).
     */
    private const PUBLISHED_KEYS = [
        '0000000000000000000000000000000000000000000000000000000000000001',
    ];

    /**
     * APP_ENV values where a published key is the intended setup: the seeded
     * test admin's secret is encrypted with it, so E2E and unit runs need it.
     * Anything else — including an unset APP_ENV, which reads as production —
     * is a deployment.
     */
    private const DEVELOPMENT_ENVIRONMENTS = ['local', 'dev', 'development', 'test', 'testing'];

    private TwoFactorAuth $tfa;
    private string $encryptionKey;

    public function __construct(InstanceConfigService $instanceConfigService)
    {
        $appEnv = strtolower(Env::get('APP_ENV', 'production'));
        $instanceName = $instanceConfigService->getInstanceName();

        $this->tfa = new TwoFactorAuth(
            issuer: $appEnv === 'production' ? $instanceName : "{$instanceName} (dev)",
            qrcodeprovider: new ChillerlanQrProvider(),
        );

        $keyHex = Env::get('TOTP_ENCRYPTION_KEY');
        self::rejectPublishedKey($keyHex, $appEnv);
        $this->encryptionKey = self::decodeKey($keyHex);
    }

    /**
     * Refuse to start a deployment on a key from the repository.
     *
     * This is deliberately fatal rather than a log line. The key protects every
     * admin's TOTP secret at rest, and its whole job is to make a leaked
     * database dump useless — with a published key a dump yields working second
     * factors, and nothing about the running system looks wrong. A warning in a
     * log nobody reads would not change that.
     */
    private static function rejectPublishedKey(string $keyHex, string $appEnv): void
    {
        if (in_array($appEnv, self::DEVELOPMENT_ENVIRONMENTS, true)) {
            return;
        }

        if (in_array(strtolower($keyHex), self::PUBLISHED_KEYS, true)) {
            throw new \RuntimeException(
                'TOTP_ENCRYPTION_KEY is the development key published in this repository, so anyone '
                . 'who obtains the database can decrypt every admin TOTP secret. Generate a key of '
                . 'your own with: openssl rand -hex 32'
            );
        }
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
