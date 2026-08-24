<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notifications;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\CreditLimits\Repositories\CreditLimitConfigRepository;
use App\Modules\CreditLimits\Repositories\NearLimitRepository;
use App\Modules\CreditLimits\Services\CreditLimitConfigService;
use App\Modules\Notifications\Enums\DigestCadence;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailStatus;
use App\Modules\Notifications\Repositories\MailConfigRepository;
use App\Modules\Notifications\Repositories\MailOutboxRepository;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Modules\Notifications\Services\CreditLimitDigestNotifier;
use App\Modules\Notifications\Services\CreditLimitDigestService;
use App\Shared\Services\AuditService;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Feature\DatabaseTestCase;

/**
 * The near-limit digest end to end against the real tables (ADR-0047).
 *
 * The unit suites pin the window arithmetic, the report assembly and the
 * refusals against doubles. Three things can only be shown here, and each is a
 * place a mock would happily be wrong:
 *
 * 1. **The near-limit query.** Its `HAVING` uses `DIV` for integer division, so
 *    the boundary cent is decided by MariaDB and not by PHP. Every member on
 *    this list sits at that boundary by definition.
 * 2. **Idempotency.** It is a unique index, not a check in the code. A test
 *    that asserted the service "looked first" would pass against exactly the
 *    lookup-then-insert race the index exists to make impossible.
 * 3. **Who is written to.** The fan-out narrows on a `JOIN` over
 *    `admin_user_roles` (#633), and the digest is one of only two kinds whose
 *    audience is wider than `admin`.
 */
class CreditLimitDigestTest extends DatabaseTestCase
{
    /** A Monday in ISO week 35 of 2026. */
    private const NOW = '2026-08-24 09:00:00';

    /** @var list<array{table:string,id:string}> */
    private array $created = [];

    /** @var list<string> */
    private array $createdAdmins = [];

    private ?string $originalCadence = null;
    private ?string $originalClubAddress = null;
    private ?array $originalLimits = null;
    private ?string $originalMailDsn = null;
    private ?string $originalSenderAddress = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The scan refuses to queue anything on an installation that cannot
        // send (see the notifier: `NullTransport` records a *permanent*
        // failure, so every digest would land in the Notifications page as a
        // red row). The dev stack deliberately leaves `MAIL_DSN` empty — a
        // transport wired into the long-running backend would let one suite's
        // drain sweep another's queue — so this suite supplies one for its own
        // process only. Nothing is sent: `AdminNotifier` writes rows and the
        // drain, which is not run here, is the only sender (ADR-0038 rule 3).
        $this->originalMailDsn = getenv('MAIL_DSN') === false ? null : (string) getenv('MAIL_DSN');
        $this->givenMailDsn('smtp://mailpit:1025');

        // The other half of `canSend()`: a sender address. The seeded row has
        // none, which is correct for a fresh installation and means the gate
        // would refuse every test here for a reason none of them is about.
        $this->originalSenderAddress = (string) $this->db
            ->query('SELECT sender_address FROM mail_config WHERE id = 1')->fetchColumn();
        $this->db->prepare('UPDATE mail_config SET sender_address = ? WHERE id = 1')
            ->execute(['bar@example.org']);

        // The club copy is a separate mechanism (ADR-0044 rule 3) and this kind
        // does not use it. Unset so every recipient this file sees is an
        // account, and any club row that appeared would be a real failure.
        $mailConfig = new MailConfigRepository($this->db, $this->logger);
        $this->originalClubAddress = $mailConfig->getConfig()['club_notification_address'] ?? null;
        $this->db->prepare('UPDATE mail_config SET club_notification_address = NULL WHERE id = 1')->execute();

