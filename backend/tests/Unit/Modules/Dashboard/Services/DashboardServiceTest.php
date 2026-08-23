<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Dashboard\Services;

use App\Modules\CreditLimits\Domain\CreditLimit;
use App\Modules\CreditLimits\Domain\CreditLimitPolicy;
use App\Modules\CreditLimits\Services\CreditLimitConfigService;
use App\Modules\Dashboard\Repositories\DashboardRepository;
use App\Modules\Dashboard\Services\DashboardService;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Terminals\Repositories\TerminalAnomaliesRepository;
use App\Modules\Transactions\Repositories\JugendschutzViolationsRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard's judgement calls (#118).
 *
 * They used to live in a 293-line controller holding a raw PDO, so none of them
 * could be checked without a request and a database — and the module shipped
 * with no tests at all. Everything below is about meaning, not SQL: when a
 * terminal counts as online, when missing SEPA data is worth an alarm, which
 * of a product's names to show.
 */
class DashboardServiceTest extends TestCase
{
    private DashboardRepository $dashboardRepository;
    private MembersRepository $membersRepository;
    private TransactionsRepository $transactionsRepository;
    private SettlementsRepository $settlementsRepository;
    private TerminalsRepository $terminalsRepository;
    private EncryptionKeysRepository $encryptionKeysRepository;
    private SepaConfigRepository $sepaConfigRepository;
    private TerminalAnomaliesRepository $terminalAnomaliesRepository;
    private JugendschutzViolationsRepository $jugendschutzViolationsRepository;
    private CreditLimitConfigService $creditLimitConfigService;
    private DashboardService $service;

    /** The club's configured ceiling, as the panel resolves it. */
    private CreditLimit $clubLimit;

    protected function setUp(): void
    {
        $this->dashboardRepository = $this->createMock(DashboardRepository::class);
        $this->membersRepository = $this->createMock(MembersRepository::class);
        $this->transactionsRepository = $this->createMock(TransactionsRepository::class);
        $this->settlementsRepository = $this->createMock(SettlementsRepository::class);
        $this->terminalsRepository = $this->createMock(TerminalsRepository::class);
        $this->encryptionKeysRepository = $this->createMock(EncryptionKeysRepository::class);
        $this->sepaConfigRepository = $this->createMock(SepaConfigRepository::class);
        $this->terminalAnomaliesRepository = $this->createMock(TerminalAnomaliesRepository::class);
        $this->jugendschutzViolationsRepository = $this->createMock(JugendschutzViolationsRepository::class);
        $this->jugendschutzViolationsRepository->method('unacknowledgedSummary')
            ->willReturn(['count' => 0, 'latest_occurred_at' => null]);

        // The club's ceiling now comes from configuration rather than a
        // constant (ADR-0047). Stubbed with the shipped defaults, which are
        // the numbers this panel has always drawn its line at.
        $this->clubLimit = CreditLimitPolicy::shipped()->clubDefault();
        $this->creditLimitConfigService = $this->createMock(CreditLimitConfigService::class);
        $this->creditLimitConfigService->method('policy')->willReturn(CreditLimitPolicy::shipped());

        $this->service = $this->serviceWith($this->creditLimitConfigService);
    }

    /**
     * The same service with a different club policy — the one dependency whose
     * value changes what the near-limit panel asks for.
     */
    private function serviceWith(CreditLimitConfigService $creditLimits): DashboardService
    {
        return new DashboardService(
            $this->dashboardRepository,
            $this->membersRepository,
            $this->transactionsRepository,
            $this->settlementsRepository,
            $this->terminalsRepository,
            $this->encryptionKeysRepository,
            $this->sepaConfigRepository,
            $this->terminalAnomaliesRepository,
            $this->jugendschutzViolationsRepository,
            $creditLimits,
        );
    }

    // ── Jugendschutz violations (#622, ADR-0045 §3) ─────────────────────────
    //
    // The audit entry M7 writes is the record, and it is `admin`-only. The
    // dashboard is `TREASURY`, so this alert is what lets the **Kassenwart** —
    // the office the epic names as the recipient — learn a violation happened
    // at all. It counts only violations nobody has acknowledged: the record is
    // permanent (invariant 4), the *alert* is what quietens.

