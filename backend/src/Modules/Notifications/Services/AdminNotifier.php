<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\DTOs\EnqueueResultDto;
use App\Modules\Notifications\DTOs\MailRequestDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Repositories\MailConfigRepository;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;

/**
 * Operational mail addressed to whoever runs the club (#438, ADR-0038).
 *
 * Split out of {@see NotificationsService} — which still exposes
 * `warnAdmins()` and forwards to this — for a reason that is about
 * dependencies rather than about size. `NotificationsService` needs
 * `MembersRepository` for the money mail, and that reaches
 * {@see \App\Shared\Security\IbanSealedBox}, which refuses to construct without
 * `IBAN_FINGERPRINT_KEY`. Any caller that wanted only the admin fan-out
 * therefore had to satisfy the IBAN configuration to enrol a terminal —
 * ADR-0043's issuance notice is the first such caller, and the coupling showed
 * up as a service that could not be built in a test run that has no bank
 * details anywhere near it.
 *
 * So what this class holds is exactly what admin-addressed mail needs: the
 * queue, the admin list, the club address, the audit log, and a logger for the
 * one case none of those can record — a notice with nobody to send it to. No
 * member ever appears here.
 *
 * Like everything on this side of ADR-0038 it **only queues**. It opens no
 * socket; the scheduler is the only sender (rule 3).
 */
class AdminNotifier
{
    public function __construct(
        private MailOutboxRepository $mailOutboxRepository,
        private AdminUsersRepository $adminUsersRepository,
        private AuditService $auditService,
        private MailConfigRepository $mailConfigRepository,
        private Logger $logger,
    ) {}

