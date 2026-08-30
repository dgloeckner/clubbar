<?php

declare(strict_types=1);

namespace App\Modules\AdminUsers\Services;

use App\Modules\AdminUsers\DTOs\AdminInvitationDto;
use App\Modules\AdminUsers\DTOs\AdminUserDto;
use App\Modules\AdminUsers\Enums\AdminRole;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Modules\AdminUsers\Repositories\AdminUserRolesRepository;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Modules\Notifications\Services\NotificationsService;
use App\Shared\Services\AuditService;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;

class AdminUsersService
{
    public function __construct(
        private AdminUsersRepository $adminUsersRepository,
        private AuditService $auditService,
        private NotificationsService $notificationsService,
        private AdminUserRolesRepository $adminUserRolesRepository,
        private AdminNotifier $adminNotifier,
        private AdminInvitationService $invitationService,
    ) {}

    /**
     * The roles this account holds (ADR-0044).
     *
     * What `GET /auth/profile` reports and what the panel renders from. The
     * *enforcing* read is `AdminSessionAuth`'s, which goes to the repository
     * directly — a gate that ran through a service would be one more layer
     * between the request and the answer.
     *
     * @return list<AdminRole>
     */
    public function getRoles(string $id): array
    {
        return $this->adminUserRolesRepository->rolesFor($id);
    }

    /**
     * Make an account's roles exactly this set, and record what moved.
     *
     * An empty set is refused. It is not a restricted account, it is one that
     * can do nothing at all — and silently, since nothing in the account form
     * says a role is what makes the login useful. Revoking somebody's last
     * role is spelled "deactivate the account", which already exists and says
     * what it means.
     *
     * The audit rows are written from the **diff**, not from the submitted
     * set: a PATCH that re-sends the roles an account already holds is a save,
     * not a grant, and a log where every save looks like an escalation is a log
     * nobody reads. A request that both grants and revokes writes both rows —
     * two events that happened to arrive together.
     *
     * @param list<AdminRole> $roles
     * @return bool Whether anything actually changed.
     */
    public function setRoles(string $id, array $roles, ?string $currentAdminId = null): bool
    {
        if (!$this->applyRoles($id, $roles, $currentAdminId)) {
            return false;
        }

        // The out-of-band half of ADR-0044 rule 2. The step-up proves who is
        // acting and the audit rows record it; this is what reaches the people
        // who were *not* acting — every other active admin, and the club-level
        // address — through a channel the compromised session does not hold.
        $this->announce(MailKind::ADMIN_ROLE_CHANGED, $id, 'changed', $currentAdminId);

        return true;
    }

    /**
     * Write the role set and audit what moved, announcing nothing.
     *
     * Separate from {@see setRoles()} because account creation goes through it
     * too, and a brand-new account must not also be announced as one whose
     * roles *changed*: it has no history to have changed from. The caller that
     * knows which event this is owns the announcement.
     *
     * @param list<AdminRole> $roles
     * @return bool Whether anything actually changed.
     */
    private function applyRoles(string $id, array $roles, ?string $currentAdminId): bool
    {
        if ($roles === []) {
            throw new BusinessRuleException(
                BusinessRuleReason::ADMIN_USER_NEEDS_A_ROLE,
                'An admin user must hold at least one role',
            );
        }

        $after = AdminRole::fromValues(AdminRole::toValues($roles));

        // Admin-exclusivity (CONTEXT.md's Role entry, ADR-0044): an account's
        // role set is either `admin` alone, or any non-empty subset of the two
        // lesser roles. `admin` combined with a lesser role is not "more
        // privileged" — it is a state the domain does not recognise.
        if (in_array(AdminRole::ADMIN, $after, true) && count($after) > 1) {
            throw new BusinessRuleException(
                BusinessRuleReason::ADMIN_ROLE_IS_EXCLUSIVE,
                'admin cannot be combined with a lesser role',
            );
        }

        $before = $this->adminUserRolesRepository->rolesFor($id);

        // Defense in depth for "never neuter the last admin". Nothing on the
        // request path is supposed to reach this today — `PATCH
        // /admin-users/{id}` is `admin`-only (RouteRoleMap) and the controller
        // refuses a caller editing their own roles — so the only way to revoke
        // `admin` from its last holder is for a *different* admin to do it,
        // which this guard would also refuse. It exists anyway: a fragile
        // invariant whose only backing is "nothing else calls this function
        // yet" is worth two lines to make explicit.
        $revokingAdmin = in_array(AdminRole::ADMIN, $before, true) && !in_array(AdminRole::ADMIN, $after, true);
        if ($revokingAdmin && $this->adminUserRolesRepository->countActiveHolders(AdminRole::ADMIN) <= 1) {
            throw new BusinessRuleException(
                BusinessRuleReason::LAST_ADMIN_ROLE_HOLDER,
                'Cannot remove admin from the last account holding it',
            );
        }

        $granted = array_values(array_diff(AdminRole::toValues($after), AdminRole::toValues($before)));
        $revoked = array_values(array_diff(AdminRole::toValues($before), AdminRole::toValues($after)));

        if ($granted === [] && $revoked === []) {
            return false;
        }

        $this->adminUserRolesRepository->replace($id, $after);

        // Both lists ride on both rows. Reading "granted: admin" without
        // knowing the account now holds *only* admin tells you less than half
        // of what changed, and the row is the only artefact a reviewer has.
        if ($granted !== []) {
            $this->auditService->log(
                action: AuditAction::ROLE_GRANTED,
                entityType: EntityType::ADMIN_USER,
                entityId: $id,
                oldValues: ['roles' => AdminRole::toValues($before)],
                newValues: ['roles' => AdminRole::toValues($after), 'granted' => $granted],
                adminUserId: $currentAdminId,
            );
        }

        if ($revoked !== []) {
            $this->auditService->log(
                action: AuditAction::ROLE_REVOKED,
                entityType: EntityType::ADMIN_USER,
                entityId: $id,
                oldValues: ['roles' => AdminRole::toValues($before)],
                newValues: ['roles' => AdminRole::toValues($after), 'revoked' => $revoked],
                adminUserId: $currentAdminId,
            );
        }

        return true;
    }

