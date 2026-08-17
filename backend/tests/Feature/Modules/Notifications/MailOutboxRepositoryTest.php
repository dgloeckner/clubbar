<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notifications;

use App\Modules\Notifications\DTOs\MailRequestDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
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

    /** @var list<array{id: string, next_attempt_at: string}> */
    private array $parked = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new MailOutboxRepository($this->db, $this->logger);
        $this->parkForeignMessages();
    }

    /**
     * Give this test class a queue of its own.
     *
     * `claimBatch()` is global by nature — a drain takes whatever is due, which
     * is the whole point of it — so "the second drain may only take what the
     * first left" is a meaningful assertion only when nothing else is waiting.
     * And something else routinely is: the API suite creates settlements
     * against this same database, every one of them queues an announcement, and
     * those rows stay `pending` because nothing drains them yet.
     *
     * So anything already due is pushed a day out for the duration, and put
     * back exactly as it was in tearDown. Deleting it would be easier and
     * wrong: those rows belong to somebody else's fixture.
     */
    private function parkForeignMessages(): void
    {
        $due = $this->db
            ->query("SELECT id, next_attempt_at FROM mail_outbox WHERE status = 'pending'")
            ->fetchAll();

        foreach ($due as $row) {
            $this->parked[] = ['id' => (string) $row['id'], 'next_attempt_at' => (string) $row['next_attempt_at']];
        }

        if ($this->parked !== []) {
            $this->db->exec(
                "UPDATE mail_outbox SET next_attempt_at = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE status = 'pending'"
            );
        }
    }

    private function unparkForeignMessages(): void
    {
        $stmt = $this->db->prepare('UPDATE mail_outbox SET next_attempt_at = ? WHERE id = ?');
        foreach ($this->parked as $row) {
            $stmt->execute([$row['next_attempt_at'], $row['id']]);
        }
        $this->parked = [];
    }

    protected function tearDown(): void
    {
        // `subject_id` is polymorphic and carries no foreign key, so deleting
        // the settlement does not take the queue with it — deleting the member
        // does, and that is the cascade that matters (erasure, #408).
        foreach ($this->settlementIds as $id) {
            $this->db->prepare('DELETE FROM settlements WHERE id = ?')->execute([$id]);
        }
        foreach ($this->memberIds as $id) {
            $this->db->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);
        }
        foreach ($this->adminIds as $id) {
            $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
        }

        $this->unparkForeignMessages();

        parent::tearDown();
    }

    /** A second admin, distinct from the one {@see settlement()} creates, to stand in as an actor. */
    private function admin(): string
    {
        $id = $this->generateUuid();
        $this->adminIds[] = $id;
        $this->db->prepare('INSERT INTO admin_users (id, email, password_hash, is_active) VALUES (?, ?, ?, 1)')
            ->execute([$id, $id . '@example.com', password_hash('test123', PASSWORD_BCRYPT)]);

        return $id;
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
            $this->repository->enqueue(MailRequestDto::forMember(
                kind: MailKind::SEPA_PRENOTIFICATION,
                settlementId: $settlementId,
                memberId: $memberId,
                recipient: $memberId . '@example.com',
                language: MailLanguage::German,
            ));
        }

        return [$settlementId, $memberIds];
    }

    private function row(string $settlementId, string $memberId, MailKind $kind = MailKind::SEPA_PRENOTIFICATION): array
    {
        $stmt = $this->db->prepare('SELECT * FROM mail_outbox WHERE subject_id = ? AND member_id = ? AND kind = ?');
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

        $second = $this->repository->enqueue(MailRequestDto::forMember(
            kind: MailKind::SEPA_PRENOTIFICATION,
            settlementId: $settlementId,
            memberId: $memberIds[0],
            recipient: 'changed@example.com',
            language: MailLanguage::English,
        ));

        $this->assertFalse($second, 'a repeated enqueue must report that it inserted nothing');

        $rows = $this->repository->findBySubjectId($settlementId);
        $this->assertCount(1, $rows);
        // The first snapshot wins. It is the address the club committed to
        // announcing to; a retried finalize does not get to rewrite it.
        $this->assertSame($memberIds[0] . '@example.com', $rows[0]['recipient']);
        $this->assertSame('de', $rows[0]['language']);
    }

    /**
     * `actor_admin_user_id` (migration 043) is a fact about who acted, distinct
     * from the recipient a row is addressed to — `enqueue()` must persist both
     * rather than collapsing them onto the one `admin_user_id` column.
     */
    public function test_enqueue_persists_the_actor_separately_from_the_recipient(): void
    {
        $recipientId = $this->admin();
        $actorId = $this->admin();
        $subjectId = $this->generateUuid();

        $this->repository->enqueue(MailRequestDto::forAdmin(
            kind: MailKind::TERMINAL_TOKEN_ISSUED,
            subjectId: $subjectId,
            adminUserId: $recipientId,
            recipient: $recipientId . '@example.com',
            language: MailLanguage::German,
            occasion: 'enrolled:20260816142317',
            actorAdminUserId: $actorId,
        ));

        $stmt = $this->db->prepare('SELECT * FROM mail_outbox WHERE subject_id = ?');
        $stmt->execute([$subjectId]);
        $row = $stmt->fetch();

        $this->assertSame($recipientId, $row['admin_user_id']);
        $this->assertSame($actorId, $row['actor_admin_user_id']);
        $this->assertNotSame($row['admin_user_id'], $row['actor_admin_user_id']);
    }

    /**
     * Deleting the acting admin's account must not take a still-relevant notice
     * to a different, still-active recipient down with it (`ON DELETE SET
     * NULL`) — only the name is lost, exactly as the DTO's blank-actor case
     * already handles at render time.
     */
    public function test_deleting_the_actor_admin_clears_the_column_without_deleting_the_row(): void
    {
        $recipientId = $this->admin();
        $actorId = $this->admin();
        $subjectId = $this->generateUuid();

        $this->repository->enqueue(MailRequestDto::forAdmin(
            kind: MailKind::TERMINAL_TOKEN_ISSUED,
            subjectId: $subjectId,
            adminUserId: $recipientId,
            recipient: $recipientId . '@example.com',
            language: MailLanguage::German,
            occasion: 'enrolled:20260816142317',
            actorAdminUserId: $actorId,
        ));

        $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$actorId]);
        $this->adminIds = array_values(array_diff($this->adminIds, [$actorId]));

        $stmt = $this->db->prepare('SELECT * FROM mail_outbox WHERE subject_id = ?');
        $stmt->execute([$subjectId]);
        $row = $stmt->fetch();

        $this->assertNotFalse($row, 'the notice to the still-active recipient must survive');
        $this->assertNull($row['actor_admin_user_id']);
        $this->assertSame($recipientId, $row['admin_user_id']);
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

        $this->db->prepare('UPDATE mail_outbox SET next_attempt_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE subject_id = ?')
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
        $status = $this->repository->markFailed($claimed[0]['id'], '451 greylisted', 1, true, 900);

        $this->assertSame(MailStatus::PENDING, $status);

        $row = $this->row($settlementId, $memberIds[0]);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertSame('451 greylisted', $row['last_error']);
        $this->assertGreaterThan($row['queued_at'], $row['next_attempt_at'], 'the retry must be scheduled forward');

        // And it is not claimable until the backoff elapses.
        $this->assertSame([], $this->repository->claimBatch(5));
    }

    /**
     * How many goes there are is the *service's* decision now (the ladder is a
     * function of the attempt count and the scheduler's interval), so what this
     * asserts is that the queue honours the decision it is handed: `retry` back
     * to pending, no retry terminal, and `attempts` recorded either way.
     */
    public function test_the_attempt_cap_stops_the_retrying(): void
    {
        [$settlementId, $memberIds] = $this->queue(1);
        $cap = 3;

        for ($attempt = 1; $attempt <= $cap; $attempt++) {
            $this->db->prepare('UPDATE mail_outbox SET next_attempt_at = NOW() WHERE subject_id = ?')
                ->execute([$settlementId]);

            $claimed = $this->repository->claimBatch(1);
            $this->assertCount(1, $claimed, "attempt {$attempt} should have been claimable");

            $this->assertSame($attempt - 1, $this->repository->attemptsFor($claimed[0]['id']));

            $retry = $attempt < $cap;
            $status = $this->repository->markFailed(
                $claimed[0]['id'],
                '451 greylisted',
                $attempt,
                $retry,
                $retry ? 900 : 0
            );
            $expected = $retry ? MailStatus::PENDING : MailStatus::FAILED;
            $this->assertSame($expected, $status, "attempt {$attempt} landed in the wrong state");
        }

        $row = $this->row($settlementId, $memberIds[0]);
        $this->assertSame(MailStatus::FAILED->value, $row['status']);
        $this->assertSame($cap, (int) $row['attempts']);

        // Failed is terminal for the drain. The Kassenwart decides what happens
        // next (#407) — best effort means visible, not chased.
        $this->db->prepare('UPDATE mail_outbox SET next_attempt_at = NOW() WHERE subject_id = ?')
            ->execute([$settlementId]);
        $this->assertSame([], $this->repository->claimBatch(5));
    }

    public function test_a_permanent_failure_does_not_get_a_second_attempt(): void
    {
        [$settlementId, $memberIds] = $this->queue(1);

        $claimed = $this->repository->claimBatch(1);
        $status = $this->repository->markFailed($claimed[0]['id'], '550 no such mailbox', 1, false, 0);

        $this->assertSame(MailStatus::FAILED, $status);
        $this->assertSame('550 no such mailbox', $this->row($settlementId, $memberIds[0])['last_error']);
    }

    /** The one state change #407's retry button is allowed to make. */
    public function test_a_failed_message_can_be_put_back_in_the_queue(): void
    {
        [$settlementId, $memberIds] = $this->queue(1);

        $claimed = $this->repository->claimBatch(1);
        $this->repository->markFailed($claimed[0]['id'], '550 no such mailbox', 1, false, 0);

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
        $this->repository->markFailed($claimed[1]['id'], '550 gone', 1, false, 0);
        $this->db->prepare('UPDATE mail_outbox SET status = ?, claim_token = NULL, claimed_at = NULL WHERE id = ?')
            ->execute([MailStatus::PENDING->value, $claimed[2]['id']]);

        $superseded = $this->repository->supersedePending($settlementId, MailKind::SEPA_PRENOTIFICATION);

        $this->assertSame(1, $superseded);

        $byId = [];
        foreach ($this->repository->findBySubjectId($settlementId) as $row) {
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
     *
     * The reading is global on purpose — it answers "is anything stuck?" for
     * the whole installation, not per settlement — so this asserts the two
     * things that hold whatever else the database contains: the backlog is
     * never newer than the message just queued, and a sent message stops
     * counting towards it.
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

        $claimed = $this->repository->claimBatch(1);
        $this->repository->markSent($claimed[0]['id'], null);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM mail_outbox WHERE subject_id = ? AND status = 'pending'");
        $stmt->execute([$settlementId]);

        $this->assertSame(0, (int) $stmt->fetchColumn(), 'a sent message no longer waits');
    }

    /**
     * What the alarm actually keys on (ADR-0039 decision 5): the earliest
     * moment anything became **due**, not the oldest thing in the queue.
     *
     * The distinction is the whole correction. A message on the fourth rung of
     * its ladder is days old and not due for hours; under the old age-based
     * predicate it tripped the alarm every time, which is precisely the
     * wolf-crying ADR-0038 forbids.
     */
    public function test_a_message_inside_its_backoff_is_not_reported_as_due(): void
    {
        [$settlementId] = $this->queue(1);

        $this->assertNotNull($this->repository->oldestDueAt(), 'a freshly queued message is due immediately');

        $this->db->prepare('UPDATE mail_outbox SET next_attempt_at = DATE_ADD(NOW(), INTERVAL 4 HOUR) WHERE subject_id = ?')
            ->execute([$settlementId]);

        // Everything else pending is parked a day out for the duration of this
        // class, so with this message backed off there is nothing due at all.
        $dueAt = $this->repository->oldestDueAt();
        $this->assertTrue(
            $dueAt === null || $dueAt > date('Y-m-d H:i:s'),
            'a message waiting out its backoff must not be reported as due'
        );

        // And the row is unmistakably still old, which is what the old
        // predicate would have alarmed on.
        $this->db->prepare('UPDATE mail_outbox SET queued_at = DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE subject_id = ?')
            ->execute([$settlementId]);

        $stmt = $this->db->prepare(
            'SELECT MIN(next_attempt_at) FROM mail_outbox WHERE subject_id = ? AND status = ?'
        );
        $stmt->execute([$settlementId, MailStatus::PENDING->value]);
        $this->assertGreaterThan(date('Y-m-d H:i:s'), (string) $stmt->fetchColumn());
    }

    /** The numbers the self-check shows and the heartbeat ping carries. */
    public function test_the_status_counts_name_every_status_even_at_zero(): void
    {
        [$settlementId] = $this->queue(2);

        $before = $this->repository->countsByStatus();
        foreach (MailStatus::cases() as $status) {
            $this->assertArrayHasKey($status->value, $before, 'a missing key and a zero must not look alike');
        }

        $claimed = $this->repository->claimBatch(2);
        $this->repository->markSent($claimed[0]['id'], null);
        $this->repository->markFailed($claimed[1]['id'], '550 gone', 1, false, 0);

        $after = $this->repository->countsByStatus();
        $this->assertSame($before[MailStatus::SENT->value] + 1, $after[MailStatus::SENT->value]);
        $this->assertSame($before[MailStatus::FAILED->value] + 1, $after[MailStatus::FAILED->value]);
    }
}
