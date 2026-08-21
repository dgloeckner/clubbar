<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Transactions\Repositories;

use App\Modules\Transactions\Repositories\JugendschutzViolationsRepository;
use Tests\Feature\DatabaseTestCase;

/**
 * Reading and acknowledging recorded violations, against a real database
 * (#622, ADR-0045 §3).
 *
 * Worth a Feature test rather than a mocked one for two reasons the SQL owns
 * rather than the PHP:
 *
 * 1. The unacknowledged set is a **LEFT JOIN ... WHERE ack IS NULL** across two
 *    tables, and "counts only the ones nobody has looked at" is a claim about
 *    that join, not about any branch a mock could stand in for.
 * 2. `acknowledge()` must refuse an audit row that is not a violation. It is
 *    keyed on `audit_log.id`, which every audited action in the system shares,
 *    so without that guard an ack could be written against a login or a
 *    settlement export — and the dashboard would then be quietened by a row
 *    that has nothing to do with it.
 */
class JugendschutzViolationsRepositoryTest extends DatabaseTestCase
{
    private JugendschutzViolationsRepository $repository;
    /** @var list<int> */
    private array $auditIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new JugendschutzViolationsRepository($this->db);
    }

    protected function tearDown(): void
    {
        foreach ($this->auditIds as $id) {
            $this->db->prepare('DELETE FROM jugendschutz_violation_acks WHERE audit_log_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM audit_log WHERE id = ?')->execute([$id]);
        }
        parent::tearDown();
    }

    /** An audit row of the given action, returning its id. */
    private function auditRow(string $action, string $entityId, array $newValues = [], string $occurredAt = '2026-08-20 21:00:00'): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO audit_log (admin_user_id, action, entity_type, entity_id, new_values, created_at)
             VALUES (NULL, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$action, 'transaction', $entityId, json_encode($newValues), $occurredAt]);
        $id = (int) $this->db->lastInsertId();
        $this->auditIds[] = $id;

        return $id;
    }

    private function violation(string $transactionId, int $minAge = 18, string $occurredAt = '2026-08-20 21:00:00'): int
    {
        return $this->auditRow('jugendschutz_violation', $transactionId, [
            'member_id' => 'm-' . $transactionId,
            'product_id' => 'p-' . $transactionId,
            'min_age' => $minAge,
            'age_at_sale' => $minAge - 3,
            'occurred_at' => $occurredAt,
        ], $occurredAt);
    }

    public function test_a_fresh_violation_is_unacknowledged(): void
    {
        $before = $this->repository->unacknowledgedSummary()['count'];

        $this->violation('tx-1');

        $this->assertSame($before + 1, $this->repository->unacknowledgedSummary()['count']);
    }

    public function test_acknowledging_takes_it_out_of_the_count(): void
    {
        $id = $this->violation('tx-2');
        $before = $this->repository->unacknowledgedSummary()['count'];

        $this->assertTrue($this->repository->acknowledge($id, null, 'spoke to the Getränkewart'));

        $this->assertSame($before - 1, $this->repository->unacknowledgedSummary()['count']);
    }

    /**
     * Invariant 4, expressed in storage.
     *
     * Acknowledging must not touch the record. If the audit row could be
     * altered or removed by this path, the club's ability to say the sale
     * happened would depend on nobody having clicked "dealt with".
     */
    public function test_acknowledging_leaves_the_audit_entry_exactly_as_it_was(): void
    {
        $id = $this->violation('tx-3', minAge: 16);
        $before = $this->db->query("SELECT * FROM audit_log WHERE id = {$id}")->fetch();

        $this->repository->acknowledge($id, null, null);

        $after = $this->db->query("SELECT * FROM audit_log WHERE id = {$id}")->fetch();
        $this->assertSame($before, $after, 'the record is not the alert and must not move');
    }

    /** Two admins clearing the same alert must not produce two rows. */
    public function test_acknowledging_twice_is_refused_the_second_time(): void
    {
        $id = $this->violation('tx-4');

        $this->assertTrue($this->repository->acknowledge($id, null, null));
        $this->assertFalse($this->repository->acknowledge($id, null, null));
    }

    /**
     * The guard that makes an `audit_log.id` key safe.
     *
     * Every audited action shares that id space, so without this an ack could
     * be written against a login attempt or a settlement export.
     */
    public function test_an_audit_row_that_is_not_a_violation_cannot_be_acknowledged(): void
    {
        $id = $this->auditRow('export', 'settlement-1');

        $this->assertFalse($this->repository->acknowledge($id, null, null));
        $this->assertSame(
            0,
            (int) $this->db->query("SELECT COUNT(*) FROM jugendschutz_violation_acks WHERE audit_log_id = {$id}")->fetchColumn(),
        );
    }

    public function test_an_audit_row_that_does_not_exist_cannot_be_acknowledged(): void
    {
        $this->assertFalse($this->repository->acknowledge(2147483646, null, null));
    }

    /**
     * The list the dashboard offers to acknowledge from.
     *
     * Carries the audit id (the acknowledgement key), the sale it names and the
     * age that was breached — and **no member**. The dashboard is `TREASURY`, so
     * a Kassenwart reading it may legitimately see members elsewhere; this
     * surface still does not show one, because it renders on a screen that may
     * be open in the clubroom and rule 6 does not stop at the till. The
     * transaction id is the handle into the journal, which their role permits.
     */
    public function test_the_unacknowledged_list_names_the_sale_and_the_limit_but_no_member(): void
    {
        $id = $this->violation('tx-9', minAge: 18, occurredAt: '2099-08-20 21:00:00');

        $rows = $this->repository->listUnacknowledged(50);
        $mine = null;
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                $mine = $row;
            }
        }

        $this->assertNotNull($mine, 'a fresh violation must be offered for acknowledgement');
        $this->assertSame('tx-9', $mine['transaction_id']);
        $this->assertSame(18, $mine['min_age']);
        $this->assertArrayNotHasKey('member_id', $mine);
        $this->assertArrayNotHasKey('age_at_sale', $mine);
    }

    public function test_the_unacknowledged_list_drops_what_has_been_acknowledged(): void
    {
        $id = $this->violation('tx-10', occurredAt: '2099-08-20 21:00:00');
        $this->repository->acknowledge($id, null, null);

        $ids = array_column($this->repository->listUnacknowledged(50), 'id');

        $this->assertNotContains($id, array_map('intval', $ids));
    }

    /**
     * The dashboard shows when the most recent one happened.
     *
     * `MAX()` is taken over **every** unacknowledged violation in the database,
     * which is what the dashboard wants and what makes an absolute assertion
     * unsafe: any violation another test left behind would steer it. The two
     * tests below therefore date their rows past anything else that could be
     * present, so the value under test is deterministic without either
     * assuming an empty table or mutating rows they do not own
     * (E2E Pattern 001, applied to a shared development database).
     */
    public function test_the_summary_carries_the_latest_unacknowledged_moment(): void
    {
        $this->violation('tx-5', occurredAt: '2099-08-19 18:00:00');
        $this->violation('tx-6', occurredAt: '2099-08-20 22:30:00');

        $this->assertSame('2099-08-20 22:30:00', $this->repository->unacknowledgedSummary()['latest_occurred_at']);
    }

    /** Acknowledged ones stop steering that timestamp too. */
    public function test_the_latest_moment_ignores_acknowledged_violations(): void
    {
        $this->violation('tx-7', occurredAt: '2099-08-19 18:00:00');
        $newer = $this->violation('tx-8', occurredAt: '2099-08-20 22:30:00');

        $this->repository->acknowledge($newer, null, null);

        // Falls back to the older one, which is still asking to be looked at —
        // rather than to null, which would read as "nothing to see".
        $this->assertSame('2099-08-19 18:00:00', $this->repository->unacknowledgedSummary()['latest_occurred_at']);
    }
}
