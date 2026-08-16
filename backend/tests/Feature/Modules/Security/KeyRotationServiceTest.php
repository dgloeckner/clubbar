<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Modules\Notifications\Services\NotificationsService;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Security\Repositories\SealedIbanRepository;
use App\Modules\Security\Services\EncryptionKeyService;
use App\Modules\Security\Services\KeyRotationService;
use App\Shared\Security\IbanSealedBox;
use App\Shared\Services\AuditService;
use Tests\Feature\DatabaseTestCase;

/**
 * Rotating every stored IBAN from one key generation to the next (#394,
 * ADR-0036).
 *
 * The properties under test are the ones a club depends on when it does this
 * once a year, on a shared host, from a browser tab that might be closed:
 * that stopping halfway loses nothing, that a member edit landing mid-rotation
 * is not rolled back, and that a key cannot be declared retired while rows are
 * still sealed under it. Each is checked against the database, not against a
 * return value.
 */
class KeyRotationServiceTest extends DatabaseTestCase
{
    private const IBAN = 'DE89370400440532013000';

    private EncryptionKeysRepository $keys;
    private SealedIbanRepository $sealedIbans;
    private KeyRotationService $service;
    private EncryptionKeyService $keyService;
    private IbanSealedBox $sealedBox;

    private array $createdKeyIds = [];
    private array $createdMemberIds = [];
    private array $createdMandateIds = [];
    private array $parkedKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sealedBox = new IbanSealedBox(str_repeat('0', 63) . '2', 'test');
        $this->keys = new EncryptionKeysRepository($this->db, $this->logger);
        $this->sealedIbans = new SealedIbanRepository($this->db);
        $this->keyService = new EncryptionKeyService(
            $this->keys,
            $this->sealedIbans,
            $this->sealedBox,
            $this->createMock(AuditService::class),
            $this->createMock(NotificationsService::class),
            $this->logger,
        );
        $this->service = new KeyRotationService(
            $this->keys,
            $this->sealedIbans,
            $this->keyService,
            $this->sealedBox,
            $this->createMock(AuditService::class),
        );

        // This test owns the ACTIVE slot: it activates its own keys, and the
        // one-ACTIVE invariant would otherwise be decided by whatever the dev
        // seed left behind. Parked rather than deleted — other suites in the
        // same run still need the seeded key afterwards.
        foreach (['active', 'retiring'] as $status) {
            foreach ($this->keys->findByStatus($status) as $row) {
                $this->parkedKeys[] = [$row['id'], $status];
                $this->db->prepare("UPDATE encryption_keys SET status = 'retired' WHERE id = ?")
                    ->execute([$row['id']]);
            }
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData('mandates', $this->createdMandateIds);
        $this->cleanupTestData('members', $this->createdMemberIds);
        $this->cleanupTestData('encryption_keys', $this->createdKeyIds);

        foreach ($this->parkedKeys as [$id, $status]) {
            $this->db->prepare('UPDATE encryption_keys SET status = ? WHERE id = ?')->execute([$status, $id]);
        }

        parent::tearDown();
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /** @return array{id: string, public: string, secret: string} */
    private function registerKey(): array
    {
        $keypair = sodium_crypto_box_keypair();
        $public = sodium_crypto_box_publickey($keypair);

        $dto = $this->keyService->register(base64_encode($public), 'rot-' . $this->generateUuid(), null);
        $this->createdKeyIds[] = $dto->id;

        return ['id' => $dto->id, 'public' => $public, 'secret' => sodium_crypto_box_secretkey($keypair)];
    }

    private function createMandateUnder(string $keyId, string $publicKeyRaw, string $iban = self::IBAN): string
    {
        $memberId = $this->generateUuid();
        $this->createdMemberIds[] = $memberId;
        $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active) VALUES (?, ?, ?, ?, ?, 1)'
        )->execute([$memberId, 'Rot', 'Test', "rot-{$memberId}@example.com", 'de']);

