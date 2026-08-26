<?php

declare(strict_types=1);

namespace App\Modules\Backups\Transport;

/**
 * How far an upload got, remembered beside the archive it belongs to.
 *
 * ADR-0049 decision 8 draws the line: *"the backup directory holds the
 * archives, an append-only journal, and, while an upload is in flight, a
 * sidecar file with its resume state."* Not a database row. A run that stored
 * its progress in the database it dumps would seal half an upload into the
 * next archive, and restoring that archive would resurrect an upload of a file
 * that no longer exists.
 *
 * ## Why resume at all
 *
 * A shared tariff gives a run a few minutes and an uplink a club does not
 * control. Without this, an archive that takes longer than one run to send is
 * an archive that is never sent — every night starting from byte zero and
 * every night being cut off at the same place. ADR-0049 draws the distinction
 * that makes this safe: **a snapshot must be atomic, a transfer must be
 * resumable.** Resuming a *dump* was rejected outright; resuming a *transfer*
 * of an already-sealed, already-checksummed file cannot tear anything.
 *
 * ## The presence of the file is the signal
 *
 * A sidecar means "this archive has an upload that has not finished". So it is
 * deleted on success, and an unreadable or expired one reads as absent rather
 * than as something to half-believe: the next run then starts a clean session,
 * which costs bandwidth, where trusting a dead session would cost the upload
 * entirely.
 *
 * Part of #691, epic #686.
 */
final class UploadState
{
    /** Appended to the archive's own name, so the two sort together and tidy together. */
    public const SUFFIX = '.upload.json';

    public function __construct(private readonly string $archivePath)
    {
    }

    public function path(): string
    {
        return $this->archivePath . self::SUFFIX;
    }

    /**
     * The session to resume, or null if there is nothing usable to resume.
     *
     * Null covers three different situations on purpose — no sidecar, a
     * corrupt one, and one whose session Graph has already expired — because
     * the caller's response to all three is the same and correct: start a new
     * session. Distinguishing them would only create a branch that can be got
     * wrong.
     */
    public function read(): ?PendingUpload
    {
        $raw = @file_get_contents($this->path());
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['uploadUrl'], $decoded['size'])) {
            return null;
        }

        $expiresAt = strtotime((string) ($decoded['expiresAt'] ?? ''));
        if ($expiresAt === false || $expiresAt <= time()) {
            // An expired session is worse than none: every chunk against it
            // gets a 404, so a run would spend its whole budget learning what
            // the timestamp already said.
            return null;
        }

        return new PendingUpload(
            (string) $decoded['uploadUrl'],
            $expiresAt,
            (int) ($decoded['uploaded'] ?? 0),
            (int) $decoded['size'],
        );
    }

    public function write(string $uploadUrl, string $expiresAt, int $uploaded, int $size): void
    {
        @file_put_contents(
            $this->path(),
            (string) json_encode([
                'archive' => basename($this->archivePath),
                'uploadUrl' => $uploadUrl,
                'expiresAt' => $expiresAt,
                'uploaded' => $uploaded,
                'size' => $size,
                'at' => gmdate('c'),
            ], JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        @chmod($this->path(), 0600);
    }

    /** The upload finished — or the session died and must not be resumed. */
    public function clear(): void
    {
        @unlink($this->path());
    }

    /**
     * Every archive in a directory that still has an unfinished upload.
     *
     * The sweep a run does after pushing its own archive: last night's cut-off
     * transfer is the one closest to being off-site, and nothing else in the
     * system would ever come back to it.
     *
     * By file presence rather than by content, because an *expired* sidecar
     * still marks an archive that has not reached the remote — it just has to
     * start a fresh session to get there.
     *
     * @return list<string> absolute archive paths, oldest name first
     */
    public static function pendingIn(string $directory): array
    {
        $pending = [];

        foreach (glob(rtrim($directory, '/') . '/*' . self::SUFFIX) ?: [] as $sidecar) {
            $archive = substr($sidecar, 0, -strlen(self::SUFFIX));
            if (is_file($archive)) {
                $pending[] = $archive;
            }
        }

        sort($pending);

        return $pending;
    }
}
