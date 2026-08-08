<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Settlements\Services;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Settlements\Enums\SettlementMethod;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Settlements\Services\SepaExportService;
use Tests\Feature\DatabaseTestCase;

/**
 * The export writes down what it sent (#150).
 *
 * A bank return quotes the EndToEndId back as `EREF+` and nothing else usable
 * survives the R-transaction, so the identifier is worthless unless the
 * database can be asked "which collection was this?". These tests exercise the
 * real repositories against the real schema — the point of the change is a row
 * that exists afterwards, which a mock cannot demonstrate.
 *
 * `sepa_config` is the one collaborator left stubbed: it is a singleton row
 * shared by every test, and rewriting it here would break tests running beside
 * this one.
 */
class SepaExportPersistenceTest extends DatabaseTestCase
{
    private SepaExportService $service;
    private SettlementsRepository $settlementsRepository;

    /** @var array<string, list<string>> table => ids */
    private array $created = [];

    /** Reverse FK order. */
    private const CLEANUP_ORDER = [
        'settlements', 'transactions', 'mandates', 'products', 'categories', 'members', 'admin_users',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->settlementsRepository = new SettlementsRepository($this->db, $this->logger);

        $sepaConfig = $this->createMock(SepaConfigRepository::class);
        $sepaConfig->method('getConfig')->willReturn([
            'creditor_id' => 'DE98ZZZ09999999999',
            'creditor_name' => 'Ruderclub',
            'creditor_iban' => 'DE89370400440532013000',
            'payment_reference_prefix' => null,
        ]);

        $this->service = new SepaExportService(
            $sepaConfig,
            new MembersRepository($this->db, $this->logger),
            $this->settlementsRepository,
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->created['settlements'] ?? [] as $settlementId) {
            $this->db->prepare('DELETE FROM settlement_items WHERE settlement_id = ?')->execute([$settlementId]);
        }
        foreach (self::CLEANUP_ORDER as $table) {
            $this->cleanupTestData($table, $this->created[$table] ?? []);
        }
        $this->created = [];
        parent::tearDown();
    }

    public function test_exporting_records_the_end_to_end_id_on_every_item_of_the_collection(): void
    {
        $memberId = $this->createMemberWithMandate();
        $settlementId = $this->createSettlementWithItems($memberId, [1500, 350]);

        $this->service->export($settlementId);

        $expected = 'E2E-' . $this->half($settlementId) . '-' . $this->half($memberId);
        $this->assertSame(
            [$expected, $expected],
            $this->storedEndToEndIds($settlementId),
            'both items belong to the one collection line the bank sees, so both carry its identifier'
        );
    }

    public function test_the_stored_identifier_is_the_one_the_file_carries(): void
    {
        $memberId = $this->createMemberWithMandate();
        $settlementId = $this->createSettlementWithItems($memberId, [1500]);

        $xml = $this->service->export($settlementId)->xml;

        $stored = $this->storedEndToEndIds($settlementId)[0];
        $this->assertStringContainsString(
            '<EndToEndId>' . $stored . '</EndToEndId>',
            $xml,
            'a stored value the bank never saw would resolve nothing'
        );
    }

    public function test_re_exporting_leaves_the_identifier_untouched(): void
    {
        $memberId = $this->createMemberWithMandate();
        $settlementId = $this->createSettlementWithItems($memberId, [1500]);

        $this->service->export($settlementId);
        $first = $this->storedEndToEndIds($settlementId);

        $this->service->export($settlementId);

        $this->assertSame(
            $first,
            $this->storedEndToEndIds($settlementId),
            're-running the export must not invalidate an identifier already at the bank (#150)'
        );
    }

