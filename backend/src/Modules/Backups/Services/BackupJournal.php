<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

/**
 * `index.jsonl` — one line per run attempt, appended beside the archives.
 *
 * ## Why a file and not a table
 *
 * A backup that stores its state inside the thing it backs up is
 * self-referential, and the loops were not theoretical (ADR-0049 decision 8):
 * every archive contained its own half-written `running` row, so restoring one
 * resurrected a run that never finishes and read as a stalled scheduler. The
 * journal lives *beside* the archives instead, where a restore cannot touch it
 * and where it travels with the directory it describes.
 *
 * ## It is a convenience, never a truth
 *
 * The **archive headers are the record.** *Which private keys still open
 * archives that still exist?* is answered by scanning the directory and reading
 * headers — no key required — and by listing the remote store; that answer
 * cannot drift from reality the way a row can. What the journal adds is
 * *history*: attempts that produced no file, failures with their reason, and
 * the last time a run started, which is what the minimum-interval guard needs
 * and which no archive records because no archive was written.
 *
 * So nothing here is load-bearing. A deleted journal costs run history and
 * nothing else; a truncated line is skipped rather than fatal; an unwritable
 * directory is logged by the caller and does not fail the run that already
 * produced an archive.
 *
 * ## Appends are atomic
 *
 * `fopen('a')` plus `LOCK_EX` and a single `fwrite` of one line. Two overlapping
 * runs are already prevented by the entrypoint's `flock`, but the URL trigger
 * and the CLI job are separate processes and the guarantee is cheap: on every
 * platform this ships to, one locked write of well under the pipe buffer lands
 * whole rather than interleaved.
 *
 * ADR-0049 decision 8. Part of #703, epic #686.
 */
final class BackupJournal
{
    public const FILENAME = 'index.jsonl';

    /**
     * How much of the tail to read when looking for the last `started` entry.
     *
     * A run appends two lines of a few hundred bytes, so 64 KiB is months of
     * history — and reading a bounded tail rather than the whole file means a
     * journal that has grown for years, or one somebody appended a stray
     * megabyte to, still costs one small read.
     */
    private const TAIL_BYTES = 65536;

    public function __construct(private readonly string $directory)
    {
    }

    public function path(): string
    {
        return rtrim($this->directory, '/') . '/' . self::FILENAME;
    }

    /**
     * A run has begun. Written before the dump, so an attempt that dies
     * mid-dump still spent its interval.
     */
    public function started(string $triggerSource): void
    {
        $this->append(['event' => 'started', 'trigger' => $triggerSource]);
    }

    /**
     * A run produced an archive.
     *
     * @param list<string> $recipientFingerprints
     */
    public function written(
        string $filename,
        int $bytes,
        string $sha256,
        array $recipientFingerprints,
        int $tables,
    ): void {
        $this->append([
            'event' => 'written',
            'filename' => $filename,
            'bytes' => $bytes,
            'sha256' => $sha256,
            'recipients' => $recipientFingerprints,
            'tables' => $tables,
        ]);
    }

    /** A run ended without an archive, and why. */
    public function failed(string $error): void
    {
        $this->append(['event' => 'failed', 'error' => mb_substr($error, 0, 1000)]);
    }

    /**
     * An archive reached the remote store.
     *
     * The line that answers the only question the journal is uniquely able to
     * answer about the remote: *when did this stop working?* The store itself
     * says what is there now; nothing but this says an archive from three
     * weeks ago went up and the ones since did not.
     */
    public function uploaded(string $filename, string $remote, string $remotePath, int $bytesSent): void
    {
        $this->append([
            'event' => 'uploaded',
            'filename' => $filename,
            'remote' => $remote,
            'remotePath' => $remotePath,
            'bytes_sent' => $bytesSent,
        ]);
    }

    /**
     * An upload got part of the way and will continue next run.
     *
     * Recorded as its own event rather than as a failure: a club on a slow
     * uplink whose archive needs three nights to leave the building is working
     * correctly, and a journal that called that a failure would teach its
     * reader to skip the lines that matter.
     */
    public function uploadProgress(string $filename, string $remote, int $bytesSent): void
    {
        $this->append([
            'event' => 'upload_progress',
            'filename' => $filename,
            'remote' => $remote,
            'bytes_sent' => $bytesSent,
        ]);
    }

    public function uploadFailed(string $filename, string $remote, string $error): void
    {
        $this->append([
            'event' => 'upload_failed',
            'filename' => $filename,
            'remote' => $remote,
            'error' => mb_substr($error, 0, 1000),
        ]);
    }

