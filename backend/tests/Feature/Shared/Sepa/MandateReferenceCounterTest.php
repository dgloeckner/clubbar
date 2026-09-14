<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\Sepa;

use App\Shared\Sepa\MandateReferenceCounterRepository;
use Tests\Feature\DatabaseTestCase;

/**
 * The SQL half of the minter, which cannot be unit-tested: `LAST_INSERT_ID()`
 * does not exist in SQLite, and it is the whole mechanism here.
 *
 * The test leaves the counter where it found it. The row is a singleton shared
 * with everything else running against this database, so it restores the value
 * rather than resetting it — a mandate created by another test between two runs
 * must not read as a bug here.
 */
class MandateReferenceCounterTest extends DatabaseTestCase
{
    private MandateReferenceCounterRepository $counter;
    private int $startedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->counter = new MandateReferenceCounterRepository($this->db);
        $this->startedAt = (int) $this->db->query('SELECT value FROM mandate_reference_counter WHERE id = 1')->fetchColumn();
    }

    protected function tearDown(): void
    {
        $restore = $this->db->prepare('UPDATE mandate_reference_counter SET value = ? WHERE id = 1');
        $restore->execute([$this->startedAt]);
        parent::tearDown();
    }

    public function test_the_first_draw_is_one_past_where_the_counter_stood(): void
    {
        self::assertSame($this->startedAt + 1, $this->counter->next());
    }

    public function test_consecutive_draws_are_consecutive_numbers(): void
    {
        $first = $this->counter->next();

        self::assertSame($first + 1, $this->counter->next());
        self::assertSame($first + 2, $this->counter->next());
    }

    /** The row carries the number that was handed out, not one either side. */
    public function test_the_row_holds_the_number_that_was_handed_out(): void
    {
        $drawn = $this->counter->next();

        $stored = (int) $this->db->query('SELECT value FROM mandate_reference_counter WHERE id = 1')->fetchColumn();
        self::assertSame($drawn, $stored);
    }

    /**
     * The whole reason the counter row is used instead of `CREATE SEQUENCE`: a
     * sequence is non-transactional, so a rolled-back mint would still burn a
     * number and — worse — the paper printed from a pending registration and
     * the stored row could name different ones.
     */
    public function test_a_draw_rolls_back_with_the_transaction_that_made_it(): void
    {
        $this->db->beginTransaction();
        $this->counter->next();
        $this->db->rollBack();

        self::assertSame($this->startedAt, (int) $this->db->query('SELECT value FROM mandate_reference_counter WHERE id = 1')->fetchColumn());
        self::assertSame($this->startedAt + 1, $this->counter->next());
    }

    /**
     * A second connection draws its own number. `LAST_INSERT_ID(expr)` is
     * connection-scoped, which is what makes the read-after-update safe without
     * a lock the caller has to remember to take.
     */
    public function test_two_connections_never_draw_the_same_number(): void
    {
        $other = new MandateReferenceCounterRepository(
            \App\Shared\Database\ConnectionFactory::create(
                getenv('DB_HOST') ?: 'database',
                getenv('DB_NAME') ?: 'clubbar',
                getenv('DB_USER') ?: 'clubbar',
                getenv('DB_PASS') ?: 'clubbar',
            )
        );

        $mine = $this->counter->next();
        $theirs = $other->next();

        self::assertNotSame($mine, $theirs);
        self::assertSame($mine + 1, $theirs);
    }
}