    /**
     * Whether applying this role set would change anything.
     *
     * The controller asks before deciding to demand a step-up, mirroring
     * `PATCH /profile`, which gates on the email actually moving rather than on
     * every save. Re-submitting an unchanged role set alongside a display-name
     * edit must not demand a password and a fresh TOTP code.
     *
     * @param list<AdminRole> $roles
     */
    public function rolesWouldChange(string $id, array $roles): bool
    {
        $before = AdminRole::toValues($this->adminUserRolesRepository->rolesFor($id));
        $after = AdminRole::toValues(AdminRole::fromValues(AdminRole::toValues($roles)));

        return $before !== $after;
    }

    public function listAdminUsers(int $limit, int $offset, array $filters = []): PaginatedResultDto
    {
        $result = $this->adminUsersRepository->listPaginated($limit, $offset, $filters);
        // One query for the page's roles rather than one per row: the list is
        // the surface #516 renders a role column from, and N+1 here would be
        // paid on every keystroke of the admin search.
        $roles = $this->adminUserRolesRepository->rolesForMany(
            array_map(static fn(array $row): string => $row['id'], $result['items'])
        );

        // The same one-query-per-page reasoning as the roles above: the list
        // renders a "waiting for their invitation" marker (migration 058), and
        // asking per row would be an N+1 paid on every keystroke of the admin
        // search.
        $pending = $this->invitationService->pendingByAdminIds(
            array_map(static fn(array $row): string => $row['id'], $result['items'])
        );

        $items = array_map(
            fn($row) => AdminUserDto::fromRow(
                $row,
                $roles[$row['id']] ?? [],
                // Absent from the map means the account was never invited —
                // it predates invitations — which is not pending.
                $pending[$row['id']] ?? false,
            )->toArray(),
            $result['items'],
        );

        return new PaginatedResultDto(items: $items, total: $result['total'], limit: $limit, offset: $offset);
    }

    public function findAdminUserById(string $id): ?AdminUserDto
    {
        $row = $this->adminUsersRepository->findById($id);
        if (!$row) {
            return null;
        }

        return AdminUserDto::fromRow(
            $row,
            $this->adminUserRolesRepository->rolesFor($id),
            $this->invitationService->pendingByAdminIds([$id])[$id] ?? false,
        );
    }

