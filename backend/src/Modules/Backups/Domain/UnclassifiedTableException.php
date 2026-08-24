<?php

declare(strict_types=1);

namespace App\Modules\Backups\Domain;

use RuntimeException;

/**
 * A table exists that nobody decided about (pattern-018).
 *
 * Deliberately fatal rather than a default: see {@see TableClassification}.
 */
final class UnclassifiedTableException extends RuntimeException
{
    public static function for(string $table): self
    {
        return new self(sprintf(
            'Table "%s" has no backup classification. Add it to TableClassification::MAP as '
            . 'FULL, SCHEMA_ONLY or SKIP — a backup must not decide this by default.',
            $table
        ));
    }
}
