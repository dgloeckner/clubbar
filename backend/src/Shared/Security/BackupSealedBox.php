<?php

declare(strict_types=1);

namespace App\Shared\Security;

use InvalidArgumentException;

/**
 * The archive container: sealed to a list of recipients, openable by any one
 * of them, and unreadable by the machine that wrote it.
 *
 * ## Why a list and not a key
 *
 * Not cryptographic hedging. The realistic failure in a Verein is that the one
 * person holding the key moves away, and the club discovers it on the day it
 * needs a restore. Two standing recipients — the **Admin** and a second board
 * member — cost 48 bytes each and make that survivable. That the same mechanism
 * turns key rotation into a safe overlap instead of a cutover (ADR-0049
 * decision 3) is a consequence, not the motivation.
 *
 * ## Why not the IBAN keypair, and why not the Kassenwart either
 *
 * The Kassenwart holds a copy of the IBAN private key, because SEPA collection
 * is impossible without it (ADR-0044, CONTEXT.md). Sealing backups to it would
 * hand them the audit log, every admin's TOTP ciphertext and the database
 * password. Backups belong to the Admin — whoever holds the server.
 *
 * That settles who the archive is sealed to, and not only which key it uses.
 * The temptation is worth naming, because it looks like prudence: the
 * Kassenwart is the volunteer most reliably still in post next year, so giving
 * them the second copy is the obvious move. It would re-cross the boundary the
 * paragraph above draws, through a second keypair — a backup carries the same
 * three things whichever key sealed it. The second copy goes to a board member
 * because two holders are needed, not because any office confers access.
 *
 * ## Layout
 *
 *   magic      "CLUBBAR-BACKUP" + one version byte
 *   header     4-byte big-endian length, then JSON (see below)
 *   keys       per recipient, crypto_box_seal(stream key, their public key)
 *   body       crypto_secretstream_xchacha20poly1305 over the payload
 *
 * The header is deliberately outside the encryption: it carries no secret, and
 * a holder must be able to learn *which key opens this* before being asked for
 * one. Everything that could be tampered with to change the plaintext is inside
 * the stream, whose tags also make truncation and reordering detectable —
 * which is why the body is a secretstream rather than a sequence of boxes we
 * would have had to frame ourselves.
 *
 * ## The archive is the record (version 2)
 *
 * There is no backup state in the application's database — no run table, no key
 * table, no configuration row (ADR-0049 decision 8). So the header has to carry
 * everything a future reader needs in order to know what they are holding, and
 * it does:
 *
 * | Field | Why it is a must-have |
 * |---|---|
 * | `version`, `algorithm` | What this build can read, stated rather than probed |
 * | `created_at` | When the snapshot was taken, UTC |
 * | `recipients` | *Which envelope in the safe opens this* — answerable years later, from the file alone |
 * | `instance` | Attribution: id, club name, database. Clubs merge, hosts are shared, volunteers keep copies |
 * | `schema_version` | Which application version can load this, and whether an upgrade must follow |
 * | `dump_format` | The SQL dialect contract between emitter and restore tooling |
 * | `manifest` | What is inside, without decrypting — and the decryptor's per-table split can name its parts |
 * | `compression` | How to turn the decrypted body back into SQL — stated, never sniffed |
 * | `plaintext_bytes`, `plaintext_sha256` | A restore can prove it decrypted what was sealed |
 *
 * None of it is secret: table names and row counts are not member data, and
 * everything that could change the plaintext is still inside the authenticated
 * stream. The checksum is of the *plaintext*, which is the half a reader cannot
 * otherwise verify — the ciphertext's own integrity is the stream's job.
 *
 * ADR-0049 decisions 2 and 8. Part of #689 and #703, epic #686.
 */
final class BackupSealedBox
{
    /**
     * The container marker, without its version byte.
     *
     * Split from the version deliberately: an archive written by a later build
     * should be refused with *"this build reads version 2"* rather than with
     * "bad magic", which reads as a corrupt file and sends the holder looking
     * for the wrong problem.
     */
    public const MAGIC = 'CLUBBAR-BACKUP';

    /**
     * Bumped by #703 (the header describes the archive, decision 8) and again
     * by #691 (the body is compressed before it is sealed).
     */
    public const VERSION = 3;
    public const ALGORITHM = 'crypto_box_seal+secretstream_xchacha20poly1305';

    /**
     * Bytes per stream chunk, measured on the **compressed** body — the stream
     * cipher sees what compression produced, not what the dump wrote.
     */
    public const CHUNK_BYTES = 65536;