    /**
     * Create an account and mail its owner the link that gives it a password
     * (migration 058).
     *
     * The account is written with **no usable password at all** — a hash of 32
     * random bytes nobody has ever seen, and which nothing can produce again.
     * That is the whole change: this method used to mint a real password and
     * hand it back to the caller, who then had to move a live credential to
     * their colleague through a channel of their own choosing. Now the only way
     * into the account is the invitation, which expires, works once, and sets a
     * secret the caller never learns.
     *
     * The placeholder is not decoration. Without it the row would need a
     * nullable hash, and every path that compares a password would grow a "no
     * password set" branch — including `AuthService::authenticate()`, where
     * getting that branch wrong once means an account anyone can sign into. An
     * unguessable hash makes "cannot sign in yet" fall out of the ordinary
     * comparison instead.
     *
     * @param list<AdminRole> $roles Defaults to `admin` — see below.
     * @return array{admin: AdminUserDto, invitation: AdminInvitationDto}
     */
    public function createAdminUser(
        string $email,
        string $displayName,
        string $locale,
        ?string $currentAdminId = null,
        array $roles = [],
    ): array {
        $hash = password_hash(base64_encode(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12]);

        $admin = $this->adminUsersRepository->create([
            'email' => $email,
            'password' => $hash,
            'display_name' => $displayName,
            'locale' => $locale,
            'is_active' => true,
        ]);

        // `admin` when the caller names no role. That is behaviour-preserving
        // — every admin the panel created before roles existed had full access
        // — and it is the safe direction for a default to fail: an account
        // created with no role at all could open nothing, including the page
        // that would fix it.
        $roles = $roles === [] ? [AdminRole::ADMIN] : $roles;

        // Through `applyRoles`, not straight to the repository, so a creation
        // also writes its ROLE_GRANTED row. "Who gained `admin` last quarter"
        // has to return the accounts that were *created* as admin, not only
        // the ones promoted later. `applyRoles` rather than `setRoles` because
        // this is not a role *change* — it is the account's first state, and
        // announcing it as a change would be a second, wrong message.
        $this->applyRoles($admin['id'], $roles, $currentAdminId);

        $this->auditService->log(
            action: AuditAction::CREATE,
            entityType: EntityType::ADMIN_USER,
            entityId: $admin['id'],
            newValues: [
                'email' => $email,
                'display_name' => $displayName,
                // No password was generated for anyone to carry — the account
                // is unusable until its owner follows the invitation.
                'password' => '[AWAITING_INVITATION]',
                'roles' => AdminRole::toValues($roles),
            ],
            adminUserId: $currentAdminId,
        );

        // ADR-0044 rule 3, and the event the ADR calls the *loud* path: it is
        // announced to every active admin and to the club address, so that a
        // single-admin installation still has a witness other than whoever
        // just acted.
        $this->announce(MailKind::ADMIN_ACCOUNT_CREATED, $admin['id'], 'created', $currentAdminId);

        // After the announcement, and deliberately not wrapped in the same
        // best-effort swallow. The announcement is a courtesy to the other
        // admins; this is the only thing that makes the new account usable, so
        // a failure here is a failure of the request and has to read as one.
        $invitation = $this->invitationService->issue($admin['id'], $currentAdminId);

        return ['admin' => $this->withRoles($admin), 'invitation' => $invitation];
    }

    /**
     * Queue an admin lifecycle notice, and never let it fail the thing it
     * announces.
     *
     * The account has already been created, or the roles already moved, by the
     * time this runs. A queue that will not take the notice is a smaller
     * problem than a caller told their change failed when it did not — the
     * same reasoning `onEmailChanged()` applies below, and ADR-0038 rule 3's
     * position that this only ever queues.
     *
     * The occasion carries the moment rather than a tier, so two changes to one
     * account are two messages rather than one the unique index swallows. Unix
     * seconds, because `forAdmin()` appends a 36-character UUID to build the
     * dedup key and the column is VARCHAR(64) — a formatted timestamp overruns
     * it.
     */
    private function announce(MailKind $kind, string $adminUserId, string $event, ?string $actorAdminUserId): void
    {
        try {
            $this->adminNotifier->warnAdmins(
                kind: $kind,
                subjectId: $adminUserId,
                occasion: $event . ':' . time(),
                actorAdminUserId: $actorAdminUserId,
            );
        } catch (\Throwable) {
            // Deliberately swallowed rather than audited: a failed enqueue is
            // not a role event, and writing one under ROLE_GRANTED would put
            // noise in the one query — "who gained admin" — that these actions
            // exist to answer.
        }
    }

    /**
     * True when the address is already registered to some *other* admin user.
     *
     * `admin_users.email` is UNIQUE, so a duplicate that reaches the INSERT
     * comes back as a PDOException and a 500. Both write paths — the
     * admin-users endpoint and the own-profile endpoint — ask this first (#117).
     */
    public function emailTakenByAnother(string $email, ?string $excludeId = null): bool
    {
        $existing = $this->adminUsersRepository->findByEmail($email);

        return $existing !== null && $existing['id'] !== $excludeId;
    }

