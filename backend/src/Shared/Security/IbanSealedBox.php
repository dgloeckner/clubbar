<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Sealed-box encryption for IBANs (ADR-0035).
 *
 * The construction is asymmetric on purpose: this class can seal with the
 * registered public key on every write, but opening requires the private key,
 * which the server does not have — it lives in the club's offline archive and
 * is supplied per privileged request (SEPA export, rotation). A database dump,
 * a config file, or the whole webspace therefore never yields a readable IBAN.
 *
 * Equality between a submitted IBAN and a stored one (bank-change detection in
 * MembersRepository::applyMandateChange) cannot go through decryption, so it
 * goes through a keyed BLAKE2b fingerprint instead. The fingerprint key sits in
 * config.php — never the database — because the IBAN space is enumerable
 * per bank: an unkeyed hash in a stolen dump would fall to offline brute
 * force, a keyed one requires the filesystem too.
 */
class IbanSealedBox
{
    /** Curve25519 keys and the BLAKE2b key are 32 bytes = 64 hex characters. */
    private const KEY_BYTES = 32;

    private const CIPHERTEXT_PREFIX = 'v1:';

    /**
     * Key material that appears verbatim in this public repository (compose
     * files, e2e fixtures). Fine as fixtures, worthless as secrets — a
     * deployment refusing to run on them is the same guarantee TotpService
     * gives for its key (#107).
     */
    private const PUBLISHED_FINGERPRINT_KEYS = [
        '0000000000000000000000000000000000000000000000000000000000000002',
    ];

    private const PUBLISHED_PUBLIC_KEYS = [
        '7479840773cdbd0f57bacf5c8488818e55845ee19207aaf685b74869c1682155',
    ];

    private const DEVELOPMENT_ENVIRONMENTS = ['local', 'dev', 'development', 'test', 'testing'];

    private string $fingerprintKey;
    private string $appEnv;

    public function __construct(string $fingerprintKeyHex, string $appEnv)
    {
        // Fail closed: without sodium there is no weaker fallback (ADR-0035).
        // A silent downgrade to plaintext or homemade crypto is exactly the
        // failure this check exists to make loud.
        if (!function_exists('sodium_crypto_box_seal')) {
            throw new \RuntimeException(
                'The sodium extension with crypto_box_seal support is required for IBAN encryption. '
                . 'There is no fallback; enable ext-sodium.'
            );
        }

        $this->appEnv = strtolower($appEnv);

        if (!in_array($this->appEnv, self::DEVELOPMENT_ENVIRONMENTS, true)
            && in_array(strtolower($fingerprintKeyHex), self::PUBLISHED_FINGERPRINT_KEYS, true)) {
            throw new \RuntimeException(
                'IBAN_FINGERPRINT_KEY is the development key published in this repository, so anyone '
                . 'who obtains the database and the repo can confirm IBAN guesses offline. Generate a '
                . 'key of your own with: openssl rand -hex 32'
            );
        }

        $this->fingerprintKey = self::decodeKey($fingerprintKeyHex, 'IBAN_FINGERPRINT_KEY');
    }

    /**
     * Normalize an IBAN the way the SEPA export does: uppercase, no spaces.
     * Sealing and fingerprinting both go through this so the same account
     * always produces the same fingerprint regardless of input formatting.
     */
    public static function normalize(string $iban): string
    {
        return strtoupper(str_replace(' ', '', trim($iban)));
    }

    public static function lastFour(string $iban): string
    {
        return substr(self::normalize($iban), -4);
    }

    /**
     * Seal a plaintext IBAN under a registered public key.
     * Format: v1:base64(sealed box). The version prefix is what lets reads,
     * migrations and future format changes tell ciphertext from legacy
     * plaintext without guessing.
     */
    public function seal(string $iban, string $publicKeyRaw): string
    {
        self::assertRawKeyLength($publicKeyRaw, 'public key');

        return self::CIPHERTEXT_PREFIX
            . base64_encode(sodium_crypto_box_seal(self::normalize($iban), $publicKeyRaw));
    }

