<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Modules\Security\DTOs\EncryptionKeyDto;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Security\Repositories\SealedIbanRepository;
use App\Modules\Security\Services\EncryptionKeyExpiredException;
use App\Modules\Security\Services\EncryptionKeyService;
use App\Modules\Security\Services\EncryptionNotConfiguredException;
use App\Modules\Security\Services\PrivateKeyMismatchException;
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

    /** Activate a key registered by {@see registerKey()}, proving possession with its own secret. */
    private function activateKey(array $key, ?string $adminId = null): EncryptionKeyDto
    {
        return $this->service->activate($key['dto']->id, base64_encode($key['secret']), $adminId);
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
        $activated = $this->activateKey($first);

        $this->assertSame('active', $activated->status);
        $this->assertNotNull($activated->expiresAt);
        // 365-day cryptoperiod from activation
        $expiry = new \DateTimeImmutable($activated->expiresAt);
        $activatedAt = new \DateTimeImmutable($activated->activatedAt);
        $this->assertSame(365, (int) $activatedAt->diff($expiry)->format('%a'));

        // Activating a second key retires the first into RETIRING — never two ACTIVE.
        $second = $this->registerKey();
        $this->activateKey($second);

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
        $this->activateKey($key);

        $this->expectException(\RuntimeException::class);
        $this->activateKey($key);
    }

    /**
     * The gap this closes: registration proves nothing about a public key, so
     * 32 bytes nobody holds the counterpart of used to activate exactly like a
     * generated key — and every IBAN sealed afterwards was unreadable, with
     * nothing saying so until the next SEPA export refused the archived key.
     */
    public function testActivateRefusesAKeyFromAnotherPair(): void
    {
        $key = $this->registerKey();
        $stranger = sodium_crypto_box_secretkey(sodium_crypto_box_keypair());

        try {
            $this->service->activate($key['dto']->id, base64_encode($stranger), null);
            $this->fail('a private key from another pair must not activate this one');
        } catch (PrivateKeyMismatchException $e) {
            $this->assertStringContainsString('does not match', $e->getMessage());
        }

        $this->assertSame(
            'pending',
            $this->repository->findById($key['dto']->id)['status'],
            'a refused activation must leave the key pending, so it seals nothing',
        );
    }

    public function testActivateRefusesAMalformedPrivateKey(): void
    {
        $key = $this->registerKey();

        $this->expectException(PrivateKeyMismatchException::class);
        $this->service->activate($key['dto']->id, 'not-base64!!', null);
    }

    /**
     * An unknown id and a wrong key are different mistakes and must stay
     * distinguishable — the HTTP layer maps them to 404 and 422.
     */
    public function testActivateUnknownKeyIsNotAMismatch(): void
    {
        try {
            $this->service->activate($this->generateUuid(), base64_encode(str_repeat("\x01", 32)), null);
            $this->fail('activating an unknown key must throw');
        } catch (PrivateKeyMismatchException $e) {
            $this->fail('an unknown key is not a key mismatch: ' . $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown encryption key', $e->getMessage());
        }
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
        $this->activateKey($key);

        // Age the key past its cryptoperiod.
        $this->db->prepare('UPDATE encryption_keys SET expires_at = ? WHERE id = ?')
            ->execute([(new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'), $key['dto']->id]);

        $this->expectException(EncryptionKeyExpiredException::class);
        $this->service->requireOperationalActiveKey();
    }

    public function testRequireOperationalActiveKeyReturnsActiveRow(): void
    {
        $key = $this->registerKey();
        $this->activateKey($key);

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
