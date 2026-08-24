<?php

declare(strict_types=1);

namespace App\Modules\Backups\Domain;

/**
 * How much of a table belongs in a backup (ADR-0049 decision 1).
 */
enum TableClass
{
    /** Structure and every row. The default answer for business data. */
    case FULL;

    /**
     * Structure only. For bulk reference data that is identical in every
     * installation and reimportable from its own source.
     */
    case SCHEMA_ONLY;

    /** Not in the archive at all. For state that describes the last few hours. */
    case SKIP;
}
