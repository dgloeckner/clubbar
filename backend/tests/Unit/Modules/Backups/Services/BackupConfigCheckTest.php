<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Backups\Services;

use App\Modules\Backups\Services\BackupConfigCheck;
use App\Shared\Security\SecurityFinding;
use PHPUnit\Framework\TestCase;

/**
 * Reporting what `config.php` actually says about backups.
 *
 * The installer writes the file safely now (#710), but nothing stops anybody
 * editing it afterwards — and across the two years between a setup and a
 * disaster, hand-editing is what happens. Every state below is otherwise
 * **silent until somebody needs a restore**, which is the worst moment to
 * discover any of it.
 *
 * ADR-0031 decision 3's rule is what shapes the statuses: a row is green only
 * when the effective state was *observed*, and something unmeasurable is
 * `UNKNOWN` rather than a pass.
 *
 * Part of #710, epic #686.
 */
class BackupConfigCheckTest extends TestCase
{
    private const KEY_A = 'admin:bb637d8ec1cb92bca0467e59faa6d61f6b7f8088103e5b89d7afdc01f1efa45c';
    private const KEY_B = 'vorstand:363e2a0c16939139dd4e593e63cfde7ebcbda57d7fd36c307be67005ebc7ab4d';
    private const DSN = 'msgraph://tenant/client@drive/b!driveid/clubbar';

    /**
     * Backups off is a legitimate state, not a broken one. A club that has not
     * set them up has not misconfigured anything, and a report that cried FAIL
     * here would teach its reader to skip the section.
     */
    public function test_no_recipient_keys_is_a_warning_rather_than_a_failure(): void
    {
        $finding = $this->findingById($this->check(keys: ''), 'backup_recipients');

        $this->assertSame(SecurityFinding::WARN, $finding->status);
        $this->assertStringContainsString('no archives are written', $finding->observed);
    }

    /**
     * The state this whole change came from: a key in the wrong encoding. It
     * fails nightly, in a job nobody reads, and the club believes it has
     * backups.
     */
    public function test_a_key_in_the_wrong_encoding_fails_and_says_why(): void
    {
        $base64 = 'admin:yeMcz7Ncmobf9EuVwTkeR+DOf3focDz4UV0c9/CIjk4=';

        $finding = $this->findingById($this->check(keys: $base64), 'backup_recipients');

        $this->assertSame(SecurityFinding::FAIL, $finding->status);
        $this->assertMatchesRegularExpression('/hex/i', (string) $finding->remedy);
    }

    /**
     * Two holders is organisational, not cryptographic: one volunteer leaving
     * with the only key makes every existing archive unreadable forever
     * (ADR-0049 decision 2).
     */
    public function test_a_single_recipient_is_flagged_as_one_volunteer_away_from_unreadable(): void
    {
        $finding = $this->findingById($this->check(keys: self::KEY_A), 'backup_recipients');

        $this->assertSame(SecurityFinding::WARN, $finding->status);
        $this->assertStringContainsString('Add a second recipient', (string) $finding->remedy);
    }

    public function test_two_recipients_pass(): void
    {
        $finding = $this->findingById(
            $this->check(keys: self::KEY_A . "\n" . self::KEY_B),
            'backup_recipients'
        );

        $this->assertSame(SecurityFinding::PASS, $finding->status);
        $this->assertStringContainsString('2 recipients', $finding->observed);
    }

    /**
     * **The belief this feature exists to destroy**, arrived at by a typo: a
     * club that filled in a DSN and thinks its archives are leaving the host.
     */
    public function test_a_malformed_dsn_fails_rather_than_reading_as_no_remote(): void
    {
        $finding = $this->findingById($this->check(dsn: 'msgraph://tenant-only'), 'backup_remote');

        $this->assertSame(SecurityFinding::FAIL, $finding->status);
        $this->assertStringContainsString('unusable', $finding->observed);
    }

    public function test_no_dsn_warns_that_the_archive_shares_a_fate_with_the_database(): void
    {
        $finding = $this->findingById($this->check(dsn: ''), 'backup_remote');

        $this->assertSame(SecurityFinding::WARN, $finding->status);
        $this->assertStringContainsString('not off-site', (string) $finding->remedy);
    }

    public function test_a_valid_dsn_passes_and_names_the_store_without_the_ids(): void
    {
        $finding = $this->findingById($this->check(), 'backup_remote');

        $this->assertSame(SecurityFinding::PASS, $finding->status);
        $this->assertStringContainsString('clubbar', $finding->observed);
        $this->assertStringNotContainsString('tenant/client', $finding->observed);
    }

