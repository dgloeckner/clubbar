<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\DTOs\EnqueueResultDto;
use App\Modules\Notifications\DTOs\MailRequestDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Repositories\MailConfigRepository;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Modules\Notifications\Services\DispenserAttentionNotifier;
use App\Modules\Notifications\Services\MailConfigService;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Terminals\Services\DispenserFillService;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Nobody is told when a dispenser needs a human (#956, epic finding 17).
 *
 * The panel (#954) and the kiosk (#948) show the four failures to whoever is
 * looking. This scan is the half that speaks first — and what is worth pinning
 * is less *what it says* than **when it stays quiet** and **what makes one
 * notice one notice**.
 *
 * Two dedup anchors live in here, and mixing them up is the defect this file is
 * mostly written against:
 *
 * - a **fault** is keyed on `state_since`, which moves with the episode and not
 *   otherwise, so an hour-long jam is one mail;
 * - a **shortage** is keyed on `dispenser_refilled_at`, because a draining
 *   hopper changes none of the fields `state_since` follows — keyed on it, the
 *   warning would fire once per terminal and then be silent for ever.
 */
class DispenserAttentionNotifierTest extends TestCase
{
    /** The instant every test here reasons from. */
    private const NOW = '2026-09-21 12:00:00';

    private const TERMINAL = 't-1';

    // ----------------------------------------------------------- persistence

    /**
     * A dispenser is briefly unreachable during a dispense often enough that an
     * immediate notice would be a WLAN log delivered by email.
     */
    public function test_fault_younger_than_ten_minutes_is_not_mailed(): void
    {
        $result = $this->scan(
            [$this->terminal(status: $this->jam(since: '2026-09-21T11:51:00.000Z'))],
            $this->expectingNoCall(),
        );

        $this->assertSame(0, $result->queued);
        $this->assertSame(1, $result->waiting);
        $this->assertSame(0, $result->needingAttention);
    }

    public function test_a_fault_that_has_held_long_enough_reaches_the_admin_office(): void
    {
        $calls = [];
        $result = $this->scan(
            [$this->terminal(status: $this->jam(since: '2026-09-21T11:45:00.000Z'))],
            $this->capturing($calls, queued: 2),
        );

        $this->assertSame(2, $result->queued);
        $this->assertSame(1, $result->needingAttention);
        $this->assertSame(
            [[MailKind::DISPENSER_ATTENTION, self::TERMINAL, 'fault:20260921114500000']],
            $calls,
        );
    }

    // ------------------------------------------------------------- the episode

    /**
     * A jam re-reported every thirty seconds for an hour is **one** fault that
     * started an hour ago. `state_since` is carried forward across identical
     * reports (ADR-0057), so the occasion is stable — and the unique index,
     * not a lookup, is what turns that into one mail per admin.
     */
    public function test_fault_is_mailed_once_per_episode_per_admin(): void
    {
        $terminals = [$this->terminal(status: $this->jam(since: '2026-09-21T10:00:00.000Z'))];

        $first = [];
        $this->scan($terminals, $this->capturing($first, queued: 2));

        // The same episode on the next tick: the same key, which the unique
        // index refuses — reported as `already_queued`, not as silence.
        $second = [];
        $result = $this->scan($terminals, $this->capturing($second, queued: 0, alreadyQueued: 2));

        $this->assertSame($first, $second, 'the same episode must produce the same dedup occasion');
        $this->assertSame(0, $result->queued);
        $this->assertSame(2, $result->alreadyQueued);
    }

    /**
     * And the other half: a machine that recovered and jammed again is a new
     * errand. `state_since` moved, so the key moves with it.
     */
    public function test_new_episode_after_recovery_is_mailed_again(): void
    {
        $before = [];
        $this->scan(
            [$this->terminal(status: $this->jam(since: '2026-09-21T09:00:00.000Z'))],
            $this->capturing($before, queued: 1),
        );

        $after = [];
        $this->scan(
            [$this->terminal(status: $this->jam(since: '2026-09-21T11:30:00.000Z'))],
            $this->capturing($after, queued: 1),
        );

        $this->assertNotSame($before, $after);
        $this->assertSame('fault:20260921090000000', $before[0][2]);
        $this->assertSame('fault:20260921113000000', $after[0][2]);
    }

    // --------------------------------------------------------------- the low

    /**
     * **The anchor #955 asked for.** A hopper draining changes none of the five
     * fields `state_since` follows, so a shortage keyed on it would be one mail
     * per terminal for ever. Keyed on the refill, it is one mail per load.
     */
    public function test_low_is_mailed_once_per_refill(): void
    {
        $terminal = $this->terminal(
            status: $this->idle(),
            refilledAt: '2026-09-20 18:00:00',
            refillTokens: 100,
        );

        $first = [];
        $this->scan([$terminal], $this->capturing($first, queued: 1), sold: 85);

        // The hopper keeps draining on the same load. Same occasion, so the
        // unique index swallows it — one warning per load, not one per tick.
        $draining = [];
        $this->scan([$terminal], $this->capturing($draining, queued: 0, alreadyQueued: 1), sold: 95);

        $this->assertSame($first, $draining);
        $this->assertSame('low:20260920180000', $first[0][2]);

        // Somebody refilled and counted it in: a new load, a new occasion.
        $refilled = [];
        $this->scan(
            [$this->terminal(status: $this->idle(), refilledAt: '2026-09-21 09:30:00', refillTokens: 100)],
            $this->capturing($refilled, queued: 1),
            sold: 90,
        );

        $this->assertSame('low:20260921093000', $refilled[0][2]);
    }

    /**
     * **No refill recorded is not an empty hopper.** They are opposite errands,
     * and `estimatedLeft()` is null for the first — which is exactly the
     * dispatch a club would stop trusting this channel over.
     */
    public function test_a_hopper_nobody_has_counted_is_never_called_empty(): void
    {
        $result = $this->scan(
            [$this->terminal(status: $this->idle(), refilledAt: null, refillTokens: null)],
            $this->expectingNoCall(),
            sold: 400,
        );

        $this->assertSame(0, $result->queued);
        $this->assertSame(1, $result->terminalsExamined);
    }

    /** Above the threshold is not news. */
    public function test_a_hopper_above_its_threshold_says_nothing(): void
    {
        $this->scan(
            [$this->terminal(status: $this->idle(), refilledAt: '2026-09-20 18:00:00', refillTokens: 100)],
            $this->expectingNoCall(),
            sold: 40,
        );
    }

    /** A terminal that reports *no dispenser attached* has no hopper to run out. */
    public function test_a_terminal_with_no_dispenser_is_not_warned_about_a_hopper(): void
    {
        $this->scan(
            [$this->terminal(
                status: ['configured' => false, 'state_since' => '2026-09-01T00:00:00.000Z'],
                refilledAt: '2026-09-20 18:00:00',
                refillTokens: 100,
            )],
            $this->expectingNoCall(),
            sold: 99,
        );
    }

    // ------------------------------------------------------ what is not a fault

    /**
     * **A recovered crash is a working machine** — `state: idle`, `fault: none`,
     * with an `error` transaction behind it. ADR-0057 context 4: *can it serve
     * a token* and *does a human have to go there* are different questions, and
     * taking a bar out of service for the second would close it for nothing.
     */
    public function test_a_recovered_crash_is_not_an_errand(): void
    {
        $result = $this->scan([$this->terminal(status: $this->idle())], $this->expectingNoCall());

        $this->assertSame(0, $result->needingAttention);
    }

    /**
     * **A protocol mismatch is not a device fault.** Its own occasion, never
     * folded into "offline" — the defect this epic already found once, which
     * sent somebody looking for a power cable at a machine that was fine.
     */
    public function test_a_protocol_mismatch_is_its_own_errand(): void
    {
        $calls = [];
        $this->scan(
            [$this->terminal(status: [
                'configured' => true,
                'contact' => 'protocol_mismatch',
                'state' => null,
                'fault' => 'none',
                'fault_code' => 0,
                'available' => false,
                'unavailable_reason' => 'protocol_mismatch',
                'state_since' => '2026-09-21T10:00:00.000Z',
            ])],
            $this->capturing($calls, queued: 1),
        );

        $this->assertStringStartsWith('mismatch:', $calls[0][2]);
        $this->assertStringNotContainsString('offline', $calls[0][2]);
    }

    /**
     * **A stale report is no claim.** A terminal that stopped syncing says
     * nothing about its dispenser; that it stopped is the sync status's
     * business, and mailing a jam nothing has confirmed since yesterday sends
     * somebody on an errand the installation has no evidence for.
     */
    public function test_stale_report_mails_nothing(): void
    {
        $result = $this->scan(
            [$this->terminal(
                status: $this->jam(since: '2026-09-20T08:00:00.000Z'),
                statusAt: '2026-09-20 08:00:00',
            )],
            $this->expectingNoCall(),
        );

        $this->assertSame(1, $result->stale);
        $this->assertSame(0, $result->queued);
    }

    /**
     * One walk to one machine is one mail. ADR-0058: the estimate never
     * overrides the availability verdict, and the jam's own message carries the
     * "probably empty" sentence when the books agree.
     */
    public function test_a_fault_outranks_the_hopper_on_the_same_terminal(): void
    {
        $calls = [];
        $this->scan(
            [$this->terminal(
                status: $this->jam(since: '2026-09-21T10:00:00.000Z'),
                refilledAt: '2026-09-20 18:00:00',
                refillTokens: 100,
            )],
            $this->capturing($calls, queued: 1),
            sold: 100,
        );

        $this->assertCount(1, $calls);
        $this->assertStringStartsWith('fault:', $calls[0][2]);
    }

    /** A till in a cupboard is not an errand, and a mail about one earns a filter rule. */
    public function test_an_inactive_terminal_is_left_alone(): void
    {
        $result = $this->scan(
            [$this->terminal(status: $this->jam(since: '2026-09-21T09:00:00.000Z'), isActive: false)],
            $this->expectingNoCall(),
        );

        $this->assertSame(0, $result->terminalsExamined);
    }

    // ------------------------------------------------------------- the office

    /**
     * **Only `admin`.** ADR-0057, owner decision 2026-09-20: every
     * `/api/admin/terminals*` route is ADMIN_ONLY, so the mail about that
     * screen carries exactly that set. The two negatives are asserted by name,
     * because "nobody else was written to" is the property, not "two rows were
     * written".
     */
    public function test_kassenwart_and_getraenkewart_are_never_recipients(): void
    {
        $asked = [];
        $admins = $this->createMock(AdminUsersRepository::class);
        $admins->method('findActiveRecipientsWithAnyRole')->willReturnCallback(
            function (array $roles) use (&$asked): array {
                $asked = $roles;

                // The office directory as a club really has it. Only accounts
                // holding one of the roles asked for come back — the query's
                // own contract, restated here so a widened role set would show
                // up as a Kassenwart in the outbox.
                $directory = [
                    'admin' => ['id' => 'a1', 'email' => 'admin@club.example', 'locale' => 'de'],
                    'kassenwart' => ['id' => 'k1', 'email' => 'kassenwart@club.example', 'locale' => 'de'],
                    'getraenkewart' => ['id' => 'g1', 'email' => 'getraenkewart@club.example', 'locale' => 'de'],
                ];

                return array_values(array_intersect_key(
                    $directory,
                    array_flip(array_map(static fn (AdminRole $role): string => $role->value, $roles)),
                ));
            }
        );

        $written = [];
        $outbox = $this->createMock(MailOutboxRepository::class);
        $outbox->method('enqueue')->willReturnCallback(
            function (MailRequestDto $request) use (&$written): bool {
                $written[] = $request->recipient;
                return true;
            }
        );

        $this->scan(
            [$this->terminal(status: $this->jam(since: '2026-09-21T10:00:00.000Z'))],
            $this->realNotifier($outbox, $admins),
        );

        $this->assertSame([AdminRole::ADMIN], $asked);
        $this->assertSame(['admin@club.example'], $written);
        $this->assertNotContains('kassenwart@club.example', $written);
        $this->assertNotContains('getraenkewart@club.example', $written);
    }

    /**
     * Fail closed (ADR-0044 rule 5): no active admin escalates to the club
     * address — never back to every account, which is the leak the rule exists
     * to prevent.
     */
    public function test_no_admin_escalates_to_the_club_address(): void
    {
        $admins = $this->createMock(AdminUsersRepository::class);
        $admins->method('findActiveRecipientsWithAnyRole')->willReturn([]);

        $written = [];
        $outbox = $this->createMock(MailOutboxRepository::class);
        $outbox->method('enqueue')->willReturnCallback(
            function (MailRequestDto $request) use (&$written): bool {
                $written[] = $request->recipient;
                return true;
            }
        );

        $this->scan(
            [$this->terminal(status: $this->jam(since: '2026-09-21T10:00:00.000Z'))],
            $this->realNotifier($outbox, $admins, club: 'vorstand@club.example'),
        );

        $this->assertSame(['vorstand@club.example'], $written);
    }

    // ------------------------------------------------------------- the plumbing

    /**
     * The same gate the two scans beside this one use: `NullTransport` records
     * a permanent failure, so a club with no mail configured would collect red
     * rows in a page it never asked for.
     */
    public function test_an_installation_with_no_mail_queues_nothing(): void
    {
        $result = $this->scan(
            [$this->terminal(status: $this->jam(since: '2026-09-21T09:00:00.000Z'))],
            $this->expectingNoCall(),
            canSend: false,
        );

        $this->assertSame('mail not configured', $result->reason);
    }

    /**
     * The caller is the cron tick whose real job is draining the queue. A scan
     * that could not read a table must not stop the club's announcements.
     */
    public function test_a_scan_that_throws_is_reported_and_swallowed(): void
    {
        $throwing = $this->createMock(AdminNotifier::class);
        $throwing->method('warnAdmins')->willThrowException(new \RuntimeException('the database went away'));

        $result = $this->scan(
            [$this->terminal(status: $this->jam(since: '2026-09-21T09:00:00.000Z'))],
            $throwing,
        );

        $this->assertStringStartsWith('scan failed:', (string) $result->reason);
    }

    /** The line an operator reads in the cron's own output. */
    public function test_the_summary_distinguishes_silence_from_work(): void
    {
        $quiet = $this->scan([$this->terminal(status: $this->idle())], $this->expectingNoCall());

        $this->assertSame(
            'terminals=1 needing_attention=0 waiting=0 stale=0 queued=0 already_queued=0 '
            . 'admins_without_email=0',
            $quiet->summary(),
        );
        $this->assertNull($quiet->toArray()['reason']);

        $off = $this->scan([], $this->expectingNoCall(), canSend: false);
        $this->assertSame('nothing due (mail not configured)', $off->summary());
    }

    /**
     * The budget the dedup key has to fit in: `warnAdmins()` writes
     * `occasion:adminUserId` into a VARCHAR(64) and an admin id is 36
     * characters.
     */
    public function test_every_occasion_fits_the_dedup_column(): void
    {
        $calls = [];
        $this->scan(
            [
                $this->terminal(
                    id: 'fault-t',
                    status: $this->jam(since: '2026-09-21T10:00:00.123Z'),
                ),
                $this->terminal(
                    id: 'mismatch-t',
                    status: [
                        'configured' => true,
                        'contact' => 'protocol_mismatch',
                        'fault' => 'none',
                        'available' => false,
                        'unavailable_reason' => 'protocol_mismatch',
                        'state_since' => '2026-09-21T10:00:00.123Z',
                    ],
                ),
                $this->terminal(
                    id: 'low-t',
                    status: $this->idle(),
                    refilledAt: '2026-09-20 18:00:00',
                    refillTokens: 100,
                ),
            ],
            $this->capturing($calls, queued: 1),
            sold: 99,
        );

        $this->assertCount(3, $calls);
        foreach ($calls as $call) {
            $this->assertLessThanOrEqual(27, strlen($call[2]), $call[2]);
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param list<array<string,mixed>> $terminals
     */
    private function scan(
        array $terminals,
        AdminNotifier $adminNotifier,
        int $sold = 0,
        bool $canSend = true,
    ): \App\Modules\Notifications\DTOs\DispenserAttentionScanResultDto {
        $repository = $this->createMock(TerminalsRepository::class);
        $repository->method('findAll')->willReturn($terminals);
        $repository->method('countTokensSoldSinceRefill')->willReturnCallback(
            static fn (?string $id = null): array => [(string) $id => $sold]
        );

        $mailConfig = $this->createMock(MailConfigService::class);
        $mailConfig->method('canSend')->willReturn($canSend);

        $notifier = new DispenserAttentionNotifier(
            $repository,
            // The real reading, not a stub: the threshold comparison lives in
            // `DispenserFillDto` and must not be restated here, or this file
            // would pass while the panel and the mail disagreed.
            new DispenserFillService($repository, $this->createMock(AuditService::class)),
            $adminNotifier,
            $mailConfig,
            $this->createMock(Logger::class),
        );

        return $notifier->run(new DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')));
    }

    /** @return array<string,mixed> */
    private function terminal(
        ?array $status = null,
        string $id = self::TERMINAL,
        string $statusAt = '2026-09-21 11:59:00',
        ?string $refilledAt = null,
        ?int $refillTokens = null,
        int $threshold = 20,
        bool $isActive = true,
    ): array {
        return [
            'id' => $id,
            'name' => 'Theke',
            'is_active' => $isActive,
            'dispenser_status' => $status === null ? null : json_encode($status),
            'dispenser_status_at' => $statusAt,
            'dispenser_refilled_at' => $refilledAt,
            'dispenser_refill_tokens' => $refillTokens,
            'dispenser_low_threshold' => $threshold,
        ];
    }

    /** @return array<string,mixed> */
    private function jam(string $since): array
    {
        return [
            'configured' => true,
            'contact' => 'reported',
            'state' => 'fault',
            'fault' => 'jam',
            'fault_code' => 0,
            'available' => false,
            'unavailable_reason' => 'jam',
            'state_since' => $since,
        ];
    }

    /** @return array<string,mixed> */
    private function idle(): array
    {
        return [
            'configured' => true,
            'contact' => 'reported',
            'state' => 'idle',
            'fault' => 'none',
            'fault_code' => 0,
            'available' => true,
            'unavailable_reason' => null,
            'state_since' => '2026-09-21T06:00:00.000Z',
        ];
    }

    /** @param list<array{0: MailKind, 1: string, 2: string}> $calls */
    private function capturing(array &$calls, int $queued, int $alreadyQueued = 0): AdminNotifier
    {
        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->method('warnAdmins')->willReturnCallback(
            function (MailKind $kind, string $subjectId, string $occasion) use (&$calls, $queued, $alreadyQueued) {
                $calls[] = [$kind, $subjectId, $occasion];

                return new EnqueueResultDto(queued: $queued, alreadyQueued: $alreadyQueued);
            }
        );

        return $notifier;
    }

    private function expectingNoCall(): AdminNotifier
    {
        $notifier = $this->createMock(AdminNotifier::class);
        $notifier->expects($this->never())->method('warnAdmins');

        return $notifier;
    }

    /** The real fan-out over mocked storage, so the office filter is exercised. */
    private function realNotifier(
        MailOutboxRepository $outbox,
        AdminUsersRepository $admins,
        ?string $club = null,
    ): AdminNotifier {
        $mailConfig = $this->createMock(MailConfigRepository::class);
        $mailConfig->method('getConfig')->willReturn(['club_notification_address' => $club]);

        return new AdminNotifier(
            $outbox,
            $admins,
            $this->createMock(AuditService::class),
            $mailConfig,
            $this->createMock(Logger::class),
        );
    }
}
