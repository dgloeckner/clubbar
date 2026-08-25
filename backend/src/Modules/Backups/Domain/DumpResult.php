<?php

declare(strict_types=1);

namespace App\Modules\Backups\Domain;

/**
 * What a dump wrote: every base table, and how many rows each contributed.
 *
 * The manifest travels in the archive's own cleartext header (ADR-0049
 * decision 8), which is what lets a reader see what is inside a file before
 * they can open it — and lets the offline decryptor name the parts of its
 * per-table split.
 *
 * It is **informational**, not an alarm. An earlier draft compared one night's
 * manifest against the last and reported tables appearing or disappearing; that
 * went with the classification it was guarding, because under dump-everything a
 * new table is simply in the archive. A migration ran is not news the backup
 * has to break.
 *
 * ADR-0049 decision 1. Part of #703, epic #686.
 */
final class DumpResult
{
    /**
     * @param array<string, int> $manifest table name => rows written
     */
    public function __construct(public readonly array $manifest)
    {
    }
}
