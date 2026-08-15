<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Settlements\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Modules\Instance\Repositories\InstanceConfigRepository;
use App\Modules\Instance\Services\InstanceConfigService;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailStatus;
use App\Modules\Notifications\Repositories\MailConfigRepository;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Services\MailConfigService;
use App\Modules\Notifications\Services\NotificationsService;
use App\Modules\Notifications\Services\SettlementMailBuilder;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Modules\Settlements\Repositories\SettlementAnnouncementsRepository;
use App\Modules\Settlements\Repositories\SettlementReversalsRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Settlements\Services\SettlementsService;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\Config\AppConfig;
use App\Shared\Mail\MailTransportFactory;
use App\Shared\Security\IbanSealedBox;
use App\Shared\Services\AuditService;
use Tests\Feature\DatabaseTestCase;

/**
 * Finalizing a settlement queues the announcements, and cancelling it splits
 * them on what each member already knows (ADR-0038, #402/#404).
 *
 * Everything here is asserted through a real database because that is where
 * the guarantees live: the uniqueness of a message, the transaction that binds
 * the queue to the settlement, and the fact that a cancellation can see which
 * announcements went out. The service-level policy is pinned with mocks in
 * `Tests\Unit\...\NotificationsServiceTest`; this is the chain.
 *
 * Nothing is sent. There is no drain yet (#403), and the enqueue makes no
 * network call by design — which is exactly why this test needs no transport.
 */
class SettlementAnnouncementTest extends DatabaseTestCase
{
    private SettlementsService $service;
    private MailOutboxRepository $outbox;
    private SettlementMailBuilder $builder;

    private array $testMemberIds = [];
    private array $testAdminUserIds = [];
    private array $testCategoryIds = [];
    private array $testProductIds = [];
    private array $testTransactionIds = [];
    private array $testSettlementIds = [];

    private string $adminId;

    /** @var array<string,mixed>|null Restored in tearDown when a test rewrote it. */
    private ?array $originalSepaConfig = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureActiveEncryptionKey();

        $settlementsRepository = new SettlementsRepository($this->db, $this->logger);
        $membersRepository = new MembersRepository(
            $this->db,
            $this->logger,
            new IbanSealedBox(str_repeat('0', 63) . '2', 'test'),
            new EncryptionKeysRepository($this->db, $this->logger),
        );
        $auditService = new AuditService(new AuditLogRepository($this->db, $this->logger));

        $this->outbox = new MailOutboxRepository($this->db, $this->logger);

        $this->service = new SettlementsService(
            $settlementsRepository,
            $membersRepository,
            new TransactionsRepository($this->db, $this->logger),
            $auditService,
            $this->db,
            new SettlementReversalsRepository($this->db, $this->logger),
            new NotificationsService(
                $this->outbox,
                $membersRepository,
                $auditService,
                new AdminUsersRepository($this->db, $this->logger),
                new SettlementAnnouncementsRepository($this->db, $this->logger),
                $this->logger,
            ),
            $this->ensureObservedSchedulerRun(),
            new SettlementAnnouncementsRepository($this->db, $this->logger),
        );

        $mailConfigService = new MailConfigService(
            new MailConfigRepository($this->db, $this->logger),
            new InstanceConfigService(new InstanceConfigRepository($this->db, $this->logger), $auditService),
            new MailTransportFactory(new AppConfig(), $this->logger),
            $auditService,
        );

        $this->builder = new SettlementMailBuilder(
            $settlementsRepository,
            $membersRepository,
            new SepaConfigRepository($this->db, $this->logger),
            $mailConfigService,
        );

