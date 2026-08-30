<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\Services;

use App\Modules\AdminUsers\DTOs\AdminInvitationDto;
use App\Modules\AdminUsers\Domain\InvitationLink;
use App\Modules\AdminUsers\Repositories\AdminInvitationsRepository;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Shared\Config\AppConfig;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;

/**
 * Onboarding an admin by invitation link (migration 058).
 *
 * ## What this replaces
 *
 * Creating an admin used to mint a random password, show it once to whoever
 * pressed the button, and leave the two of them to move a live credential
 * between themselves — by chat, by note, by reading it aloud across a room.
 * The new admin's password was known to somebody else before they had ever
 * touched the account, through a channel this system neither chose nor can see.
 *
 * A link is strictly weaker than that password in every dimension that matters:
 * it expires ({@see InvitationLink::TTL_DAYS} days), it works once, it is
 * revoked the moment a replacement is issued, and what it sets is a secret only
 * the invitee ever learns.
 *
 * ## What it deliberately is not
 *
 * **A password-reset channel.** {@see reissue()} refuses an account that has
 * already accepted one. An emailed link able to re-credential an established
 * admin would be a second way past the step-up guarding
 * `POST /admin-users/{id}/reset-password`, reachable by anyone who can read
 * that admin's mailbox — and it would be the weaker of the two paths, which is
 * the one an attacker picks.
 *
 * **A second factor.** Accepting sets the first factor and nothing else. The
 * invitee then signs in on the ordinary path, where `totp_enabled = 0` sends
 * them straight into Authenticator enrolment (`AuthController::login` branch
 * 2). No session is minted from a mail link, and the enrolment gate is the same
 * one every account has always passed through.
 */
class AdminInvitationService
{
    public function __construct(
        private AdminInvitationsRepository $invitations,
        private AdminUsersRepository $adminUsers,
        private InvitationTokenCipher $cipher,
        private AdminNotifier $adminNotifier,
        private AuditService $auditService,
        private AppConfig $config,
        private Logger $logger,
    ) {}

    /**
     * Mint a link for an account and queue the message carrying it.
     *
     * Called for every account creation, and — through {@see reissue()} — when
     * the first one did not arrive. Any live invitation for the account is
     * revoked first, so there is never more than one working link to an
     * account: a resend that left its predecessor alive would mean an admin who
     * believed they had cancelled something had not.
     *
     * The mail is best effort and the link is not. If queueing fails, the
     * invitation still exists and the URL still comes back to the caller, who
     * can pass it on by hand — the same position ADR-0038 puts every other
     * notification in, and the reason {@see AdminInvitationDto} carries a URL
     * at all.
     */
    public function issue(string $adminUserId, ?string $actorAdminUserId = null): AdminInvitationDto
    {
        $admin = $this->adminUsers->findById($adminUserId);
        if ($admin === null) {
            throw NotFoundException::forResource('AdminUser', $adminUserId);
        }

        $this->invitations->revokeOutstandingFor($adminUserId);

        $token = InvitationLink::mintToken();
        $expiresAt = InvitationLink::expiresAt();

        $invitation = $this->invitations->create(
            adminUserId: $adminUserId,
            tokenHash: InvitationLink::hash($token),
            tokenCipher: $this->cipher->seal($token),
            expiresAt: $expiresAt,
            createdBy: $actorAdminUserId,
        );

        $this->auditService->log(
            action: AuditAction::INVITATION_SENT,
            entityType: EntityType::ADMIN_USER,
            entityId: $adminUserId,
            // No token, and no address either — the address is already on the
            // account row and on the outbox row, and ADR-0029 scrubs the audit
            // log by entity id rather than by content.
            newValues: ['invitation_id' => $invitation['id'], 'expires_at' => $expiresAt],
            adminUserId: $actorAdminUserId,
        );

        try {
            $this->adminNotifier->inviteAdmin(
                adminUserId: $adminUserId,
                recipient: (string) $admin['email'],
                invitationId: (string) $invitation['id'],
                language: MailLanguage::fromPreferred($admin['locale'] ?? null),
                actorAdminUserId: $actorAdminUserId,
            );
        } catch (\Throwable $e) {
            // Never allowed to fail the thing it carries. The invitation is
            // already written and the URL is already in the response; a queue
            // that will not take the message leaves the caller with a working
            // link and no email, which is recoverable, while a 500 here leaves
            // an account nobody can reach and a link nobody was shown.
            $this->logger->error('Could not queue the invitation mail', [
                'admin_user_id' => $adminUserId,
                'invitation_id' => $invitation['id'],
                'error' => $e->getMessage(),
            ]);
        }

        return new AdminInvitationDto(
            adminUserId: $adminUserId,
            email: (string) $admin['email'],
            expiresAt: $expiresAt,
            url: InvitationLink::url($this->config->appUrl, $token),
        );
    }

