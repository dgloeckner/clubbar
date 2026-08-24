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
 * Which settles the *recipients* too, and it is worth saying out loud because
 * the temptation is real: the Kassenwart is the volunteer most reliably still
 * in post next year, so handing them the second copy looks like prudence. It
 * would re-cross the boundary the paragraph above draws, through a second
 * keypair — a backup carries the same three things whichever key sealed it. The
 * second recipient is a board member because two holders are needed, not
 * because the office confers access.
 *
 * ## Layout
 *
 *   magic      "CLUBBAR-BACKUP\x01"
 *   header     4-byte big-endian length, then JSON: version, created_at,
 *              algorithm, chunk size, and one {fingerprint,label} per recipient
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
 * ADR-0049 decision 2. Part of #689, epic #686.
 */
final class BackupSealedBox
{
    public const MAGIC = "CLUBBAR-BACKUP\x01";
    public const VERSION = 1;
    public const ALGORITHM = 'crypto_box_seal+secretstream_xchacha20poly1305';

    /** Plaintext bytes per stream chunk. */
    public const CHUNK_BYTES = 65536;

    private const HEADER_LENGTH_BYTES = 4;

    /**
     * @param list<array{label: string, public_key: string}> $recipients
     */
    public static function seal(
        string $payload,
        array $recipients,
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

        $header = [
            'version' => self::VERSION,
            'algorithm' => self::ALGORITHM,
            'created_at' => $createdAt ?? gmdate('c'),
            'chunk_bytes' => self::CHUNK_BYTES,
            'recipients' => $descriptors,
        ];

        $out = self::MAGIC
            . self::lengthPrefixed(json_encode($header, JSON_THROW_ON_ERROR))
            . $streamHeader;

        foreach ($sealedKeys as $sealed) {
            $out .= self::lengthPrefixed($sealed);
        }

        $out .= self::encryptBody($state, $payload);

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
            return self::decryptBody($streamKey, $streamHeader, substr($archive, $offset));
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
     * @return array{version: int, algorithm: string, created_at: string, chunk_bytes: int,
     *               recipients: list<array{fingerprint: string, label: string}>}
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
        $length = self::readLength($archive, $offset);
        $json = self::take($archive, $offset, $length);

        try {
            $header = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Archive header is not readable JSON.', 0, $e);
        }

        if (!is_array($header) || ($header['version'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported archive version %s; this build reads version %d.',
                var_export($header['version'] ?? null, true),
                self::VERSION
            ));
        }

        return [$header, $offset];
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
