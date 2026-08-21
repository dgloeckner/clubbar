<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\TerminalAnomalyMailBuilder;
use App\Modules\Terminals\Repositories\TerminalAnomaliesRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use PHPUnit\Framework\TestCase;

/**
 * Rendering the terminal anomaly warning (ADR-0041 §4).
 *
 * This is the first admin-addressed builder in the codebase — `warnAdmins()`
 * could queue since #438 and nothing could render what it queued — so the
 * plumbing here is exercised for the first time, not just the wording.
 */
class TerminalAnomalyMailBuilderTest extends TestCase
{
    private TerminalsRepository $terminals;
    private TerminalAnomaliesRepository $anomalies;
    private AdminUsersRepository $adminUsers;
    private TerminalAnomalyMailBuilder $builder;
    private MailConfigDto $mailConfig;

    protected function setUp(): void
    {
        $this->terminals = $this->createMock(TerminalsRepository::class);
        $this->anomalies = $this->createMock(TerminalAnomaliesRepository::class);
        $this->adminUsers = $this->createMock(AdminUsersRepository::class);
        $this->adminUsers->method('findActiveRecipients')->willReturn([]);

        $this->mailConfig = MailConfigDto::fromRow([
            'sender_name' => 'FRGS Ruderbar',
            'sender_address' => 'bar@example.org',
            'footer_org_name' => 'FRGS Ruderbar',
        ]);

        $this->builder = new TerminalAnomalyMailBuilder(
            $this->terminals,
            $this->anomalies,
            $this->adminUsers,
        );
    }

    private function outboxRow(string $dedupKey = 'concurrent_ip:abcd1234:admin-1'): array
    {
        return [
            'kind' => MailKind::TERMINAL_ANOMALY_WARNING->value,
            'subject_id' => 'terminal-1',
            'dedup_key' => $dedupKey,
            'recipient' => 'kassenwart@example.org',
            'language' => 'de',
            'admin_user_id' => 'admin-1',
        ];
    }

    public function testItClaimsOnlyItsOwnKind(): void
    {
        $this->assertTrue($this->builder->supports(MailKind::TERMINAL_ANOMALY_WARNING));
        $this->assertFalse($this->builder->supports(MailKind::SEPA_PRENOTIFICATION));
        $this->assertFalse($this->builder->supports(MailKind::KEY_EXPIRY_WARNING));
    }

    public function testItRendersAConcurrentUseWarning(): void
    {
        $this->terminals->method('findById')->willReturn(['id' => 'terminal-1', 'name' => 'Theke 1']);
        $this->anomalies->method('listOpen')->willReturn([[
            'id' => 'abcd1234-0000-4000-8000-000000000000',
            'terminal_id' => 'terminal-1',
            'kind' => 'concurrent_ip',
            'details' => json_encode([
                'overlap_seconds' => 1500,
                'ips' => [
                    ['ip_address' => '203.0.113.10', 'first_seen_at' => '2026-08-15 18:00:00', 'last_seen_at' => '2026-08-15 20:00:00'],
                    ['ip_address' => '198.51.100.7', 'first_seen_at' => '2026-08-15 19:00:00', 'last_seen_at' => '2026-08-15 21:00:00'],
                ],
            ]),
        ]]);

        $message = $this->builder->build($this->outboxRow(), $this->mailConfig);

        $this->assertSame('kassenwart@example.org', $message->to);
        $this->assertStringContainsString('Theke 1', $message->subject);
        $this->assertStringContainsString('203.0.113.10', $message->html);
        $this->assertStringContainsString('198.51.100.7', $message->text);
        // 1500s = 25 minutes.
        $this->assertStringContainsString('25 min', $message->text);
    }

    /**
     * The message must say plainly that nothing was blocked. An operational
     * warning that leaves the reader unsure whether the bar is still selling
     * has done harm the anomaly itself had not.
     */
    public function testItSaysNothingWasBlocked(): void
    {
        $this->terminals->method('findById')->willReturn(['id' => 'terminal-1', 'name' => 'Theke 1']);
        $this->anomalies->method('listOpen')->willReturn([]);

        $message = $this->builder->build($this->outboxRow(), $this->mailConfig);

        $this->assertStringContainsString('nichts gesperrt', $message->text);
    }

