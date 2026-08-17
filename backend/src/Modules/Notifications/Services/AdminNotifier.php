<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\DTOs\EnqueueResultDto;
use App\Modules\Notifications\DTOs\MailRequestDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Shared\Enums\AuditAction;
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
 * queue, the admin list, and the audit log. No member ever appears here.
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
     * Every active admin is written to, and each is deduplicated separately —
     * one admin having already been warned must not silence the others.
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

        foreach ($this->adminUsersRepository->findActiveRecipients() as $admin) {
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

        $result = new EnqueueResultDto($queued, $withoutEmail, alreadyQueued: $alreadyQueued);

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
}