    /**
     * How the body is compressed before sealing (#691).
     *
     * `gzip` because the payload is SQL — highly repetitive — and this is the
     * slice where size costs something: a club's upload bandwidth and its
     * storage quota. Level 6 rather than 9: on a few megabytes of SQL the
     * marginal gain is a couple of per cent for several times the CPU, and this
     * runs at 03:00 on a tariff that counts it.
     *
     * **Compress-then-encrypt is safe here**, which is worth stating because
     * the reflex says otherwise. CRIME and BREACH need an attacker who can
     * inject chosen plaintext into the compressed stream and observe the length
     * repeatedly; an archive is written once, from the club's own database,
     * with no attacker input and no oracle to query. What compression leaks is
     * a rough size, which the file already has.
     */
    public const COMPRESSION = 'gzip';
    private const COMPRESSION_LEVEL = 6;

    /** What the flag says when this host could not compress at all. */
    public const COMPRESSION_NONE = 'none';

    private const HEADER_LENGTH_BYTES = 4;

    /**
     * @param list<array{label: string, public_key: string}> $recipients
     * @param array{instance_id?: ?string, instance_name?: ?string, database?: ?string,
     *              schema_version?: ?string, dump_format?: ?int,
     *              manifest?: array<string, int>, config_included?: bool} $describes
     *        what the payload is, as {@see \App\Modules\Backups\Services\DatabaseDump::sourceDescription()}
     *        reports it plus the manifest. Absent fields become explicit nulls
     *        rather than missing keys, so a header always answers the question
     *        even when the answer is "this archive does not say".
     */
    public static function seal(
        string $payload,
        array $recipients,
        array $describes = [],
        string $appEnv = 'production',
        ?string $createdAt = null,
    ): string
    {
        if ($recipients === []) {
            throw new InvalidArgumentException(
                'A backup needs at least one recipient public key. Sealing to nobody would '
                . 'produce an archive no one can ever open — refuse rather than degrade '
                . '(ADR-0031 rule 3).'
            );
        }

        $streamKey = sodium_crypto_secretstream_xchacha20poly1305_keygen();

        $sealedKeys = [];
        $descriptors = [];
        foreach ($recipients as $recipient) {
            $publicKey = self::validatePublicKey($recipient['public_key'], $recipient['label'], $appEnv);

            $sealedKeys[] = sodium_crypto_box_seal($streamKey, $publicKey);
            $descriptors[] = [
                'fingerprint' => hash('sha256', $publicKey),
                'label' => $recipient['label'],
            ];
        }

        [$state, $streamHeader] = self::initStream($streamKey);

        [$body, $compression] = self::compress($payload);

        $header = [
            'version' => self::VERSION,
            'algorithm' => self::ALGORITHM,
            'created_at' => $createdAt ?? gmdate('c'),
            'chunk_bytes' => self::CHUNK_BYTES,
            'recipients' => $descriptors,
            'instance' => [
                'id' => $describes['instance_id'] ?? null,
                'name' => $describes['instance_name'] ?? null,
                'database' => $describes['database'] ?? null,
            ],
            'schema_version' => $describes['schema_version'] ?? null,
            'dump_format' => $describes['dump_format'] ?? null,
            // An object even when empty, so a reader never has to tell an empty
            // list from an empty map.
            'manifest' => (object) ($describes['manifest'] ?? []),
            // Whether the payload carries the installation's `config.php` as
            // well as its rows (#692). Readable without a key, because it
            // changes what a restore still needs: an archive without it
            // restores a database nobody can log in to, since the TOTP
            // encryption key is not in the database.
            'config_included' => (bool) ($describes['config_included'] ?? false),
            'compression' => $compression,
            // Of the **plaintext**, never of the compressed intermediate. What
            // a restore holds is the `.sql` the decryptor hands back, so that
            // is what this promise has to be about — and a checksum over the
            // intermediate would start disagreeing with the decryptor the day
            // the compression level changed, while still looking correct.
            'plaintext_bytes' => strlen($payload),
            'plaintext_sha256' => hash('sha256', $payload),
        ];

        $out = self::MAGIC
            . chr(self::VERSION)
            . self::lengthPrefixed(json_encode($header, JSON_THROW_ON_ERROR))
            . $streamHeader;

        foreach ($sealedKeys as $sealed) {
            $out .= self::lengthPrefixed($sealed);
        }

        $out .= self::encryptBody($state, $body);

        sodium_memzero($streamKey);

        return $out;
    }