    public function test_a_remote_with_no_secret_cannot_sign_in_and_says_so(): void
    {
        $finding = $this->findingById($this->check(secret: ''), 'backup_secret');

        $this->assertSame(SecurityFinding::FAIL, $finding->status);
        $this->assertStringContainsString('cannot sign in', (string) $finding->remedy);
    }

    /**
     * An expired secret is indistinguishable from a working one from everywhere
     * except the failing upload — Entra warns nobody.
     */
    public function test_an_expired_secret_is_reported_with_how_long_it_has_been_failing(): void
    {
        $finding = $this->findingById(
            $this->check(expires: date('Y-m-d', strtotime('-10 days'))),
            'backup_secret'
        );

        $this->assertSame(SecurityFinding::FAIL, $finding->status);
        $this->assertStringContainsString('expired', $finding->observed);
    }

    public function test_a_secret_expiring_soon_warns_while_rotation_is_still_free(): void
    {
        $finding = $this->findingById(
            $this->check(expires: date('Y-m-d', strtotime('+9 days'))),
            'backup_secret'
        );

        $this->assertSame(SecurityFinding::WARN, $finding->status);
        $this->assertStringContainsString('no downtime', (string) $finding->remedy);
    }

    public function test_a_secret_with_a_year_left_passes(): void
    {
        $finding = $this->findingById(
            $this->check(expires: date('Y-m-d', strtotime('+300 days'))),
            'backup_secret'
        );

        $this->assertSame(SecurityFinding::PASS, $finding->status);
    }

    /**
     * Unmeasurable is never a pass (ADR-0031 decision 3). Without the date
     * nothing can warn before the secret lapses, and Microsoft will not.
     */
    public function test_a_missing_expiry_date_is_unknown_rather_than_a_pass(): void
    {
        $finding = $this->findingById($this->check(expires: ''), 'backup_secret');

        $this->assertSame(SecurityFinding::UNKNOWN, $finding->status);
    }

    /** @param list<SecurityFinding> $findings */
    private function findingById(array $findings, string $id): SecurityFinding
    {
        foreach ($findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        $this->fail(sprintf(
            'No finding "%s". Got: %s.',
            $id,
            implode(', ', array_map(static fn (SecurityFinding $f): string => $f->id, $findings))
        ));
    }

    /**
     * **A monitor watching a job that never runs** (#712).
     *
     * The club created a healthchecks.io check, pasted the URL in, and backups
     * are off — so nothing ever pings it. The check goes red on day one and
     * stays red for ever, and the club either chases a failure that is not
     * happening or deletes the check and believes it is monitored. Invisible
     * from everywhere else, because both halves look individually fine.
     */
    public function test_a_monitor_configured_while_backups_are_off_is_reported(): void
    {
        $finding = $this->findingById(
            $this->check(keys: '', monitor: 'https://hc-ping.com/8f14e45f'),
            'backup_monitor'
        );

        $this->assertSame(SecurityFinding::WARN, $finding->status);
        $this->assertStringContainsString('never pinged', $finding->observed);
    }

    /** Backups on and a monitor configured is the intended state; no row. */
    public function test_a_monitor_with_backups_on_produces_no_row(): void
    {
        $ids = array_map(
            static fn (SecurityFinding $f): string => $f->id,
            $this->check(monitor: 'https://hc-ping.com/8f14e45f')
        );

        $this->assertNotContains('backup_monitor', $ids);
    }

    /**
     * Backups off and no monitor is a club that has not set backups up. The
     * `backup_recipients` row already says so; a second row saying it again is
     * how a report teaches people to skim it.
     */
    public function test_no_monitor_and_no_backups_produces_no_monitor_row(): void
    {
        $ids = array_map(
            static fn (SecurityFinding $f): string => $f->id,
            $this->check(keys: '')
        );

        $this->assertNotContains('backup_monitor', $ids);
    }

    /** @return list<SecurityFinding> */
    private function check(
        string $keys = self::KEY_A . "\n" . self::KEY_B,
        ?string $dsn = self::DSN,
        ?string $secret = 'a-secret',
        ?string $expires = '2099-01-01',
        ?string $monitor = null,
    ): array {
        return (new BackupConfigCheck($keys, $dsn, $secret, $expires, $monitor))->findings();
    }
}