    public function test_no_unacknowledged_violations_is_silent(): void
    {
        $alert = DashboardService::jugendschutzViolationAlert(0, null);

        $this->assertSame('none', $alert['severity']);
        $this->assertSame(0, $alert['count']);
        $this->assertNull($alert['latest_occurred_at']);
    }

    /**
     * One is an error, not a warning.
     *
     * Every other alert here grades by volume, because they are about money or
     * infrastructure drifting. This one is about a minor having been served
     * alcohol, which is an incident at n=1 — § 28 JuSchG exposure does not
     * soften because it only happened once.
     */
    public function test_a_single_unacknowledged_violation_reads_as_an_error(): void
    {
        $alert = DashboardService::jugendschutzViolationAlert(1, '2026-08-20 21:14:00');

        $this->assertSame('error', $alert['severity']);
        $this->assertSame(1, $alert['count']);
        $this->assertSame('2026-08-20 21:14:00', $alert['latest_occurred_at']);
    }

    public function test_the_message_counts_them(): void
    {
        $alert = DashboardService::jugendschutzViolationAlert(3, '2026-08-20 21:14:00');

        $this->assertSame(3, $alert['count']);
        $this->assertStringContainsString('3', $alert['message']);
        $this->assertSame('error', $alert['severity']);
    }

    /**
     * The alert names nobody.
     *
     * It renders on a screen a Kassenwart may have open in the clubroom, and
     * ADR-0045 rule 6 does not stop at the till. A count and a timestamp are
     * enough to send somebody to the record; who it was is read there, by
     * whoever is entitled to.
     */
    public function test_the_alert_carries_no_member_and_no_age(): void
    {
        $serialised = strtolower((string) json_encode(
            DashboardService::jugendschutzViolationAlert(2, '2026-08-20 21:14:00')
        ));

        foreach (['member', 'birth', 'age_at_sale', 'geburt'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialised);
        }
    }

    // ── Terminal credential anomalies (ADR-0041) ────────────────────────────

    public function test_no_open_anomalies_reads_as_no_alert(): void
    {
        $alert = DashboardService::terminalAnomalyAlert([]);

        $this->assertSame('none', $alert['severity']);
        $this->assertSame(0, $alert['count']);
        $this->assertSame([], $alert['kinds']);
        $this->assertSame(0, $alert['terminal_count']);
        $this->assertNull($alert['terminal_name']);
    }

    /**
     * Concurrent use is the one kind with no routine innocent cause, so it is
     * the one that reads as an error.
     */
    public function test_concurrent_use_reads_as_an_error(): void
    {
        $alert = DashboardService::terminalAnomalyAlert([
            ['terminal_id' => 't1', 'terminal_name' => 'Theke 1', 'kind' => 'concurrent_ip'],
        ]);

        $this->assertSame('error', $alert['severity']);
        $this->assertStringContainsString('Theke 1', $alert['message']);
        $this->assertSame(1, $alert['terminal_count']);
        $this->assertSame('Theke 1', $alert['terminal_name']);
    }

    /**
     * A cursor reset is what a re-provisioned terminal looks like too, so it
     * warns rather than alarms.
     */
    public function test_a_cursor_anomaly_alone_reads_as_a_warning(): void
    {
        $alert = DashboardService::terminalAnomalyAlert([
            ['terminal_id' => 't1', 'terminal_name' => 'Theke 1', 'kind' => 'cursor_reset'],
        ]);

        $this->assertSame('warning', $alert['severity']);
    }

    /**
     * One till can carry both a concurrent-use row and a cursor row about the
     * same incident. Counting rows would then say "2 terminals" about one.
     */
    public function test_two_anomalies_on_one_terminal_are_reported_as_one_terminal(): void
    {
        $alert = DashboardService::terminalAnomalyAlert([
            ['terminal_id' => 't1', 'terminal_name' => 'Theke 1', 'kind' => 'concurrent_ip'],
            ['terminal_id' => 't1', 'terminal_name' => 'Theke 1', 'kind' => 'cursor_regression'],
        ]);

        $this->assertSame(2, $alert['count']);
        $this->assertStringContainsString('Theke 1', $alert['message']);
        $this->assertStringNotContainsString('2 terminals', $alert['message']);
        $this->assertSame(1, $alert['terminal_count']);
        $this->assertSame('Theke 1', $alert['terminal_name']);
    }

