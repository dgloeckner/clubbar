<?php

declare(strict_types=1);

namespace App\Modules\Backups\Domain;

/**
 * What a dump does when it meets a table nobody classified.
 *
 * Two audiences want opposite things from the same event, and collapsing them
 * into one behaviour costs one of them dearly:
 *
 * - **CI fails closed.** A human is present, the cost of stopping is nil, and
 *   an unclassified table is exactly the drift the classification exists to
 *   catch. `THROW`.
 * - **The 03:00 run fails open, loudly.** Refusing the whole night's backup
 *   because one table name is unrecognised is the "control that looks like
 *   protection" failure ADR-0049 opens with — and an unrecognised table is far
 *   likelier to be business data than ephemera, so including it is also the
 *   better guess. `INCLUDE_AND_REPORT`, with the run raising a finding.
 *
 * The asymmetry generalises, and ADR-0049 decision 1 states it in one line:
 * **confidentiality fails closed, completeness fails open and reports.** No
 * recipient key means no archive at all; an unknown table means a slightly
 * larger archive and a finding.
 *
 * ADR-0049 decision 1. Part of #699, epic #686.
 */
enum UnclassifiedTablePolicy
{
    /** Raise {@see UnclassifiedTableException}. The default, and what CI uses. */
    case THROW;

    /** Dump it as {@see TableClass::FULL} and name it in {@see DumpResult}. */
    case INCLUDE_AND_REPORT;
}
