<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\Notifications\DTOs\EnqueueResultDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\CredentialExpiryNotifier;
use App\Modules\Notifications\Services\MailConfigService;
use App\Modules\Notifications\Services\NotificationsService;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Shared\Logging\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The scheduled scan behind #438.
 *
 * What is worth testing here is not "does it queue a mail" — it is the three
 * things the issue's acceptance criterion actually turns on: that a tier fires
 * **once** rather than once per tick, that a credential outside every tier fires
 * **nothing**, and that an installation with no mail configured behaves exactly
 * as it did before this existed.
 */
class CredentialExpiryNotifierTest extends TestCase
{
    private \DateTimeImmutable $now;
    private EncryptionKeysRepository $keys;
    private TerminalsRepository $terminals;
    private NotificationsService $notifications;
    private MailConfigService $mailConfig;

    /** @var list<array{kind: MailKind, subject_id: string, occasion: string}> */
    private array $warned = [];

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-08-16 12:00:00');

        $this->keys = $this->createMock(EncryptionKeysRepository::class);
        $this->keys->method('findActive')->willReturn(null);

        $this->terminals = $this->createMock(TerminalsRepository::class);
        $this->terminals->method('findAll')->willReturn([]);

        $this->notifications = $this->createMock(NotificationsService::class);