    public function test_several_terminals_are_counted_rather_than_named(): void
    {
        $alert = DashboardService::terminalAnomalyAlert([
            ['terminal_id' => 't1', 'terminal_name' => 'Theke 1', 'kind' => 'concurrent_ip'],
            ['terminal_id' => 't2', 'terminal_name' => 'Theke 2', 'kind' => 'concurrent_ip'],
        ]);

        $this->assertStringContainsString('2 terminals', $alert['message']);
        $this->assertSame(2, $alert['terminal_count']);
        $this->assertNull($alert['terminal_name']);
    }

    // ── Terminal status ─────────────────────────────────────────────────────

    public function test_a_switched_off_terminal_reads_as_disabled_however_recently_it_synced(): void
    {
        $now = 1_800_000_000;

        $status = DashboardService::terminalStatus(
            ['is_active' => 0, 'last_sync_at' => date('Y-m-d H:i:s', $now)],
            $now,
        );

        $this->assertSame('disabled', $status);
    }

    public function test_a_terminal_that_synced_inside_the_window_reads_as_online(): void
    {
        $now = 1_800_000_000;

        $status = DashboardService::terminalStatus(
            ['is_active' => 1, 'last_sync_at' => date('Y-m-d H:i:s', $now - 60)],
            $now,
        );

        $this->assertSame('online', $status);
    }

    public function test_the_online_window_is_inclusive_at_its_edge(): void
    {
        $now = 1_800_000_000;
        $edge = $now - DashboardService::TERMINAL_ONLINE_WINDOW_SECONDS;

        $this->assertSame(
            'online',
            DashboardService::terminalStatus(['is_active' => 1, 'last_sync_at' => date('Y-m-d H:i:s', $edge)], $now),
        );
        $this->assertSame(
            'offline',
            DashboardService::terminalStatus(['is_active' => 1, 'last_sync_at' => date('Y-m-d H:i:s', $edge - 1)], $now),
        );
    }

    public function test_a_terminal_that_has_never_synced_reads_as_offline_not_online(): void
    {
        $status = DashboardService::terminalStatus(['is_active' => 1, 'last_sync_at' => null], 1_800_000_000);

        $this->assertSame('offline', $status);
    }

    // ── SEPA alert ──────────────────────────────────────────────────────────

    public function test_no_members_without_a_mandate_is_not_an_alert(): void
    {
        $alert = DashboardService::sepaAlert(0);

        $this->assertSame('none', $alert['severity']);
        $this->assertSame(0, $alert['count']);
        $this->assertSame('No SEPA data issues', $alert['message']);
    }

    public function test_a_handful_of_members_without_a_mandate_is_a_warning(): void
    {
        $this->assertSame('warning', DashboardService::sepaAlert(1)['severity']);
        $this->assertSame('warning', DashboardService::sepaAlert(DashboardService::SEPA_WARNING_THRESHOLD)['severity']);
    }

    public function test_more_than_a_handful_escalates_to_an_error(): void
    {
        $alert = DashboardService::sepaAlert(DashboardService::SEPA_WARNING_THRESHOLD + 1);

        $this->assertSame('error', $alert['severity']);
        $this->assertStringContainsString('missing SEPA data', $alert['message']);
    }

    // ── Encryption key alert (#394) ─────────────────────────────────────────

    public function test_no_active_encryption_key_is_the_loudest_alert(): void
    {
        // Not merely "rotate soon": with no active key, storing a member's bank
        // details is refused outright, so the dashboard has to say so.
        $alert = DashboardService::encryptionKeyAlert(null);

        $this->assertSame('missing', $alert['state']);
        $this->assertSame('error', $alert['severity']);
        $this->assertNull($alert['days_until_expiry']);
        $this->assertStringContainsString('cannot be stored', $alert['message']);
    }