    /**
     * Issue a replacement link, for the ordinary case: the first one went to
     * spam, or expired before anyone opened it.
     *
     * Refused for an account that has already accepted one — see the class
     * comment — and for a deactivated account, which is a credential for a
     * login that cannot succeed. The second guard matters more than it looks:
     * without it, deactivating a colleague would not stop an outstanding
     * invitation from being renewed.
     */
    public function reissue(string $adminUserId, ?string $actorAdminUserId = null): AdminInvitationDto
    {
        $admin = $this->adminUsers->findById($adminUserId);
        if ($admin === null) {
            throw NotFoundException::forResource('AdminUser', $adminUserId);
        }

        if (!(bool) $admin['is_active']) {
            throw new BusinessRuleException(
                BusinessRuleReason::ADMIN_ACCOUNT_INACTIVE,
                'Cannot invite a deactivated admin account',
            );
        }

        if ($this->invitations->hasAccepted($adminUserId)) {
            throw new BusinessRuleException(
                BusinessRuleReason::ADMIN_ALREADY_ONBOARDED,
                'This account has already accepted an invitation; reset its password instead',
            );
        }

        return $this->issue($adminUserId, $actorAdminUserId);
    }

    /**
     * Who a presented link is for, so the accept page can greet them by name
     * and show the address the account will sign in with.
     *
     * Deliberately narrow: display name, address, locale. Nothing about roles,
     * nothing about the club, nothing about who issued it — this is answered to
     * an unauthenticated caller holding a token, and the token proves only that
     * they can read one mailbox.
     *
     * @return array{email: string, display_name: string, locale: string}
     */
    public function describe(string $token): array
    {
        $invitation = $this->requireValid($token);
        $admin = $this->adminUsers->findById((string) $invitation['admin_user_id']);

        if ($admin === null) {
            // The FK cascades, so the account cannot vanish under a live
            // invitation by any ordinary route. Treated as an unusable link
            // rather than a 500: from the invitee's side it is exactly that.
            throw $this->invalid('account gone');
        }

        return [
            'email' => (string) $admin['email'],
            'display_name' => (string) ($admin['display_name'] ?: $admin['email']),
            'locale' => (string) ($admin['locale'] ?? 'de'),
        ];
    }