    /**
     * Apply a partial update.
     *
     * A body carrying `is_active` alongside `email`, `display_name` or `locale`
     * used to (de)activate and return early, silently discarding the other
     * three fields (#117). All of them are applied now.
     *
     * The activation change goes first on purpose: its guard rails — no
     * deactivating your own account, no deactivating the last active admin —
     * throw before anything is written, so a refused request leaves the record
     * exactly as it was rather than half-updated.
     */
    public function updateAdminUser(string $id, array $validated, ?string $currentAdminId = null): ?AdminUserDto
    {
        $activated = null;
        if (array_key_exists('is_active', $validated)) {
            $active = filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN);

            $activated = $active
                ? $this->reactivateAdminUser($id, $currentAdminId)
                : $this->deactivateAdminUser($id, $currentAdminId ?? '');
        }

        $data = [];
        if (isset($validated['email'])) $data['email'] = $validated['email'];
        if (isset($validated['display_name'])) $data['display_name'] = $validated['display_name'];
        if (isset($validated['locale'])) $data['locale'] = $validated['locale'];

        if (empty($data)) return $activated ?? $this->findAdminUserById($id);

        // Read before the write: the old address is what the audit entry and the
        // notification are about, and it is gone the moment the UPDATE lands.
        $before = $this->adminUsersRepository->findById($id);
        $previousEmail = $before['email'] ?? null;
        $emailMoved = isset($data['email'])
            && $previousEmail !== null
            && strcasecmp($data['email'], $previousEmail) !== 0;

        $admin = $this->adminUsersRepository->updateById($id, $data);
        if (!$admin) return null;

        if ($emailMoved) {
            $this->onEmailChanged($id, $previousEmail, $data['email'], $currentAdminId);
        }

        // The email half is audited under its own action above; what is left
        // here is the ordinary profile edit.
        $remaining = $emailMoved ? array_diff_key($data, ['email' => null]) : $data;
        if (!empty($remaining)) {
            $this->auditService->log(
                action: AuditAction::UPDATE,
                entityType: EntityType::ADMIN_USER,
                entityId: $id,
                newValues: $remaining,
                adminUserId: $currentAdminId,
            );
        }