    public function test_a_comfortably_valid_key_raises_nothing(): void
    {
        $alert = DashboardService::encryptionKeyAlert($this->keyExpiringInDays(200));

        $this->assertSame('ok', $alert['state']);
        $this->assertSame('none', $alert['severity']);
    }

    public function test_the_warning_tiers_follow_the_credential_lifetime_policy(): void
    {
        // 90/30/7, per ADR-0036 — checked here because this is where they turn
        // into something an admin sees.
        $this->assertSame('info', DashboardService::encryptionKeyAlert($this->keyExpiringInDays(60))['state']);
        $this->assertSame('warning', DashboardService::encryptionKeyAlert($this->keyExpiringInDays(20))['state']);
        $this->assertSame('critical', DashboardService::encryptionKeyAlert($this->keyExpiringInDays(3))['state']);

        $this->assertSame('warning', DashboardService::encryptionKeyAlert($this->keyExpiringInDays(60))['severity']);
        $this->assertSame('error', DashboardService::encryptionKeyAlert($this->keyExpiringInDays(3))['severity']);
    }

    public function test_an_expired_key_says_what_it_blocks(): void
    {
        $alert = DashboardService::encryptionKeyAlert($this->keyExpiringInDays(-1));

        $this->assertSame('expired', $alert['state']);
        $this->assertSame('error', $alert['severity']);
        $this->assertStringContainsString('club-key', $alert['message']);
        $this->assertStringContainsString('SEPA', $alert['message']);
    }

    /** @return array{key_identifier: string, expires_at: string} */
    private function keyExpiringInDays(int $days): array
    {
        $expiry = (new \DateTimeImmutable())->add(new \DateInterval('P' . abs($days) . 'D'));
        if ($days < 0) {
            $expiry = (new \DateTimeImmutable())->sub(new \DateInterval('P' . abs($days) . 'D'));
        }

        return [
            'key_identifier' => 'club-key-test',
            // A day boundary is measured in whole days, so nudge past it: a key
            // expiring "in 3 days" at 14:00 has 2.x days left by that measure.
            'expires_at' => $expiry->add(new \DateInterval('PT1H'))->format('Y-m-d H:i:s'),
        ];
    }

    // ── SEPA config alert (#360/#456) ───────────────────────────────────────

    public function test_no_sepa_config_row_is_an_error(): void
    {
        $alert = DashboardService::sepaConfigAlert(null);

        $this->assertSame('error', $alert['severity']);
        $this->assertStringContainsString('creditor details', $alert['message']);
    }

    public function test_missing_creditor_details_is_an_error(): void
    {
        $alert = DashboardService::sepaConfigAlert([
            'creditor_id' => null,
            'creditor_name' => null,
            'creditor_iban' => null,
            'mandate_template_url' => 'https://club.example/anmeldung',
        ]);

        $this->assertSame('error', $alert['severity']);
        $this->assertStringContainsString('creditor details', $alert['message']);
    }

    public function test_missing_mandate_template_url_is_its_own_error(): void
    {
        $alert = DashboardService::sepaConfigAlert([
            'creditor_id' => 'DE98ZZZ09999999999',
            'creditor_name' => 'Musterverein e.V.',
            'creditor_iban' => 'DE89370400440532013000',
            'mandate_template_url' => null,
        ]);

        $this->assertSame('error', $alert['severity']);
        $this->assertStringContainsString('mandate template URL', $alert['message']);
    }

    public function test_a_fully_configured_club_raises_nothing(): void
    {
        $alert = DashboardService::sepaConfigAlert([
            'creditor_id' => 'DE98ZZZ09999999999',
            'creditor_name' => 'Musterverein e.V.',
            'creditor_iban' => 'DE89370400440532013000',
            'mandate_template_url' => 'https://club.example/anmeldung',
        ]);

        $this->assertSame('none', $alert['severity']);
    }

    // ── Product display name ────────────────────────────────────────────────

    public function test_a_product_shows_its_german_name_when_it_has_one(): void
    {
        $this->assertSame('Bier', DashboardService::displayName('{"de":"Bier","en":"Beer"}'));
    }

