<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

use App\Shared\Security\BackupSealedBox;

/**
 * What this installation holds locally, and which keys still open it (#693).
 *
 * ## Local only, and that is the contract
 *
 * Nothing here touches the storage provider. Not because a page may never ask
 * it — the backups page does, on a request of its own — but because **the local
 * view must render without waiting for anyone**. A throttled tenant should cost
 * a club one greyed-out column, never the table that tells them what they have.
 *
 * So the shape is deliberate: this class answers everything the filesystem
 * knows, immediately, and {@see RemoteInventory} and the live lookup enrich it
 * afterwards. A reader that finds itself wanting to `await` something here has
 * found a field that belongs in the enrichment instead.
 *
 * ## The key list is the point of the page
 *
 * ADR-0049 hands the private halves to the club, and #703 removed the
 * application's key register with the tracking tables: custody lives in the
 * club's own register, on paper, where a restore cannot rewrite it. What was
 * lost with those tables is the answer to a question a treasurer eventually
 * asks — *"which of these private keys may we finally destroy?"*
 *
 * That answer is derivable without any register, because **every archive names
 * the keys that open it, in a header readable with no key at all**. Aggregating
 * those headers gives the label, the fingerprint, and the span of archives each
 * key still opens. A key whose last archive has aged out opens nothing this
 * installation still holds — which is exactly the fact somebody needs before
 * they shred a paper envelope, and it is derived rather than asserted.
 *
 * It is a **checklist to walk the register against**, never a replacement for
 * it: this sees only what is on this host now.
 *
 * ## A corrupt archive is shown, not hidden
 *
 * An archive whose header will not parse still appears, with its name, size and
 * age, marked unreadable. Omitting it would be the worst behaviour available:
 * the one file most worth investigating would silently leave the list, and the
 * club would count backups it does not have.
 *
 * Part of #693, epic #686.
 */
final class BackupsInventory
{
    public function __construct(private readonly string $backupDirectory)
    {
    }

    /**
     * Every archive on disk, newest first, with what its header says.
     *
     * @return list<array{
     *     name: string, bytes: int, at: int, readable: bool,
     *     created_at: ?string, config_included: ?bool, plaintext_bytes: ?int,
     *     recipients: list<array{label: string, fingerprint: string}>
     * }>
     */
    public function archives(): array
    {
        $archives = [];

        foreach ((new ArchiveDirectory($this->backupDirectory))->newestFirst() as $archive) {
            $archives[] = $this->describe($archive);
        }

        return $archives;
    }

    /**
     * The keys that open what is here, most recently used first.
     *
     * Aggregated by **fingerprint**, not by label. A label is what an operator
     * typed into `config.php` and can be reused, corrected or reassigned; the
     * fingerprint is the key. Two envelopes both labelled `vorstand` from
     * different years are two keys, and a list that merged them would tell a
     * club it was safe to destroy one of them.
     *
     * The label is carried alongside because it is what is written on the
     * envelope, and a fingerprint alone is not something anyone can act on.
     *
     * @return list<array{
     *     label: string, fingerprint: string, archives: int,
     *     first_seen: ?string, last_seen: ?string
     * }>
     */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->archives() as $archive) {
            foreach ($archive['recipients'] as $recipient) {
                $fingerprint = $recipient['fingerprint'];

                if (!isset($keys[$fingerprint])) {
                    $keys[$fingerprint] = [
                        'label' => $recipient['label'],
                        'fingerprint' => $fingerprint,
                        'archives' => 0,
                        'first_seen' => null,
                        'last_seen' => null,
                    ];
                }

                $keys[$fingerprint]['archives']++;

                // From the **filename** instant, not the header's `created_at`.
                // The two agree on an untouched archive and disagree exactly
                // when it matters — a copy, or a restore out of a provider's
                // recycle bin — and this list is ordered by the filename
                // instant, for the reason {@see ArchiveDirectory} gives. A span
                // measured on a different clock from the order it is shown in
                // is a span that can contradict the table around it.
                $seen = gmdate('c', $archive['at']);

                // The archives arrive newest first, so the first sighting is
                // the most recent and the last one to overwrite is the oldest.
                $keys[$fingerprint]['last_seen'] ??= $seen;
                $keys[$fingerprint]['first_seen'] = $seen;
            }
        }

        return array_values($keys);
    }

    /**
     * One archive, with its header if the header can be read.
     *
     * @param array{name: string, bytes: int, at: int} $archive
     *
     * @return array{
     *     name: string, bytes: int, at: int, readable: bool,
     *     created_at: ?string, config_included: ?bool, plaintext_bytes: ?int,
     *     recipients: list<array{label: string, fingerprint: string}>
     * }
     */
    private function describe(array $archive): array
    {
        $base = [
            'name' => $archive['name'],
            'bytes' => $archive['bytes'],
            'at' => $archive['at'],
            'readable' => false,
            'created_at' => null,
            'config_included' => null,
            'plaintext_bytes' => null,
            'recipients' => [],
        ];

        try {
            $header = BackupSealedBox::readHeaderFromFile(
                rtrim($this->backupDirectory, '/') . '/' . $archive['name']
            );
        } catch (\Throwable) {
            // Deliberately swallowed, and deliberately not logged per archive:
            // this runs on a page load over every file in the directory, and a
            // truncated archive would otherwise write a log line every time
            // anybody opened the page. The row itself carries the fact.
            return $base;
        }

        return [
            ...$base,
            'readable' => true,
            'created_at' => isset($header['created_at']) ? (string) $header['created_at'] : null,
            'config_included' => (bool) ($header['config_included'] ?? false),
            'plaintext_bytes' => isset($header['plaintext_bytes']) ? (int) $header['plaintext_bytes'] : null,
            'recipients' => self::recipients($header),
        ];
    }

    /**
     * @param array<string, mixed> $header
     *
     * @return list<array{label: string, fingerprint: string}>
     */
    private static function recipients(array $header): array
    {
        $recipients = [];

        foreach ((array) ($header['recipients'] ?? []) as $recipient) {
            if (!is_array($recipient)) {
                continue;
            }

            $fingerprint = (string) ($recipient['fingerprint'] ?? '');
            if ($fingerprint === '') {
                continue;
            }

            $recipients[] = [
                'label' => (string) ($recipient['label'] ?? ''),
                'fingerprint' => $fingerprint,
            ];
        }

        return $recipients;
    }
}
