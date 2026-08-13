<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Security\Services\EncryptionKeyService;
use App\Modules\Security\Services\IbanEncryptionMigrationService;
use App\Shared\Security\IbanSealedBox;
use App\Shared\Services\AuditService;
use Tests\Feature\DatabaseTestCase;

class IbanEncryptionMigrationServiceTest extends DatabaseTestCase
{
    private const FINGERPRINT_KEY = '0000000000000000000000000000000000000000000000000000000000000002';

    private IbanEncryptionMigrationService $service;
    private IbanSealedBox $box;
    private array $memberIds = [];
    private array $mandateIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->box = new IbanSealedBox(self::FINGERPRINT_KEY, 'test');
        $keysRepository = new EncryptionKeysRepository($this->db, $this->logger);
        $this->service = new IbanEncryptionMigrationService(
            $this->db,
            $this->box,
            new EncryptionKeyService($keysRepository, $this->box, $this->createMock(AuditService::class)),
            $this->createMock(AuditService::class),
            null,
        );
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData('mandates', $this->mandateIds);
        $this->cleanupTestData('members', $this->memberIds);
        parent::tearDown();
    }

    /** Insert a legacy plaintext mandate the way reset_test_data.sql does. */
    private function insertLegacyMandate(string $iban): array
    {
        $memberId = $this->generateUuid();
        $mandateId = $this->generateUuid();
        $this->memberIds[] = $memberId;
        $this->mandateIds[] = $mandateId;

        $this->db->prepare(
            "INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active, created_at, updated_at)
             VALUES (?, 'Legacy', 'Row', ?, 'de', 1, NOW(), NOW())"
        )->execute([$memberId, "legacy-{$memberId}@example.com"]);

        $this->db->prepare(
            'INSERT INTO mandates (id, member_id, active_member_id, reference, iban, signed_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$mandateId, $memberId, $memberId, 'LEG' . substr(str_replace('-', '', $mandateId), 0, 20), $iban, '2024-01-01']);

        return ['member_id' => $memberId, 'mandate_id' => $mandateId];
    }

    public function testBatchSealsLegacyRowsAndNullsPlaintext(): void
    {
        $row = $this->insertLegacyMandate('DE89370400440532013000');

        $result = $this->service->encryptBatch();

        $this->assertGreaterThanOrEqual(1, $result['processed']);

        $stmt = $this->db->prepare('SELECT * FROM mandates WHERE id = ?');
        $stmt->execute([$row['mandate_id']]);
        $mandate = $stmt->fetch();

        $this->assertNull($mandate['iban'], 'plaintext must be gone after sealing');
        $this->assertStringStartsWith('v1:', (string) $mandate['iban_ciphertext']);
        $this->assertSame('3000', $mandate['iban_last4']);
        $this->assertSame($this->box->fingerprint('DE89370400440532013000'), $mandate['iban_fingerprint']);
        $this->assertNotNull($mandate['encryption_key_id']);
    }

    public function testBatchIsIdempotent(): void
    {
        $row = $this->insertLegacyMandate('DE02120300000000202051');

        $this->service->encryptBatch();

        $stmt = $this->db->prepare('SELECT iban_ciphertext FROM mandates WHERE id = ?');
        $stmt->execute([$row['mandate_id']]);
        $firstCiphertext = $stmt->fetch()['iban_ciphertext'];

        // Second run finds no plaintext left on this row and must not touch it.
        $this->service->encryptBatch();

        $stmt->execute([$row['mandate_id']]);
        $this->assertSame($firstCiphertext, $stmt->fetch()['iban_ciphertext']);
    }

    public function testSealedRowOpensWithTheDevPrivateKey(): void
    {
        $row = $this->insertLegacyMandate('DE89370400440532013000');
        $this->service->encryptBatch();

        $stmt = $this->db->prepare('SELECT iban_ciphertext FROM mandates WHERE id = ?');
        $stmt->execute([$row['mandate_id']]);
        $ciphertext = $stmt->fetch()['iban_ciphertext'];

        // The dev keypair published in the repository (docker-compose seeds
        // its public half as the ACTIVE key).
        $devSecret = hex2bin('f678fb17b592c29db54e43f808ee74fd67f7dd5c6c405b24e3e31ead38f3058a');

        $this->assertSame('DE89370400440532013000', $this->box->open($ciphertext, $devSecret));
    }

    public function testLegacyPlaintextRowStillReadsThroughTheJoin(): void
    {
        $row = $this->insertLegacyMandate('DE89370400440532013000');

        $membersRepository = new MembersRepository(
            $this->db,
            $this->logger,
            $this->box,
            new EncryptionKeysRepository($this->db, $this->logger),
        );

        $member = $membersRepository->findById($row['member_id']);

        $this->assertSame('3000', $member['iban_last4'], 'last4 is derived on read for unsealed legacy rows');
        $this->assertNotEmpty($member['has_iban']);
    }

    public function testCountRemainingTracksPlaintextRows(): void
    {
        $before = $this->service->countRemaining();
        $this->insertLegacyMandate('DE89370400440532013000');

        $this->assertSame($before + 1, $this->service->countRemaining());

        $this->service->encryptBatch();
        // Our row is sealed; anything remaining belongs to other fixtures.
        $stmt = $this->db->prepare('SELECT iban FROM mandates WHERE id = ?');
        $stmt->execute([$this->mandateIds[0]]);
        $this->assertNull($stmt->fetch()['iban']);
    }
}