    /**
     * Open a stored ciphertext with a temporarily supplied private key.
     * Strict: unknown version, malformed base64, or a wrong key all throw —
     * a SEPA export must never silently emit garbage to a bank.
     */
    public function open(string $stored, string $secretKeyRaw): string
    {
        self::assertRawKeyLength($secretKeyRaw, 'private key');

        if (!self::isEncrypted($stored)) {
            throw new \RuntimeException('Stored value is not a sealed IBAN (missing version prefix).');
        }

        if (!str_starts_with($stored, self::CIPHERTEXT_PREFIX)) {
            throw new \RuntimeException('Unsupported IBAN ciphertext version.');
        }

        $raw = base64_decode(substr($stored, strlen(self::CIPHERTEXT_PREFIX)), true);
        if ($raw === false) {
            throw new \RuntimeException('Malformed IBAN ciphertext.');
        }

        $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey(
            $secretKeyRaw,
            sodium_crypto_box_publickey_from_secretkey($secretKeyRaw),
        );

        $plaintext = sodium_crypto_box_seal_open($raw, $keypair);
        sodium_memzero($keypair);

        if ($plaintext === false) {
            throw new \RuntimeException('IBAN ciphertext could not be opened with the supplied private key.');
        }

        return $plaintext;
    }

    /** Keyed BLAKE2b fingerprint of the normalized IBAN, hex-encoded. */
    public function fingerprint(string $iban): string
    {
        return bin2hex(sodium_crypto_generichash(
            self::normalize($iban),
            $this->fingerprintKey,
            self::KEY_BYTES,
        ));
    }

    public static function isEncrypted(?string $value): bool
    {
        return $value !== null && preg_match('/^v\d+:/', $value) === 1;
    }

    /** Derive the Curve25519 public key belonging to a supplied private key. */
    public function publicKeyFromSecret(string $secretKeyRaw): string
    {
        self::assertRawKeyLength($secretKeyRaw, 'private key');
        return sodium_crypto_box_publickey_from_secretkey($secretKeyRaw);
    }

    /**
     * Refuse to register the dev keypair published in this repository as a
     * production encryption key — sealing under it would make every dump
     * openable with a key any reader of the repo already has.
     */
    public function rejectPublishedPublicKey(string $publicKeyRaw): void
    {
        if (in_array($this->appEnv, self::DEVELOPMENT_ENVIRONMENTS, true)) {
            return;
        }

        if (in_array(bin2hex($publicKeyRaw), self::PUBLISHED_PUBLIC_KEYS, true)) {
            throw new \InvalidArgumentException(
                'This public key is the development keypair published in the repository; IBANs sealed '
                . 'under it are readable by anyone. Generate a production keypair with the offline '
                . 'generator (tools/keypair-generator.html).'
            );
        }
    }

    private static function assertRawKeyLength(string $keyRaw, string $what): void
    {
        if (strlen($keyRaw) !== self::KEY_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'The %s must be %d raw bytes; got %d.',
                $what,
                self::KEY_BYTES,
                strlen($keyRaw),
            ));
        }
    }

    /**
     * Checked rather than assumed for the same reason as TotpService: an
     * empty value (exactly what package/index.php produces when the config
     * key is absent) would silently fingerprint every IBAN under the same
     * degenerate key on every such install.
     */
    private static function decodeKey(string $keyHex, string $name): string
    {
        $expected = self::KEY_BYTES * 2;

        if (strlen($keyHex) !== $expected || !ctype_xdigit($keyHex)) {
            throw new \RuntimeException(sprintf(
                '%s must be %d hexadecimal characters (%d bytes); got %d character(s).',
                $name,
                $expected,
                self::KEY_BYTES,
                strlen($keyHex),
            ));
        }

        return hex2bin($keyHex);
    }
}
