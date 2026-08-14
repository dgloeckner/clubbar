<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Terminals\Repositories;

use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Shared\Security\CredentialLifecycle;
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

        $this->assertSame(CredentialLifecycle::LIFETIME_DAYS, (int) $issued->diff($expires)->days);
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

    /**
     * Overlap rotation (#395): staging a replacement leaves the token in the
     * field working, which is what lets an admin rotate from a desk rather than
     * from behind the bar.
     */
    public function test_issuePendingToken_leaves_the_current_token_authenticating(): void
    {
        $oldHash = $this->hash('old');
        $terminal = $this->createTerminal($oldHash);

        $newHash = $this->hash('new');
        $staged = $this->terminalsRepository->issuePendingToken($terminal['id'], $newHash, 90);

        $this->assertNotNull($staged);
        $this->assertNotNull(
            $this->terminalsRepository->findByTokenHash($oldHash),
            'the token in the field must keep working while the replacement waits',
        );
        $this->assertNotNull($this->terminalsRepository->findByPendingTokenHash($newHash));
        // Not yet the active credential — only using it makes it that.
        $this->assertNull($this->terminalsRepository->findByTokenHash($newHash));

        $issued = new \DateTimeImmutable($staged['pending_token_issued_at']);
        $expires = new \DateTimeImmutable($staged['pending_token_expires_at']);
        $this->assertSame(90, (int) $issued->diff($expires)->days);
    }

    public function test_promotePendingToken_installs_the_new_token_and_retires_the_old_one(): void
    {
        $oldHash = $this->hash('old');
        $terminal = $this->createTerminal($oldHash);
        $newHash = $this->hash('new');
        $this->terminalsRepository->issuePendingToken($terminal['id'], $newHash, 90);

        $promoted = $this->terminalsRepository->promotePendingToken($terminal['id'], $newHash);

        $this->assertNotNull($promoted);
        $this->assertNull(
            $this->terminalsRepository->findByTokenHash($oldHash),
            'the replaced token must stop working the moment its successor is used',
        );
        $found = $this->terminalsRepository->findByTokenHash($newHash);
        $this->assertNotNull($found);
        $this->assertSame($terminal['id'], $found['id']);
        $this->assertNull($promoted['pending_token_hash']);
        $this->assertNull($promoted['pending_token_expires_at']);

        $issued = new \DateTimeImmutable($promoted['token_issued_at']);
        $expires = new \DateTimeImmutable($promoted['token_expires_at']);
        $this->assertSame(90, (int) $issued->diff($expires)->days);
    }

    /** The losing half of a two-syncs-at-once race promotes nothing. */
    public function test_promotePendingToken_is_refused_once_the_token_is_no_longer_pending(): void
    {
        $terminal = $this->createTerminal($this->hash('old'));
        $newHash = $this->hash('new');
        $this->terminalsRepository->issuePendingToken($terminal['id'], $newHash, 90);
        $this->terminalsRepository->promotePendingToken($terminal['id'], $newHash);

        $this->assertNull($this->terminalsRepository->promotePendingToken($terminal['id'], $newHash));
    }

    /**
     * A terminal whose token has already run out rotates the same way — there
     * is simply nothing to overlap with, and the staged token stands alone.
     */
    public function test_a_pending_token_recovers_a_terminal_whose_token_expired(): void
    {
        $oldHash = $this->hash('old');
        $terminal = $this->createTerminal($oldHash);
        $this->setExpiry($terminal['id'], 'NOW() - INTERVAL 1 DAY');

        $newHash = $this->hash('new');
        $this->terminalsRepository->issuePendingToken($terminal['id'], $newHash, 90);
        $promoted = $this->terminalsRepository->promotePendingToken($terminal['id'], $newHash);

        $this->assertNotNull($promoted);
        $this->assertNotNull($this->terminalsRepository->findByTokenHash($newHash));
    }

    public function test_findByPendingTokenHash_refuses_a_pending_token_past_its_own_lifetime(): void
    {
        $terminal = $this->createTerminal($this->hash('old'));
        $newHash = $this->hash('new');
        $this->terminalsRepository->issuePendingToken($terminal['id'], $newHash, 90);
        $this->db->exec(
            "UPDATE terminals SET pending_token_expires_at = NOW() - INTERVAL 1 DAY WHERE id = '{$terminal['id']}'"
        );

        $this->assertNull($this->terminalsRepository->findByPendingTokenHash($newHash));
        // …and it is reported as expired rather than unknown, so its operator
        // is told to ask for a new one instead of hunting a typo.
        $this->assertNotNull($this->terminalsRepository->findExpiredByTokenHash($newHash));
    }

    public function test_issuePendingToken_returns_null_for_a_terminal_that_does_not_exist(): void
    {
        $this->assertNull($this->terminalsRepository->issuePendingToken($this->generateUuid(), $this->hash('ghost'), 90));
    }

    /**
     * Revoking clears the lifetime along with the hash, so nothing is left for
     * a later `NOW()` to compare against.
     */
    public function test_updateById_can_clear_the_token_lifetime(): void
    {
        $hash = $this->hash('revoke');
        $terminal = $this->createTerminal($hash);

        $pendingHash = $this->hash('revoke-pending');
        $this->terminalsRepository->issuePendingToken($terminal['id'], $pendingHash, 90);

        $updated = $this->terminalsRepository->updateById($terminal['id'], [
            'api_token_hash' => null,
            'token_issued_at' => null,
            'token_expires_at' => null,
            'pending_token_hash' => null,
            'pending_token_issued_at' => null,
            'pending_token_expires_at' => null,
            'is_active' => 0,
        ]);

        $this->assertNull($updated['token_issued_at']);
        $this->assertNull($updated['token_expires_at']);
        $this->assertNull($this->terminalsRepository->findByTokenHash($hash));
        // The staged replacement goes with it — otherwise a revoked terminal
        // walks back in with the token an admin had already prepared (#395).
        $this->assertNull($this->terminalsRepository->findByPendingTokenHash($pendingHash));
    }
}
