<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Security\Repositories\SealedIbanRepository;
use App\Modules\Security\Services\EncryptionKeyExpiredException;
use App\Modules\Security\Services\EncryptionKeyService;
use App\Modules\Security\Services\EncryptionNotConfiguredException;
use App\Shared\Security\IbanSealedBox;
use App\Shared\Services\AuditService;
use Tests\Feature\DatabaseTestCase;

class EncryptionKeyServiceTest extends DatabaseTestCase
{
    private EncryptionKeyService $service;
    private EncryptionKeysRepository $repository;
    private array $createdKeyIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureActiveEncryptionKey();

        $this->repository = new EncryptionKeysRepository($this->db, $this->logger);
        $this->service = new EncryptionKeyService(
            $this->repository,
            new SealedIbanRepository($this->db),
            new IbanSealedBox(str_repeat('aa', 32), 'test'),
            $this->createMock(AuditService::class),
        );

        // The dev stack seeds an active key; park any pre-existing operational
        // keys so this test owns the ACTIVE slot, and restore them afterwards.
        $this->parkedKeys = [];
        foreach (['active', 'retiring'] as $status) {
            foreach ($this->repository->findByStatus($status) as $row) {
                $this->parkedKeys[] = [$row['id'], $status];
                $this->db->prepare("UPDATE encryption_keys SET status = 'retired' WHERE id = ?")
                    ->execute([$row['id']]);
            }
        }
    }

    private array $parkedKeys = [];

    protected function tearDown(): void
    {
        $this->cleanupTestData('encryption_keys', $this->createdKeyIds);

        foreach ($this->parkedKeys as [$id, $status]) {
            $this->db->prepare('UPDATE encryption_keys SET status = ? WHERE id = ?')
                ->execute([$status, $id]);
        }

        parent::tearDown();
    }

    private function registerKey(?string $identifier = null): array
    {
        $keypair = sodium_crypto_box_keypair();
        $publicKey = sodium_crypto_box_publickey($keypair);

        $dto = $this->service->register(
            base64_encode($publicKey),
            $identifier ?? ('test-key-' . $this->generateUuid()),
            null,
        );
        $this->createdKeyIds[] = $dto->id;

        return ['dto' => $dto, 'secret' => sodium_crypto_box_secretkey($keypair), 'public' => $publicKey];
    }

    public function testRegisterCreatesPendingKeyWithFingerprint(): void
    {
        $key = $this->registerKey();

        $this->assertSame('pending', $key['dto']->status);
        $this->assertSame(hash('sha256', $key['public']), $key['dto']->fingerprintSha256);
        $this->assertNull($key['dto']->expiresAt);
    }

    public function testRegisterRejectsDuplicateFingerprint(): void
    {
        $keypair = sodium_crypto_box_keypair();
        $publicB64 = base64_encode(sodium_crypto_box_publickey($keypair));

        $dto = $this->service->register($publicB64, 'dup-a-' . $this->generateUuid(), null);
        $this->createdKeyIds[] = $dto->id;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fingerprint');
        $this->service->register($publicB64, 'dup-b-' . $this->generateUuid(), null);
    }

    public function testRegisterRejectsMalformedPublicKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->register('not-base64!!', 'bad-' . $this->generateUuid(), null);
    }

    public function testActivateStartsCryptoperiodAndKeepsSingleActive(): void
    {
        $first = $this->registerKey();
        $activated = $this->service->activate($first['dto']->id, null);

        $this->assertSame('active', $activated->status);
        $this->assertNotNull($activated->expiresAt);
        // 365-day cryptoperiod from activation
        $expiry = new \DateTimeImmutable($activated->expiresAt);
        $activatedAt = new \DateTimeImmutable($activated->activatedAt);
        $this->assertSame(365, (int) $activatedAt->diff($expiry)->format('%a'));

        // Activating a second key retires the first into RETIRING — never two ACTIVE.
        $second = $this->registerKey();
        $this->service->activate($second['dto']->id, null);

        $firstRow = $this->repository->findById($first['dto']->id);
        $this->assertSame('retiring', $firstRow['status']);

        $activeRows = $this->repository->findByStatus('active');
        $activeIds = array_column($activeRows, 'id');
        $this->assertSame([$second['dto']->id], array_values(array_intersect($activeIds, [
            $first['dto']->id, $second['dto']->id,
        ])));
        $this->assertCount(1, $activeRows);
    }

    public function testActivateRejectsNonPendingKey(): void
    {
        $key = $this->registerKey();
        $this->service->activate($key['dto']->id, null);

        $this->expectException(\RuntimeException::class);
        $this->service->activate($key['dto']->id, null);
    }

    public function testRevokeAndCompromiseSetTerminalStates(): void
    {
        $a = $this->registerKey();
        $revoked = $this->service->revoke($a['dto']->id, false, null);
        $this->assertSame('revoked', $revoked->status);

        $b = $this->registerKey();
        $compromised = $this->service->revoke($b['dto']->id, true, null);
        $this->assertSame('compromised', $compromised->status);
        $this->assertNotNull($compromised->retiredAt);
    }

    public function testRequireOperationalActiveKeyThrowsWhenUnconfigured(): void
    {
        $this->expectException(EncryptionNotConfiguredException::class);
        $this->service->requireOperationalActiveKey();
    }

    public function testRequireOperationalActiveKeyThrowsWhenExpired(): void
    {
        $key = $this->registerKey();
        $this->service->activate($key['dto']->id, null);

        // Age the key past its cryptoperiod.
        $this->db->prepare('UPDATE encryption_keys SET expires_at = ? WHERE id = ?')
            ->execute([(new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'), $key['dto']->id]);

        $this->expectException(EncryptionKeyExpiredException::class);
        $this->service->requireOperationalActiveKey();
    }

    public function testRequireOperationalActiveKeyReturnsActiveRow(): void
    {
        $key = $this->registerKey();
        $this->service->activate($key['dto']->id, null);

        $row = $this->service->requireOperationalActiveKey();
        $this->assertSame($key['dto']->id, $row['id']);
    }

    public function testValidatePrivateKeyForAcceptsMatchingKey(): void
    {
        $key = $this->registerKey();
        $row = $this->repository->findById($key['dto']->id);

        $secret = $this->service->validatePrivateKeyFor($row, base64_encode($key['secret']));
        $this->assertSame($key['secret'], $secret);
    }

    public function testValidatePrivateKeyForRejectsForeignKey(): void
    {
        $key = $this->registerKey();
        $row = $this->repository->findById($key['dto']->id);
        $foreignSecret = sodium_crypto_box_secretkey(sodium_crypto_box_keypair());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match');
        $this->service->validatePrivateKeyFor($row, base64_encode($foreignSecret));
    }

    public function testValidatePrivateKeyForRejectsMalformedInput(): void
    {
        $key = $this->registerKey();
        $row = $this->repository->findById($key['dto']->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->validatePrivateKeyFor($row, 'zzz');
    }

    public function testSealedIbanRoundtripsThroughRegisteredKey(): void
    {
        $key = $this->registerKey();
        $box = new IbanSealedBox(str_repeat('aa', 32), 'test');

        $sealed = $box->seal('DE89370400440532013000', $key['public']);
        $this->assertSame('DE89370400440532013000', $box->open($sealed, $key['secret']));
    }
}