    /**
     * Set the account's first password and close the invitation.
     *
     * The order is the point. `markAccepted()` runs **before** the password is
     * written and carries its own guard in the `WHERE` clause, so two requests
     * arriving with the same link cannot both pass: the loser writes nothing
     * and is told the link is spent. A read-then-write would let the second
     * request silently overwrite the first person's password.
     *
     * No session is minted here. The invitee signs in normally afterwards,
     * which is what puts them through Authenticator enrolment — see the class
     * comment.
     *
     * @return array{email: string} The address to sign in with, so the panel
     *         can carry it to the login form rather than asking for it again.
     */
    public function accept(string $token, string $password): array
    {
        $invitation = $this->requireValid($token);
        $adminUserId = (string) $invitation['admin_user_id'];

        if (!$this->invitations->markAccepted((string) $invitation['id'])) {
            throw $this->invalid('lost the race to another acceptance');
        }

        $admin = $this->adminUsers->updateById($adminUserId, [
            'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        ]);

        if ($admin === null) {
            throw $this->invalid('account gone');
        }

        $this->auditService->log(
            action: AuditAction::INVITATION_ACCEPTED,
            entityType: EntityType::ADMIN_USER,
            entityId: $adminUserId,
            newValues: ['invitation_id' => $invitation['id'], 'password' => '[SET]'],
            // The invitee acted, and they now have an account, so the entry is
            // attributed to them. They had no session while doing it — this is
            // the only row in the log written by a request that carries none,
            // which is exactly why it has to exist.
            adminUserId: $adminUserId,
        );

        // Anything issued against this account before it had a password stops
        // working. There should be nothing — an account with no usable password
        // has never been signed into — but a pending account whose password an
        // admin reset by hand is a reachable state, and the epoch is what makes
        // this a guarantee rather than an expectation.
        //
        // **Stamped a second back**, and this is the only caller that does so.
        // `predatesCredentialChange()` compares with `<=`, so a login landing in
        // the same wall-clock second as the accept counts as predating it — and
        // that login is the very next thing the invitee does. Without the
        // second, setting a password and immediately signing in answers
        // `credentials_changed`: "your credentials were changed, sign in again",
        // to somebody who just did. It is the mirror of
        // `SessionTimeout::beginAfterCredentialChange()`, which moves the
        // session forward for the same tie; here it is the epoch that moves,
        // because the session does not exist yet.
        //
        // Nothing real is spared by it. The only session it now fails to end is
        // one authenticated inside the same second as the acceptance, which
        // cannot be the pre-existing session this guard is for.
        $this->adminUsers->touchCredentialsEpoch($adminUserId, time() - 1);

        return ['email' => (string) $admin['email']];
    }

    /**
     * Whether this account is still waiting on its first sign-in credential.
     *
     * "Invited and never accepted". An account predating invitations has no
     * row at all and is therefore not pending — it was created with a password
     * somebody already passed on, and the way back in for it is a reset.
     *
     * @param list<string> $adminUserIds
     * @return array<string,bool>
     */
    public function pendingByAdminIds(array $adminUserIds): array
    {
        return $this->invitations->pendingByAdminIds($adminUserIds);
    }

    /**
     * The row a token names, if it is usable.
     *
     * Unknown, expired, accepted and revoked all leave by the same door with
     * the same code. The distinction is logged, never answered: an anonymous
     * caller told "expired" rather than "unknown" has learned that the token
     * existed, and that is enough to make this endpoint an oracle.
     *
     * @return array<string,mixed>
     */
    private function requireValid(string $token): array
    {
        if (!InvitationLink::looksLikeToken($token)) {
            throw $this->invalid('malformed');
        }

        $invitation = $this->invitations->findByTokenHash(InvitationLink::hash($token));

        if ($invitation === null) {
            throw $this->invalid('unknown');
        }

        if ($invitation['accepted_at'] !== null) {
            throw $this->invalid('already accepted');
        }

        if ($invitation['revoked_at'] !== null) {
            throw $this->invalid('revoked by a newer invitation');
        }

        if (strtotime((string) $invitation['expires_at']) < time()) {
            throw $this->invalid('expired');
        }

        return $invitation;
    }

    private function invalid(string $why): BusinessRuleException
    {
        // The log is where the four causes stay distinguishable, and it is the
        // only place they need to be: an admin asked "why did their link not
        // work?" reads this, the invitee is told to ask for a new one.
        $this->logger->info('Invitation link refused', ['why' => $why]);

        return new BusinessRuleException(
            BusinessRuleReason::INVITATION_INVALID,
            'This invitation link is not valid',
        );
    }
}