    public function testItRendersACursorResetWarning(): void
    {
        $this->terminals->method('findById')->willReturn(['id' => 'terminal-1', 'name' => 'Theke 1']);
        $this->anomalies->method('listOpen')->willReturn([[
            'id' => 'ffff0000-0000-4000-8000-000000000000',
            'terminal_id' => 'terminal-1',
            'kind' => 'cursor_reset',
            'details' => json_encode([
                'stream' => 'products',
                'high_water_cursor' => 9000,
                'presented_cursor' => 0,
                'observed_at' => '2026-08-15 19:00:00',
            ]),
        ]]);

        $message = $this->builder->build($this->outboxRow('cursor_reset:ffff0000:admin-1'), $this->mailConfig);

        $this->assertStringContainsString('products', $message->text);
        $this->assertStringContainsString('9000', $message->text);
    }

    public function testItRendersInEnglishForAnEnglishAdmin(): void
    {
        $this->terminals->method('findById')->willReturn(['id' => 'terminal-1', 'name' => 'Theke 1']);
        $this->anomalies->method('listOpen')->willReturn([]);

        $row = $this->outboxRow();
        $row['language'] = 'en';

        $message = $this->builder->build($row, $this->mailConfig);

        $this->assertStringContainsString('worth a look', $message->subject);
        $this->assertStringContainsString('Nothing has been blocked', $message->text);
    }

    /**
     * `subject_id` is polymorphic and carries no foreign key, so a terminal
     * deleted between enqueue and send is reachable. A message must not be
     * invented around the gap.
     */
    public function testItRefusesToInventAMessageForAMissingTerminal(): void
    {
        $this->terminals->method('findById')->willReturn(null);

        $this->expectException(\RuntimeException::class);

        $this->builder->build($this->outboxRow(), $this->mailConfig);
    }

    /**
     * A colleague acknowledging the anomaly before the queue drained leaves no
     * evidence to show. The warning still goes — that something was seen is
     * worth knowing even once it has been dealt with.
     */
    public function testAnAlreadyClearedAnomalyStillProducesAMessage(): void
    {
        $this->terminals->method('findById')->willReturn(['id' => 'terminal-1', 'name' => 'Theke 1']);
        $this->anomalies->method('listOpen')->willReturn([]);

        $message = $this->builder->build($this->outboxRow(), $this->mailConfig);

        $this->assertStringContainsString('Theke 1', $message->subject);
        $this->assertNotSame('', trim($message->text));
    }

    public function testOccasionParsesBackIntoKindAndIdPrefix(): void
    {
        $this->assertSame(
            ['cursor_regression', 'abcd1234'],
            TerminalAnomalyMailBuilder::parseOccasion('cursor_regression:abcd1234:some-admin-uuid'),
        );
    }

    /**
     * The recipient's name is resolved for the greeting, but the *address*
     * always comes from the outbox row — it is the snapshot of who was told,
     * and re-reading it would quietly send the warning somewhere else.
     */
    public function testItGreetsTheAdminByNameWithoutRereadingTheirAddress(): void
    {
        $this->terminals->method('findById')->willReturn(['id' => 'terminal-1', 'name' => 'Theke 1']);
        $this->anomalies->method('listOpen')->willReturn([]);

        $adminUsers = $this->createMock(AdminUsersRepository::class);
        $adminUsers->method('findActiveRecipients')->willReturn([
            ['id' => 'admin-1', 'email' => 'moved-away@example.org', 'locale' => 'de', 'display_name' => 'Kassenwart Klaus'],
        ]);

        $builder = $this->rebuildWith($adminUsers);
        $message = $builder->build($this->outboxRow(), $this->mailConfig);

        $this->assertStringContainsString('Kassenwart Klaus', $message->text);
        $this->assertSame('kassenwart@example.org', $message->to);
    }

