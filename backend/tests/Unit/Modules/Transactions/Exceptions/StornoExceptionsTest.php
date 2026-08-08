<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Transactions\Exceptions;

use App\Modules\Transactions\Exceptions\CannotStornoAStornoException;
use App\Modules\Transactions\Exceptions\TransactionAlreadyStornoedException;
use App\Shared\Exceptions\AppException;
use PHPUnit\Framework\TestCase;

/**
 * The two storno refusals carry their own status and error code (#169).
 *
 * These are asserted rather than assumed because the codes are a contract: the
 * admin UI keys its messages off them to tell "somebody already reversed this"
 * apart from "this is itself a reversal", and both are states a second admin
 * can legitimately race a user into.
 */
class StornoExceptionsTest extends TestCase
{
    public function test_already_stornoed_is_a_409_conflict(): void
    {
        $e = new TransactionAlreadyStornoedException('This transaction has already been stornoed');

        $this->assertInstanceOf(AppException::class, $e);
        $this->assertSame(409, $e->getHttpStatusCode());
        $this->assertSame('already_stornoed', $e->getErrorCode());
        $this->assertSame('This transaction has already been stornoed', $e->getMessage());
    }

    public function test_cannot_storno_a_storno_is_a_422(): void
    {
        // Not a 409: nothing is in conflict. The request is simply not a thing
        // that can be asked for — booking a mistake back is a new purchase.
        $e = new CannotStornoAStornoException('A storno cannot itself be stornoed.');

        $this->assertInstanceOf(AppException::class, $e);
        $this->assertSame(422, $e->getHttpStatusCode());
        $this->assertSame('cannot_storno_a_storno', $e->getErrorCode());
        $this->assertSame('A storno cannot itself be stornoed.', $e->getMessage());
    }

    public function test_the_two_refusals_are_distinguishable(): void
    {
        $this->assertNotSame(
            (new TransactionAlreadyStornoedException('a'))->getErrorCode(),
            (new CannotStornoAStornoException('b'))->getErrorCode(),
        );
    }
}
