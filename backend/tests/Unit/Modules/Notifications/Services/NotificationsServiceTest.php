<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Notifications\DTOs\MailRequestDto;
use App\Modules\Notifications\Enums\CronInterval;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailStatus;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Services\NotificationsService;
use App\Modules\Notifications\Services\RetrySchedule;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Mail\MailSendResult;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * Who gets queued, who does not, and what a cancellation does to each
 * (ADR-0038, #402).
 *
 * The uniqueness of a message is a database constraint and is asserted against
 * the real schema in {@see \Tests\Feature\Database\MailOutboxSchemaTest}. What
 * is tested here is the policy sitting on top of it: which members produce a
 * row at all.
 */
class NotificationsServiceTest extends TestCase
{
    private MailOutboxRepository $outbox;
    private MembersRepository $members;
    private AuditService $audit;
    private AdminUsersRepository $admins;
    private NotificationsService $service;

    private const SETTLEMENT = '11111111-1111-4111-8111-111111111111';
    private const ADMIN = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        $this->outbox = $this->createMock(MailOutboxRepository::class);
        $this->members = $this->createMock(MembersRepository::class);
        $this->audit = $this->createMock(AuditService::class);
        $this->admins = $this->createMock(AdminUsersRepository::class);

        $this->service = new NotificationsService(
            $this->outbox,
            $this->members,
            $this->audit,
            $this->admins,
        );
    }

    /** @param array<string,array<string,mixed>> $overrides */
    private function recipients(array $overrides): array
    {
        $rows = [];
        foreach ($overrides as $id => $fields) {
            $rows[$id] = array_merge([
                'id' => $id,
                'first_name' => 'Max',
                'last_name' => 'Mustermann',
                'email' => 'max@example.org',
                'preferred_language' => 'de',
                'mandate_reference' => 'F3332CA866B249E7A202BFBF4836B605',
                'iban_last4' => '3000',
            ], $fields);
        }

        return $rows;
    }

    public function test_enqueueForSettlement_queues_one_message_per_collected_member(): void
    {
        $this->members->method('findMailRecipients')->willReturn($this->recipients([
            'm1' => ['email' => 'one@example.org'],
            'm2' => ['email' => 'two@example.org'],
        ]));

        $queued = [];
        $this->outbox->method('enqueue')->willReturnCallback(
            function (MailRequestDto $request) use (&$queued): bool {
                $queued[] = $request;
                return true;
            }
        );

        $result = $this->service->enqueueForSettlement(self::SETTLEMENT, ['m1' => 500, 'm2' => 250], self::ADMIN);

        $this->assertSame(2, $result->queued);
        $this->assertSame([], $result->withoutEmail);
        $this->assertCount(2, $queued);
        $this->assertSame(MailKind::SEPA_PRENOTIFICATION, $queued[0]->kind);
        $this->assertSame(self::SETTLEMENT, $queued[0]->subjectId);
        $this->assertSame('one@example.org', $queued[0]->recipient);
        $this->assertSame('two@example.org', $queued[1]->recipient);
        // The member is what makes two announcements about one settlement two
        // messages rather than one.
        $this->assertSame('m1', $queued[0]->dedupKey);
        $this->assertSame('m1', $queued[0]->memberId);
        $this->assertNull($queued[0]->adminUserId);
    }

    /**
     * A run can settle a member at zero — a storno cancelling their sales — and
     * that member is correctly part of the run (ruling #141 §5). Nothing is
     * collected from them, so there is nothing to announce.
     */
    public function test_enqueueForSettlement_skips_a_member_who_settles_at_zero(): void
    {
        $this->members->expects($this->once())
            ->method('findMailRecipients')
            ->with(['m1'])
            ->willReturn($this->recipients(['m1' => []]));

        $this->outbox->expects($this->once())->method('enqueue')->willReturn(true);

        $result = $this->service->enqueueForSettlement(self::SETTLEMENT, ['m1' => 500, 'm2' => 0], self::ADMIN);

        $this->assertSame(1, $result->queued);
        $this->assertSame(['m2'], $result->withoutBalance);
    }

    public function test_enqueueForSettlement_skips_a_member_in_credit(): void
    {
        $this->members->method('findMailRecipients')->willReturn([]);
        $this->outbox->expects($this->never())->method('enqueue');

        $result = $this->service->enqueueForSettlement(self::SETTLEMENT, ['m1' => -350], self::ADMIN);

        $this->assertSame(0, $result->queued);
        $this->assertSame(['m1'], $result->withoutBalance);
    }

    /**
     * The one skip that is a problem: this member is being collected from and
     * cannot be told. It is reported (#405's warning bucket) and it blocks
     * nothing — a member the club cannot reach still owes the money.
     */
    public function test_enqueueForSettlement_reports_a_collected_member_without_an_email(): void
    {
        $this->members->method('findMailRecipients')->willReturn($this->recipients([
            'm1' => ['email' => null],
            'm2' => ['email' => '   '],
            'm3' => ['email' => 'three@example.org'],
        ]));

        $this->outbox->expects($this->once())->method('enqueue')->willReturn(true);

        $result = $this->service->enqueueForSettlement(
            self::SETTLEMENT,
            ['m1' => 100, 'm2' => 200, 'm3' => 300],
            self::ADMIN,
        );

        $this->assertSame(1, $result->queued);
        $this->assertSame(['m1', 'm2'], $result->withoutEmail);
    }

    public function test_enqueueForSettlement_freezes_the_members_language(): void
    {
        $this->members->method('findMailRecipients')->willReturn($this->recipients([
            'm1' => ['preferred_language' => 'en'],
            'm2' => ['preferred_language' => 'de'],
        ]));

        $languages = [];
        $this->outbox->method('enqueue')->willReturnCallback(
            function (MailRequestDto $request) use (&$languages): bool {
                $languages[(string) $request->memberId] = $request->language->value;
                return true;
            }
        );

        $this->service->enqueueForSettlement(self::SETTLEMENT, ['m1' => 100, 'm2' => 100], self::ADMIN);

        $this->assertSame(['m1' => 'en', 'm2' => 'de'], $languages);
    }

    /**
     * French is a language a member may prefer and no announcement exists in
     * (see {@see \App\Modules\Notifications\Enums\MailLanguage}). The stored
     * value has to be the language the mail will actually be in.
     */
    public function test_enqueueForSettlement_stores_de_for_a_language_with_no_translation(): void
    {
        $this->members->method('findMailRecipients')->willReturn($this->recipients([
            'm1' => ['preferred_language' => 'fr'],
            'm2' => ['preferred_language' => null],
        ]));

        $languages = [];
        $this->outbox->method('enqueue')->willReturnCallback(
            function (MailRequestDto $request) use (&$languages): bool {
                $languages[] = $request->language->value;
                return true;
            }
        );

        $this->service->enqueueForSettlement(self::SETTLEMENT, ['m1' => 100, 'm2' => 100], self::ADMIN);

        $this->assertSame(['de', 'de'], $languages);
    }

    /**
     * A repeated enqueue is a no-op at the database, and the count has to say
     * so — otherwise a retried finalize would audit forty-seven announcements
     * that were already queued the first time.
     */
    public function test_enqueueForSettlement_does_not_count_a_message_that_already_existed(): void
    {
        $this->members->method('findMailRecipients')->willReturn($this->recipients([
            'm1' => [], 'm2' => [],
        ]));

        $this->outbox->method('enqueue')->willReturn(false);
        $this->audit->expects($this->never())->method('log');

        $result = $this->service->enqueueForSettlement(self::SETTLEMENT, ['m1' => 100, 'm2' => 100], self::ADMIN);

        $this->assertSame(0, $result->queued);
    }

    public function test_enqueueForSettlement_audits_the_commitment_to_announce(): void
    {
        $this->members->method('findMailRecipients')->willReturn($this->recipients(['m1' => []]));
        $this->outbox->method('enqueue')->willReturn(true);

        $logged = null;
        $this->audit->expects($this->once())->method('log')->willReturnCallback(
            function (AuditAction $action, EntityType $entityType, string $entityId, ?array $oldValues, ?array $newValues, ?string $adminUserId) use (&$logged): void {
                $logged = compact('action', 'entityType', 'entityId', 'newValues', 'adminUserId');
            }
        );

        $this->service->enqueueForSettlement(self::SETTLEMENT, ['m1' => 100], self::ADMIN);

        $this->assertSame(AuditAction::MAIL_ENQUEUED, $logged['action']);
        $this->assertSame(EntityType::SETTLEMENT, $logged['entityType']);
        $this->assertSame(self::SETTLEMENT, $logged['entityId']);
        $this->assertSame(self::ADMIN, $logged['adminUserId']);
        $this->assertSame(MailKind::SEPA_PRENOTIFICATION->value, $logged['newValues']['kind']);
        $this->assertSame(1, $logged['newValues']['queued']);
    }

    public function test_enqueueForSettlement_touches_nothing_when_no_member_is_collected_from(): void
    {
        $this->members->expects($this->never())->method('findMailRecipients');
        $this->outbox->expects($this->never())->method('enqueue');
        $this->audit->expects($this->never())->method('log');

        $result = $this->service->enqueueForSettlement(self::SETTLEMENT, ['m1' => 0], self::ADMIN);

        $this->assertSame(0, $result->queued);
    }

    /* ───────────────────────────── cancellation ───────────────────────────── */

    /**
     * Cancelled before the drain ran: nothing left the host, so there is
     * nothing to retract. Telling a member a collection is off when they were
     * never told it was coming is worse than saying nothing.
     */
    public function test_cancel_supersedes_unsent_announcements_and_sends_no_notice(): void
    {
        $this->outbox->method('findMemberIdsWithStatus')->willReturn([]);
        $this->outbox->expects($this->once())
            ->method('supersedePending')
            ->with(self::SETTLEMENT, MailKind::SEPA_PRENOTIFICATION)
            ->willReturn(3);

        $this->outbox->expects($this->never())->method('enqueue');

        $result = $this->service->cancelSettlementNotifications(self::SETTLEMENT, self::ADMIN);

        $this->assertSame(0, $result->queued);
    }

    public function test_cancel_notifies_only_the_members_whose_announcement_went_out(): void
    {
        $this->outbox->method('findMemberIdsWithStatus')
            ->with(self::SETTLEMENT, MailKind::SEPA_PRENOTIFICATION, MailStatus::SENT)
            ->willReturn(['m2']);
        $this->outbox->method('supersedePending')->willReturn(1);

        $this->members->method('findMailRecipients')->willReturn($this->recipients([
            'm2' => ['email' => 'two@example.org'],
        ]));

        $notices = [];
        $this->outbox->method('enqueue')->willReturnCallback(
            function (MailRequestDto $request) use (&$notices): bool {
                $notices[] = [$request->kind, $request->memberId, $request->recipient];
                return true;
            }
        );

        $result = $this->service->cancelSettlementNotifications(self::SETTLEMENT, self::ADMIN);

        $this->assertSame(1, $result->queued);
        $this->assertSame([[MailKind::CANCELLATION_NOTICE, 'm2', 'two@example.org']], $notices);
    }

    public function test_cancel_audits_the_supersede_separately_from_the_notices(): void
    {
        $this->outbox->method('findMemberIdsWithStatus')->willReturn([]);
        $this->outbox->method('supersedePending')->willReturn(2);

        $actions = [];
        $this->audit->method('log')->willReturnCallback(
            function (AuditAction $action) use (&$actions): void {
                $actions[] = $action;
            }
        );

        $this->service->cancelSettlementNotifications(self::SETTLEMENT, self::ADMIN);

        $this->assertSame([AuditAction::MAIL_SUPERSEDED], $actions);
    }

    /**
     * The announcement reached an address that has since been cleared —
     * corrected to nothing, or erased. There is no recipient left to retract
     * to, and inventing one is not an option.
     */
    public function test_cancel_reports_a_notified_member_whose_address_is_gone(): void
    {
        $this->outbox->method('findMemberIdsWithStatus')->willReturn(['m2']);
        $this->outbox->method('supersedePending')->willReturn(0);
        $this->members->method('findMailRecipients')->willReturn($this->recipients(['m2' => ['email' => null]]));

        $this->outbox->expects($this->never())->method('enqueue');

        $result = $this->service->cancelSettlementNotifications(self::SETTLEMENT, self::ADMIN);

        $this->assertSame(0, $result->queued);
        $this->assertSame(['m2'], $result->withoutEmail);
    }

    /* ──────────── Operational mail, addressed to an admin (#438) ──────────── */

    /**
     * The generalisation #438 needs: a message about a credential rather than a
     * settlement, addressed to whoever runs the club rather than to a member.
     */
    public function test_warnAdmins_queues_one_message_per_active_admin(): void
    {
        $this->admins->method('findActiveRecipients')->willReturn([
            ['id' => 'a1', 'email' => 'one@club.example', 'locale' => 'de', 'display_name' => 'One'],
            ['id' => 'a2', 'email' => 'two@club.example', 'locale' => 'en', 'display_name' => 'Two'],
        ]);

        $queued = [];
        $this->outbox->method('enqueue')->willReturnCallback(
            function (MailRequestDto $request) use (&$queued): bool {
                $queued[] = $request;
                return true;
            }
        );

        $result = $this->service->warnAdmins(MailKind::KEY_EXPIRY_WARNING, 'key-1', '30d');

        $this->assertSame(2, $result->queued);
        $this->assertSame('key-1', $queued[0]->subjectId);
        $this->assertSame('a1', $queued[0]->adminUserId);
        $this->assertNull($queued[0]->memberId, 'an operational warning is not member data');
        $this->assertSame('en', $queued[1]->language->value, "the admin's own locale");
    }

    /**
     * The whole point of the dedup key. The tier is recomputed on every admin
     * request for as long as the key sits inside the window, so "queue a
     * warning" has to mean "queue it once" — and each tier is its own message.
     */
    public function test_warnAdmins_keys_a_warning_to_its_tier_and_its_admin(): void
    {
        $this->admins->method('findActiveRecipients')->willReturn([
            ['id' => 'a1', 'email' => 'one@club.example', 'locale' => 'de', 'display_name' => 'One'],
            ['id' => 'a2', 'email' => 'two@club.example', 'locale' => 'de', 'display_name' => 'Two'],
        ]);

        $keys = [];
        $this->outbox->method('enqueue')->willReturnCallback(
            function (MailRequestDto $request) use (&$keys): bool {
                $keys[] = $request->dedupKey;
                return true;
            }
        );

        $this->service->warnAdmins(MailKind::KEY_EXPIRY_WARNING, 'key-1', '90d');
        $this->service->warnAdmins(MailKind::KEY_EXPIRY_WARNING, 'key-1', '30d');

        // Four distinct messages: two tiers × two admins. One admin having been
        // warned must never silence the other, and 90 must not silence 30.
        $this->assertSame(['90d:a1', '90d:a2', '30d:a1', '30d:a2'], $keys);
        $this->assertCount(4, array_unique($keys));
    }

    public function test_warnAdmins_audits_against_the_credential_not_a_settlement(): void
    {
        $this->admins->method('findActiveRecipients')->willReturn([
            ['id' => 'a1', 'email' => 'one@club.example', 'locale' => 'de', 'display_name' => 'One'],
        ]);
        $this->outbox->method('enqueue')->willReturn(true);

        $logged = null;
        $this->audit->expects($this->once())->method('log')->willReturnCallback(
            function (AuditAction $action, EntityType $entityType, string $entityId, ?array $old, ?array $new) use (&$logged): void {
                $logged = compact('action', 'entityType', 'entityId', 'new');
            }
        );

        $this->service->warnAdmins(MailKind::TERMINAL_TOKEN_EXPIRY_WARNING, 'terminal-9', '7d');

        $this->assertSame(EntityType::TERMINAL, $logged['entityType']);
        $this->assertSame('terminal-9', $logged['entityId']);
        $this->assertSame('7d', $logged['new']['occasion']);
    }

    /**
     * This runs off a check that fires on every admin page load. An audit entry
     * per page load would bury the one that records a warning actually going
     * out.
     */
    public function test_warnAdmins_writes_no_audit_entry_when_everything_was_already_queued(): void
    {
        $this->admins->method('findActiveRecipients')->willReturn([
            ['id' => 'a1', 'email' => 'one@club.example', 'locale' => 'de', 'display_name' => 'One'],
        ]);
        $this->outbox->method('enqueue')->willReturn(false);
        $this->audit->expects($this->never())->method('log');

        $this->assertSame(0, $this->service->warnAdmins(MailKind::KEY_EXPIRY_WARNING, 'key-1', '30d')->queued);
    }

    public function test_warnAdmins_reports_an_admin_with_no_address(): void
    {
        $this->admins->method('findActiveRecipients')->willReturn([
            ['id' => 'a1', 'email' => '', 'locale' => 'de', 'display_name' => 'One'],
        ]);
        $this->outbox->expects($this->never())->method('enqueue');

        $this->assertSame(['a1'], $this->service->warnAdmins(MailKind::KEY_EXPIRY_WARNING, 'key-1', '30d')->withoutEmail);
    }

    /**
     * A member cannot act on an expiring encryption key, and telling them one
     * is expiring discloses the state of the club's own security.
     */
    public function test_warnAdmins_refuses_a_kind_that_is_addressed_to_a_member(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->warnAdmins(MailKind::SEPA_PRENOTIFICATION, 'settlement-1', '30d');
    }

    /* ─────────────────────────── send outcomes ─────────────────────────── */

    public function test_recordResult_marks_a_delivered_message_sent(): void
    {
        $this->outbox->expects($this->once())->method('markSent')->with('outbox-1', 'mid-9');
        $this->outbox->expects($this->never())->method('markFailed');

        $this->assertSame(
            MailStatus::SENT,
            $this->service->recordResult('outbox-1', MailSendResult::sent('mid-9'), CronInterval::HOURLY),
        );
    }

    /**
     * Greylisting arrives as a transient failure, and it is the reason the
     * retry path exists at all. The decision — another go, and how long it
     * waits — is made here rather than in the repository, because the ladder is
     * a function of the attempt count and of the scheduler's interval
     * (ADR-0039 decision 5); the queue is handed the answer.
     */
    public function test_recordResult_schedules_the_next_attempt_a_tick_out(): void
    {
        $this->outbox->method('attemptsFor')->with('outbox-1')->willReturn(0);
        $this->outbox->expects($this->once())
            ->method('markFailed')
            ->with(
                'outbox-1',
                '451 greylisted, try again later',
                1,
                true,
                RetrySchedule::backoffSeconds(CronInterval::HOURLY, 1),
            )
            ->willReturn(MailStatus::PENDING);

        $this->assertSame(
            MailStatus::PENDING,
            $this->service->recordResult(
                'outbox-1',
                MailSendResult::transientFailure('451 greylisted, try again later'),
                CronInterval::HOURLY,
            ),
        );
    }

    /**
     * The same failure on a daily scheduler waits a day, not fifteen minutes.
     * A backoff finer than the schedule is a number describing a machine this
     * installation does not have.
     */
    public function test_the_backoff_is_measured_in_the_installations_own_ticks(): void
    {
        $this->outbox->method('attemptsFor')->willReturn(1);
        $this->outbox->expects($this->once())
            ->method('markFailed')
            ->with('outbox-1', $this->anything(), 2, true, RetrySchedule::backoffSeconds(CronInterval::DAILY, 2))
            ->willReturn(MailStatus::PENDING);

        $this->service->recordResult(
            'outbox-1',
            MailSendResult::transientFailure('451 greylisted'),
            CronInterval::DAILY,
        );
    }

    public function test_the_last_attempt_closes_the_message_instead_of_scheduling_another(): void
    {
        $this->outbox->method('attemptsFor')->willReturn(RetrySchedule::MAX_ATTEMPTS - 1);
        $this->outbox->expects($this->once())
            ->method('markFailed')
            ->with('outbox-1', $this->anything(), RetrySchedule::MAX_ATTEMPTS, false, 0)
            ->willReturn(MailStatus::FAILED);

        $this->assertSame(
            MailStatus::FAILED,
            $this->service->recordResult(
                'outbox-1',
                MailSendResult::transientFailure('451 still greylisted'),
                CronInterval::HOURLY,
            ),
        );
    }

    public function test_recordResult_records_a_permanent_failure_without_a_retry(): void
    {
        $this->outbox->method('attemptsFor')->willReturn(0);
        $this->outbox->expects($this->once())
            ->method('markFailed')
            ->with('outbox-1', '550 no such mailbox', 1, false, 0)
            ->willReturn(MailStatus::FAILED);

        $this->assertSame(
            MailStatus::FAILED,
            $this->service->recordResult(
                'outbox-1',
                MailSendResult::permanentFailure('550 no such mailbox'),
                CronInterval::HOURLY,
            ),
        );
    }
}
