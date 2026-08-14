<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Shared validation for the 32-byte (64 hex character) keys configured in
 * config.php: TOTP_ENCRYPTION_KEY (SymmetricSecretBox) and
 * IBAN_FINGERPRINT_KEY (IbanSealedBox). Both classes independently
 * reimplemented this exact decode-and-reject-published-key logic before
 * #437 collapsed it here — one audited implementation instead of two.
 */
final class HexSecretKey
{
    public const BYTES = 32;

    /**
     * Environments where a published/fixture key is the intended setup: the
     * seeded test data and e2e fixtures are encrypted with it. Anything
     * else — including an unset APP_ENV, which reads as production — is a
     * deployment.
     */
    private const DEVELOPMENT_ENVIRONMENTS = ['local', 'dev', 'development', 'test', 'testing'];

    private function __construct()
    {
    }

    public static function isDevelopmentEnv(string $appEnv): bool
    {
        return in_array(strtolower($appEnv), self::DEVELOPMENT_ENVIRONMENTS, true);
    }

    /**
     * Turn a configured hex string into raw key bytes.
     *
     * Checked rather than assumed: an empty or truncated value — exactly
     * what package/index.php produces when the config key is absent — would
     * otherwise silently become a degenerate key on every such install, and
     * nothing would report a problem. The wrong length is therefore an
     * error naming the variable, not a warning.
     */
    public static function decode(string $keyHex, string $envVarName): string
    {
        $expectedHexLength = self::BYTES * 2;

        if (strlen($keyHex) !== $expectedHexLength || !ctype_xdigit($keyHex)) {
            throw new \RuntimeException(sprintf(
                '%s must be %d hexadecimal characters (%d bytes); got %d character(s).',
                $envVarName,
                $expectedHexLength,
                self::BYTES,
                strlen($keyHex),
            ));
        }

        return hex2bin($keyHex);
    }

    /**
     * Refuse to start a deployment on a key that appears verbatim in this
     * public repository (compose files, e2e fixtures). Fine as a fixture,
     * worthless as a secret: `cp .env.example .env` is the documented
     * self-hosting flow, so an operator could reach production with a key
     * any reader of the repo already has (#107).
     */
    public static function rejectIfPublished(
        string $candidateHex,
        array $publishedHexKeys,
        string $appEnv,
        string $message,
        string $exceptionClass = \RuntimeException::class,
    ): void {
        if (self::isDevelopmentEnv($appEnv)) {
            return;
        }

        if (in_array(strtolower($candidateHex), $publishedHexKeys, true)) {
            throw new $exceptionClass($message);
        }
    }
}
