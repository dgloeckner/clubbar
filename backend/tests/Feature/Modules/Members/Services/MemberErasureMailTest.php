<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Members\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Members\Services\MembersService;
use App\Modules\Notifications\DTOs\MailRequestDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Enums\MailStatus;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Services\NotificationsService;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Settlements\Repositories\SettlementAnnouncementsRepository;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\Security\IbanSealedBox;
use App\Shared\Services\AuditService;
use Tests\Feature\DatabaseTestCase;

/**
 * Anonymisation reaches the outbox (#408, ADR-0029).
 *
 * The whole point of the issue in one sentence: the queue snapshots a member's
 * address so it can prove who was announced to, and clearing `members.email`
 * does not touch a snapshot. Without this, erasure would report success while
 * the address sat in a second table — the failure mode that is invisible
 * precisely because the thing that is supposed to report it says it worked.
 *
 * So this goes through the **real** `MembersService::anonymizeMember()`, the
 * real transaction, and asserts by selecting the column afterwards.
 */
class MemberErasureMailTest extends DatabaseTestCase
{
    private MembersService $service;
    private MailOutboxRepository $outbox;

    /** @var list<string> */
    private array $memberIds = [];
    /** @var list<string> */
    private array $settlementIds = [];
    /** @var list<string> */
    private array $adminIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureActiveEncryptionKey();

        $membersRepository = new MembersRepository(
            $this->db,
            $this->logger,
            new IbanSealedBox(str_repeat('0', 63) . '2', 'test'),
            new EncryptionKeysRepository($this->db, $this->logger),
        );
        $auditService = new AuditService(new AuditLogRepository($this->db, $this->logger));
        $this->outbox = new MailOutboxRepository($this->db, $this->logger);

        $this->service = new MembersService(
            $membersRepository,
            new TransactionsRepository($this->db, $this->logger),
            $auditService,
            new AuditLogRepository($this->db, $this->logger),
            new NotificationsService(
                $this->outbox,
                $membersRepository,
                $auditService,
                new AdminUsersRepository($this->db, $this->logger),
                new SettlementAnnouncementsRepository($this->db, $this->logger),
                $this->logger,
            ),
            $this->db,
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->settlementIds as $id) {
            $this->db->prepare('DELETE FROM mail_outbox WHERE subject_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM settlements WHERE id = ?')->execute([$id]);
        }
        foreach ($this->memberIds as $id) {
            $this->db->prepare('DELETE FROM audit_log WHERE entity_id = ?')->execute([$id]);
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
        )->execute([$id, 'Erasure', 'Test', $id . '@example.com', 'de']);

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
            'INSERT INTO settlements (id, settlement_date, execution_date, total_amount_cents, member_count, created_by_admin_id, is_cancelled)
             VALUES (?, ?, ?, ?, ?, ?, 0)'
        )->execute([$id, '2026-01-05', '2026-01-19', 500, 1, $adminId]);

        return $id;
    }

    private function queue(string $settlementId, string $memberId, MailStatus $status): void
    {
        $this->outbox->enqueue(MailRequestDto::forMember(
            kind: MailKind::SEPA_PRENOTIFICATION,
            settlementId: $settlementId,
            memberId: $memberId,
            recipient: $memberId . '@example.com',
            language: MailLanguage::German,
        ));

        if ($status !== MailStatus::PENDING) {
            $this->db->prepare(
                'UPDATE mail_outbox SET status = ?, sent_at = NOW() WHERE subject_id = ? AND member_id = ?'
            )->execute([$status->value, $settlementId, $memberId]);
        }
    }

    /** @return list<array<string,mixed>> */
    private function rowsFor(string $memberId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM mail_outbox WHERE member_id = ?');
        $stmt->execute([$memberId]);

        return $stmt->fetchAll();
    }

    /**
     * The acceptance criterion, asserted by querying the column rather than by
     * trusting the service that claims to have cleared it.
     */
    public function test_anonymising_a_member_leaves_no_address_in_the_outbox(): void
    {
        $settlementId = $this->settlement();
        $memberId = $this->member();
        $bystander = $this->member();

        $this->queue($settlementId, $memberId, MailStatus::SENT);
        $this->queue($settlementId, $bystander, MailStatus::SENT);

        $this->service->anonymizeMember($memberId);

        foreach ($this->rowsFor($memberId) as $row) {
            $this->assertSame('', $row['recipient']);
        }
        // Erasing one member is not a licence to empty the queue.
        $this->assertSame($bystander . '@example.com', $this->rowsFor($bystander)[0]['recipient']);

        // And the member record itself went, which is what makes the above an
        // erasure rather than an unrelated UPDATE that happened to run.
        $stmt = $this->db->prepare('SELECT email, deleted_at FROM members WHERE id = ?');
        $stmt->execute([$memberId]);
        $member = $stmt->fetch();
        $this->assertNull($member['email']);
        $this->assertNotNull($member['deleted_at']);
    }

    /**
     * A message still queued for an erased member is withdrawn, deterministically.
     *
     * Left `pending`, it would be claimed by the next drain, addressed to an
     * empty string, and reported as a delivery failure against somebody who no
     * longer exists.
     */
    public function test_a_pending_message_is_superseded_rather_than_left_addressed_to_nobody(): void
    {
        $settlementId = $this->settlement();
        $memberId = $this->member();

        $this->queue($settlementId, $memberId, MailStatus::PENDING);

        $this->service->anonymizeMember($memberId);

        $rows = $this->rowsFor($memberId);
        $this->assertCount(1, $rows);
        $this->assertSame(MailStatus::SUPERSEDED->value, $rows[0]['status']);
        $this->assertSame('', $rows[0]['recipient']);
    }
}
