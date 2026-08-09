<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Terminals\Repositories;

use App\Modules\Terminals\Repositories\TerminalsRepository;
use Tests\Feature\DatabaseTestCase;

/**
 * Terminal token lifetime (#106).
 *
 * These run against the real database because the whole point of the fix is a
 * comparison MariaDB makes: `token_expires_at > NOW()`, evaluated on the same
 * clock that stamped the token.
 */
class TerminalsRepositoryTest extends DatabaseTestCase
{
    private TerminalsRepository $terminalsRepository;
    private array $testTerminalIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->terminalsRepository = new TerminalsRepository($this->db, $this->logger);
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData('terminals', $this->testTerminalIds);
        parent::tearDown();
    }

    private function createTerminal(string $hash, int $ttlDays = 90): array
    {
        $id = $this->generateUuid();
        $this->testTerminalIds[] = $id;

        return $this->terminalsRepository->create([
            'id' => $id,
            'name' => 'Expiry Test Terminal',
            'device_id' => 'expiry-test-' . $id,
            'api_token_hash' => $hash,
            'token_ttl_days' => $ttlDays,
        ]);
    }

    private function setExpiry(string $id, string $expression): void
    {
        $this->db->exec("UPDATE terminals SET token_expires_at = {$expression} WHERE id = '{$id}'");
    }

    private function hash(string $suffix): string
    {
        return hash('sha256', 'token-' . $suffix . '-' . $this->generateUuid());
    }

    public function test_create_stamps_the_token_lifetime_from_the_configured_ttl(): void
    {
        $terminal = $this->createTerminal($this->hash('create'), 30);

        $this->assertNotNull($terminal['token_issued_at']);
        $this->assertNotNull($terminal['token_expires_at']);

        $issued = new \DateTimeImmutable($terminal['token_issued_at']);
        $expires = new \DateTimeImmutable($terminal['token_expires_at']);

        $this->assertSame(30, (int) $issued->diff($expires)->days);
    }

    public function test_create_falls_back_to_the_default_ttl_when_none_is_given(): void
    {
        $id = $this->generateUuid();
        $this->testTerminalIds[] = $id;

        $terminal = $this->terminalsRepository->create([
            'id' => $id,
            'name' => 'Default TTL Terminal',
            'device_id' => 'default-ttl-' . $id,
            'api_token_hash' => $this->hash('default'),
        ]);

        $issued = new \DateTimeImmutable($terminal['token_issued_at']);
        $expires = new \DateTimeImmutable($terminal['token_expires_at']);

        $this->assertSame(90, (int) $issued->diff($expires)->days);
    }

    public function test_findByTokenHash_returns_a_terminal_whose_token_is_still_within_its_lifetime(): void
    {
        $hash = $this->hash('valid');
        $terminal = $this->createTerminal($hash);

        $found = $this->terminalsRepository->findByTokenHash($hash);

        $this->assertNotNull($found);
        $this->assertSame($terminal['id'], $found['id']);
    }

    public function test_findByTokenHash_refuses_a_token_whose_lifetime_has_run_out(): void
    {
        $hash = $this->hash('expired');
        $terminal = $this->createTerminal($hash);
        $this->setExpiry($terminal['id'], 'NOW() - INTERVAL 1 SECOND');

        $this->assertNull($this->terminalsRepository->findByTokenHash($hash));
    }

    /**
     * Fail-closed: a row with a token hash but no expiry is not "unlimited",
     * it is a row nobody stamped — the state the pre-#106 schema left behind.
     */
    public function test_findByTokenHash_refuses_a_token_with_no_expiry_at_all(): void
    {
        $hash = $this->hash('null-expiry');
        $terminal = $this->createTerminal($hash);
        $this->setExpiry($terminal['id'], 'NULL');

        $this->assertNull($this->terminalsRepository->findByTokenHash($hash));
    }

    public function test_findExpiredByTokenHash_finds_the_aged_out_token_the_auth_lookup_refused(): void
    {
        $hash = $this->hash('diagnose');
        $terminal = $this->createTerminal($hash);
        $this->setExpiry($terminal['id'], 'NOW() - INTERVAL 1 DAY');

        $found = $this->terminalsRepository->findExpiredByTokenHash($hash);

        $this->assertNotNull($found);
        $this->assertSame($terminal['id'], $found['id']);
    }

    public function test_findExpiredByTokenHash_ignores_a_token_that_still_authenticates(): void
    {
        $hash = $this->hash('still-valid');
        $this->createTerminal($hash);

        $this->assertNull($this->terminalsRepository->findExpiredByTokenHash($hash));
    }

    public function test_rotateToken_restarts_the_lifetime_of_an_expired_terminal(): void
    {
        $oldHash = $this->hash('old');
        $terminal = $this->createTerminal($oldHash);
        $this->setExpiry($terminal['id'], 'NOW() - INTERVAL 1 DAY');

        $newHash = $this->hash('new');
        $rotated = $this->terminalsRepository->rotateToken($terminal['id'], $newHash, 90);

        $this->assertNotNull($rotated);
        $this->assertNull($this->terminalsRepository->findByTokenHash($oldHash), 'the old token must not survive rotation');

        $found = $this->terminalsRepository->findByTokenHash($newHash);
        $this->assertNotNull($found);
        $this->assertSame($terminal['id'], $found['id']);

        $issued = new \DateTimeImmutable($rotated['token_issued_at']);
        $expires = new \DateTimeImmutable($rotated['token_expires_at']);
        $this->assertSame(90, (int) $issued->diff($expires)->days);
    }

    public function test_rotateToken_returns_null_for_a_terminal_that_does_not_exist(): void
    {
        $this->assertNull($this->terminalsRepository->rotateToken($this->generateUuid(), $this->hash('ghost'), 90));
    }

    /**
     * Revoking clears the lifetime along with the hash, so nothing is left for
     * a later `NOW()` to compare against.
     */
    public function test_updateById_can_clear_the_token_lifetime(): void
    {
        $hash = $this->hash('revoke');
        $terminal = $this->createTerminal($hash);

        $updated = $this->terminalsRepository->updateById($terminal['id'], [
            'api_token_hash' => null,
            'token_issued_at' => null,
            'token_expires_at' => null,
            'is_active' => 0,
        ]);

        $this->assertNull($updated['token_issued_at']);
        $this->assertNull($updated['token_expires_at']);
        $this->assertNull($this->terminalsRepository->findByTokenHash($hash));
    }
}
