<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notifications;

use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailStatus;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use Tests\Feature\DatabaseTestCase;

/**
 * The queue against a real MariaDB (ADR-0038, #402).
 *
 * Claiming, backoff and the stale window are the parts a mocked PDO cannot say
 * anything useful about: they are `UPDATE … LIMIT` semantics and interval
 * arithmetic, and the reason they are written the way they are is that
 * `SELECT … FOR UPDATE SKIP LOCKED` needs MariaDB 10.6+ and the database
 * version belongs to the host.
 */
class MailOutboxRepositoryTest extends DatabaseTestCase
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
        // mail_outbox cascades from both parents, so removing these is enough.
        foreach ($this->settlementIds as $id) {
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

    private function member(): string
    {
        $id = $this->generateUuid();
        $this->memberIds[] = $id;

        $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$id, 'Test', 'Outbox', $id . '@example.com', 'de']);

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

    /** @return array{0: string, 1: list<string>} settlement id and its member ids */
    private function queue(int $count): array
    {
        $settlementId = $this->settlement();
        $memberIds = [];

        for ($i = 0; $i < $count; $i++) {
            $memberId = $this->member();
            $memberIds[] = $memberId;
            $this->repository->enqueue(
                MailKind::SEPA_PRENOTIFICATION,
                $settlementId,
                $memberId,
                $memberId . '@example.com',
                'de',
            );
        }

        return [$settlementId, $memberIds];
    }

    private function row(string $settlementId, string $memberId, MailKind $kind = MailKind::SEPA_PRENOTIFICATION): array
    {
        $stmt = $this->db->prepare('SELECT * FROM mail_outbox WHERE settlement_id = ? AND member_id = ? AND kind = ?');
        $stmt->execute([$settlementId, $memberId, $kind->value]);

        return $stmt->fetch() ?: [];
    }

    /**
     * The idempotency criterion, at the level the application meets it: the
     * second call is a no-op that reports itself as one, rather than an error
     * that would abort the settlement transaction it runs inside.
     */
    public function test_enqueueing_the_same_message_twice_inserts_once(): void
    {
        [$settlementId, $memberIds] = $this->queue(1);

        $second = $this->repository->enqueue(
            MailKind::SEPA_PRENOTIFICATION,
            $settlementId,
            $memberIds[0],
            'changed@example.com',
            'en',
        );

        $this->assertFalse($second, 'a repeated enqueue must report that it inserted nothing');

        $rows = $this->repository->findBySettlementId($settlementId);
        $this->assertCount(1, $rows);
        // The first snapshot wins. It is the address the club committed to
        // announcing to; a retried finalize does not get to rewrite it.
        $this->assertSame($memberIds[0] . '@example.com', $rows[0]['recipient']);
        $this->assertSame('de', $rows[0]['language']);
    }

    /**
     * Two drains over one queue send exactly N, never N+1. The claim is a
     * single atomic `UPDATE`, so a row carries exactly one token.
     */
    public function test_two_concurrent_claims_never_hand_out_the_same_message(): void
    {
        [$settlementId] = $this->queue(6);

        $first = $this->repository->claimBatch(4);
        $second = $this->repository->claimBatch(4);

        $this->assertCount(4, $first);
        $this->assertCount(2, $second, 'the second drain may only take what the first left');

        $ids = array_merge(array_column($first, 'id'), array_column($second, 'id'));
        $this->assertCount(6, array_unique($ids), 'no message may be claimed by both runs');

        $this->assertSame([], $this->repository->claimBatch(4), 'a drained queue hands out nothing');
    }

    /**
     * A run that was killed mid-batch must not strand its rows forever. After
     * the stale window they are claimable again — which is the whole reason
     * the claim carries a timestamp and not just a token.
     */
    public function test_a_claim_older_than_the_stale_window_is_reclaimable(): void
    {
        [$settlementId, $memberIds] = $this->queue(1);

        $claimed = $this->repository->claimBatch(1);
        $this->assertCount(1, $claimed);
        $this->assertSame([], $this->repository->claimBatch(1), 'a fresh claim is respected');

        // Age the claim past the window, as a killed run would leave it.
        $this->db->prepare('UPDATE mail_outbox SET claimed_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE id = ?')
            ->execute([$claimed[0]['id']]);

        $reclaimed = $this->repository->claimBatch(1);
        $this->assertCount(1, $reclaimed);
        $this->assertSame($claimed[0]['id'], $reclaimed[0]['id']);
    }

    public function test_a_message_scheduled_for_later_is_not_claimed_yet(): void
    {
        [$settlementId, $memberIds] = $this->queue(1);

        $this->db->prepare('UPDATE mail_outbox SET next_attempt_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE settlement_id = ?')
            ->execute([$settlementId]);

        $this->assertSame([], $this->repository->claimBatch(5));
    }

    public function test_a_delivered_message_records_its_handle_and_leaves_the_queue(): void
    {
        [$settlementId, $memberIds] = $this->queue(1);

        $claimed = $this->repository->claimBatch(1);
        $this->repository->markSent($claimed[0]['id'], '<abc@mta.example>');

        $row = $this->row($settlementId, $memberIds[0]);
        $this->assertSame(MailStatus::SENT->value, $row['status']);
        $this->assertSame('<abc@mta.example>', $row['message_id']);
        $this->assertNotNull($row['sent_at']);
        $this->assertNull($row['claim_token']);
        $this->assertSame([], $this->repository->claimBatch(5));
    }

    /**
     * Greylisting is the case this exists for: the receiving MTA rejects the
     * first attempt on purpose and expects another one later. Without a retry
     * that mail is lost, and it is ordinary traffic rather than an error.
     */
    public function test_a_transient_failure_buys_a_backoff_and_another_attempt(): void
    {
        [$settlementId, $memberIds] = $this->queue(1);

        $claimed = $this->repository->claimBatch(1);
        $status = $this->repository->markFailed($claimed[0]['id'], '451 greylisted', true, 3, 900);

        $this->assertSame(MailStatus::PENDING, $status);

        $row = $this->row($settlementId, $memberIds[0]);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertSame('451 greylisted', $row['last_error']);
        $this->assertGreaterThan($row['queued_at'], $row['next_attempt_at'], 'the retry must be scheduled forward');

        // And it is not claimable until the backoff elapses.
        $this->assertSame([], $this->repository->claimBatch(5));
    }

    public function test_the_attempt_cap_stops_the_retrying(): void
    {
        [$settlementId, $memberIds] = $this->queue(1);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->db->prepare('UPDATE mail_outbox SET next_attempt_at = NOW() WHERE settlement_id = ?')
                ->execute([$settlementId]);

            $claimed = $this->repository->claimBatch(1);
            $this->assertCount(1, $claimed, "attempt {$attempt} should have been claimable");

            $status = $this->repository->markFailed($claimed[0]['id'], '451 greylisted', true, 3, 900);
            $expected = $attempt < 3 ? MailStatus::PENDING : MailStatus::FAILED;
            $this->assertSame($expected, $status, "attempt {$attempt} landed in the wrong state");
        }

        $row = $this->row($settlementId, $memberIds[0]);
        $this->assertSame(MailStatus::FAILED->value, $row['status']);
        $this->assertSame(3, (int) $row['attempts']);

        // Failed is terminal for the drain. The Kassenwart decides what happens
        // next (#407) — best effort means visible, not chased.
        $this->db->prepare('UPDATE mail_outbox SET next_attempt_at = NOW() WHERE settlement_id = ?')
            ->execute([$settlementId]);
        $this->assertSame([], $this->repository->claimBatch(5));
    }

    public function test_a_permanent_failure_does_not_get_a_second_attempt(): void
    {
        [$settlementId, $memberIds] = $this->queue(1);

        $claimed = $this->repository->claimBatch(1);
        $status = $this->repository->markFailed($claimed[0]['id'], '550 no such mailbox', false, 3, 900);

        $this->assertSame(MailStatus::FAILED, $status);
        $this->assertSame('550 no such mailbox', $this->row($settlementId, $memberIds[0])['last_error']);
    }

    /** The one state change #407's retry button is allowed to make. */
    public function test_a_failed_message_can_be_put_back_in_the_queue(): void
    {
        [$settlementId, $memberIds] = $this->queue(1);

        $claimed = $this->repository->claimBatch(1);
        $this->repository->markFailed($claimed[0]['id'], '550 no such mailbox', false, 3, 900);

        $this->assertTrue($this->repository->resetToPending($claimed[0]['id']));

        $row = $this->row($settlementId, $memberIds[0]);
        $this->assertSame(MailStatus::PENDING->value, $row['status']);
        $this->assertSame(0, (int) $row['attempts']);
        $this->assertNull($row['last_error']);
        $this->assertCount(1, $this->repository->claimBatch(5));
    }

    public function test_a_sent_message_cannot_be_put_back_in_the_queue(): void
    {
        [$settlementId, $memberIds] = $this->queue(1);

        $claimed = $this->repository->claimBatch(1);
        $this->repository->markSent($claimed[0]['id'], null);

        $this->assertFalse(
            $this->repository->resetToPending($claimed[0]['id']),
            'resending an announcement somebody already received is not a retry'
        );
    }

    public function test_superseding_closes_unsent_messages_and_leaves_the_rest_alone(): void
    {
        [$settlementId, $memberIds] = $this->queue(3);

        // One went out, one failed, one is still waiting.
        $claimed = $this->repository->claimBatch(3);
        $this->repository->markSent($claimed[0]['id'], null);
        $this->repository->markFailed($claimed[1]['id'], '550 gone', false, 3, 900);
        $this->db->prepare('UPDATE mail_outbox SET status = ?, claim_token = NULL, claimed_at = NULL WHERE id = ?')
            ->execute([MailStatus::PENDING->value, $claimed[2]['id']]);

        $superseded = $this->repository->supersedePending($settlementId, MailKind::SEPA_PRENOTIFICATION);

        $this->assertSame(1, $superseded);

        $byId = [];
        foreach ($this->repository->findBySettlementId($settlementId) as $row) {
            $byId[$row['id']] = $row['status'];
        }

        $this->assertSame(MailStatus::SENT->value, $byId[$claimed[0]['id']], 'a sent message stays sent — it happened');
        $this->assertSame(MailStatus::FAILED->value, $byId[$claimed[1]['id']], 'a failed message is already closed');
        $this->assertSame(MailStatus::SUPERSEDED->value, $byId[$claimed[2]['id']]);
    }

    public function test_only_the_members_who_were_actually_told_are_reported(): void
    {
        [$settlementId, $memberIds] = $this->queue(2);

        $claimed = $this->repository->claimBatch(2);
        $this->repository->markSent($claimed[0]['id'], null);

        $told = $this->repository->findMemberIdsWithStatus(
            $settlementId,
            MailKind::SEPA_PRENOTIFICATION,
            MailStatus::SENT,
        );

        $this->assertSame([$claimed[0]['member_id']], $told);
    }

    /**
     * The backlog signal #406 alarms on: a queue that stops moving erodes the
     * seven-day announcement distance, and nothing else would notice.
     */
    public function test_the_oldest_waiting_message_is_the_stall_signal(): void
    {
        [$settlementId, $memberIds] = $this->queue(1);

        $oldest = $this->repository->oldestPendingQueuedAt();
        $this->assertNotNull($oldest);
        $this->assertLessThanOrEqual(
            $this->row($settlementId, $memberIds[0])['queued_at'],
            $oldest,
            'the reported backlog must be at least as old as the message just queued'
        );

        // Draining removes it from the backlog; an empty queue is the healthy
        // answer and reports nothing rather than a stale timestamp.
        $claimed = $this->repository->claimBatch(1);
        $this->repository->markSent($claimed[0]['id'], null);

        $this->assertNull($this->repository->oldestPendingQueuedAt());
    }
}