    /**
     * Warn whoever runs the club about something, at most once per occasion
     * (#438).
     *
     * The occasion is the point. An expiry warning is computed from a tier the
     * dashboard already recalculates on every request, so "is the key inside
     * the 30-day window?" is true for thirty days running — and a queue that
     * took that at face value would send thirty emails. Passing the tier as the
     * occasion makes `UNIQUE (kind, subject_id, dedup_key)` answer "has this
     * already been said?" for us, which is the idempotent-notification storage
     * #438 says it needs, and a stronger answer than the `logOnceSince` dedup
     * it names as the nearest precedent: that one is a time window, this one is
     * a constraint.
     *
     * An occasion need not be a tier. ADR-0043's issuance notice passes an
     * *event* — `enrolled:<generation>` — because a credential is minted once
     * rather than staying true for thirty days, and two mintings for one
     * terminal are two things to be told about. The constraint does the same
     * job either way: it answers "has this already been said?", and what
     * counts as "this" is the caller's to define.
     *
     * Every active admin **holding an office this kind is addressed to** is
     * written to ({@see MailKind::recipientRoles()}), and each is deduplicated
     * separately — one admin having already been warned must not silence the
     * others. When nobody holds such an office the notice is escalated to the
     * club address rather than widened back to everybody; see the comment at
     * the fan-out below.
     *
     * The caller supplies the occasion and nothing else about timing. This
     * queues; it does not decide when anything is due, and it never sends
     * (ADR-0038 rule 3: the scheduler is the only sender).
     *
     * @param string $occasion What makes this warning distinct from the next one
     *                         about the same subject — a tier such as `30d`, or
     *                         an event such as `rotated:20260816142317`.
     */
    public function warnAdmins(
        MailKind $kind,
        string $subjectId,
        string $occasion,
        ?string $actorAdminUserId = null,
    ): EnqueueResultDto {
        if ($kind->addressesMember()) {
            // A member has no way to act on an expiring credential, and telling
            // them one is expiring leaks the state of the club's own security.
            throw new \InvalidArgumentException(
                sprintf('%s is addressed to a member and cannot be sent to admins', $kind->value)
            );
        }

        $queued = 0;
        $alreadyQueued = 0;
        $withoutEmail = [];

        // Only the offices this kind is for (#633). Before this, an account
        // holding `getraenkewart` alone was told an encryption key's
        // fingerprint and which accounts had been promoted — every one of them
        // behind an `admin`-only route in the panel.
        $roles = $kind->recipientRoles();
        $recipients = $this->adminUsersRepository->findActiveRecipientsWithAnyRole($roles);

        foreach ($recipients as $admin) {
            $email = trim((string) ($admin['email'] ?? ''));
            if ($email === '') {
                $withoutEmail[] = (string) $admin['id'];
                continue;
            }

            if ($this->mailOutboxRepository->enqueue(MailRequestDto::forAdmin(
                kind: $kind,
                subjectId: $subjectId,
                adminUserId: (string) $admin['id'],
                recipient: $email,
                language: MailLanguage::fromPreferred($admin['locale'] ?? null),
                occasion: $occasion,
                actorAdminUserId: $actorAdminUserId,
            ))) {
                $queued++;
            } else {
                // The unique index refused it: this admin has already been told
                // about this occasion. Counted rather than ignored, because a
                // repeating caller (#438) needs "already said" and "nothing to
                // say" to be distinguishable — both are zero queued.
                $alreadyQueued++;
            }
        }

        // Nobody holds an office this kind is for. `AdminUsersService` will not
        // let the last `admin` be demoted or deactivated, so reaching this
        // means something outside the application put the installation there —
        // a hand-edited row, a restore from a partial dump — and the answer has
        // to be a decision rather than whatever the filter happens to do.
        //
        // It is **not** "fall back to every active admin": that is the leak
        // this issue is about, and it would arrive exactly when the
        // installation is least able to notice. The notice is escalated to the
        // club address instead — configured, `admin`-only to change, and
        // already the second pair of eyes ADR-0044 rule 3 relies on — and
        // reported as unreachable when there is no club address either, so a
        // warning that reached nobody is visible instead of silent.
        $nobodyEligible = $roles !== [] && $recipients === [];

        // The club-level copy (ADR-0044 rule 3), for admin lifecycle kinds —
        // plus, from #633, any kind whose own offices are unstaffed. It is
        // *additional* to the fan-out above, never instead of it: the point is
        // a second pair of eyes, and with exactly one admin the first pair
        // belongs to whoever just acted.
        //
        // Read from `mail_config`, which the grant table keeps `admin`-only —
        // that placement is what stops a Kassenwart redirecting the channel
        // that would report their own promotion.
        $club = ($kind->addressesClub() || $nobodyEligible) ? $this->clubAddress() : null;
        if ($club !== null) {
            if ($this->mailOutboxRepository->enqueue(MailRequestDto::forClub(
                kind: $kind,
                subjectId: $subjectId,
                recipient: $club,
                // The club address belongs to no account and therefore has no
                // `preferred_language`; the installation's own default is the
                // closest true answer.
                language: MailLanguage::German,
                occasion: $occasion,
                actorAdminUserId: $actorAdminUserId,
            ))) {
                $queued++;
            }
        }

        $result = new EnqueueResultDto(
            $queued,
            $withoutEmail,
            alreadyQueued: $alreadyQueued,
            nobodyEligible: $nobodyEligible,
        );

        if ($nobodyEligible && $club === null) {
            // No office to write to and no club address to escalate to, so this
            // notice reached nobody at all. Keyed on the address rather than on
            // `$queued`, because a club copy the unique index refused is still
            // a message sitting in the queue — "already said" is not "said to
            // nobody".
            //
            // Logged rather than audited: the audit entry below is written only
            // when something was queued, and the repeating callers (#438) run
            // off a request-time check — an audit row per admin page load would
            // bury the one that matters. A broken installation is precisely
            // what the log is for.
            $this->logger->warning('Admin notice has no eligible recipient', [
                'kind' => $kind->value,
                'subject_id' => $subjectId,
                'occasion' => $occasion,
                'roles' => AdminRole::toValues($roles),
            ]);
        }

        // Audited only when something was actually queued: this runs off a
        // request-time check that fires on every admin page load, and an audit
        // entry per page load would bury the one that matters.
        if ($queued > 0) {
            $this->auditService->log(
                action: AuditAction::MAIL_ENQUEUED,
                entityType: $kind->subjectType()->auditEntityType(),
                entityId: $subjectId,
                newValues: ['kind' => $kind->value, 'occasion' => $occasion] + $result->toArray(),
                adminUserId: $actorAdminUserId,
            );
        }

        return $result;
    }

    /**
     * The configured club address, or null when there is none.
     *
     * Unset degrades to exactly today's behaviour — active admins only, no
     * error, nothing queued to nowhere. An installation that never configures
     * this is not misconfigured; it simply has not bought the property. A
     * failure to read the config is the same answer for the same reason: this
     * runs inside actions that must not fail because a notification could not
     * be addressed.
     */
    private function clubAddress(): ?string
    {
        try {
            $address = trim((string) (($this->mailConfigRepository->getConfig() ?? [])['club_notification_address'] ?? ''));
        } catch (\Throwable) {
            return null;
        }

        return $address === '' ? null : $address;
    }
}
