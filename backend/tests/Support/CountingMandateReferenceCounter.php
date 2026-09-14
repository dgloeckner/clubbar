<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Sepa\MandateReferenceCounter;

/**
 * A counter with no database behind it, which remembers how often it was drawn
 * from.
 *
 * The count is the point: the self-registration honeypot must answer with a
 * reference-shaped value *without* consuming a number, and "the counter did not
 * move" is the only way to assert that from outside.
 */
final class CountingMandateReferenceCounter implements MandateReferenceCounter
{
    private int $value = 0;

    public function next(): int
    {
        return ++$this->value;
    }

    /** How many numbers have been handed out — also the last one handed out. */
    public function drawn(): int
    {
        return $this->value;
    }
}
