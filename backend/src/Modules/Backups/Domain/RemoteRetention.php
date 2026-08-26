<?php

declare(strict_types=1);

namespace App\Modules\Backups\Domain;

use App\Modules\Backups\Transport\RemoteArchive;

/**
 * Which archives the remote store should stop holding, and why that is a
 * decision worth its own class.
 *
 * ## What it is for
 *
 * [ADR-0029](../../../../../adr/0029-two-tier-retention-and-erasure.md)'s
 * erasure is immediate in the live database and *not* in the archives: a member
 * anonymised today still exists, in full, inside every archive sealed before
 * today. Ageing those out is what turns the erasure from a promise with no end
 * date into one with a bounded schedule — about three months at the default.
 *
 * ## Why deciding is separated from deleting
 *
 * Because the credential doing the deleting can delete anything in the library.
 * Microsoft 365 has no add-only app role — `Sites.Selected` restricts *which*
 * site and its `write` role includes delete — which ADR-0049 and
 * `docs/m365-backup-target.md` state rather than hide. The narrowest useful
 * discipline available is therefore that the *selection* is a pure function
 * over a listing, testable without a network, and that {@see RemoteArchive}
 * carries its own drive so a delete can only name something a listing produced.
 *
 * Two rules follow, and both are here rather than in the transport:
 *
 * 1. **Only archives whose name carries a date.** Something with no age cannot
 *    be too old. Falling back to the store's `createdDateTime` would age an
 *    archive by when it was *uploaded*, which is a different day whenever a
 *    failed night was retried.
 * 2. **Never the newest.** A club whose backups stopped six months ago has a
 *    real problem, and deleting the last archive it managed to push is the one
 *    action that makes that problem unrecoverable instead of merely urgent.
 *
 * Part of #691, epic #686.
 */
final class RemoteRetention
{
    /**
     * @param list<RemoteArchive> $archives as listed from the store
     * @return list<RemoteArchive> oldest first, newest never included
     */
    public static function expiredAmong(array $archives, BackupRetention $retention, ?int $now = null): array
    {
        $now ??= time();
        $cutoff = $now - ($retention->remoteDays * 86400);

        $dated = [];
        foreach ($archives as $archive) {
            $at = $archive->createdAt();
            if ($at !== null) {
                $dated[] = ['at' => $at, 'archive' => $archive];
            }
        }

        usort($dated, static fn (array $a, array $b): int => [$a['at'], $a['archive']->name] <=> [$b['at'], $b['archive']->name]);

        // Drop the newest dated archive from consideration before the cutoff is
        // applied at all, so "everything is expired" cannot empty the store.
        array_pop($dated);

        $expired = [];
        foreach ($dated as $entry) {
            if ($entry['at'] < $cutoff) {
                $expired[] = $entry['archive'];
            }
        }

        return $expired;
    }
}
