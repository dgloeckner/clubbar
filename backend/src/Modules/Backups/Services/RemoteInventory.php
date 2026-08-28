<?php

declare(strict_types=1);

namespace App\Modules\Backups\Services;

use App\Modules\Backups\Transport\RemoteArchive;

/**
 * What the store held the last time the nightly job looked (#693, ADR-0049).
 *
 * ## Why this file exists at all
 *
 * The backups page has to answer one question the local directory cannot:
 * *is this archive still off-site?* A local archive and an uploaded one are the
 * same bytes in the same folder, and an upload is a copy rather than a move.
 *
 * The store can be asked, and the backups page does ask it — but never on the
 * path that renders the local view. That separation is the design:
 *
 * - {@see BackupStatusCheck} and {@see BackupSchedule} may **never** reach the
 *   provider, and their constructors assert it. They feed the security
 *   self-check and a banner on every page of the panel, so a tenant outage
 *   there would sit between an admin and every screen they own.
 * - The backups page is different: the store *is* its subject. It may ask —
 *   on a **separate request**, bounded, and enriching a local view that has
 *   already rendered.
 *
 * This file is what makes that separation affordable. The nightly run
 * **already lists the store** — `BackupService::pruneRemote()` needs the
 * listing to age archives out — and until now it threw that listing away.
 * Writing it down gives the page an instant answer before any live call
 * returns, and a truthful one when the live call fails.
 *
 * ## A snapshot, and it says so
 *
 * `takenAt` is not decoration. What this file holds is what the store looked
 * like *last night*, and a page that presented it as live would be lying in the
 * one direction that matters: an archive deleted from the store this morning
 * still appears here. Every reader gets the timestamp so it can say "as of",
 * and a club that needs certainty has the store's own web interface.
 *
 * ## Stale beats absent
 *
 * A run whose listing failed leaves the previous file untouched. Last night's
 * answer is imperfect; no answer at all sends somebody to the provider's portal
 * to establish something they were told a day ago. The reader is told how old
 * it is and can judge — and can ask for a live check, which is the other half
 * of the page.
 *
 * Part of #693, epic #686.
 */
final class RemoteInventory
{
    /**
     * Beside the archives and the journal, and named so it sorts with them.
     *
     * Not inside the archive directory's `clubbar-*.cbb` pattern, so
     * {@see ArchiveDirectory} — which decides what retention may **delete** —
     * never sees it.
     */
    public const FILENAME = 'remote.json';

    public function __construct(private readonly string $directory)
    {
    }

    public function path(): string
    {
        return rtrim($this->directory, '/') . '/' . self::FILENAME;
    }

    /**
     * Write down what the store just answered.
     *
     * Called from the nightly run with a listing it already had to fetch. The
     * write is atomic — a page reading this file while a run rewrites it must
     * get one whole version or the other, never a truncated one.
     *
     * @param list<RemoteArchive> $archives
     * @param string              $remote   `BackupTransport::describe()` — which
     *                                      store this is, for a page that has
     *                                      no other way to name it
     */
    public function record(array $archives, string $remote, ?int $takenAt = null): bool
    {
        $payload = [
            'taken_at' => $takenAt ?? time(),
            'remote' => $remote,
            'archives' => array_map(
                static fn (RemoteArchive $archive): array => [
                    'name' => $archive->name,
                    'size' => $archive->size,
                    'created_at' => $archive->createdAt(),
                ],
                $archives
            ),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $temporary = $this->path() . '.tmp';
        if (@file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
            return false;
        }

        // rename() is atomic within a filesystem, so a reader sees the old file
        // or the new one. Writing in place would let a page load land on a
        // half-written array and report a club has no off-site copies.
        if (!@rename($temporary, $this->path())) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    /**
     * The snapshot, or null when there has never been one.
     *
     * Null is a real answer and readers must render it as one — *"the nightly
     * job has not listed the store yet"* — rather than as an empty store. A
     * club whose backups are configured but whose cron was never added would
     * otherwise be told its archives are missing off-site, which is true in a
     * way that hides the actual problem.
     *
     * @return array{taken_at: int, remote: string, names: list<string>}|null
     */
    public function read(): ?array
    {
        $raw = @file_get_contents($this->path());
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // A half-written or hand-edited file is the same as no file: the
            // reader's response is identical, and inventing a branch for it
            // would only be a branch that can be got wrong.
            return null;
        }

        if (!is_array($decoded) || !is_array($decoded['archives'] ?? null)) {
            return null;
        }

        $names = [];
        foreach ($decoded['archives'] as $archive) {
            $name = is_array($archive) ? ($archive['name'] ?? null) : null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return [
            'taken_at' => (int) ($decoded['taken_at'] ?? 0),
            'remote' => (string) ($decoded['remote'] ?? ''),
            'names' => $names,
        ];
    }

    /**
     * Was this archive off-site when the store was last listed?
     *
     * Deliberately three-valued. `null` means *nobody has looked* — no snapshot
     * exists — and it must not collapse into `false`, which claims the store
     * was asked and said no. A club acts on those differently: one is a
     * scheduler problem, the other is a missing backup.
     */
    public function holds(string $archiveName): ?bool
    {
        $snapshot = $this->read();

        return $snapshot === null ? null : in_array($archiveName, $snapshot['names'], true);
    }
}
