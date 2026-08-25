<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Sealed-box encryption for IBANs (ADR-0036).
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

    /**
     * Public so {@see BackupSealedBox} can enforce the same blocklist rather
     * than keeping a second copy that would drift (#689). These are the
     * keypairs published in this repository; a club that sealed real data to
     * one would be sealing it to everybody.
     */
    public const PUBLISHED_PUBLIC_KEYS = [
        '7479840773cdbd0f57bacf5c8488818e55845ee19207aaf685b74869c1682155',
        // The key the rotation e2e test rotates *onto* (#394). Published for
        // the same reason as the first one and blocked for the same reason: a
        // rotation that lands on it would leave every IBAN readable by anyone
        // holding a copy of this repository.
        '515f0f4eb534478980d7320182b4c9427b851f3f082cfb31e18b84b9e952d040',
    ];

    private string $fingerprintKey;
    private string $appEnv;

    public function __construct(string $fingerprintKeyHex, string $appEnv)
    {
        // Fail closed: without sodium there is no weaker fallback (ADR-0036).
        // A silent downgrade to plaintext or homemade crypto is exactly the
        // failure this check exists to make loud.
        if (!function_exists('sodium_crypto_box_seal')) {
            throw new \RuntimeException(
                'The sodium extension with crypto_box_seal support is required for IBAN encryption. '
                . 'There is no fallback; enable ext-sodium.'
            );
        }

        $this->appEnv = strtolower($appEnv);

        HexSecretKey::rejectIfPublished(
            $fingerprintKeyHex,
            self::PUBLISHED_FINGERPRINT_KEYS,
            $this->appEnv,
            'IBAN_FINGERPRINT_KEY is the development key published in this repository, so anyone '
            . 'who obtains the database and the repo can confirm IBAN guesses offline. Generate a '
            . 'key of your own with: openssl rand -hex 32'
        );

        $this->fingerprintKey = HexSecretKey::decode($fingerprintKeyHex, 'IBAN_FINGERPRINT_KEY');
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
            HexSecretKey::BYTES,
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
        HexSecretKey::rejectIfPublished(
            bin2hex($publicKeyRaw),
            self::PUBLISHED_PUBLIC_KEYS,
            $this->appEnv,
            'This public key is the development keypair published in the repository; IBANs sealed '
            . 'under it are readable by anyone. Generate a production keypair with the offline '
            . 'generator (tools/keypair-generator.html).',
            \InvalidArgumentException::class,
        );
    }

    private static function assertRawKeyLength(string $keyRaw, string $what): void
    {
        if (strlen($keyRaw) !== HexSecretKey::BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'The %s must be %d raw bytes; got %d.',
                $what,
                HexSecretKey::BYTES,
                strlen($keyRaw),
            ));
        }
    }
}