    public function test_a_product_falls_back_to_english_when_german_is_missing(): void
    {
        $this->assertSame('Beer', DashboardService::displayName('{"en":"Beer"}'));
    }

    public function test_an_absent_or_unreadable_name_blob_yields_no_name_rather_than_a_crash(): void
    {
        $this->assertNull(DashboardService::displayName(null));
        $this->assertNull(DashboardService::displayName(''));
        $this->assertNull(DashboardService::displayName('not json'));
        $this->assertNull(DashboardService::displayName('{"fr":"Bière"}'));
    }

    // ── Dashboard assembly ──────────────────────────────────────────────────

    public function test_the_dashboard_presents_transactions_terminals_and_alerts_together(): void
    {
        $this->membersRepository->method('count')->willReturn(120);
        $this->membersRepository->method('countActive')->willReturn(90);
        $this->transactionsRepository->method('countRecentTransactions')->willReturn(42);
        $this->transactionsRepository->method('sumUnsettledAmountCents')->willReturn(7_500);
        $this->settlementsRepository->method('countPending')->willReturn(2);
        $this->settlementsRepository->method('getLatest')->willReturn(['created_at' => '2026-07-01 08:00:00']);
        $this->terminalsRepository->method('findAll')->willReturn([
            [
                'id' => 'term-1',
                'name' => 'Tresen',
                'device_id' => 'dev-1',
                'is_active' => 1,
                'last_sync_at' => date('Y-m-d H:i:s'),
            ],
            [
                'id' => 'term-2',
                'name' => 'Stale',
                'device_id' => 'dev-2',
                'is_active' => 1,
                'last_sync_at' => date('Y-m-d H:i:s', time() - DashboardService::TERMINAL_ONLINE_WINDOW_SECONDS - 1),
            ],
        ]);

        $this->dashboardRepository->method('sumRevenueSince')->willReturn(1_000);
        $this->dashboardRepository->method('countMembersWithoutMandate')->willReturn(3);
        $this->dashboardRepository->method('findRecentTransactions')->willReturn([
            [
                'id' => 'tx-1',
                'member_id' => 'mem-1',
                'member_name' => 'Anna Meier',
                'terminal_name' => 'Tresen',
                'type' => 'purchase',
                'amount_cents' => '250',
                'product_names' => '{"de":"Bier","en":"Beer"}',
                'timestamp' => '2026-07-02 19:04:11',
            ],
        ]);

        $dashboard = $this->service->getDashboard()->toArray();

        $this->assertSame(90, $dashboard['metrics']['active_members']);
        $this->assertSame(30, $dashboard['metrics']['inactive_members']);
        $this->assertSame(3, $dashboard['metrics']['sepa_issue_count']);
        $this->assertSame(2, $dashboard['metrics']['terminal_count']);

        $this->assertSame('online', $dashboard['terminal_status'][0]['status']);
        $this->assertTrue($dashboard['terminal_status'][0]['is_active']);
        $this->assertSame('offline', $dashboard['terminal_status'][1]['status']);
        $this->assertTrue($dashboard['terminal_status'][1]['is_active']);

        // term-2 is enabled but stale, so it must not inflate active_terminals —
        // the count has to agree with how many entries terminal_status reports as 'online'.
        $this->assertSame(1, $dashboard['metrics']['active_terminals']);

        $transaction = $dashboard['recent_transactions'][0];
        $this->assertSame('Bier', $transaction['product_name']);
        $this->assertSame(250, $transaction['amount_cents'], 'amounts come back from the driver as strings');
        $this->assertSame('2026-07-02T19:04:11', $transaction['timestamp'], 'the API speaks ISO-8601');

        $this->assertSame('warning', $dashboard['alerts']['sepa_issues']['severity']);
        $this->assertSame('error', $dashboard['alerts']['sepa_config']['severity'], 'no SEPA config was stubbed in');
        $this->assertSame('2026-07-01 08:00:00', $dashboard['system_status']['last_settlement_date']);
    }