        $mandateId = $this->generateUuid();
        $this->createdMandateIds[] = $mandateId;
        $this->db->prepare(
            'INSERT INTO mandates (id, member_id, active_member_id, reference, iban_ciphertext, iban_last4, iban_fingerprint, encryption_key_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $mandateId,
            $memberId,
            $memberId,
            substr(str_replace('-', '', $mandateId), 0, 35),
            $this->sealedBox->seal($iban, $publicKeyRaw),
            IbanSealedBox::lastFour($iban),
            $this->sealedBox->fingerprint($iban),
            $keyId,
        ]);

        return $mandateId;
    }

    private function storedRow(string $mandateId): array
    {
        $stmt = $this->db->prepare('SELECT iban_ciphertext, encryption_key_id FROM mandates WHERE id = ?');
        $stmt->execute([$mandateId]);

        return $stmt->fetch();
    }

    /**
     * Old key ACTIVE with $mandateCount rows under it, then a new key activated
     * over it — the state a rotation actually starts from.
     *
     * @return array{old: array, new: array, mandates: string[]}
     */
    private function startRotation(int $mandateCount): array
    {
        $old = $this->registerKey();
        $this->keyService->activate($old['id'], base64_encode($old['secret']), null);

        $mandates = [];
        for ($i = 0; $i < $mandateCount; $i++) {
            $mandates[] = $this->createMandateUnder($old['id'], $old['public']);
        }

        $new = $this->registerKey();
        $this->keyService->activate($new['id'], base64_encode($new['secret']), null);

        return ['old' => $old, 'new' => $new, 'mandates' => $mandates];
    }

    // ── Tests ───────────────────────────────────────────────────────────────

    public function testActivatingAReplacementLeavesTheOldRowsBehindOnTheOldKey(): void
    {
        $rotation = $this->startRotation(2);

        // The point of the rotation existing at all: activation switches what
        // *new* writes are sealed under, and touches nothing already stored.
        $this->assertSame('retiring', $this->keys->findById($rotation['old']['id'])['status']);
        $this->assertSame(2, $this->sealedIbans->countByKey($rotation['old']['id']));
        $this->assertSame(0, $this->sealedIbans->countByKey($rotation['new']['id']));
    }

    public function testBatchReSealsRowsUnderTheNewKeyAndKeepsThePlaintext(): void
    {
        $rotation = $this->startRotation(3);

        $batch = $this->service->rotateBatch(
            $rotation['old']['id'],
            base64_encode($rotation['old']['secret']),
            null,
        );

        $this->assertSame(3, $batch->processed);
        $this->assertSame(0, $batch->skipped);
        $this->assertSame(0, $batch->remaining);
        $this->assertTrue($batch->isDrained());

        foreach ($rotation['mandates'] as $mandateId) {
            $row = $this->storedRow($mandateId);
            $this->assertSame($rotation['new']['id'], $row['encryption_key_id']);
            // The whole exercise is worthless if the IBAN does not survive it:
            // the new ciphertext must open to the same account number, with the
            // new key and only with the new key.
            $this->assertSame(self::IBAN, $this->sealedBox->open($row['iban_ciphertext'], $rotation['new']['secret']));
        }
    }

    public function testTheOldKeyCanNoLongerOpenAReSealedRow(): void
    {
        $rotation = $this->startRotation(1);
        $this->service->rotateBatch($rotation['old']['id'], base64_encode($rotation['old']['secret']), null);

        $this->expectException(\RuntimeException::class);
        $this->sealedBox->open(
            $this->storedRow($rotation['mandates'][0])['iban_ciphertext'],
            $rotation['old']['secret'],
        );
    }

    public function testRotationIsResumableAfterAnAbortedBatch(): void
    {
        // More rows than one batch can take, so the first call cannot finish
        // the job and the second has to know where to continue from.
        $rotation = $this->startRotation(KeyRotationService::BATCH_SIZE + 2);

        $first = $this->service->rotateBatch($rotation['old']['id'], base64_encode($rotation['old']['secret']), null);
        $this->assertSame(KeyRotationService::BATCH_SIZE, $first->processed);
        $this->assertSame(2, $first->remaining);
        $this->assertFalse($first->isDrained());

        // Nothing carried over between the two calls — no cursor, no key, no
        // session state. A fresh request with the same arguments is exactly
        // what the browser sends after the admin reopens the wizard.
        $second = $this->service->rotateBatch($rotation['old']['id'], base64_encode($rotation['old']['secret']), null);
        $this->assertSame(2, $second->processed);
        $this->assertSame(0, $second->remaining);
        $this->assertTrue($second->isDrained());

        $this->assertSame(0, $this->sealedIbans->countByKey($rotation['old']['id']));
        $this->assertSame(
            KeyRotationService::BATCH_SIZE + 2,
            $this->sealedIbans->countByKey($rotation['new']['id']),
        );
    }

    public function testAConcurrentMemberEditIsNotOverwrittenByTheRotation(): void
    {
        $rotation = $this->startRotation(2);
        [$edited, $untouched] = $rotation['mandates'];

        // What a member edit does mid-rotation: it seals the new IBAN under the
        // ACTIVE key itself, so the row leaves the backlog without the rotation
        // touching it. The rotation must not put its own (older) reading back.
        $newIban = 'DE02120300000000202051';
        $this->db->prepare('UPDATE mandates SET iban_ciphertext = ?, iban_last4 = ?, encryption_key_id = ? WHERE id = ?')
            ->execute([
                $this->sealedBox->seal($newIban, $rotation['new']['public']),
                IbanSealedBox::lastFour($newIban),
                $rotation['new']['id'],
                $edited,
            ]);

        $batch = $this->service->rotateBatch($rotation['old']['id'], base64_encode($rotation['old']['secret']), null);

        // Only the row still on the old key was moved. The edited row was never
        // selected, so it is neither processed nor skipped — it had already
        // left the backlog.
        $this->assertSame(1, $batch->processed);
        $this->assertSame(0, $batch->remaining);

        $this->assertSame(
            $newIban,
            $this->sealedBox->open($this->storedRow($edited)['iban_ciphertext'], $rotation['new']['secret']),
            'the member edit must survive the rotation',
        );
        $this->assertSame(
            self::IBAN,
            $this->sealedBox->open($this->storedRow($untouched)['iban_ciphertext'], $rotation['new']['secret']),
        );
    }

    public function testARowMovedBetweenSelectionAndUpdateIsCountedAsSkippedNotLost(): void
    {
        // The same race one step later: the row was read as part of the batch
        // and then changed before the UPDATE. The optimistic predicate makes
        // that a no-op rather than a rollback of the edit.
        $rotation = $this->startRotation(1);
        $mandateId = $rotation['mandates'][0];

        $moved = $this->sealedIbans->reseal(
            $mandateId,
            $rotation['old']['id'],
            $this->sealedBox->seal(self::IBAN, $rotation['new']['public']),
            $rotation['new']['id'],
        );
        $this->assertTrue($moved);

        // Second attempt with the same "old key" expectation: the row is no
        // longer there to move.
        $again = $this->sealedIbans->reseal(
            $mandateId,
            $rotation['old']['id'],
            $this->sealedBox->seal('DE02120300000000202051', $rotation['new']['public']),
            $rotation['new']['id'],
        );
        $this->assertFalse($again, 'a row already on the new key must not be rewritten');

        $this->assertSame(
            self::IBAN,
            $this->sealedBox->open($this->storedRow($mandateId)['iban_ciphertext'], $rotation['new']['secret']),
        );
    }

    public function testBatchRefusesAPrivateKeyFromADifferentKeypair(): void
    {
        $rotation = $this->startRotation(1);
        $stranger = sodium_crypto_box_secretkey(sodium_crypto_box_keypair());

        try {
            $this->service->rotateBatch($rotation['old']['id'], base64_encode($stranger), null);
            $this->fail('a key from another keypair must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('does not match', $e->getMessage());
        }

        // Refused before anything was read: the backlog is untouched.
        $this->assertSame(1, $this->sealedIbans->countByKey($rotation['old']['id']));
    }

    public function testBatchRefusesAMalformedPrivateKey(): void
    {
        $rotation = $this->startRotation(1);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->rotateBatch($rotation['old']['id'], 'not-base64-at-all', null);
    }

    public function testTheActiveKeyCannotBeRotatedAwayFrom(): void
    {
        $key = $this->registerKey();
        $this->keyService->activate($key['id'], base64_encode($key['secret']), null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Activate the replacement key first');
        $this->service->rotateBatch($key['id'], base64_encode($key['secret']), null);
    }

    public function testCompletingARotationWithRowsLeftIsRefused(): void
    {
        $rotation = $this->startRotation(1);

        try {
            $this->service->completeRotation($rotation['old']['id'], null);
            $this->fail('a key with rows still sealed under it must not be retired');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('still sealed', $e->getMessage());
        }

        $this->assertSame('retiring', $this->keys->findById($rotation['old']['id'])['status']);
    }

    public function testCompletingADrainedRotationRetiresTheOldKey(): void
    {
        $rotation = $this->startRotation(2);
        $this->service->rotateBatch($rotation['old']['id'], base64_encode($rotation['old']['secret']), null);

        $dto = $this->service->completeRotation($rotation['old']['id'], null);

        $this->assertSame('retired', $dto->status);
        $this->assertSame(0, $dto->sealedRecordCount);

        $row = $this->keys->findById($rotation['old']['id']);
        $this->assertSame('retired', $row['status']);
        $this->assertNotNull($row['retired_at']);

        // Still exactly one ACTIVE key at the end of it.
        $this->assertCount(1, $this->keys->findByStatus('active'));
        $this->assertSame($rotation['new']['id'], $this->keys->findActive()['id']);
    }

    public function testACompromisedKeyIsDrainedWithoutLosingItsStatus(): void
    {
        // The incident path (ADR-0036): a compromised key is replaced
        // immediately, its rows are re-encrypted with the same machinery, and
        // it keeps saying "compromised" afterwards — finishing the clean-up
        // does not resolve the incident that caused it.
        $old = $this->registerKey();
        $this->keyService->activate($old['id'], base64_encode($old['secret']), null);
        $mandateId = $this->createMandateUnder($old['id'], $old['public']);

        $this->keyService->revoke($old['id'], true, null);
        $this->assertNull($this->keys->findActive(), 'a compromised key stops being the active one');

        $new = $this->registerKey();
        $this->keyService->activate($new['id'], base64_encode($new['secret']), null);

        $batch = $this->service->rotateBatch($old['id'], base64_encode($old['secret']), null);
        $this->assertSame(1, $batch->processed);

        $dto = $this->service->completeRotation($old['id'], null);
        $this->assertSame('compromised', $dto->status);
        $this->assertNotNull($this->keys->findById($old['id'])['retired_at']);

        $this->assertSame(
            self::IBAN,
            $this->sealedBox->open($this->storedRow($mandateId)['iban_ciphertext'], $new['secret']),
        );
    }

    public function testTwoActiveRowsResolveToTheMostRecentlyActivatedKey(): void
    {
        // The invariant lives in the service, not the schema — MariaDB has no
        // partial unique index — so anything writing the table directly can
        // leave two rows ACTIVE. A re-applied dev seed did exactly that. What
        // must not happen then is an *arbitrary* answer: the write path would
        // seal under one key while the export validated against the other, and
        // rows would be sealed with a key nobody could name.
        $first = $this->registerKey();
        $this->keyService->activate($first['id'], base64_encode($first['secret']), null);

        $second = $this->registerKey();
        $this->keyService->activate($second['id'], base64_encode($second['secret']), null);

        // Backdated as well as re-activated: `activated_at` is a DATETIME, and
        // two activations inside the same second would leave the tie to be
        // broken by id — stable, but not the property being asserted here.
        $this->db->prepare("UPDATE encryption_keys SET status = 'active', activated_at = ? WHERE id = ?")
            ->execute([
                (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
                $first['id'],
            ]);

        $this->assertCount(2, $this->keys->findByStatus('active'));
        $this->assertSame($second['id'], $this->keys->findActive()['id']);
        $this->assertSame($second['id'], $this->keys->requireOperationalActive()['id']);
    }

    public function testTheListingCarriesEachKeysBacklog(): void
    {
        $rotation = $this->startRotation(2);

        $byId = [];
        foreach ($this->keyService->listKeys() as $dto) {
            $byId[$dto->id] = $dto;
        }

        $this->assertSame(2, $byId[$rotation['old']['id']]->sealedRecordCount);
        $this->assertSame(0, $byId[$rotation['new']['id']]->sealedRecordCount);
    }
}