    public function testAnAdminWithNoDisplayNameGetsTheGenericGreeting(): void
    {
        $this->terminals->method('findById')->willReturn(['id' => 'terminal-1', 'name' => 'Theke 1']);
        $this->anomalies->method('listOpen')->willReturn([]);

        $adminUsers = $this->createMock(AdminUsersRepository::class);
        $adminUsers->method('findActiveRecipients')->willReturn([
            ['id' => 'admin-1', 'email' => 'a@example.org', 'locale' => 'de', 'display_name' => ''],
        ]);

        $message = $this->rebuildWith($adminUsers)->build($this->outboxRow(), $this->mailConfig);

        $this->assertNotSame('', trim($message->text));
    }

    /**
     * The anomaly is matched within this terminal's open rows, so another
     * till's row must not supply the evidence.
     */
    public function testEvidenceFromAnotherTerminalIsNotUsed(): void
    {
        $this->terminals->method('findById')->willReturn(['id' => 'terminal-1', 'name' => 'Theke 1']);
        $this->anomalies->method('listOpen')->willReturn([[
            'id' => 'abcd1234-0000-4000-8000-000000000000',
            'terminal_id' => 'terminal-2',
            'kind' => 'concurrent_ip',
            'details' => json_encode(['overlap_seconds' => 1500, 'ips' => [['ip_address' => '10.9.9.9']]]),
        ]]);

        $message = $this->builder->build($this->outboxRow(), $this->mailConfig);

        $this->assertStringNotContainsString('10.9.9.9', $message->text);
    }

    public function testEvidenceFromADifferentAnomalyOnTheSameTerminalIsNotUsed(): void
    {
        $this->terminals->method('findById')->willReturn(['id' => 'terminal-1', 'name' => 'Theke 1']);
        $this->anomalies->method('listOpen')->willReturn([[
            'id' => '99999999-0000-4000-8000-000000000000',
            'terminal_id' => 'terminal-1',
            'kind' => 'concurrent_ip',
            'details' => json_encode(['overlap_seconds' => 1500, 'ips' => [['ip_address' => '10.9.9.9']]]),
        ]]);

        $message = $this->builder->build($this->outboxRow('concurrent_ip:abcd1234:admin-1'), $this->mailConfig);

        $this->assertStringNotContainsString('10.9.9.9', $message->text);
    }

    /** A malformed entry in the payload is skipped rather than rendered as "?". */
    public function testDescribeSkipsAMalformedAddressEntry(): void
    {
        $rows = TerminalAnomalyMailBuilder::describe('concurrent_ip', [
            'overlap_seconds' => 900,
            'ips' => ['not-an-array', ['ip_address' => '203.0.113.10']],
        ]);

        $this->assertSame('15 min', $rows['Overlap']);
        $this->assertCount(2, $rows, 'the overlap plus the one usable address');
    }

    private function rebuildWith(AdminUsersRepository $adminUsers): TerminalAnomalyMailBuilder
    {
        return new TerminalAnomalyMailBuilder(
            $this->terminals,
            $this->anomalies,
            $adminUsers,
        );
    }

    /**
     * The same guarantee, stated as a constraint (#633): the fan-out is
     * narrowed by office, and this resolution is not. The row names who was
     * written to; a role filter here would blank the greeting for an account
     * whose roles have moved since — or for one an `admin`-only kind would not
     * write to today.
     */
    public function testItResolvesTheRecipientsNameWithoutConsultingTheirRoles(): void
    {
        $this->terminals->method('findById')->willReturn(['id' => 'terminal-1', 'name' => 'Theke 1']);
        $this->anomalies->method('listOpen')->willReturn([]);

        $adminUsers = $this->createMock(AdminUsersRepository::class);
        $adminUsers->expects($this->never())->method('findActiveRecipientsWithAnyRole');
        $adminUsers->method('findActiveRecipients')->willReturn([
            ['id' => 'admin-1', 'email' => 'a@example.org', 'locale' => 'de', 'display_name' => 'Vorstand Vera'],
        ]);

        $message = $this->rebuildWith($adminUsers)->build($this->outboxRow(), $this->mailConfig);

        $this->assertStringContainsString('Vorstand Vera', $message->text);
    }
}