    public function test_a_dashboard_with_no_settlement_yet_reports_no_settlement_date(): void
    {
        $this->membersRepository->method('count')->willReturn(0);
        $this->membersRepository->method('countActive')->willReturn(0);
        $this->transactionsRepository->method('countRecentTransactions')->willReturn(0);
        $this->transactionsRepository->method('sumUnsettledAmountCents')->willReturn(0);
        $this->settlementsRepository->method('countPending')->willReturn(0);
        $this->settlementsRepository->method('getLatest')->willReturn(null);
        $this->terminalsRepository->method('findAll')->willReturn([]);
        $this->dashboardRepository->method('sumRevenueSince')->willReturn(0);
        $this->dashboardRepository->method('countMembersWithoutMandate')->willReturn(0);
        $this->dashboardRepository->method('findRecentTransactions')->willReturn([]);

        $dashboard = $this->service->getDashboard()->toArray();

        $this->assertNull($dashboard['system_status']['last_settlement_date']);
        $this->assertSame('none', $dashboard['alerts']['sepa_issues']['severity']);
    }

    // ── Members near their credit limit ─────────────────────────────────────

    public function test_the_dashboard_asks_only_for_members_inside_the_terminals_warning_band(): void
    {
        $this->stubEmptyDashboard();
        $this->dashboardRepository->expects($this->once())
            ->method('findMembersNearCreditLimit')
            ->with(
                $this->clubLimit->limitCents,
                $this->clubLimit->warnThresholdPercent,
                DashboardService::MEMBERS_NEAR_LIMIT_SHOWN,
            )
            ->willReturn([]);

        $nearLimit = $this->service->getDashboard()->toArray()['members_near_limit'];

        $this->assertSame($this->clubLimit->limitCents, $nearLimit['limit_cents']);
        $this->assertSame($this->clubLimit->warnAtCents(), $nearLimit['warn_at_cents']);
        $this->assertSame([], $nearLimit['members']);
        $this->assertSame(0, $nearLimit['total']);
    }

    public function test_each_member_near_the_limit_carries_their_tab_share_and_verdict(): void
    {
        $this->stubEmptyDashboard();
        $this->dashboardRepository->method('findMembersNearCreditLimit')->willReturn([
            ['id' => 'mem-1', 'name' => 'Anna Meier', 'balance_cents' => '10500', 'limit_cents' => '10000'],
            ['id' => 'mem-2', 'name' => 'Bert Klein', 'balance_cents' => '8200', 'limit_cents' => '10000'],
        ]);

        $members = $this->service->getDashboard()->toArray()['members_near_limit']['members'];

        $this->assertSame(
            [
                'id' => 'mem-1',
                'name' => 'Anna Meier',
                'balance_cents' => 10_500,
                'limit_cents' => 10_000,
                'percent_of_limit' => 105,
                'status' => 'exceeded',
            ],
            $members[0],
            'amounts come back from the driver as strings',
        );
        $this->assertSame(82, $members[1]['percent_of_limit']);
        $this->assertSame('approaching', $members[1]['status']);
    }

    public function test_a_list_shorter_than_the_cap_is_its_own_total_and_costs_no_second_query(): void
    {
        $this->stubEmptyDashboard();
        $this->dashboardRepository->method('findMembersNearCreditLimit')->willReturn([
            ['id' => 'mem-1', 'name' => 'Anna Meier', 'balance_cents' => '8100', 'limit_cents' => '10000'],
        ]);
        $this->dashboardRepository->expects($this->never())->method('countMembersNearCreditLimit');

        $nearLimit = $this->service->getDashboard()->toArray()['members_near_limit'];

        $this->assertSame(1, $nearLimit['total']);
    }

