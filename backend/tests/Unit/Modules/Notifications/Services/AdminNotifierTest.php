<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\DTOs\MailRequestDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Repositories\MailConfigRepository;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * Operational mail addressed to whoever runs the club (#438, ADR-0043).
 *
 * These tests moved here with `warnAdmins()` when it left
 * {@see \App\Modules\Notifications\Services\NotificationsService} — unchanged,
 * because the behaviour did not change; only what has to be constructed to
 * reach it did.
 *
 * The property they all circle is the dedup key. Every caller repeats itself:
 * the expiry scan recomputes its tier on every tick, the anomaly detector
 * re-reads an open anomaly, and a rotation is one of many for a terminal. The
 * queue's `UNIQUE (kind, subject_id, dedup_key)` is what turns "queue a
 * warning" into "queue it once", per admin, per occasion.
 */
class AdminNotifierTest extends TestCase
{
    private MailOutboxRepository $outbox;
    private AdminUsersRepository $admins;
    private AuditService $audit;
    private AdminNotifier $notifier;

    /**
     * `mail_config` with no club address, which is the default an installation
     * that has never configured one has (ADR-0044 rule 3). Tests about the
     * club-level copy set it explicitly.
     */
    private MailConfigRepository $mailConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outbox = $this->createMock(MailOutboxRepository::class);
        $this->admins = $this->createMock(AdminUsersRepository::class);
        $this->audit = $this->createMock(AuditService::class);
        $this->mailConfig = $this->createMock(MailConfigRepository::class);
        $this->mailConfig->method('getConfig')->willReturn(['club_notification_address' => null]);

        $this->notifier = new AdminNotifier($this->outbox, $this->admins, $this->audit, $this->mailConfig);
    }

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

        $result = $this->notifier->warnAdmins(MailKind::KEY_EXPIRY_WARNING, 'key-1', '30d');

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

        $this->notifier->warnAdmins(MailKind::KEY_EXPIRY_WARNING, 'key-1', '90d');
        $this->notifier->warnAdmins(MailKind::KEY_EXPIRY_WARNING, 'key-1', '30d');

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

        $this->notifier->warnAdmins(MailKind::TERMINAL_TOKEN_EXPIRY_WARNING, 'terminal-9', '7d');

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

        $this->assertSame(0, $this->notifier->warnAdmins(MailKind::KEY_EXPIRY_WARNING, 'key-1', '30d')->queued);
    }

    public function test_warnAdmins_reports_an_admin_with_no_address(): void
    {
        $this->admins->method('findActiveRecipients')->willReturn([
            ['id' => 'a1', 'email' => '', 'locale' => 'de', 'display_name' => 'One'],
        ]);
        $this->outbox->expects($this->never())->method('enqueue');

        $this->assertSame(['a1'], $this->notifier->warnAdmins(MailKind::KEY_EXPIRY_WARNING, 'key-1', '30d')->withoutEmail);
    }

    /**
     * A member cannot act on an expiring encryption key, and telling them one
     * is expiring discloses the state of the club's own security.
     */
    public function test_warnAdmins_refuses_a_kind_that_is_addressed_to_a_member(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->notifier->warnAdmins(MailKind::SEPA_PRENOTIFICATION, 'settlement-1', '30d');
    }

    /**
     * ADR-0043's occasion is an event rather than a tier, and the constraint
     * does the same job: two mintings for one terminal are two messages, and a
     * redelivered enqueue of the same one is not a third.
     */
    public function test_an_event_occasion_separates_two_issuances_of_one_terminal(): void
    {
        $this->admins->method('findActiveRecipients')->willReturn([
            ['id' => 'a1', 'email' => 'one@club.example', 'locale' => 'de', 'display_name' => 'One'],
        ]);

        $keys = [];
        $this->outbox->method('enqueue')->willReturnCallback(
            function (MailRequestDto $request) use (&$keys): bool {
                $keys[] = $request->dedupKey;
                return true;
            }
        );

        $this->notifier->warnAdmins(MailKind::TERMINAL_TOKEN_ISSUED, 'terminal-9', 'enrolled:20260816142317');
        $this->notifier->warnAdmins(MailKind::TERMINAL_TOKEN_ISSUED, 'terminal-9', 'rotated:20260901090000');

        $this->assertSame(['enrolled:20260816142317:a1', 'rotated:20260901090000:a1'], $keys);
    }

    /**
     * The actor is one value, threaded onto every recipient's row — not
     * recomputed per admin, and not the same thing as `adminUserId`, which
     * varies per row because it names who that particular copy is addressed to.
     */
    public function test_warnAdmins_stamps_the_same_actor_onto_every_recipients_row(): void
    {
        $this->admins->method('findActiveRecipients')->willReturn([
            ['id' => 'a1', 'email' => 'one@club.example', 'locale' => 'de', 'display_name' => 'One'],
            ['id' => 'a2', 'email' => 'two@club.example', 'locale' => 'de', 'display_name' => 'Two'],
        ]);

        $queued = [];
        $this->outbox->method('enqueue')->willReturnCallback(
            function (MailRequestDto $request) use (&$queued): bool {
                $queued[] = $request;
                return true;
            }
        );

        $this->notifier->warnAdmins(
            MailKind::TERMINAL_TOKEN_ISSUED,
            'terminal-9',
            'enrolled:20260816142317',
            actorAdminUserId: 'a1',
        );

        $this->assertSame('a1', $queued[0]->actorAdminUserId);
        $this->assertSame('a1', $queued[1]->actorAdminUserId, 'the second recipient still names the same actor');
        $this->assertSame('a2', $queued[1]->adminUserId, "but is still addressed to that recipient's own id");
    }
}