        $this->mailConfig = $this->createMock(MailConfigService::class);
        $this->mailConfig->method('canSend')->willReturn(true);
    }

    private function expiresIn(int $days): string
    {
        return $this->now->modify(sprintf('%+d days', $days))->format('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $overrides */
    private function key(int $expiresInDays, array $overrides = []): array
    {
        return $overrides + [
            'id' => 'key-1',
            'key_identifier' => 'ruderbar-2026',
            'status' => 'active',
            'expires_at' => $this->expiresIn($expiresInDays),
        ];
    }

    /** @param array<string,mixed> $overrides */
    private function terminal(?int $expiresInDays, array $overrides = []): array
    {
        return $overrides + [
            'id' => 'terminal-1',
            'name' => 'Theke',
            'is_active' => 1,
            'pending_token_hash' => null,
            'token_issued_at' => '2026-01-02 03:04:05',
            'token_expires_at' => $expiresInDays === null ? null : $this->expiresIn($expiresInDays),
        ];
    }

    /** The generation segment the fixture above produces. */
    private const GENERATION = '20260102030405';

    /**
     * Records what was asked for and answers as the outbox would, so a second
     * request for the same (kind, subject, occasion) is refused the way the
     * unique index refuses it.
     */
    private function captureWarnings(): void
    {
        $seen = [];

        $this->notifications
            ->method('warnAdmins')
            ->willReturnCallback(function (MailKind $kind, string $subjectId, string $occasion) use (&$seen): EnqueueResultDto {
                $this->warned[] = ['kind' => $kind, 'subject_id' => $subjectId, 'occasion' => $occasion];

                $slot = $kind->value . '|' . $subjectId . '|' . $occasion;
                if (isset($seen[$slot])) {
                    return new EnqueueResultDto(0, [], [], alreadyQueued: 1);
                }
                $seen[$slot] = true;

                return new EnqueueResultDto(1);
            });
    }

    private function notifier(): CredentialExpiryNotifier
    {
        return new CredentialExpiryNotifier(
            $this->keys,
            $this->terminals,
            $this->notifications,
            $this->mailConfig,
            $this->createMock(Logger::class),
        );
    }

    public function test_a_key_inside_a_tier_is_warned_about_once_per_tier(): void
    {
        $this->keys = $this->createMock(EncryptionKeysRepository::class);
        $this->keys->method('findActive')->willReturn($this->key(45));
        $this->captureWarnings();

        $notifier = $this->notifier();

        $first = $notifier->run($this->now);
        $this->assertSame(1, $first->queued);
        $this->assertSame(1, $first->inWarningWindow);

        // The tick that follows fifteen minutes later, and the ~2900 after it
        // before the 30-day tier opens: the scan still finds the key in the
        // 90-day window and the queue still refuses to say it twice.
        $second = $notifier->run($this->now->modify('+15 minutes'));
        $this->assertSame(0, $second->queued);
        $this->assertSame(1, $second->alreadyQueued);

        $this->assertSame(
            [MailKind::KEY_EXPIRY_WARNING, MailKind::KEY_EXPIRY_WARNING],
            array_column($this->warned, 'kind'),
        );
        $this->assertSame(['90d', '90d'], array_column($this->warned, 'occasion'));
    }

    public function test_each_tier_is_its_own_message(): void
    {
        $this->keys = $this->createMock(EncryptionKeysRepository::class);
        $this->keys->method('findActive')->willReturn($this->key(95));
        $this->captureWarnings();

        $notifier = $this->notifier();

        // Walk the clock forward past each threshold. `expiresIn()` is anchored
        // to `$this->now`, so moving `$now` forward shortens the remaining life.
        $notifier->run($this->now);                       // 95 days left — nothing
        $notifier->run($this->now->modify('+10 days'));   // 85 — the 90 tier
        $notifier->run($this->now->modify('+70 days'));   // 25 — the 30 tier
        $notifier->run($this->now->modify('+90 days'));   //  5 — the 7 tier
        $notifier->run($this->now->modify('+96 days'));   // -1 — expired, silent

        $this->assertSame(['90d', '30d', '7d'], array_column($this->warned, 'occasion'));
    }

    public function test_a_credential_outside_every_tier_queues_nothing(): void
    {
        $this->keys = $this->createMock(EncryptionKeysRepository::class);
        $this->keys->method('findActive')->willReturn($this->key(200));

        $this->notifications->expects($this->never())->method('warnAdmins');

        $result = $this->notifier()->run($this->now);

        $this->assertSame(1, $result->credentialsExamined);
        $this->assertSame(0, $result->inWarningWindow);
        $this->assertSame(0, $result->queued);
    }

    /**
     * The second half of the acceptance criterion: *"a deployment with no mail
     * configured behaves exactly as today"*. Without this gate every warning
     * would land in the Notifications page as a permanent failure, because
     * `NullTransport` reports one — on an installation whose owner never asked
     * for mail.
     */
    public function test_an_installation_that_cannot_send_scans_nothing_at_all(): void
    {
        $this->mailConfig = $this->createMock(MailConfigService::class);
        $this->mailConfig->method('canSend')->willReturn(false);

        $this->keys = $this->createMock(EncryptionKeysRepository::class);
        $this->keys->expects($this->never())->method('findActive');
        $this->notifications->expects($this->never())->method('warnAdmins');

        $result = $this->notifier()->run($this->now);

        $this->assertSame('mail not configured', $result->reason);
        $this->assertSame(0, $result->credentialsExamined);
        $this->assertSame('nothing due (mail not configured)', $result->summary());
    }

    public function test_terminal_tokens_are_scanned_alongside_the_key(): void
    {
        $this->keys = $this->createMock(EncryptionKeysRepository::class);
        $this->keys->method('findActive')->willReturn($this->key(5));

        $this->terminals = $this->createMock(TerminalsRepository::class);
        $this->terminals->method('findAll')->willReturn([
            $this->terminal(20, ['id' => 'terminal-1']),
            $this->terminal(300, ['id' => 'terminal-2']),
        ]);

        $this->captureWarnings();

        $result = $this->notifier()->run($this->now);

        $this->assertSame(3, $result->credentialsExamined);
        $this->assertSame(2, $result->inWarningWindow);
        $this->assertSame(2, $result->queued);

        $this->assertSame(
            [
                // The key carries no generation: rotating it produces a new row
                // with a new id, so `subject_id` already separates the cycles.
                ['kind' => MailKind::KEY_EXPIRY_WARNING, 'subject_id' => 'key-1', 'occasion' => '7d'],
                [
                    'kind' => MailKind::TERMINAL_TOKEN_EXPIRY_WARNING,
                    'subject_id' => 'terminal-1',
                    'occasion' => '30d:' . self::GENERATION,
                ],
            ],
            $this->warned,
        );
    }

    /**
     * The reason the generation segment exists (#438 follow-up).
     *
     * A rotation leaves the terminal, and therefore `subject_id`, exactly as it
     * was. Without a discriminator the successor token's warnings share a dedup
     * key with the predecessor's, and whether the admin is warned at all comes
     * down to whether `MailRetention` has pruned the old row yet — comfortable
     * at the default 365-day TTL, a coin flip at `API_TOKEN_TTL_DAYS=90`, which
     * is what that setting used to default to.
     */
    public function test_a_rotated_token_is_its_own_warning_at_the_same_tier(): void
    {
        $this->terminals = $this->createMock(TerminalsRepository::class);
        $this->terminals->method('findAll')->willReturnOnConsecutiveCalls(
            [$this->terminal(5)],
            // Same terminal, same id, same tier — a token issued later.
            [$this->terminal(5, ['token_issued_at' => '2026-06-07 08:09:10'])],
        );

        $this->captureWarnings();

        $notifier = $this->notifier();
        $first = $notifier->run($this->now);
        $second = $notifier->run($this->now);

        $this->assertSame(1, $first->queued);
        $this->assertSame(1, $second->queued, 'the replacement token got no warning of its own');
        $this->assertSame(0, $second->alreadyQueued);

        $this->assertSame(
            ['7d:' . self::GENERATION, '7d:20260607080910'],
            array_column($this->warned, 'occasion'),
        );
    }

    /**
     * A row edited by hand — migration 011 backfilled `token_issued_at`, so an
     * empty one is not a shape the application produces. One namespace for it is
     * exactly the guarantee this feature had before the segment existed.
     */
    public function test_a_terminal_without_an_issuance_stamp_still_warns_once(): void
    {
        $this->terminals = $this->createMock(TerminalsRepository::class);
        $this->terminals->method('findAll')->willReturn([$this->terminal(5, ['token_issued_at' => null])]);

        $this->captureWarnings();

        $notifier = $this->notifier();
        $this->assertSame(1, $notifier->run($this->now)->queued);
        $this->assertSame(0, $notifier->run($this->now)->queued);

        $this->assertSame(['7d:0', '7d:0'], array_column($this->warned, 'occasion'));
    }

    /**
     * A switched-off till and a till whose replacement token is already waiting
     * are both silent — the first because nobody should act on it, the second
     * because somebody already did exactly what this mail would ask for.
     */
    public function test_inactive_terminals_and_pending_rotations_are_skipped(): void
    {
        $this->terminals = $this->createMock(TerminalsRepository::class);
        $this->terminals->method('findAll')->willReturn([
            $this->terminal(3, ['id' => 'switched-off', 'is_active' => 0]),
            $this->terminal(3, ['id' => 'already-rotated', 'pending_token_hash' => 'abc123']),
            $this->terminal(3, ['id' => 'genuinely-due']),
        ]);

        $this->captureWarnings();

        $result = $this->notifier()->run($this->now);

        $this->assertSame(1, $result->credentialsExamined);
        $this->assertSame(['genuinely-due'], array_column($this->warned, 'subject_id'));
    }

    /** A terminal issued before token expiry existed has no date to warn about. */
    public function test_a_terminal_without_a_token_expiry_is_silent(): void
    {
        $this->terminals = $this->createMock(TerminalsRepository::class);
        $this->terminals->method('findAll')->willReturn([$this->terminal(null)]);

        $this->notifications->expects($this->never())->method('warnAdmins');

        $result = $this->notifier()->run($this->now);

        $this->assertSame(1, $result->credentialsExamined);
        $this->assertSame(0, $result->inWarningWindow);
    }

    /**
     * The caller is the cron tick, whose other job is draining the queue. A scan
     * that could not read a table must not stop the club's announcements from
     * going out.
     */
    public function test_it_never_throws(): void
    {
        $this->keys = $this->createMock(EncryptionKeysRepository::class);
        $this->keys->method('findActive')->willThrowException(new \RuntimeException('table is gone'));

        $result = $this->notifier()->run($this->now);

        $this->assertStringContainsString('scan failed', (string) $result->reason);
        $this->assertSame(0, $result->queued);
    }

    /**
     * `warnAdmins()` writes `occasion:adminUserId` into a VARCHAR(64) and an
     * admin id is 36 characters. The longest occasion this class can produce is
     * the one carrying a generation, so that is the one the budget is pinned
     * against — silently overflowing the column would collapse two credential
     * generations into one message, which is the failure the segment was added
     * to prevent.
     */
    public function test_the_occasion_round_trips_and_fits_the_column(): void
    {
        $adminId = '11111111-2222-3333-4444-555555555555';
        $this->assertSame(36, strlen($adminId));

        foreach ([90, 30, 7] as $tier) {
            foreach ([null, CredentialExpiryNotifier::tokenGeneration('2026-01-02 03:04:05')] as $generation) {
                $dedupKey = CredentialExpiryNotifier::occasion($tier, $generation) . ':' . $adminId;

                $this->assertLessThanOrEqual(64, strlen($dedupKey), $dedupKey);
                $this->assertSame($tier, CredentialExpiryNotifier::tierFromDedupKey($dedupKey));
            }
        }
    }

    public function test_the_token_generation_is_a_sortable_stamp(): void
    {
        $this->assertSame('20260102030405', CredentialExpiryNotifier::tokenGeneration('2026-01-02 03:04:05'));

        // Two rotations, in order — the point of a sortable stamp is that an
        // outbox read by dedup key sorts a terminal's cycles chronologically.
        $this->assertLessThan(
            CredentialExpiryNotifier::tokenGeneration('2026-06-07 08:09:10'),
            CredentialExpiryNotifier::tokenGeneration('2026-01-02 03:04:05'),
        );

        $this->assertSame('0', CredentialExpiryNotifier::tokenGeneration(null));
        $this->assertSame('0', CredentialExpiryNotifier::tokenGeneration(''));
        $this->assertSame('0', CredentialExpiryNotifier::tokenGeneration('not a date'));
    }

    public function test_a_dedup_key_that_names_no_tier_reads_as_null(): void
    {
        $this->assertNull(CredentialExpiryNotifier::tierFromDedupKey(''));
        $this->assertNull(CredentialExpiryNotifier::tierFromDedupKey('concurrent_ip:abcd1234:admin-1'));
        $this->assertNull(CredentialExpiryNotifier::tierFromDedupKey('90:admin-1'));
    }
}