    public function test_a_member_in_credit_gets_no_identifier_because_no_collection_was_sent(): void
    {
        // Ruling #141: a net credit carries no file line. There is nothing for
        // the bank to return, so there is nothing to resolve — writing an
        // identifier here would invent a collection that never happened.
        $collected = $this->createMemberWithMandate();
        $inCredit = $this->createMemberWithMandate();
        $settlementId = $this->createSettlementWithItems($collected, [1500]);
        $this->addItems($settlementId, $inCredit, [-2000]);

        $this->service->export($settlementId);

        $this->assertNull(
            $this->storedEndToEndIdFor($settlementId, $inCredit),
            'no collection line was sent for a member in credit'
        );
        $this->assertNotNull($this->storedEndToEndIdFor($settlementId, $collected));
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function track(string $table, string $id): string
    {
        $this->created[$table][] = $id;
        return $id;
    }

    private function half(string $uuid): string
    {
        return substr(str_replace('-', '', $uuid), 0, 12);
    }

    /** @return list<string> in item insertion order */
    private function storedEndToEndIds(string $settlementId): array
    {
        $stmt = $this->db->prepare(
            'SELECT end_to_end_id FROM settlement_items WHERE settlement_id = ? ORDER BY id'
        );
        $stmt->execute([$settlementId]);

        return array_map(
            static fn(array $row): ?string => $row['end_to_end_id'],
            $stmt->fetchAll()
        );
    }

    private function storedEndToEndIdFor(string $settlementId, string $memberId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT end_to_end_id FROM settlement_items WHERE settlement_id = ? AND member_id = ?'
        );
        $stmt->execute([$settlementId, $memberId]);

        return $stmt->fetchColumn() ?: null;
    }

    private function createMemberWithMandate(): string
    {
        $memberId = $this->track('members', $this->generateUuid());
        $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active)
             VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$memberId, 'Ada', 'Lovelace', $memberId . '@example.com', 'de']);

        // is_active is a generated column — derived from ended_at, not written.
        $mandateId = $this->track('mandates', $this->generateUuid());
        $this->db->prepare(
            'INSERT INTO mandates (id, member_id, active_member_id, reference, iban, signed_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $mandateId,
            $memberId,
            $memberId,
            'MND' . strtoupper(substr(str_replace('-', '', $memberId), 0, 20)),
            'DE02120300000000202051',
            '2025-01-15',
        ]);

        return $memberId;
    }

    /** @param list<int> $amounts signed cents, one settlement item each */
    private function createSettlementWithItems(string $memberId, array $amounts): string
    {
        $adminId = $this->track('admin_users', $this->generateUuid());
        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, is_active) VALUES (?, ?, ?, 1)'
        )->execute([$adminId, $adminId . '@example.com', password_hash('test123', PASSWORD_BCRYPT)]);

        $settlement = $this->settlementsRepository->create([
            'id' => $this->track('settlements', $this->generateUuid()),
            'method' => SettlementMethod::DIRECT_DEBIT->value,
            'settlement_date' => '2026-08-06',
            // A Thursday: BankingCalendar refuses anything that is not a
            // TARGET2 business day (#11).
            'execution_date' => '2026-08-20',
            'total_amount_cents' => array_sum($amounts),
            'member_count' => 1,
            'created_by_admin_id' => $adminId,
        ]);

        $this->addItems($settlement['id'], $memberId, $amounts);

        return $settlement['id'];
    }

    /** @param list<int> $amounts */
    private function addItems(string $settlementId, string $memberId, array $amounts): void
    {
        $categoryId = $this->track('categories', $this->generateUuid());
        $this->db->prepare('INSERT INTO categories (id, names, is_active) VALUES (?, ?, 1)')
            ->execute([$categoryId, json_encode(['de' => 'Getränke', 'en' => 'Drinks'])]);

        $productId = $this->track('products', $this->generateUuid());
        $this->db->prepare(
            'INSERT INTO products (id, category_id, names, price_cents, is_active) VALUES (?, ?, ?, ?, 1)'
        )->execute([$productId, $categoryId, json_encode(['de' => 'Bier', 'en' => 'Beer']), 350]);

        foreach ($amounts as $amountCents) {
            $transactionId = $this->track('transactions', $this->generateUuid());
            $this->db->prepare(
                'INSERT INTO transactions (id, member_id, product_id, amount_cents, transaction_type, occurred_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$transactionId, $memberId, $productId, $amountCents, 'purchase', '2026-08-01 18:00:00']);

            $this->settlementsRepository->createItem([
                'settlement_id' => $settlementId,
                'transaction_id' => $transactionId,
                'member_id' => $memberId,
                'amount_cents' => $amountCents,
            ]);
        }
    }
}
