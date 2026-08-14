<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Instance\Services\InstanceConfigService;
use App\Shared\Config\Env;
use App\Shared\Security\HexSecretKey;
use App\Shared\Security\SymmetricSecretBox;
use RobThree\Auth\TwoFactorAuth;

class TotpService
{
    /**
     * Key that appears verbatim in this public repository — in the compose
     * files, in db/seed.sql, and formerly in .env.example. Fine as a fixture
     * and worthless as a secret: `cp .env.example .env` is the documented
     * self-hosting flow, so an operator could reach production with a key
     * any reader of the repo already has (#107).
     */
    private const PUBLISHED_KEYS = [
        '0000000000000000000000000000000000000000000000000000000000000001',
    ];

    private TwoFactorAuth $tfa;
    private SymmetricSecretBox $secretBox;

    public function __construct(InstanceConfigService $instanceConfigService)
    {
        $appEnv = strtolower(Env::get('APP_ENV', 'production'));
        $instanceName = $instanceConfigService->getInstanceName();

        $this->tfa = new TwoFactorAuth(
            issuer: $appEnv === 'production' ? $instanceName : "{$instanceName} (dev)",
            qrcodeprovider: new ChillerlanQrProvider(),
        );

        $keyHex = Env::get('TOTP_ENCRYPTION_KEY');

        // Deliberately fatal rather than a log line. The key protects every
        // admin's TOTP secret at rest, and its whole job is to make a leaked
        // database dump useless — with a published key a dump yields working
        // second factors, and nothing about the running system looks wrong.
        HexSecretKey::rejectIfPublished(
            $keyHex,
            self::PUBLISHED_KEYS,
            $appEnv,
            'TOTP_ENCRYPTION_KEY is the development key published in this repository, so anyone '
            . 'who obtains the database can decrypt every admin TOTP secret. Generate a key of '
            . 'your own with: openssl rand -hex 32'
        );

        $this->secretBox = new SymmetricSecretBox($keyHex, 'TOTP_ENCRYPTION_KEY');
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
        return $this->verifyCodeWithTimestep($secret, $code) !== null;
    }

    /**
     * Verify a 6-digit TOTP code and return the matched time-step, or null if
     * invalid. Allows ±1 time window to account for clock skew (~90 seconds).
     *
     * The time-step lets callers detect replay: a captured code stays
     * structurally valid for its whole ±1 window, so the caller must reject
     * any time-step at or below the last one it already consumed for this
     * secret (#338).
     */
    public function verifyCodeWithTimestep(string $secret, string $code): ?int
    {
        $timeslice = null;
        return $this->tfa->verifyCode($secret, $code, 1, null, $timeslice) ? $timeslice : null;
    }

    /**
     * Encrypt a TOTP secret for storage.
     * Delegates to SymmetricSecretBox (v1: libsodium secretbox, #437).
     */
    public function encrypt(string $secret): string
    {
        return $this->secretBox->encrypt($secret);
    }

    /**
     * Decrypt a stored TOTP secret. Returns false for malformed input, a
     * wrong key, or a tampered ciphertext — secretbox authenticates, so
     * tampering is detected rather than silently producing garbage.
     */
    public function decrypt(string $encrypted): string|false
    {
        return $this->secretBox->decrypt($encrypted);
    }
}