    public function test_a_full_list_reports_how_many_members_it_is_not_showing(): void
    {
        $this->stubEmptyDashboard();
        $this->dashboardRepository->method('findMembersNearCreditLimit')->willReturn(array_map(
            static fn(int $i): array => ['id' => "mem-{$i}", 'name' => "Member {$i}", 'balance_cents' => '9000', 'limit_cents' => '10000'],
            range(1, DashboardService::MEMBERS_NEAR_LIMIT_SHOWN),
        ));
        $this->dashboardRepository->expects($this->once())
            ->method('countMembersNearCreditLimit')
            ->with($this->clubLimit->limitCents, $this->clubLimit->warnThresholdPercent)
            ->willReturn(12);

        $nearLimit = $this->service->getDashboard()->toArray()['members_near_limit'];

        $this->assertCount(DashboardService::MEMBERS_NEAR_LIMIT_SHOWN, $nearLimit['members']);
        $this->assertSame(12, $nearLimit['total']);
    }

    public function test_a_member_with_only_one_name_is_labelled_with_the_half_that_exists(): void
    {
        $this->stubEmptyDashboard();
        $this->dashboardRepository->method('findMembersNearCreditLimit')->willReturn([
            ['id' => 'mem-1', 'name' => 'Meier ', 'balance_cents' => '9000', 'limit_cents' => '10000'],
        ]);

        $members = $this->service->getDashboard()->toArray()['members_near_limit']['members'];

        $this->assertSame('Meier', $members[0]['name']);
    }

    /**
     * Each row is measured against its *own* ceiling, which is the whole point
     * of the per-member override: the same tab means something different to two
     * members with different ceilings.
     */
    public function test_a_members_share_is_measured_against_their_own_ceiling(): void
    {
        $this->stubEmptyDashboard();
        $this->dashboardRepository->method('findMembersNearCreditLimit')->willReturn([
            // Past a small ceiling — refused at the bar right now.
            ['id' => 'mem-1', 'name' => 'Small Ceiling', 'balance_cents' => '6000', 'limit_cents' => '5000'],
            // Deep inside a large one, on a tab three times the size.
            ['id' => 'mem-2', 'name' => 'Large Ceiling', 'balance_cents' => '16800', 'limit_cents' => '20000'],
        ]);

        $members = $this->service->getDashboard()->toArray()['members_near_limit']['members'];

        $this->assertSame(5_000, $members[0]['limit_cents']);
        $this->assertSame(120, $members[0]['percent_of_limit']);
        $this->assertSame('exceeded', $members[0]['status']);

        $this->assertSame(20_000, $members[1]['limit_cents']);
        $this->assertSame(84, $members[1]['percent_of_limit']);
        $this->assertSame('approaching', $members[1]['status']);
    }

    /**
     * The envelope keeps naming the *club* figures — they are what the panel
     * says the default is — while each row names its own.
     */
    public function test_the_envelope_reports_the_club_default_not_a_members_ceiling(): void
    {
        $this->stubEmptyDashboard();
        $this->dashboardRepository->method('findMembersNearCreditLimit')->willReturn([
            ['id' => 'mem-1', 'name' => 'Own Ceiling', 'balance_cents' => '4500', 'limit_cents' => '5000'],
        ]);

        $nearLimit = $this->service->getDashboard()->toArray()['members_near_limit'];

        $this->assertSame(10_000, $nearLimit['limit_cents']);
        $this->assertSame(8_000, $nearLimit['warn_at_cents']);
        $this->assertSame(5_000, $nearLimit['members'][0]['limit_cents']);
    }

    /**
     * A club that caps nobody still has members who were deliberately capped,
     * and they are still being refused at the bar. The panel must therefore
     * keep asking — the query decides per row, this service does not
     * short-circuit (ADR-0047).
     */
    public function test_the_panel_still_asks_when_the_club_default_is_switched_off(): void
    {
        $this->creditLimitConfigService = $this->createMock(CreditLimitConfigService::class);
        $this->creditLimitConfigService->method('policy')->willReturn(new CreditLimitPolicy(0, 80));
        $this->service = $this->serviceWith($this->creditLimitConfigService);

        $this->stubEmptyDashboard();
        $this->dashboardRepository->expects($this->once())
            ->method('findMembersNearCreditLimit')
            ->with(0, 80, DashboardService::MEMBERS_NEAR_LIMIT_SHOWN)
            ->willReturn([
                ['id' => 'mem-1', 'name' => 'Capped Anyway', 'balance_cents' => '4500', 'limit_cents' => '5000'],
            ]);

        $nearLimit = $this->service->getDashboard()->toArray()['members_near_limit'];

        $this->assertSame(0, $nearLimit['limit_cents'], 'the club caps nobody');
        $this->assertCount(1, $nearLimit['members'], 'but this member was capped on purpose');
        $this->assertSame(90, $nearLimit['members'][0]['percent_of_limit']);
    }