    /** An archive was removed from the remote store by remote retention. */
    public function remotePruned(string $filename, string $remote, string $reason): void
    {
        $this->append([
            'event' => 'remote_pruned',
            'filename' => $filename,
            'remote' => $remote,
            'reason' => $reason,
        ]);
    }

    /** An archive was removed by retention or by the byte cap. */
    public function pruned(string $filename, int $bytes, string $reason): void
    {
        $this->append([
            'event' => 'pruned',
            'filename' => $filename,
            'bytes' => $bytes,
            'reason' => $reason,
        ]);
    }

    /**
     * When a run last *started*, successful or not, or null if none ever has.
     *
     * The minimum-interval guard keys on this rather than on success, because
     * the resource it protects — the webspace quota and the CPU of a shared
     * tariff — is spent by an attempt, not by an outcome. Keying on success
     * would let a failing run be triggered in a loop.
     */
    public function lastStartedAt(): ?int
    {
        foreach (array_reverse($this->tailLines()) as $line) {
            $entry = json_decode($line, true);

            if (is_array($entry) && ($entry['event'] ?? null) === 'started') {
                $at = strtotime((string) ($entry['at'] ?? ''));

                // A line whose timestamp will not parse is treated as absent
                // rather than as "now" or as "the epoch": one guesses the guard
                // shut and the other guesses it open, and neither is knowable.
                return $at === false ? null : $at;
            }
        }

        return null;
    }

    /**
     * When an archive last *reached the remote store*, or null if none has in
     * the window.
     *
     * Distinct from "when did a backup last succeed", and both rows exist
     * because local and remote are both kept — 30 days here, 90 there, and an
     * upload is a copy rather than a move. A host whose uploads have failed for
     * six weeks still writes a perfectly good local archive every night, so a
     * single "backups are fine" row would be green throughout.
     *
     * Unlike the last successful backup, **only the journal knows this**: the
     * archive on disk looks identical whether or not it was ever pushed. The
     * caller must therefore treat null as *unknown*, never as "never uploaded"
     * — see {@see tailLines()} for why the window can miss an older success.
     */
    public function lastUploadedAt(): ?int
    {
        foreach (array_reverse($this->tailLines()) as $line) {
            $entry = json_decode($line, true);

            if (is_array($entry) && ($entry['event'] ?? null) === 'uploaded') {
                $at = strtotime((string) ($entry['at'] ?? ''));

                return $at === false ? null : $at;
            }
        }

        return null;
    }

    /**
     * Has this installation ever run the backup job at all?
     *
     * The question behind the row that matters most: a cron never added, or
     * dropped in a tariff migration, must read as **"no backup ever"** rather
     * than as silence. Any line answers it — a failed attempt is still a run —
     * and the caller pairs this with "is there an archive?", because either one
     * alone is enough to prove the job has executed.
     *
     * Deliberately not `lastStartedAt() !== null`: that reads a bounded window
     * and parses timestamps, so a journal older than the window, or one whose
     * recent lines have unparseable dates, would answer "never" to a club that
     * has been backing up for a year.
     */
    public function hasAnyEntry(): bool
    {
        return $this->tailLines() !== [];
    }

    /**
     * One line, JSON, atomically appended. Never throws.
     *
     * @param array<string, mixed> $entry
     */
    private function append(array $entry): void
    {
        $line = json_encode(['at' => gmdate('c')] + $entry, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }

        $handle = @fopen($this->path(), 'ab');
        if ($handle === false) {
            return;
        }

        try {
            if (@flock($handle, LOCK_EX)) {
                @fwrite($handle, $line . "\n");
                @fflush($handle);
                @flock($handle, LOCK_UN);
            }
        } finally {
            @fclose($handle);
        }
    }

    /**
     * The last {@see TAIL_BYTES} of the journal, as whole lines.
     *
     * The first line of the window is dropped when the window did not start at
     * the beginning of the file, because it is almost certainly a fragment —
     * and half a JSON object decoded as an entry would be worse than one
     * forgotten attempt.
     *
     * @return list<string>
     */
    private function tailLines(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }

        $size = (int) @filesize($path);
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $truncated = $size > self::TAIL_BYTES;

        try {
            if ($truncated) {
                fseek($handle, -self::TAIL_BYTES, SEEK_END);
            }

            $window = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        $lines = array_values(array_filter(
            explode("\n", $window),
            static fn (string $line): bool => trim($line) !== ''
        ));

        if ($truncated && $lines !== []) {
            array_shift($lines);
        }

        return $lines;
    }
}
