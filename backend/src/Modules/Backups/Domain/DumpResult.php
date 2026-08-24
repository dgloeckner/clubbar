<?php

declare(strict_types=1);

namespace App\Modules\Backups\Domain;

/**
 * What a dump wrote, and what it had to guess about.
 *
 * The manifest is the count a later restore is checked against. The second half
 * exists because {@see UnclassifiedTablePolicy::INCLUDE_AND_REPORT} deliberately
 * does *not* stop the run — so the run needs somewhere to learn that it guessed,
 * or the fail-open half of the policy would be silent, which is the failure the
 * policy is trying to avoid rather than a milder version of it.
 *
 * ADR-0049 decision 1. Part of #699, epic #686.
 */
final class DumpResult
{
    /**
     * @param array<string, int> $manifest table name => rows written
     * @param list<string> $unclassifiedTables included as FULL without anyone deciding
     */
    public function __construct(
        public readonly array $manifest,
        public readonly array $unclassifiedTables = [],
    ) {
    }

    public function guessed(): bool
    {
        return $this->unclassifiedTables !== [];
    }
}