    /**
     * @throws InvalidArgumentException when the archive is malformed, truncated,
     *         tampered with, or was not sealed to this key
     */
    public static function open(string $archive, string $privateKey): string
    {
        [$header, $offset] = self::parseHeader($archive);

        $streamHeader = self::take($archive, $offset, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

        $keypair = self::keypairFrom($privateKey);
        $streamKey = null;

        foreach ($header['recipients'] as $_) {
            $length = self::readLength($archive, $offset);
            $sealed = self::take($archive, $offset, $length);

            if ($streamKey === null) {
                // Wrong recipient returns false rather than throwing; that is the
                // ordinary case here, since we try each in turn.
                $opened = @sodium_crypto_box_seal_open($sealed, $keypair);
                if ($opened !== false) {
                    $streamKey = $opened;
                }
            }
        }

        if ($streamKey === null) {
            throw new InvalidArgumentException(sprintf(
                'This archive was not sealed to this key. It names %s — fetch the matching '
                . 'private half.',
                self::describeRecipients($header['recipients'])
            ));
        }

        try {
            return self::decompress(
                self::decryptBody($streamKey, $streamHeader, substr($archive, $offset)),
                (string) ($header['compression'] ?? self::COMPRESSION_NONE),
            );
        } finally {
            sodium_memzero($streamKey);
        }
    }

    /**
     * The header, readable with no key at all.
     *
     * This is what lets the offline decryptor say *"this archive needs the key
     * labelled `vorstand`"* before asking for anything, instead of failing with
     * a decryption error and leaving the holder to guess which of the keys in
     * the safe was meant.
     *
     * Under version 2 it says considerably more than that — see the class
     * docblock — because the archive is the only record of the run that made it.
     *
     * @return array{version: int, algorithm: string, created_at: string, chunk_bytes: int,
     *               recipients: list<array{fingerprint: string, label: string}>,
     *               instance: array{id: ?string, name: ?string, database: ?string},
     *               schema_version: ?string, dump_format: ?int,
     *               manifest: array<string, int>, compression: string,
     *               plaintext_bytes: int, plaintext_sha256: string}
     */
    public static function readHeader(string $archive): array
    {
        return self::parseHeader($archive)[0];
    }

    /**
     * The header and the offset of the first byte after it.
     *
     * The offset is returned rather than recomputed from the decoded header,
     * because re-encoding is not guaranteed to reproduce the original bytes —
     * a label carrying a slash or a non-ASCII character would be at the mercy
     * of json_encode's escaping defaults, and the failure would be an archive
     * that opens on one build and not another.
     *
     * @return array{0: array<string, mixed>, 1: int}
     */
    private static function parseHeader(string $archive): array
    {
        if (!str_starts_with($archive, self::MAGIC)) {
            throw new InvalidArgumentException('Not a Club Bar backup archive (bad magic).');
        }

        $offset = strlen(self::MAGIC);
        $containerVersion = ord(self::take($archive, $offset, 1));

        if ($containerVersion !== self::VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported archive version %d; this build reads version %d.',
                $containerVersion,
                self::VERSION
            ));
        }

        $length = self::readLength($archive, $offset);
        $json = self::take($archive, $offset, $length);

        try {
            $header = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Archive header is not readable JSON.', 0, $e);
        }

        // The version byte and the JSON have to agree. They can only disagree
        // if somebody edited the header, which is worth refusing loudly rather
        // than trusting whichever half came first.
        if (!is_array($header) || ($header['version'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Archive header says version %s but the container says %d.',
                var_export($header['version'] ?? null, true),
                self::VERSION
            ));
        }