    /**
     * The rest of the dashboard, stubbed down to nothing, so a test about one
     * panel says nothing about the others.
     */
    private function stubEmptyDashboard(): void
    {
        $this->membersRepository->method('count')->willReturn(0);
        $this->membersRepository->method('countActive')->willReturn(0);
        $this->transactionsRepository->method('countRecentTransactions')->willReturn(0);
        $this->transactionsRepository->method('sumUnsettledAmountCents')->willReturn(0);
        $this->settlementsRepository->method('countPending')->willReturn(0);
        $this->settlementsRepository->method('getLatest')->willReturn(null);
        $this->terminalsRepository->method('findAll')->willReturn([]);
        $this->dashboardRepository->method('sumRevenueSince')->willReturn(0);
        $this->dashboardRepository->method('countMembersWithoutMandate')->willReturn(0);
        $this->dashboardRepository->method('findRecentTransactions')->willReturn([]);
    }

    // ── Monthly statistics ──────────────────────────────────────────────────

    public function test_monthly_stats_asks_for_the_whole_calendar_month(): void
    {
        $this->dashboardRepository->expects($this->once())
            ->method('sumRevenueBetween')
            ->with('2026-02-01', '2026-02-28')
            ->willReturn(0);

        $this->service->getMonthlyStats('2026-02');
    }

    public function test_monthly_stats_covers_the_leap_day(): void
    {
        $this->dashboardRepository->expects($this->once())
            ->method('sumRevenueBetween')
            ->with('2024-02-01', '2024-02-29')
            ->willReturn(0);

        $this->service->getMonthlyStats('2024-02');
    }

    public function test_monthly_stats_names_the_best_selling_product(): void
    {
        $this->dashboardRepository->method('findTopProductsBySoldCount')->willReturn([
            ['id' => 'p-1', 'names' => '{"de":"Bier"}', 'sold_count' => '17'],
        ]);

        $stats = $this->service->getMonthlyStats('2026-02');

        $this->assertSame(['name' => 'Bier', 'sold_count' => 17], $stats['top_product']);
    }

    public function test_a_month_with_no_sales_has_no_best_selling_product(): void
    {
        $stats = $this->service->getMonthlyStats('2026-02');

        $this->assertNull($stats['top_product']);
        $this->assertSame([], $stats['daily_revenue']);
        $this->assertSame('2026-02', $stats['month']);
    }

    public function test_monthly_stats_returns_numbers_where_the_driver_returned_strings(): void
    {
        $this->dashboardRepository->method('findDailyRevenue')->willReturn([
            ['date' => '2026-02-03', 'revenue_cents' => '4500', 'transaction_count' => '9'],
        ]);
        $this->dashboardRepository->method('findTopProductsByRevenue')->willReturn([
            ['id' => 'p-1', 'names' => '{"en":"Beer"}', 'sold_count' => '3', 'revenue_cents' => '900'],
        ]);
        $this->dashboardRepository->method('findTopMembers')->willReturn([
            ['id' => 'm-1', 'name' => 'Anna Meier', 'purchase_count' => '4', 'revenue_cents' => '1200'],
        ]);

        $stats = $this->service->getMonthlyStats('2026-02');

        $this->assertSame(
            ['date' => '2026-02-03', 'revenue_cents' => 4500, 'transaction_count' => 9],
            $stats['daily_revenue'][0],
        );
        $this->assertSame(
            ['id' => 'p-1', 'name' => 'Beer', 'sold_count' => 3, 'revenue_cents' => 900],
            $stats['top_products'][0],
        );
        $this->assertSame(
            ['id' => 'm-1', 'name' => 'Anna Meier', 'purchase_count' => 4, 'revenue_cents' => 1200],
            $stats['top_members'][0],
        );
    }

}