        $this->originalLimits = $this->db
            ->query('SELECT default_limit_cents, warn_threshold_percent FROM credit_limit_config WHERE id = 1')
            ->fetch() ?: null;
    }

    protected function tearDown(): void
    {
        // The scan covers every member in scope, which includes rows this suite
        // did not create. Left behind, these would show up in every other suite
        // that counts the queue.
        $this->db->exec("DELETE FROM mail_outbox WHERE kind = 'credit_limit_digest'");

        foreach (array_reverse($this->created) as $row) {
            $this->db->prepare("DELETE FROM {$row['table']} WHERE id = ?")->execute([$row['id']]);
        }
        $this->created = [];

        foreach ($this->createdAdmins as $id) {
            // `admin_user_roles` has ON DELETE CASCADE; the account is enough.
            $this->db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
        }
        $this->createdAdmins = [];

        if ($this->originalCadence !== null) {
            $this->db->prepare('UPDATE mail_config SET credit_limit_digest_cadence = ? WHERE id = 1')
                ->execute([$this->originalCadence]);
            $this->originalCadence = null;
        }

        $this->db->prepare('UPDATE mail_config SET club_notification_address = ? WHERE id = 1')
            ->execute([$this->originalClubAddress]);

        $this->givenMailDsn($this->originalMailDsn);

        if ($this->originalSenderAddress !== null) {
            $this->db->prepare('UPDATE mail_config SET sender_address = ? WHERE id = 1')
                ->execute([$this->originalSenderAddress]);
            $this->originalSenderAddress = null;
        }

        if ($this->originalLimits !== null) {
            $this->db->prepare(
                'UPDATE credit_limit_config SET default_limit_cents = ?, warn_threshold_percent = ? WHERE id = 1'
            )->execute([$this->originalLimits['default_limit_cents'], $this->originalLimits['warn_threshold_percent']]);
        }

        parent::tearDown();
    }

    /**
     * The acceptance criterion, as the database sees it: run the tick twice,
     * count one digest per recipient.
     */
    public function test_two_ticks_in_one_window_leave_exactly_one_digest_per_recipient(): void
    {
        $this->givenCadence(DigestCadence::WEEKLY);
        $this->givenClubLimit(10_000, 80);
        $this->givenMemberOwing(9_500);
        $kassenwart = $this->givenAdmin([AdminRole::KASSENWART]);

        $first = $this->notifier()->run($this->at(self::NOW));
        $second = $this->notifier()->run($this->at('2026-08-26 11:00:00'));

        $this->assertSame('2026-W35', $first->window);
        $this->assertGreaterThanOrEqual(1, $first->queued);
        $this->assertSame(1, $this->digestCount($kassenwart, '2026-W35'));

        $this->assertSame(0, $second->queued, 'the second tick inserted nothing');
        $this->assertGreaterThanOrEqual(1, $second->alreadyQueued);
        $this->assertSame(
            1,
            $this->digestCount($kassenwart, '2026-W35'),
            'a recipient has one digest per window however often the cron fires'
        );
    }

    /** The next window is a new digest, or the feature would send once and stop. */
    public function test_the_following_week_queues_a_new_digest(): void
    {
        $this->givenCadence(DigestCadence::WEEKLY);
        $this->givenClubLimit(10_000, 80);
        $this->givenMemberOwing(9_500);
        $kassenwart = $this->givenAdmin([AdminRole::KASSENWART]);

        $this->notifier()->run($this->at(self::NOW));
        $this->notifier()->run($this->at('2026-09-01 09:00:00'));

        $this->assertSame(1, $this->digestCount($kassenwart, '2026-W35'));
        $this->assertSame(1, $this->digestCount($kassenwart, '2026-W36'));
    }

    /** The queued row carries addressing and a window key, and no member data at all. */
    public function test_a_queued_digest_is_about_the_clubs_credit_limit_configuration(): void
    {
        $this->givenCadence(DigestCadence::WEEKLY);
        $this->givenClubLimit(10_000, 80);
        $member = $this->givenMemberOwing(9_500);
        $kassenwart = $this->givenAdmin([AdminRole::KASSENWART]);

        $this->notifier()->run($this->at(self::NOW));

        $row = $this->digestRow($kassenwart, '2026-W35');

        $this->assertSame(MailKind::CREDIT_LIMIT_DIGEST->value, $row['kind']);
        $this->assertSame(CreditLimitDigestNotifier::SUBJECT_ID, $row['subject_id']);
        $this->assertSame(MailStatus::PENDING->value, $row['status']);
        $this->assertSame($kassenwart, $row['recipient']);

        // The row names no member, which is what keeps this aggregate out of
        // the erasure scrub and out of the member delete cascade.
        $this->assertNull($row['member_id']);
        $this->assertStringNotContainsString($member, (string) $row['subject_id']);
        $this->assertStringNotContainsString($member, (string) $row['dedup_key']);
    }

    /**
     * The digest reaches the treasury pair, mirroring the route its dashboard
     * panel sits on — and nobody else.
     */
    public function test_the_digest_reaches_the_treasury_offices_and_not_the_stock_keeper(): void
    {
        $this->givenCadence(DigestCadence::WEEKLY);
        $this->givenClubLimit(10_000, 80);
        $this->givenMemberOwing(9_500);

        $kassenwart = $this->givenAdmin([AdminRole::KASSENWART]);
        $getraenkewart = $this->givenAdmin([AdminRole::GETRAENKEWART]);

        $this->notifier()->run($this->at(self::NOW));

        $this->assertSame(1, $this->digestCount($kassenwart, '2026-W35'));
        $this->assertSame(
            0,
            $this->digestCount($getraenkewart, '2026-W35'),
            'member balances are outside the stock keeper\'s remit on every surface'
        );
    }

    /**
     * A member comfortably inside their ceiling is not on the list.
     *
     * This is as far as the *empty-list* rule can be asserted here, and the
     * limit is worth stating: "no names, no digest" is a claim about the whole
     * database, and this suite shares one with every other feature test in the
     * run. A test that asserted the scan refused for want of anybody near their
     * limit would pass alone and fail in the full suite the moment another
     * file's fixtures left a full Deckel behind — which is exactly what
     * happened when it was written that way.
     *
     * So the refusal itself is pinned in `CreditLimitDigestNotifierTest`, where
     * the report is a value the test supplies. What is asserted here is the
     * half that is genuinely about the query: a member who is nowhere near
     * their ceiling never reaches the digest.
     */
    public function test_a_member_comfortably_inside_their_ceiling_is_not_reported(): void
    {
        $this->givenClubLimit(10_000, 80);
        // 10 % of the ceiling, nowhere near the 80 % band.
        $comfortable = $this->givenMemberOwing(1_000);

        $this->assertNotContains($comfortable, $this->reportedMemberIds());
    }

    public function test_a_cadence_of_off_queues_nothing(): void
    {
        $this->givenCadence(DigestCadence::OFF);
        $this->givenClubLimit(10_000, 80);
        $this->givenMemberOwing(9_500);
        $kassenwart = $this->givenAdmin([AdminRole::KASSENWART]);

        $result = $this->notifier()->run($this->at(self::NOW));

        $this->assertSame('cadence off', $result->reason);
        $this->assertSame(0, $this->digestCount($kassenwart, '2026-W35'));
    }

    /**
     * A member's own ceiling decides whether they are on the list, not the
     * club's — the property ADR-0047 is about, asserted through the real query.
     *
     * Both members owe the same €95. Against the club's €100 that is 95 % and
     * inside the band; against a €5,000 override it is 1.9 % and nowhere near
     * it. Only the first is reported.
     */
    public function test_a_member_with_a_generous_override_is_not_near_their_limit(): void
    {
        $this->givenCadence(DigestCadence::WEEKLY);
        $this->givenClubLimit(10_000, 80);

        $onTheClubDefault = $this->givenMemberOwing(9_500);
        $withOverride = $this->givenMemberOwing(9_500, creditLimitCents: 500_000);

        $names = $this->reportedMemberIds();

        $this->assertContains($onTheClubDefault, $names);
        $this->assertNotContains($withOverride, $names);
    }

    /**
     * A member deliberately capped lower is on the list at an amount the club
     * default would have ignored — the other direction of the same rule.
     */
    public function test_a_member_with_a_tight_override_appears_early(): void
    {
        $this->givenCadence(DigestCadence::WEEKLY);
        $this->givenClubLimit(10_000, 80);

        $tightlyCapped = $this->givenMemberOwing(1_800, creditLimitCents: 2_000);

        $this->assertContains($tightlyCapped, $this->reportedMemberIds());
    }

    /** `0` means unlimited, and an unlimited member is never near anything. */
    public function test_a_member_with_no_ceiling_is_never_reported(): void
    {
        $this->givenCadence(DigestCadence::WEEKLY);
        $this->givenClubLimit(10_000, 80);

        $unlimited = $this->givenMemberOwing(500_000, creditLimitCents: 0);

        $this->assertNotContains($unlimited, $this->reportedMemberIds());
    }

    /**
     * A member in credit uses none of their ceiling. Negative cents mean the
     * club owes *them*, so credit is never a limit problem.
     */
    public function test_a_member_in_credit_is_not_reported(): void
    {
        $this->givenCadence(DigestCadence::WEEKLY);
        $this->givenClubLimit(10_000, 80);

        $inCredit = $this->givenMemberOwing(-5_000);

        $this->assertNotContains($inCredit, $this->reportedMemberIds());
    }

    /** An inactive member is not being served, so they are not about to be refused. */
    public function test_a_deactivated_member_is_not_reported(): void
    {
        $this->givenCadence(DigestCadence::WEEKLY);
        $this->givenClubLimit(10_000, 80);

        $inactive = $this->givenMemberOwing(9_500, isActive: false);

        $this->assertNotContains($inactive, $this->reportedMemberIds());
    }

    /**
     * The report carries the three figures the treasurer asked for, straight
     * out of the database.
     */
    public function test_the_report_names_the_member_their_tab_and_their_ceiling(): void
    {
        $this->givenClubLimit(10_000, 80);
        $member = $this->givenMemberOwing(9_500, firstName: 'Digest', lastName: 'Subject');

        $line = null;
        foreach ($this->digestService()->collect()->lines as $candidate) {
            if ($candidate->memberId === $member) {
                $line = $candidate;
            }
        }

        $this->assertNotNull($line, 'the member owing 95 % of the club ceiling must be reported');
        $this->assertSame('Digest Subject', $line->name);
        $this->assertSame(9_500, $line->balanceCents);
        $this->assertSame(10_000, $line->limitCents);
        $this->assertSame(95, $line->percentOfLimit);
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function notifier(): CreditLimitDigestNotifier
    {
        return new CreditLimitDigestNotifier(
            $this->digestService(),
            new AdminNotifier(
                new MailOutboxRepository($this->db, $this->logger),
                new AdminUsersRepository($this->db, $this->logger),
                $this->createMock(AuditService::class),
                new MailConfigRepository($this->db, $this->logger),
                $this->logger,
            ),
            // The base class's real one, over this connection: the cadence and
            // the "can we send at all" gate are columns, and a double would let
            // a schema mistake pass unnoticed in a test that exists to touch it.
            $this->mailConfigService(),
            $this->logger,
        );
    }

    private function digestService(): CreditLimitDigestService
    {
        return new CreditLimitDigestService(
            new NearLimitRepository($this->db),
            new CreditLimitConfigService(
                new CreditLimitConfigRepository($this->db, $this->logger),
                $this->createMock(AuditService::class),
            ),
        );
    }

    /** @return list<string> */
    private function reportedMemberIds(): array
    {
        return array_map(
            static fn($line): string => $line->memberId,
            $this->digestService()->collect()->lines,
        );
    }

    private function at(string $instant): DateTimeImmutable
    {
        return new DateTimeImmutable($instant, new DateTimeZone('UTC'));
    }

    /**
     * Set (or restore) `MAIL_DSN` for this process.
     *
     * `Env::get()` consults `$_ENV` before the system environment, so both have
     * to move together or a later read would see the stale one.
     */
    private function givenMailDsn(?string $dsn): void
    {
        if ($dsn === null) {
            putenv('MAIL_DSN');
            unset($_ENV['MAIL_DSN']);

            return;
        }

        putenv('MAIL_DSN=' . $dsn);
        $_ENV['MAIL_DSN'] = $dsn;
    }

    private function givenCadence(DigestCadence $cadence): void
    {
        $this->originalCadence ??= (string) $this->db
            ->query('SELECT credit_limit_digest_cadence FROM mail_config WHERE id = 1')
            ->fetchColumn();

        $this->db->prepare('UPDATE mail_config SET credit_limit_digest_cadence = ? WHERE id = 1')
            ->execute([$cadence->value]);
    }

    private function givenClubLimit(int $defaultLimitCents, int $warnThresholdPercent): void
    {
        $this->db->prepare(
            'UPDATE credit_limit_config SET default_limit_cents = ?, warn_threshold_percent = ? WHERE id = 1'
        )->execute([$defaultLimitCents, $warnThresholdPercent]);
    }

    /**
     * A member with an unsettled tab of exactly `$owedCents`.
     *
     * One transaction, no product, no settlement item — the balance is the
     * unsettled sum, which is the same figure the member's own page reports.
     */
    private function givenMemberOwing(
        int $owedCents,
        ?int $creditLimitCents = null,
        bool $isActive = true,
        string $firstName = 'Digest',
        string $lastName = 'Member',
    ): string {
        $memberId = $this->generateUuid();
        $this->created[] = ['table' => 'members', 'id' => $memberId];

        $this->db->prepare(
            'INSERT INTO members (id, first_name, last_name, email, preferred_language, is_active, credit_limit_cents)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $memberId,
            $firstName,
            $lastName,
            $memberId . '@example.com',
            'de',
            $isActive ? 1 : 0,
            $creditLimitCents,
        ]);

        $transactionId = $this->generateUuid();
        $this->created[] = ['table' => 'transactions', 'id' => $transactionId];

        $this->db->prepare(
            'INSERT INTO transactions
                (id, member_id, product_id, amount_cents, transaction_type, occurred_at, received_at)
             VALUES (?, ?, NULL, ?, ?, NOW(), NOW())'
        )->execute([
            $transactionId,
            $memberId,
            $owedCents,
            // A payout is the type that legitimately carries a negative amount,
            // so a member in credit can be expressed without a fake purchase.
            $owedCents < 0 ? 'payout' : 'purchase',
        ]);

        return $memberId;
    }

    /**
     * @param list<AdminRole> $roles
     * @return string The account's email — which is what the outbox snapshots.
     */
    private function givenAdmin(array $roles): string
    {
        $id = $this->generateUuid();
        $email = 'digest-' . $id . '@example.org';

        $this->db->prepare(
            'INSERT INTO admin_users (id, email, password_hash, display_name, locale, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())'
        )->execute([$id, $email, password_hash('not-used-' . $id, PASSWORD_DEFAULT), 'Digest Recipient', 'de']);
        $this->createdAdmins[] = $id;

        foreach ($roles as $role) {
            $this->db->prepare('INSERT INTO admin_user_roles (admin_user_id, role) VALUES (?, ?)')
                ->execute([$id, $role->value]);
        }

        return $email;
    }

    private function digestCount(string $recipient, string $window): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM mail_outbox WHERE kind = ? AND recipient = ? AND dedup_key LIKE ?'
        );
        $stmt->execute([MailKind::CREDIT_LIMIT_DIGEST->value, $recipient, $window . ':%']);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function digestRow(string $recipient, string $window): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM mail_outbox WHERE kind = ? AND recipient = ? AND dedup_key LIKE ?'
        );
        $stmt->execute([MailKind::CREDIT_LIMIT_DIGEST->value, $recipient, $window . ':%']);
        $row = $stmt->fetch();

        $this->assertIsArray($row, "no {$window} digest for {$recipient}");

        return $row;
    }
}
