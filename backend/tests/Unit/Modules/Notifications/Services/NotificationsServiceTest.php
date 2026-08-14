<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailStatus;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Services\NotificationsService;
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
    private NotificationsService $service;

    private const SETTLEMENT = '11111111-1111-4111-8111-111111111111';
    private const ADMIN = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        $this->outbox = $this->createMock(MailOutboxRepository::class);
        $this->members = $this->createMock(MembersRepository::class);
        $this->audit = $this->createMock(AuditService::class);

        $this->service = new NotificationsService($this->outbox, $this->members, $this->audit);
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
            function (MailKind $kind, string $settlementId, string $memberId, string $recipient, string $language) use (&$queued): bool {
                $queued[] = compact('kind', 'settlementId', 'memberId', 'recipient', 'language');
                return true;
            }
        );

        $result = $this->service->enqueueForSettlement(self::SETTLEMENT, ['m1' => 500, 'm2' => 250], self::ADMIN);

        $this->assertSame(2, $result->queued);
        $this->assertSame([], $result->withoutEmail);
        $this->assertCount(2, $queued);
        $this->assertSame(MailKind::SEPA_PRENOTIFICATION, $queued[0]['kind']);
        $this->assertSame(self::SETTLEMENT, $queued[0]['settlementId']);
        $this->assertSame('one@example.org', $queued[0]['recipient']);
        $this->assertSame('two@example.org', $queued[1]['recipient']);
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
            function (MailKind $k, string $s, string $m, string $r, string $language) use (&$languages): bool {
                $languages[$m] = $language;
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
            function (MailKind $k, string $s, string $m, string $r, string $language) use (&$languages): bool {
                $languages[] = $language;
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
            function (MailKind $kind, string $s, string $memberId, string $recipient) use (&$notices): bool {
                $notices[] = [$kind, $memberId, $recipient];
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

    /* ─────────────────────────── send outcomes ─────────────────────────── */

    public function test_recordResult_marks_a_delivered_message_sent(): void
    {
        $this->outbox->expects($this->once())->method('markSent')->with('outbox-1', 'mid-9');
        $this->outbox->expects($this->never())->method('markFailed');

        $this->assertSame(
            MailStatus::SENT,
            $this->service->recordResult('outbox-1', MailSendResult::sent('mid-9')),
        );
    }

    /**
     * Greylisting arrives as a transient failure, and it is the reason the
     * retry path exists at all — the cap and the backoff are passed through
     * from here so the queue's policy has one home.
     */
    public function test_recordResult_passes_the_retry_policy_to_the_queue(): void
    {
        $this->outbox->expects($this->once())
            ->method('markFailed')
            ->with(
                'outbox-1',
                '451 greylisted, try again later',
                true,
                NotificationsService::MAX_ATTEMPTS,
                NotificationsService::RETRY_BACKOFF_SECONDS,
            )
            ->willReturn(MailStatus::PENDING);

        $this->assertSame(
            MailStatus::PENDING,
            $this->service->recordResult(
                'outbox-1',
                MailSendResult::transientFailure('451 greylisted, try again later'),
            ),
        );
    }

    public function test_recordResult_records_a_permanent_failure_without_a_retry(): void
    {
        $this->outbox->expects($this->once())
            ->method('markFailed')
            ->with('outbox-1', '550 no such mailbox', false, $this->anything(), $this->anything())
            ->willReturn(MailStatus::FAILED);

        $this->assertSame(
            MailStatus::FAILED,
            $this->service->recordResult('outbox-1', MailSendResult::permanentFailure('550 no such mailbox')),
        );
    }
}
