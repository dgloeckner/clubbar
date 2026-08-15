<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notifications;

use App\Modules\Notifications\DTOs\MailRequestDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Enums\MailStatus;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Services\MailRetention;
use Tests\Feature\DatabaseTestCase;

/**
 * Erasure and pruning over a real database (#408, ADR-0029).
 *
 * Every assertion here is made by **querying the column**, never by trusting the
 * return value of the method under test. The failure this guards against is an
 * erasure that reports success and leaves the address in place, and a method
 * that got its own `WHERE` wrong will report success just as cheerfully.
 *
 * No test sleeps and none touches a clock: ages are set by writing `sent_at`
 * directly, and the prune cutoff takes its `now` as an argument for exactly that
 * reason.
 */
class MailRetentionTest extends DatabaseTestCase
{
    private MailOutboxRepository $repository;

    /** @var list<string> */
    private array $settlementIds = [];
    /** @var list<string> */
    private array $memberIds = [];
    /** @var list<string> */
    private array $adminIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new MailOutboxRepository($this->db, $this->logger);
    }

    protected function tearDown(): void
    {
        foreach ($this->settlementIds as $id) {
            $this->db->prepare('DELETE FROM mail_outbox WHERE subject_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM settlements WHERE id = ?')->execute([$id]);
        }
        foreach ($this->memberIds as $id) {
            $this->db->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);
        }
        foreach ($this->adminIds as $id) {
            $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
        }

        parent::tearDown();
    }

    /* ─────────────────────────────── Fixtures ─────────────────────────────── */

    private function member(): string
    {
        $id = $this->generateUuid();
        $this->memberIds[] = $id;

        $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$id, 'Test', 'Retention', $id . '@example.com', 'de']);

        return $id;
    }

    private function settlement(): string
    {
        $adminId = $this->generateUuid();
        $this->adminIds[] = $adminId;
        $this->db->prepare('INSERT INTO admin_users (id, email, password_hash, is_active) VALUES (?, ?, ?, 1)')
            ->execute([$adminId, $adminId . '@example.com', password_hash('test123', PASSWORD_BCRYPT)]);

        $id = $this->generateUuid();
        $this->settlementIds[] = $id;
        $this->db->prepare(
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$id, '2026-08-01', '2026-08-21', 350, 1, $adminId]);

        return $id;
    }

    /** Queue one message and put it straight into the state the test needs. */
    private function queue(
        string $settlementId,
        string $memberId,
        MailKind $kind,
        MailStatus $status,
        ?int $sentDaysAgo = null,
    ): string {
        $this->repository->enqueue(MailRequestDto::forMember(
            kind: $kind,
            settlementId: $settlementId,
            memberId: $memberId,
            recipient: $memberId . '@example.com',
            language: MailLanguage::German,
        ));

        $sentAt = $sentDaysAgo === null ? null : date('Y-m-d H:i:s', time() - $sentDaysAgo * 86400);
        $stmt = $this->db->prepare(
            'UPDATE mail_outbox SET status = ?, sent_at = ?
              WHERE subject_id = ? AND member_id = ? AND kind = ?'
        );
        $stmt->execute([$status->value, $sentAt, $settlementId, $memberId, $kind->value]);

        $id = $this->db->prepare('SELECT id FROM mail_outbox WHERE subject_id = ? AND member_id = ? AND kind = ?');
        $id->execute([$settlementId, $memberId, $kind->value]);

        return (string) $id->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    private function read(string $outboxId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM mail_outbox WHERE id = ?');
        $stmt->execute([$outboxId]);

        return $stmt->fetch() ?: null;
    }

    /* ──────────────────────────────── Erasure ──────────────────────────────── */

    /**
     * The acceptance criterion, asserted against the column.
     *
     * Both kinds of row, because the announcement and its retraction hold the
     * same address and only one of them is the obvious one.
     */
    public function test_erasure_clears_the_address_from_every_row_of_a_member(): void
    {
        $settlementId = $this->settlement();
        $memberId = $this->member();
        $other = $this->member();

        $announcement = $this->queue($settlementId, $memberId, MailKind::SEPA_PRENOTIFICATION, MailStatus::SENT, 1);
        $notice = $this->queue($settlementId, $memberId, MailKind::CANCELLATION_NOTICE, MailStatus::SENT, 1);
        $untouched = $this->queue($settlementId, $other, MailKind::SEPA_PRENOTIFICATION, MailStatus::SENT, 1);

        $cleared = $this->repository->eraseMemberRecipients($memberId);

        $this->assertSame(2, $cleared);
        $this->assertSame('', $this->read($announcement)['recipient']);
        $this->assertSame('', $this->read($notice)['recipient']);
        // The other half of the assertion: an over-broad `WHERE` would empty the
        // whole table and still satisfy everything above.
        $this->assertSame($other . '@example.com', $this->read($untouched)['recipient']);
    }

    /**
     * The test that would fail if erasure were keyed on `subject_id`.
     *
     * A Vorabankündigung's `subject_id` is the **settlement**, so a shortcut
     * that matched on it would find nothing here — while still passing for any
     * kind whose subject happens to be the member. Naming that explicitly is the
     * point of this test existing beside the one above.
     */
    public function test_erasure_keys_on_the_member_column_not_the_subject(): void
    {
        $settlementId = $this->settlement();
        $memberId = $this->member();

        $announcement = $this->queue($settlementId, $memberId, MailKind::SEPA_PRENOTIFICATION, MailStatus::SENT, 1);

        $this->repository->eraseMemberRecipients($memberId);

        $stmt = $this->db->prepare('SELECT recipient FROM mail_outbox WHERE member_id = ?');
        $stmt->execute([$memberId]);
        $addresses = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);

        $this->assertNotEmpty($addresses, 'the row must still exist — erasure removes the address, not the record');
        $this->assertSame([''], array_map('strval', $addresses));
        $this->assertNotSame($memberId, $this->read($announcement)['subject_id']);
    }

    /**
     * A pending message for an erased member is withdrawn, not left addressed to
     * an empty string for the drain to fail on.
     */
    public function test_erasure_supersedes_a_pending_message(): void
    {
        $settlementId = $this->settlement();
        $memberId = $this->member();

        $pending = $this->queue($settlementId, $memberId, MailKind::SEPA_PRENOTIFICATION, MailStatus::PENDING);

        $superseded = $this->repository->supersedePendingForMember($memberId);

        $this->assertSame(1, $superseded);
        $this->assertSame(MailStatus::SUPERSEDED->value, $this->read($pending)['status']);
    }

    /**
     * A `failed` row is the record that somebody was *not* reached, and erasure
     * is not a reason to rewrite that. Only its address goes.
     */
    public function test_erasure_leaves_a_failed_row_failed(): void
    {
        $settlementId = $this->settlement();
        $memberId = $this->member();

        $failed = $this->queue($settlementId, $memberId, MailKind::SEPA_PRENOTIFICATION, MailStatus::FAILED);

        $this->repository->supersedePendingForMember($memberId);
        $this->repository->eraseMemberRecipients($memberId);

        $row = $this->read($failed);
        $this->assertSame(MailStatus::FAILED->value, $row['status']);
        $this->assertSame('', $row['recipient']);
    }

    /* ──────────────────────────────── Pruning ──────────────────────────────── */

    /**
     * Both halves in one test: what goes, and what of the same age stays.
     *
     * A prune test that only asserts the deletion cannot catch an over-broad
     * `WHERE` — and over-broad is the failure mode that matters, because the
     * rows next to the target are the ones the treasurer still needs.
     */
    public function test_pruning_takes_delivered_rows_past_the_window_and_nothing_else(): void
    {
        $settlementId = $this->settlement();
        $old = $this->member();
        $recent = $this->member();
        $pendingMember = $this->member();
        $failedMember = $this->member();

        $days = MailRetention::sentDaysFor(MailKind::SEPA_PRENOTIFICATION);

        $stale = $this->queue($settlementId, $old, MailKind::SEPA_PRENOTIFICATION, MailStatus::SENT, $days + 1);
        $fresh = $this->queue($settlementId, $recent, MailKind::SEPA_PRENOTIFICATION, MailStatus::SENT, $days - 1);
        // Same age as the stale one, and never delivered. Pruning must not
        // become a way to lose a message that never went out.
        $pending = $this->queue($settlementId, $pendingMember, MailKind::SEPA_PRENOTIFICATION, MailStatus::PENDING, $days + 1);
        $failed = $this->queue($settlementId, $failedMember, MailKind::SEPA_PRENOTIFICATION, MailStatus::FAILED, $days + 1);

        $deleted = $this->repository->pruneSent(
            MailKind::SEPA_PRENOTIFICATION,
            MailRetention::cutoffFor(MailKind::SEPA_PRENOTIFICATION, time()),
            MailRetention::PRUNE_BATCH,
        );

        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertNull($this->read($stale), 'a delivered row past its window must be gone');
        $this->assertNotNull($this->read($fresh), 'a delivered row inside its window must stay');
        $this->assertNotNull($this->read($pending), 'a pending row is never pruned, at any age');
        $this->assertNotNull($this->read($failed), 'a failed row is never pruned, at any age');
    }

    /**
     * Pruning is per kind, and only the named one is touched — the property the
     * Deckelauszug (#462) will rely on when its window diverges from this one.
     */
    public function test_pruning_one_kind_leaves_another_alone(): void
    {
        $settlementId = $this->settlement();
        $memberId = $this->member();

        $days = MailRetention::sentDaysFor(MailKind::SEPA_PRENOTIFICATION);
        $announcement = $this->queue($settlementId, $memberId, MailKind::SEPA_PRENOTIFICATION, MailStatus::SENT, $days + 5);
        $notice = $this->queue($settlementId, $memberId, MailKind::CANCELLATION_NOTICE, MailStatus::SENT, $days + 5);

        $this->repository->pruneSent(
            MailKind::SEPA_PRENOTIFICATION,
            MailRetention::cutoffFor(MailKind::SEPA_PRENOTIFICATION, time()),
            MailRetention::PRUNE_BATCH,
        );

        $this->assertNull($this->read($announcement));
        $this->assertNotNull($this->read($notice));
    }

    /**
     * The bound is real. A prune pass that ignored its `LIMIT` would be an
     * unbounded `DELETE` at the tail of a run the host is already timing.
     */
    public function test_pruning_respects_its_batch_limit(): void
    {
        $settlementId = $this->settlement();
        $days = MailRetention::sentDaysFor(MailKind::SEPA_PRENOTIFICATION);

        for ($i = 0; $i < 3; $i++) {
            $this->queue($settlementId, $this->member(), MailKind::SEPA_PRENOTIFICATION, MailStatus::SENT, $days + 10);
        }

        $deleted = $this->repository->pruneSent(
            MailKind::SEPA_PRENOTIFICATION,
            MailRetention::cutoffFor(MailKind::SEPA_PRENOTIFICATION, time()),
            2,
        );

        $this->assertSame(2, $deleted);
    }
}