        return $this->withRoles($admin);
    }

    /**
     * The consequences of moving a login identifier: the old address is told,
     * the change is audited under its own name, and every session that
     * predates it stops working.
     *
     * The notification is best effort by construction — it is queued, not sent
     * (ADR-0038), and an install with no `mail.dsn` queues it to a transport
     * that logs and discards. None of that may block the change itself, which
     * has already been committed by the time this runs.
     */
    private function onEmailChanged(
        string $id,
        string $previousEmail,
        string $newEmail,
        ?string $currentAdminId,
    ): void {
        $this->auditService->log(
            action: AuditAction::EMAIL_CHANGED,
            entityType: EntityType::ADMIN_USER,
            entityId: $id,
            oldValues: ['email' => $previousEmail],
            newValues: ['email' => $newEmail],
            adminUserId: $currentAdminId,
        );

        // Never allowed to fail the change it describes. The change is already
        // committed; a queue that will not take the notice is a smaller problem
        // than an admin told their address did not move when it did.
        try {
            // Unix seconds, not a formatted date: `forAdmin` appends a 36-char
            // UUID to build the dedup key and the column is VARCHAR(64), which
            // a 'Y-m-d H:i:s' occasion overruns.
            $this->notificationsService->notifyFormerAddress(
                adminUserId: $id,
                formerEmail: $previousEmail,
                occasion: 'changed:' . time(),
                actorAdminUserId: $currentAdminId,
            );
        } catch (\Throwable $e) {
            $this->auditService->log(
                action: AuditAction::EMAIL_CHANGED,
                entityType: EntityType::ADMIN_USER,
                entityId: $id,
                newValues: ['notification_failed' => $e->getMessage()],
                adminUserId: $currentAdminId,
            );
        }

        $this->adminUsersRepository->touchCredentialsEpoch($id);
    }

    public function deactivateAdminUser(string $id, string $currentAdminId): AdminUserDto
    {
        if ($id === $currentAdminId) {
            throw new BusinessRuleException(
                BusinessRuleReason::CANNOT_DEACTIVATE_SELF,
                'Cannot deactivate own account',
            );
        }

        // Role-aware, not "the last active account of any kind" (#548): that
        // blanket count let this account be deactivated as long as some
        // Kassenwart/Getränkewart account was still active, even if it was
        // the system's only remaining `admin`. Defense in depth, same as the
        // guard in `applyRoles()` — `PATCH .../{id}` is `admin`-only and
        // self-deactivation is refused above, so the caller reaching this line
        // is always a *different*, still-active admin.
        $roles = $this->adminUserRolesRepository->rolesFor($id);
        if (in_array(AdminRole::ADMIN, $roles, true)
            && $this->adminUserRolesRepository->countActiveHolders(AdminRole::ADMIN) <= 1
        ) {
            throw new BusinessRuleException(
                BusinessRuleReason::LAST_ACTIVE_ADMIN,
                'Cannot deactivate the last active admin',
            );
        }

        $admin = $this->adminUsersRepository->updateById($id, ['is_active' => 0]);
        if (!$admin) throw NotFoundException::forResource('AdminUser', $id);

        $this->auditService->log(
            action: AuditAction::DEACTIVATE,
            entityType: EntityType::ADMIN_USER,
            entityId: $id,
            newValues: ['is_active' => false],
            adminUserId: $currentAdminId,
        );

        return $this->withRoles($admin);
    }

    public function reactivateAdminUser(string $id, ?string $currentAdminId = null): AdminUserDto
    {
        $admin = $this->adminUsersRepository->updateById($id, ['is_active' => 1]);
        if (!$admin) throw NotFoundException::forResource('AdminUser', $id);

        $this->auditService->log(
            action: AuditAction::ACTIVATE,
            entityType: EntityType::ADMIN_USER,
            entityId: $id,
            newValues: ['is_active' => true],
            adminUserId: $currentAdminId,
        );

        return $this->withRoles($admin);
    }

    public function resetAdminPassword(string $targetAdminId, ?string $currentAdminId = null): array
    {
        $password = $this->generateRandomPassword();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $admin = $this->adminUsersRepository->updateById($targetAdminId, ['password' => $hash]);
        if (!$admin) throw NotFoundException::forResource('AdminUser', $targetAdminId);

        $this->auditService->log(
            action: AuditAction::PASSWORD_CHANGED,
            entityType: EntityType::ADMIN_USER,
            entityId: $targetAdminId,
            newValues: ['password' => '[RESET]'],
            adminUserId: $currentAdminId,
        );

        // UC-A63 has always said a reset invalidates the target's sessions; now
        // it does. The target is someone else, so no session of the caller's is
        // affected — unless they reset their own, which the epoch handles the
        // same way as any other credential change.
        $this->adminUsersRepository->touchCredentialsEpoch($targetAdminId);

        return ['admin' => $this->withRoles($admin), 'password' => $password];
    }

    public function verifyCurrentPassword(string $adminId, string $currentPassword): bool
    {
        $admin = $this->adminUsersRepository->findById($adminId);
        if (!$admin) {
            return false;
        }

        return password_verify($currentPassword, $admin['password_hash']);
    }

    public function changeOwnPassword(string $adminId, string $newPassword): void
    {
        $admin = $this->adminUsersRepository->findById($adminId);
        if (!$admin) throw NotFoundException::forResource('AdminUser', $adminId);

        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->adminUsersRepository->updateById($adminId, ['password' => $hash]);

        $this->auditService->log(
            action: AuditAction::PASSWORD_CHANGED,
            entityType: EntityType::ADMIN_USER,
            entityId: $adminId,
            newValues: ['password' => '[CHANGED]'],
            adminUserId: $adminId,
        );

        // Every other session on this account stops working. The caller keeps
        // theirs: `AuthController::changePassword` re-stamps it immediately
        // after this returns.
        $this->adminUsersRepository->touchCredentialsEpoch($adminId);
    }

    /**
     * A DTO carrying the account's current roles.
     *
     * Every write path returns the row it just wrote, and a response whose
     * `roles` were silently empty would read as "this account holds none" —
     * which is a state the system refuses to create.
     *
     * @param array<string, mixed> $row
     */
    private function withRoles(array $row): AdminUserDto
    {
        return AdminUserDto::fromRow($row, $this->adminUserRolesRepository->rolesFor($row['id']));
    }

    private function generateRandomPassword(int $length = 16): string
    {
        $lower = 'abcdefghijklmnopqrstuvwxyz';
        $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $digits = '0123456789';
        $specials = '!@#$%^&*';
        $all = $lower . $upper . $digits . $specials;

        // Guarantee at least one from each category
        $password = $lower[random_int(0, strlen($lower) - 1)]
            . $upper[random_int(0, strlen($upper) - 1)]
            . $digits[random_int(0, strlen($digits) - 1)]
            . $specials[random_int(0, strlen($specials) - 1)];

        $max = strlen($all) - 1;
        for ($i = 4; $i < $length; $i++) {
            $password .= $all[random_int(0, $max)];
        }

        // Shuffle to avoid predictable positions
        $chars = str_split($password);
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