        return [$header, $offset];
    }

    /**
     * The body as it goes into the stream cipher, and the codec that made it.
     *
     * Degrades rather than refuses when this host has no zlib. That is a
     * departure from ADR-0031 rule 3's "refuse and report" and it is
     * deliberate: rule 3 governs **confidentiality**, and an uncompressed
     * archive is sealed exactly as tightly as a compressed one. Refusing here
     * would trade a larger file for no file, which is the worse outcome — and
     * the header says which happened, so nothing is silent.
     *
     * @return array{0: string, 1: string} body, and the value for the header's
     *         `compression` field
     */
    private static function compress(string $payload): array
    {
        if (!function_exists('gzencode')) {
            return [$payload, self::COMPRESSION_NONE];
        }

        $compressed = @gzencode($payload, self::COMPRESSION_LEVEL);

        return $compressed === false
            ? [$payload, self::COMPRESSION_NONE]
            : [$compressed, self::COMPRESSION];
    }

    /**
     * The reverse, driven by what the header says rather than by sniffing.
     *
     * An unknown codec is refused instead of guessed at: handing back a
     * still-compressed body as if it were SQL would give the operator a file
     * that imports as garbage, which is the failure this whole container exists
     * to prevent.
     */
    private static function decompress(string $body, string $compression): string
    {
        if ($compression === self::COMPRESSION_NONE) {
            return $body;
        }

        if ($compression !== self::COMPRESSION) {
            throw new InvalidArgumentException(sprintf(
                'This archive says its body is compressed with "%s", which this build cannot '
                . 'decompress. It was written by a newer version of Club Bar.',
                $compression
            ));
        }

        if (!function_exists('gzdecode')) {
            throw new InvalidArgumentException(
                'This archive is gzip-compressed and this PHP has no zlib, so it cannot be '
                . 'opened here. tools/backup-decryptor.html opens it in a browser instead.'
            );
        }

        $plain = @gzdecode($body);

        if ($plain === false) {
            // The stream's own tags already refuse a corrupt or truncated
            // archive, so reaching this means the *codec* disagreed — worth its
            // own message rather than a generic decryption failure.
            throw new InvalidArgumentException(
                'The archive decrypted and authenticated, but its body is not valid gzip.'
            );
        }

        return $plain;
    }

    /** @return array{0: string, 1: string} state and stream header */
    private static function initStream(string $streamKey): array
    {
        [$state, $streamHeader] = array_values(
            (array) sodium_crypto_secretstream_xchacha20poly1305_init_push($streamKey)
        );

        return [$state, $streamHeader];
    }

    private static function encryptBody(string $state, string $payload): string
    {
        $out = '';
        $length = strlen($payload);
        $position = 0;

        do {
            $chunk = substr($payload, $position, self::CHUNK_BYTES);
            $position += self::CHUNK_BYTES;
            $isLast = $position >= $length;

            $out .= self::lengthPrefixed(sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                $chunk,
                '',
                $isLast
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
            ));
        } while (!$isLast);

        return $out;
    }

    private static function decryptBody(string $streamKey, string $streamHeader, string $body): string
    {
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($streamHeader, $streamKey);

        $payload = '';
        $offset = 0;
        $sawFinal = false;

        while ($offset < strlen($body)) {
            $length = self::readLength($body, $offset);
            $chunk = self::take($body, $offset, $length);

            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
            if ($result === false) {
                throw new InvalidArgumentException(
                    'Archive failed authentication — it is corrupt or has been altered.'
                );
            }

            [$plain, $tag] = $result;
            $payload .= $plain;

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                $sawFinal = true;
                break;
            }
        }

        if (!$sawFinal) {
            // The property a bare sequence of boxes could not give us: a
            // truncated archive is refused rather than silently restored short.
            throw new InvalidArgumentException(
                'Archive ends before its final chunk — it is truncated, and restoring it '
                . 'would silently lose whatever came after the cut.'
            );
        }

        return $payload;
    }

    private static function validatePublicKey(string $publicKey, string $label, string $appEnv): string
    {
        if (strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new InvalidArgumentException(sprintf(
                'Recipient "%s" has a %d-byte public key; expected %d.',
                $label,
                strlen($publicKey),
                SODIUM_CRYPTO_BOX_PUBLICKEYBYTES
            ));
        }

        // The same blocklist the IBAN sealed box enforces, not a second copy of
        // it: a keypair published in this repository must not seal a real club's
        // backups either. Defaults to production, so the check is on unless a
        // caller deliberately says otherwise.
        HexSecretKey::rejectIfPublished(
            bin2hex($publicKey),
            IbanSealedBox::PUBLISHED_PUBLIC_KEYS,
            $appEnv,
            sprintf(
                'Recipient "%s" is a development keypair published in this repository. Anyone '
                . 'holding a copy of the repo could open every backup sealed to it. Generate a '
                . 'keypair of your own with tools/keypair-generator.html.',
                $label
            ),
            InvalidArgumentException::class,
        );

        return $publicKey;
    }

    private static function keypairFrom(string $privateKey): string
    {
        if (strlen($privateKey) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            throw new InvalidArgumentException(sprintf(
                'Expected a %d-byte private key, got %d bytes.',
                SODIUM_CRYPTO_BOX_SECRETKEYBYTES,
                strlen($privateKey)
            ));
        }

        return sodium_crypto_box_keypair_from_secretkey_and_publickey(
            $privateKey,
            sodium_crypto_box_publickey_from_secretkey($privateKey)
        );
    }

    /** @param list<array{fingerprint: string, label: string}> $recipients */
    private static function describeRecipients(array $recipients): string
    {
        return implode(', ', array_map(
            static fn(array $r): string => sprintf('"%s" (%s…)', $r['label'], substr($r['fingerprint'], 0, 12)),
            $recipients
        ));
    }

    private static function lengthPrefixed(string $blob): string
    {
        return pack('N', strlen($blob)) . $blob;
    }

    private static function readLength(string $buffer, int &$offset): int
    {
        $bytes = self::take($buffer, $offset, self::HEADER_LENGTH_BYTES);

        return unpack('N', $bytes)[1];
    }

    private static function take(string $buffer, int &$offset, int $length): string
    {
        if ($length < 0 || $offset + $length > strlen($buffer)) {
            throw new InvalidArgumentException(
                'Archive ends unexpectedly — it is truncated or not a Club Bar backup.'
            );
        }

        $slice = substr($buffer, $offset, $length);
        $offset += $length;

        return $slice;
    }
}
