<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

/**
 * The archives on disk — the record itself, read straight from the directory.
 *
 * ADR-0049 decision 8: *the archive is the record, the journal is a
 * convenience.* Two callers need that record and must not disagree about what
 * counts as an archive:
 *
 * - {@see BackupService}, deciding what retention may delete
 * - {@see BackupStatusCheck}, reporting when a backup last succeeded
 *
 * A second copy of the matching rule is how a prune and a report end up
 * counting different files — so it lives here once.
 *
 * ## Only `clubbar-*.cbb`
 *
 * Nothing else an operator has put in the directory — a `.part` from a killed
 * run, the journal itself, a note to a successor — is counted, and in the
 * pruning caller that is load-bearing: this list decides what gets **deleted**.
 *
 * ## The filename beats the mtime
 *
 * The instant is taken from the name (`clubbar-20260825-030000-1a2b3c4d.cbb`)
 * and falls back to `filemtime()` only when the name does not carry one. A copy,
 * a restore from a provider's recycle bin, or a stray `touch` all rewrite the
 * mtime while the name keeps saying when the snapshot was actually taken — and
 * both callers care about the snapshot, not about the file's last handling.
 *
 * ## No transport, by design
 *
 * This reads the local directory and nothing else. Whether an archive also
 * reached the remote store is a different question, answered by the journal
 * (and, for the caller that may pay for it, by the transport's own listing) —
 * see {@see BackupStatusCheck} for why no page-facing path may reach the
 * provider.
 *
 * Part of #693, epic #686.
 */
final class ArchiveDirectory
{
    /** The archive name this job writes, and the only pattern anything will prune. */
    public const FILENAME_PREFIX = 'clubbar-';

    /** Sealed-container extension, as `tools/backup-decryptor.html` accepts it. */
    public const EXTENSION = '.cbb';

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Every archive, oldest first — the order retention wants.
     *
     * @return list<array{name: string, bytes: int, at: int}>
     */
    public function oldestFirst(): array
    {
        $pattern = rtrim($this->directory, '/') . '/' . self::FILENAME_PREFIX . '*' . self::EXTENSION;

        $archives = [];
        foreach (glob($pattern) ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $name = basename($path);
            $archives[] = [
                'name' => $name,
                'bytes' => (int) filesize($path),
                'at' => self::instantIn($name) ?? (int) filemtime($path),
            ];
        }

        usort($archives, static fn (array $a, array $b): int => [$a['at'], $a['name']] <=> [$b['at'], $b['name']]);

        return $archives;
    }

    /**
     * Every archive, newest first — the order a report wants.
     *
     * @return list<array{name: string, bytes: int, at: int}>
     */
    public function newestFirst(): array
    {
        return array_reverse($this->oldestFirst());
    }

    /** Total bytes the archives occupy, for comparing against the local cap. */
    public function totalBytes(): int
    {
        return array_sum(array_column($this->oldestFirst(), 'bytes'));
    }

    /** The UTC instant in `clubbar-20260825-030000-1a2b3c4d.cbb`, or null. */
    public static function instantIn(string $filename): ?int
    {
        if (preg_match('/^' . self::FILENAME_PREFIX . '(\d{8}-\d{6})-/', $filename, $m) !== 1) {
            return null;
        }

        $at = \DateTimeImmutable::createFromFormat('Ymd-His', $m[1], new \DateTimeZone('UTC'));

        return $at === false ? null : $at->getTimestamp();
    }
}
