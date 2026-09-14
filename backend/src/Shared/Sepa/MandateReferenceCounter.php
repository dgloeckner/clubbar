<?php

declare(strict_types=1);

namespace App\Shared\Sepa;

/**
 * The per-install source of mandate reference numbers.
 *
 * An interface for one reason: `LAST_INSERT_ID()` does not exist in SQLite, so
 * the unit tests that cover *formatting* cannot also run the SQL. The formatting
 * rules live in `MandateReferenceMinter` and are unit-tested against a stub of
 * this interface; the SQL lives in `MandateReferenceCounterRepository` and is
 * covered by a Feature test against MariaDB (Pattern 005).
 */
interface MandateReferenceCounter
{
    /**
     * Draw the next number, atomically.
     *
     * Runs on the caller's connection and therefore inside the caller's
     * transaction: a mint that rolls back gives the number back, so the paper
     * printed from a pending registration and the stored row can never differ.
     * Numbers are never reused and gaps are harmless.
     */
    public function next(): int;
}
