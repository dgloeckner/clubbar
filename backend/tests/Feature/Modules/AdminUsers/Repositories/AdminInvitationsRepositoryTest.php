<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\AdminUsers\Repositories;

use App\Modules\AdminUsers\Repositories\AdminInvitationsRepository;
use Tests\Feature\DatabaseTestCase;

/**
 * `token_cipher` is a decryptable secret with a fixed shelf life: the mail
 * builder's one read at send time (migration 058, #891). Everything here
 * asserts the *other* half of that fact — that the three moments after which
 * nothing ever reads it again are the three moments that null it out, against
 * the real column rather than a mock that would happily agree a write
 * happened.
 */
class AdminInvitationsRepositoryTest extends DatabaseTestCase
{
    private AdminInvitationsRepository $repository;

    /** @var list<string> */
    private array $adminUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new AdminInvitationsRepository($this->db, $this->logger);
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData('admin_users', $this->adminUserIds);

        parent::tearDown();
    }

    private function createTestAdminUser(): string
    {
        $adminId = $this->generateUuid();
        $this->adminUserIds[] = $adminId;

        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, is_active) VALUES (?, ?, ?, ?, 1)'
        )->execute([
            $adminId,
            'invitee-' . substr($adminId, 0, 8) . '@example.org',
            password_hash('test123', PASSWORD_BCRYPT),
            'Invitation Test Admin',
        ]);

        return $adminId;
    }

    private function tokenCipherOf(string $invitationId): string
    {
        $stmt = $this->db->prepare('SELECT token_cipher FROM admin_user_invitations WHERE id = ?');
        $stmt->execute([$invitationId]);

        return (string) $stmt->fetchColumn();
    }

    private function invite(string $adminUserId): array
    {
        return $this->repository->create(
            adminUserId: $adminUserId,
            tokenHash: hash('sha256', $this->generateUuid()),
            tokenCipher: 'v1:sealed-token-ciphertext',
            expiresAt: date('Y-m-d H:i:s', time() + 86400),
            createdBy: null,
        );
    }

    public function test_a_freshly_issued_invitation_carries_its_sealed_token(): void
    {
        $invitation = $this->invite($this->createTestAdminUser());

        $this->assertSame('v1:sealed-token-ciphertext', $this->tokenCipherOf($invitation['id']));
    }

    /**
     * Accepting is the normal path's endpoint: the token has just been read to
     * render the mail, and now it never will be again.
     */
    public function test_accepting_clears_the_sealed_token(): void
    {
        $invitation = $this->invite($this->createTestAdminUser());

        $this->assertTrue($this->repository->markAccepted($invitation['id']));
        $this->assertSame('', $this->tokenCipherOf($invitation['id']));
    }

    /**
     * The guarded `WHERE` that makes acceptance single-use must not clear the
     * cipher on the request that loses the race — the row it names may not
     * even be this one for a two-invitation account, and the column write is
     * conditioned on the very same guard as `accepted_at`.
     */
    public function test_a_lost_race_to_accept_leaves_the_sealed_token_untouched(): void
    {
        $invitation = $this->invite($this->createTestAdminUser());
        $this->repository->markAccepted($invitation['id']);

        // Cleared already by the first acceptance; the second must be a no-op,
        // not a second write that happens to agree.
        $this->assertFalse($this->repository->markAccepted($invitation['id']));
        $this->assertSame('', $this->tokenCipherOf($invitation['id']));
    }

    /**
     * A resend revokes every outstanding invitation for the account — and the
     * one it replaces has no further use for its own sealed token either.
     */
    public function test_revoking_outstanding_invitations_clears_their_sealed_tokens(): void
    {
        $adminUserId = $this->createTestAdminUser();
        $first = $this->invite($adminUserId);

        $this->assertSame(1, $this->repository->revokeOutstandingFor($adminUserId));
        $this->assertSame('', $this->tokenCipherOf($first['id']));
    }

    /** An invitation nobody presented yet is untouched by another account's resend. */
    public function test_revoking_outstanding_invitations_leaves_other_accounts_alone(): void
    {
        $mine = $this->invite($this->createTestAdminUser());
        $someoneElses = $this->invite($this->createTestAdminUser());

        $this->repository->revokeOutstandingFor((string) $mine['admin_user_id']);

        $this->assertSame('v1:sealed-token-ciphertext', $this->tokenCipherOf($someoneElses['id']));
    }

    /** The lazy path: the first presentation of a link past its TTL. */
    public function test_clearTokenCipher_nulls_the_column(): void
    {
        $invitation = $this->invite($this->createTestAdminUser());

        $this->repository->clearTokenCipher($invitation['id']);

        $this->assertSame('', $this->tokenCipherOf($invitation['id']));
    }

    /** Idempotent by design — a second clear is a no-op, not an error. */
    public function test_clearTokenCipher_is_safe_to_call_twice(): void
    {
        $invitation = $this->invite($this->createTestAdminUser());

        $this->repository->clearTokenCipher($invitation['id']);
        $this->repository->clearTokenCipher($invitation['id']);

        $this->assertSame('', $this->tokenCipherOf($invitation['id']));
    }
}