        $this->adminId = $this->createTestAdminUser();
    }

    protected function tearDown(): void
    {
        if ($this->originalSepaConfig !== null) {
            $this->db->prepare(
                'UPDATE sepa_config SET creditor_id = ?, creditor_name = ?, creditor_address_street = ?, creditor_address_city = ? WHERE id = 1'
            )->execute([
                $this->originalSepaConfig['creditor_id'] ?? null,
                $this->originalSepaConfig['creditor_name'] ?? null,
                $this->originalSepaConfig['creditor_address_street'] ?? null,
                $this->originalSepaConfig['creditor_address_city'] ?? null,
            ]);
            $this->originalSepaConfig = null;
        }

        if (!empty($this->testSettlementIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testSettlementIds), '?'));
            $this->db->prepare("DELETE FROM mail_outbox WHERE subject_id IN ({$placeholders})")
                ->execute($this->testSettlementIds);
            $this->db->prepare("DELETE FROM settlement_items WHERE settlement_id IN ({$placeholders})")
                ->execute($this->testSettlementIds);
            $this->db->prepare("DELETE FROM audit_log WHERE entity_id IN ({$placeholders})")
                ->execute($this->testSettlementIds);
        }

        if (!empty($this->testMemberIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testMemberIds), '?'));
            $this->db->prepare("DELETE FROM mandates WHERE member_id IN ({$placeholders})")
                ->execute($this->testMemberIds);
        }

        $this->cleanupTestData('settlements', $this->testSettlementIds);
        $this->cleanupTestData('transactions', $this->testTransactionIds);
        $this->cleanupTestData('products', $this->testProductIds);
        $this->cleanupTestData('categories', $this->testCategoryIds);
        $this->cleanupTestData('members', $this->testMemberIds);
        $this->cleanupTestData('admin_users', $this->testAdminUserIds);

        parent::tearDown();
    }

    // ── Finalizing announces ──────────────────────────────────────────

    public function test_finalizing_queues_one_announcement_per_collected_member(): void
    {
        $one = $this->collectableMember('Anna');
        $two = $this->collectableMember('Bernd');

        $settlementId = $this->finalize([
            $this->purchase($one, 250),
            $this->purchase($one, 180),
            $this->purchase($two, 500),
        ]);

        $rows = $this->outbox->findBySubjectId($settlementId);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(MailKind::SEPA_PRENOTIFICATION->value, $row['kind']);
            $this->assertSame(MailStatus::PENDING->value, $row['status']);
            $this->assertSame('de', $row['language']);
            $this->assertNull($row['sent_at'], 'the finalize makes no network call — nothing can be sent yet');
        }

        $recipients = array_column($rows, 'recipient', 'member_id');
        $this->assertSame($this->emailOf($one), $recipients[$one]);
        $this->assertSame($this->emailOf($two), $recipients[$two]);
    }

    /**
     * The idempotency criterion of #361, at the level a treasurer would hit it:
     * a finalize whose response was lost, retried.
     */
    public function test_re_announcing_a_settlement_produces_no_second_message(): void
    {
        $memberId = $this->collectableMember('Carla');
        $settlementId = $this->finalize([$this->purchase($memberId, 400)]);

        // The same enqueue again, exactly as a retried finalize would run it.
        $notifications = new NotificationsService(
            $this->outbox,
            new MembersRepository(
                $this->db,
                $this->logger,
                new IbanSealedBox(str_repeat('0', 63) . '2', 'test'),
                new EncryptionKeysRepository($this->db, $this->logger),
            ),
            new AuditService(new AuditLogRepository($this->db, $this->logger)),
            new AdminUsersRepository($this->db, $this->logger),
            new SettlementAnnouncementsRepository($this->db, $this->logger),
            $this->logger,
        );
        $result = $notifications->enqueueForSettlement($settlementId, [$memberId => 400], $this->adminId);

        $this->assertSame(0, $result->queued);
        $this->assertCount(1, $this->outbox->findBySubjectId($settlementId));
    }

    public function test_a_member_without_an_email_is_reported_and_never_blocks_the_collection(): void
    {
        $reachable = $this->collectableMember('Dora');
        $unreachable = $this->collectableMember('Emil', email: null);

        $settlementId = $this->finalize([
            $this->purchase($reachable, 250),
            $this->purchase($unreachable, 250),
        ]);

        $rows = $this->outbox->findBySubjectId($settlementId);
        $this->assertCount(1, $rows, 'there is no address to announce to');
        $this->assertSame($reachable, $rows[0]['member_id']);

        // The collection itself went ahead — this member still owes the money.
        $settlement = $this->service->getSettlement($settlementId);
        $this->assertSame(500, $settlement->totalAmountCents);
        $this->assertSame(2, $settlement->memberCount);

        // And the omission is on the record.
        $entry = $this->auditEntriesFor($settlementId, 'mail_enqueued')[0] ?? null;
        $this->assertNotNull($entry);
        $this->assertStringContainsString($unreachable, (string) $entry['new_values']);
    }

    public function test_a_bank_transfer_settlement_announces_nothing(): void
    {
        $memberId = $this->collectableMember('Frida');

        $settlementId = $this->finalize(
            [$this->purchase($memberId, 700)],
            SettlementMethod::BANK_TRANSFER,
        );

        $this->assertSame(
            [],
            $this->outbox->findBySubjectId($settlementId),
            'a bank transfer needs a payment request, which is #410 and is not a pre-notification'
        );
    }

    public function test_the_enqueue_is_audited_against_the_settlement(): void
    {
        $memberId = $this->collectableMember('Greta');
        $settlementId = $this->finalize([$this->purchase($memberId, 900)]);

        $entries = $this->auditEntriesFor($settlementId, 'mail_enqueued');

        $this->assertCount(1, $entries);
        $this->assertSame($this->adminId, $entries[0]['admin_user_id']);
        $this->assertStringContainsString(MailKind::SEPA_PRENOTIFICATION->value, (string) $entries[0]['new_values']);
    }

    // ── Cancelling retracts, but only what went out ────────────────────

    public function test_cancelling_before_the_drain_supersedes_and_tells_nobody(): void
    {
        $memberId = $this->collectableMember('Hans');
        $settlementId = $this->finalize([$this->purchase($memberId, 300)]);

        $this->service->cancelSettlement($settlementId, $this->adminId, 'wrong period');

        $rows = $this->outbox->findBySubjectId($settlementId);
        $this->assertCount(1, $rows, 'no cancellation notice for an announcement nobody received');
        $this->assertSame(MailStatus::SUPERSEDED->value, $rows[0]['status']);
    }

    public function test_cancelling_after_the_announcement_went_out_retracts_it(): void
    {
        $told = $this->collectableMember('Ilse');
        $notYetTold = $this->collectableMember('Jonas');

        $settlementId = $this->finalize([
            $this->purchase($told, 300),
            $this->purchase($notYetTold, 400),
        ]);

        // One announcement has left the host; the other has not.
        $this->db->prepare("UPDATE mail_outbox SET status = 'sent', sent_at = NOW() WHERE subject_id = ? AND member_id = ?")
            ->execute([$settlementId, $told]);

        $this->service->cancelSettlement($settlementId, $this->adminId, 'entered twice');

        $byMemberAndKind = [];
        foreach ($this->outbox->findBySubjectId($settlementId) as $row) {
            $byMemberAndKind[$row['member_id'] . '/' . $row['kind']] = $row['status'];
        }

        $this->assertSame(
            MailStatus::SENT->value,
            $byMemberAndKind[$told . '/' . MailKind::SEPA_PRENOTIFICATION->value],
            'a sent announcement stays sent — it happened'
        );
        $this->assertSame(
            MailStatus::PENDING->value,
            $byMemberAndKind[$told . '/' . MailKind::CANCELLATION_NOTICE->value],
            'and the member who was told gets the retraction'
        );
        $this->assertSame(
            MailStatus::SUPERSEDED->value,
            $byMemberAndKind[$notYetTold . '/' . MailKind::SEPA_PRENOTIFICATION->value],
        );
        $this->assertArrayNotHasKey(
            $notYetTold . '/' . MailKind::CANCELLATION_NOTICE->value,
            $byMemberAndKind,
            'telling somebody a collection is off that they were never told about is worse than saying nothing'
        );
    }

    // ── What the queued row renders into ──────────────────────────────

    /**
     * The render-at-send half of ADR-0038: the outbox stores no body, so this
     * is the assertion that a queued row can still become a complete message
     * — with the member's own line items, reassembled from the settlement.
     */
    /**
     * The acceptance criterion that outlives the queue (#408).
     *
     * *"Per-member sent timestamp visible in the settlement detail"* must not
     * depend on a row that pruning removes — so this test deletes the queue row
     * outright, exactly as pruning does, and reads the detail again.
     */
    public function test_the_settlement_detail_still_shows_the_announcement_after_pruning(): void
    {
        $memberId = $this->collectableMember('Fritz');
        $settlementId = $this->finalize([$this->purchase($memberId, 500)]);

        // Delivery, as the drain records it: the queue row is marked and the
        // settlement-side record is written from it.
        $sentAt = date('Y-m-d H:i:s');
        $this->db->prepare(
            "UPDATE mail_outbox SET status = 'sent', sent_at = ? WHERE subject_id = ? AND member_id = ?"
        )->execute([$sentAt, $settlementId, $memberId]);
        (new SettlementAnnouncementsRepository($this->db, $this->logger))
            ->record($settlementId, $memberId, MailKind::SEPA_PRENOTIFICATION, $sentAt);

        $before = $this->service->getSettlement($settlementId);
        $this->assertCount(1, $before->notifications);
        $this->assertCount(1, $before->announcements);

        // Pruning, ninety days later.
        $this->db->prepare('DELETE FROM mail_outbox WHERE subject_id = ?')->execute([$settlementId]);

        $after = $this->service->getSettlement($settlementId);
        $this->assertSame([], $after->notifications, 'the queue row is gone, as retention requires');
        $this->assertCount(1, $after->announcements, 'and the proof that the member was told is not');
        $this->assertSame($memberId, $after->announcements[0]['member_id']);
        $this->assertSame($sentAt, $after->announcements[0]['sent_at']);
        $this->assertSame(MailKind::SEPA_PRENOTIFICATION->value, $after->announcements[0]['kind']);
    }

    public function test_a_queued_row_renders_into_a_complete_announcement(): void
    {
        $this->configureCreditor();

        $memberId = $this->collectableMember('Klara');
        $settlementId = $this->finalize([
            $this->purchase($memberId, 250),
            $this->purchase($memberId, 180),
        ]);

        $row = $this->outbox->findBySubjectId($settlementId)[0];
        $message = $this->builder->build($row);

        $this->assertSame($this->emailOf($memberId), $message->to);

        foreach ([$message->html, $message->text] as $part) {
            $this->assertStringContainsString('DE43ZZZ00000548506', $part, 'creditor identifier');
            $this->assertStringContainsString($this->mandateReferenceOf($memberId), $part, 'mandate reference');
            $this->assertStringContainsString('4,30 €', $part, 'the amount this run collects');
            $this->assertStringContainsString('3000', $part, 'the masked account');
            $this->assertStringContainsString('Bier', $part, 'the itemised statement');
            // The club addresses its members by first name, in the Du-form.
            $this->assertStringContainsString('Hallo Klara,', $part);
            $this->assertStringNotContainsString('Hallo Klara Announced', $part);
        }

        // The due date the member is told is the settlement's execution date,
        // which SettlementLeadTime already put at least seven days out — that
        // is how the § 7 Abs. 3 promise is kept by construction.
        $executionDate = $this->service->getSettlement($settlementId)->executionDate;
        $this->assertStringContainsString(
            date('d.m.Y', strtotime($executionDate)),
            $message->text,
        );
        $this->assertGreaterThanOrEqual(
            7,
            (int) ((strtotime($executionDate) - strtotime(date('Y-m-d'))) / 86400),
        );
    }

    public function test_a_cancellation_row_renders_without_a_mandate_reference(): void
    {
        $this->configureCreditor();

        $memberId = $this->collectableMember('Lena');
        $settlementId = $this->finalize([$this->purchase($memberId, 600)]);

        $this->db->prepare("UPDATE mail_outbox SET status = 'sent', sent_at = NOW() WHERE subject_id = ?")
            ->execute([$settlementId]);
        $this->service->cancelSettlement($settlementId, $this->adminId, 'called off');

        $notice = null;
        foreach ($this->outbox->findBySubjectId($settlementId) as $row) {
            if ($row['kind'] === MailKind::CANCELLATION_NOTICE->value) {
                $notice = $row;
            }
        }
        $this->assertNotNull($notice);

        $message = $this->builder->build($notice);

        $this->assertStringContainsString('entfällt', $message->subject);
        $this->assertStringContainsString('6,00 €', $message->text);
        $this->assertStringNotContainsString($this->mandateReferenceOf($memberId), $message->text);
        $this->assertStringNotContainsString('DE43ZZZ00000548506', $message->text);
    }

    public function test_an_english_member_is_announced_to_in_english(): void
    {
        $memberId = $this->collectableMember('Mika', language: 'en');
        $settlementId = $this->finalize([$this->purchase($memberId, 250)]);

        $row = $this->outbox->findBySubjectId($settlementId)[0];
        $this->assertSame('en', $row['language']);

        $message = $this->builder->build($row);
        $this->assertStringContainsString('EUR 2.50', $message->text);
        $this->assertStringContainsString('Advance notice', $message->subject);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /** @param list<string> $transactionIds */
    private function finalize(array $transactionIds, SettlementMethod $method = SettlementMethod::DIRECT_DEBIT): string
    {
        $dto = $this->service->createSettlement(
            transactionIds: $transactionIds,
            executionDate: date('Y-m-d', strtotime('+14 days')),
            periodStart: null,
            periodEnd: null,
            method: $method,
            notes: null,
            adminUserId: $this->adminId,
        );

        $this->testSettlementIds[] = $dto->id;

        return $dto->id;
    }

    private function collectableMember(string $firstName, ?string $email = 'auto', string $language = 'de'): string
    {
        $memberId = $this->generateUuid();
        $this->testMemberIds[] = $memberId;

        $address = $email === 'auto'
            ? strtolower($firstName) . '-' . substr($memberId, 0, 8) . '@example.com'
            : $email;

        $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$memberId, $firstName, 'Announced', $address, $language]);

        // SEPA eligibility is an active mandate (#164); without one the run
        // refuses the member long before any announcement is considered.
        $this->db->prepare(
            'INSERT INTO mandates (id, member_id, active_member_id, reference, iban_ciphertext, iban_last4, iban_fingerprint, encryption_key_id, signed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->generateUuid(),
            $memberId,
            $memberId,
            strtoupper(str_replace('-', '', $memberId)),
            $this->sealIban('DE89370400440532013000'),
            '3000',
            $this->ibanFingerprint('DE89370400440532013000'),
            self::DEV_KEY_ID,
            '2024-01-01',
        ]);

        return $memberId;
    }

    private function purchase(string $memberId, int $amountCents): string
    {
        $categoryId = $this->generateUuid();
        $this->testCategoryIds[] = $categoryId;
        $this->db->prepare('INSERT INTO categories (id, names, is_active) VALUES (?, ?, 1)')
            ->execute([$categoryId, json_encode(['de' => 'Getränke', 'en' => 'Drinks'])]);

        $productId = $this->generateUuid();
        $this->testProductIds[] = $productId;
        $this->db->prepare(
            'INSERT INTO products (id, category_id, names, price_cents, is_active) VALUES (?, ?, ?, ?, 1)'
        )->execute([$productId, $categoryId, json_encode(['de' => 'Bier', 'en' => 'Beer']), $amountCents]);

        $transactionId = $this->generateUuid();
        $this->testTransactionIds[] = $transactionId;
        $this->db->prepare(
            'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$transactionId, $memberId, $productId, $amountCents, 'purchase', date('Y-m-d H:i:s')]);

        return $transactionId;
    }

    /**
     * The creditor block is club configuration; without it the mail omits it
     * rather than failing.
     *
     * `sepa_config` is a singleton shared with every other test in the suite,
     * so the previous row is kept and restored in tearDown — a test that
     * quietly rewrites club configuration is the shared-state failure of E2E
     * pattern 001, one layer down.
     */
    private function configureCreditor(): void
    {
        $this->originalSepaConfig ??= $this->db->query('SELECT * FROM sepa_config WHERE id = 1')->fetch() ?: [];

        $this->db->prepare(
            'UPDATE sepa_config SET creditor_id = ?, creditor_name = ?, creditor_address_street = ?, creditor_address_city = ? WHERE id = 1'
        )->execute([
            'DE43ZZZ00000548506',
            'Beispiel-Ruderverein e.V.',
            'Musterweg 35',
            '60599 Frankfurt am Main',
        ]);
    }

    private function emailOf(string $memberId): string
    {
        $stmt = $this->db->prepare('SELECT email FROM members WHERE id = ?');
        $stmt->execute([$memberId]);

        return (string) $stmt->fetchColumn();
    }

    private function mandateReferenceOf(string $memberId): string
    {
        $stmt = $this->db->prepare('SELECT reference FROM mandates WHERE active_member_id = ?');
        $stmt->execute([$memberId]);

        return (string) $stmt->fetchColumn();
    }

    private function createTestAdminUser(): string
    {
        $adminId = $this->generateUuid();
        $this->testAdminUserIds[] = $adminId;

        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, is_active) VALUES (?, ?, ?, ?, 1)'
        )->execute([
            $adminId,
            'announce-' . substr($adminId, 0, 8) . '@example.com',
            password_hash('test123', PASSWORD_BCRYPT),
            'Announce Admin',
        ]);

        return $adminId;
    }

    /** @return list<array<string, mixed>> */
    private function auditEntriesFor(string $settlementId, string $action): array
    {
        $stmt = $this->db->prepare('SELECT * FROM audit_log WHERE entity_id = ? AND action = ?');
        $stmt->execute([$settlementId, $action]);

        return $stmt->fetchAll();
    }
}
