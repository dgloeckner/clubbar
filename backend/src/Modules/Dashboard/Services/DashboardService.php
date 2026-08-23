<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\CreditLimits\Domain\CreditLimitPolicy;
use App\Modules\CreditLimits\Services\CreditLimitConfigService;
use App\Modules\Dashboard\DTOs\DashboardDto;
use App\Modules\Dashboard\Repositories\DashboardRepository;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Modules\Terminals\Enums\TerminalAnomalyKind;
use App\Modules\Terminals\Repositories\TerminalAnomaliesRepository;
use App\Modules\Transactions\Repositories\JugendschutzViolationsRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\Security\CredentialLifecycle;

/**
 * Everything the dashboard and the monthly statistics page mean, as opposed to
 * everything they read (Pattern 004).
 *
 * The judgement calls that used to sit in the HTTP layer — when a terminal
 * counts as online, when missing SEPA data is a warning rather than an error,
 * which of a product's translated names to show — live here, where they can be
 * exercised without a request and without a database.
 */
class DashboardService
{
    /** A terminal that has not synced within this many seconds reads as offline. */
    public const TERMINAL_ONLINE_WINDOW_SECONDS = 300;

    /** Above this many members without a mandate the SEPA alert turns from warning to error. */
    public const SEPA_WARNING_THRESHOLD = 5;

    private const RECENT_TRANSACTION_LIMIT = 10;
    private const REVENUE_WINDOW_DAYS = 30;
    private const TOP_LIST_LIMIT = 10;

    /**
     * How many members near their limit the dashboard names. The rest are
     * counted, not listed — the panel is a prompt to act, and a list longer
     * than this is a settlement run, not a conversation.
     */
    public const MEMBERS_NEAR_LIMIT_SHOWN = 5;

    public function __construct(
        private DashboardRepository $dashboardRepository,
        private MembersRepository $membersRepository,
        private TransactionsRepository $transactionsRepository,
        private SettlementsRepository $settlementsRepository,
        private TerminalsRepository $terminalsRepository,
        private EncryptionKeysRepository $encryptionKeysRepository,
        private SepaConfigRepository $sepaConfigRepository,
        private TerminalAnomaliesRepository $terminalAnomaliesRepository,
        private JugendschutzViolationsRepository $jugendschutzViolationsRepository,
        private CreditLimitConfigService $creditLimitConfigService,
    ) {}

    public function getDashboard(): DashboardDto
    {
        $jugendschutz = $this->jugendschutzViolationsRepository->unacknowledgedSummary();
        $totalMembers = $this->membersRepository->count();
        $activeMembers = $this->membersRepository->countActive();
        $recentTransactionCount = $this->transactionsRepository->countRecentTransactions(days: self::REVENUE_WINDOW_DAYS);
        $pendingSettlements = $this->settlementsRepository->countPending();

        // Revenue: today, week-to-date (Monday), month-to-date (1st)
        $todaysRevenueCents = $this->dashboardRepository->sumRevenueSince(date('Y-m-d'));
        $wtdRevenueCents = $this->dashboardRepository->sumRevenueSince(date('Y-m-d', strtotime('monday this week')));
        $mtdRevenueCents = $this->dashboardRepository->sumRevenueSince(date('Y-m-01'));

        $latestSettlement = $this->settlementsRepository->getLatest();
        $outstandingBalanceCents = $this->transactionsRepository->sumUnsettledAmountCents();

        $recentTransactions = array_map(
            fn(array $row): array => $this->presentRecentTransaction($row),
            $this->dashboardRepository->findRecentTransactions(self::RECENT_TRANSACTION_LIMIT),
        );

        $terminalRows = $this->terminalsRepository->findAll();
        $now = time();
        $terminalStatus = array_map(
            fn(array $terminal): array => $this->presentTerminal($terminal, $now),
            $terminalRows,
        );

        $sepaIssueCount = $this->dashboardRepository->countMembersWithoutMandate();

        return new DashboardDto(
            metrics: [
                'active_members' => $activeMembers,
                'inactive_members' => $totalMembers - $activeMembers,
                'outstanding_balance_cents' => $outstandingBalanceCents,
                'todays_revenue_cents' => $todaysRevenueCents,
                'wtd_revenue_cents' => $wtdRevenueCents,
                'mtd_revenue_cents' => $mtdRevenueCents,
                'terminal_count' => count($terminalRows),
                'active_terminals' => count(array_filter(
                    $terminalStatus,
                    fn(array $terminal): bool => $terminal['status'] === 'online',
                )),
                'settled_members' => 0,
                'sepa_issue_count' => $sepaIssueCount,
            ],
            recentTransactions: $recentTransactions,
            terminalStatus: $terminalStatus,
            systemStatus: [
                'last_settlement_date' => $latestSettlement['created_at'] ?? null,
                'pending_settlement_count' => $pendingSettlements,
                'total_members' => $totalMembers,
                'total_transactions' => $recentTransactionCount,
                'database_health' => 'ok',
            ],
            alerts: [
                'sepa_issues' => self::sepaAlert($sepaIssueCount),
                'encryption_key' => self::encryptionKeyAlert($this->encryptionKeysRepository->findActive()),
                'sepa_config' => self::sepaConfigAlert($this->sepaConfigRepository->getConfig()),
                'terminal_anomaly' => self::terminalAnomalyAlert($this->terminalAnomaliesRepository->listOpen()),
                'jugendschutz_violation' => self::jugendschutzViolationAlert(
                    $jugendschutz['count'],
                    $jugendschutz['latest_occurred_at'],
                ),
            ],
            membersNearLimit: $this->membersNearLimit(),
        );
    }

    /**
     * Who is about to be turned away at the terminal (#385).
     *
     * The dashboard's other figures are money in aggregate; this one is the
     * only place the admin learns *before* it happens that a member's next
     * drink will be refused — until now the club found out when someone stood
     * at the bar with a blocked card, which is the worst possible moment and
     * the wrong person to tell.
     *
     * The band and the ceiling are the terminal's, not this screen's, so a
     * member appears here exactly when the terminal has started warning them.
     * The total is reported separately from the list because the list is
     * capped: five names and "and 7 more" is a truthful screenful, five names
     * alone is not.
     *
     * @return array<string, mixed>
     */
    private function membersNearLimit(): array
    {
        $policy = $this->creditLimitConfigService->policy();
        $clubDefault = $policy->clubDefault();

        // Deliberately no short-circuit when the club caps nobody: a member
        // carrying an override still has a ceiling and is still being refused
        // at the bar. The query decides per row (ADR-0046).
        $rows = $this->dashboardRepository->findMembersNearCreditLimit(
            $policy->defaultLimitCents,
            $policy->warnThresholdPercent,
            self::MEMBERS_NEAR_LIMIT_SHOWN,
        );

        // A short list is its own total; only a full one can be hiding someone.
        $total = count($rows) < self::MEMBERS_NEAR_LIMIT_SHOWN
            ? count($rows)
            : $this->dashboardRepository->countMembersNearCreditLimit(
                $policy->defaultLimitCents,
                $policy->warnThresholdPercent,
            );

        return [
            // The club's figures, named as such. Each row carries the ceiling
            // it was actually measured against, which may be its own.
            'limit_cents' => $clubDefault->limitCents,
            'warn_at_cents' => $clubDefault->warnAtCents(),
            'total' => $total,
            'members' => array_map(
                static fn(array $row): array => self::presentMemberNearLimit($row, $policy),
                $rows,
            ),
        ];
    }

    /**
     * @param string $month `YYYY-MM`; callers validate the shape before asking.
     * @return array<string, mixed>
     */
    public function getMonthlyStats(string $month): array
    {
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate)); // Last day of month

        $topProductRows = $this->dashboardRepository->findTopProductsBySoldCount($startDate, $endDate, 1);
        $topProduct = null;
        if ($topProductRows !== []) {
            $topProduct = [
                'name' => self::displayName($topProductRows[0]['names']) ?? 'Unknown',
                'sold_count' => (int) $topProductRows[0]['sold_count'],
            ];
        }

        $dailyRevenue = array_map(static fn(array $row): array => [
            'date' => $row['date'],
            'revenue_cents' => (int) $row['revenue_cents'],
            'transaction_count' => (int) $row['transaction_count'],
        ], $this->dashboardRepository->findDailyRevenue($startDate, $endDate));

        $topProducts = array_map(static fn(array $row): array => [
            'id' => $row['id'],
            'name' => self::displayName($row['names']) ?? 'Unknown',
            'sold_count' => (int) $row['sold_count'],
            'revenue_cents' => (int) $row['revenue_cents'],
        ], $this->dashboardRepository->findTopProductsByRevenue($startDate, $endDate, self::TOP_LIST_LIMIT));

        $topMembers = array_map(static fn(array $row): array => [
            'id' => $row['id'],
            'name' => $row['name'],
            'purchase_count' => (int) $row['purchase_count'],
            'revenue_cents' => (int) $row['revenue_cents'],
        ], $this->dashboardRepository->findTopMembers($startDate, $endDate, self::TOP_LIST_LIMIT));

        return [
            'month' => $month,
            'total_revenue_cents' => $this->dashboardRepository->sumRevenueBetween($startDate, $endDate),
            'total_sold_items' => $this->dashboardRepository->countPurchasesBetween($startDate, $endDate),
            'top_product' => $topProduct,
            'daily_revenue' => $dailyRevenue,
            'top_products' => $topProducts,
            'top_members' => $topMembers,
        ];
    }

    /**
     * A terminal is `disabled` when switched off, `online` when it synced inside
     * the window, and `offline` otherwise — including when it has never synced.
     *
     * @param array<string, mixed> $terminal
     */
    public static function terminalStatus(array $terminal, int $now): string
    {
        if (!(bool) $terminal['is_active']) {
            return 'disabled';
        }

        $lastSyncAt = $terminal['last_sync_at'] ?? null;
        if ($lastSyncAt === null) {
            return 'offline';
        }

        return ($now - strtotime($lastSyncAt)) <= self::TERMINAL_ONLINE_WINDOW_SECONDS ? 'online' : 'offline';
    }

    /**
     * The IBAN encryption key's remaining lifetime, as the dashboard shows it
     * (ADR-0036 warnings, #394).
     *
     * This is the "no cron on shared hosting" answer: nothing evaluates expiry
     * on a schedule, so the warning is computed on every dashboard load. An
     * admin who opens the panel at all cannot miss that the key needs rotating
     * — and a *missing* key is the loudest case, because until one is
     * activated no member's bank details can be stored at all.
     *
     * @param array|null $activeKey the ACTIVE encryption_keys row, or null
     * @return array{state: string, severity: string, key_identifier: ?string, days_until_expiry: ?int, message: string}
     */
    public static function encryptionKeyAlert(?array $activeKey, ?\DateTimeImmutable $now = null): array
    {
        if ($activeKey === null) {
            return [
                'state' => 'missing',
                'severity' => 'error',
                'key_identifier' => null,
                'days_until_expiry' => null,
                'message' => 'No active IBAN encryption key — bank details cannot be stored until one is activated',
            ];
        }

        $state = CredentialLifecycle::state($activeKey['expires_at'] ?? null, $now);
        $days = CredentialLifecycle::daysUntilExpiry($activeKey['expires_at'] ?? null, $now);
        $identifier = $activeKey['key_identifier'];

        return [
            'state' => $state,
            'severity' => match ($state) {
                CredentialLifecycle::STATE_OK => 'none',
                CredentialLifecycle::STATE_INFO, CredentialLifecycle::STATE_WARNING => 'warning',
                default => 'error',
            },
            'key_identifier' => $identifier,
            'days_until_expiry' => $days,
            'message' => match ($state) {
                CredentialLifecycle::STATE_OK => "IBAN encryption key {$identifier} is valid",
                CredentialLifecycle::STATE_EXPIRED => "IBAN encryption key {$identifier} has expired — rotate it before storing IBANs or exporting SEPA files",
                default => "IBAN encryption key {$identifier} expires in {$days} day(s) — plan a rotation",
            },
        ];
    }

    /**
     * Whether SEPA is actually ready to collect: the creditor identity the
     * bank needs, and the mandate template URL a new member is sent to sign
     * (#360) — SepaExportService refuses to export a settlement without
     * either. Unlike `sepaAlert()`, which counts individual members with
     * missing mandate data, this is a single club-wide setup state.
     *
     * @param array<string, mixed>|null $config the sepa_config row, or null
     *     if the singleton row is somehow missing
     * @return array{severity: string, message: string}
     */
    public static function sepaConfigAlert(?array $config): array
    {
        if (!$config || empty($config['creditor_id']) || empty($config['creditor_name']) || empty($config['creditor_iban'])) {
            return [
                'severity' => 'error',
                'message' => 'SEPA creditor details are not configured — settlements cannot be exported to the bank',
            ];
        }

        if (empty($config['mandate_template_url'])) {
            return [
                'severity' => 'error',
                'message' => 'No mandate template URL configured — settlements cannot be exported until members have a form to sign',
            ];
        }

        return ['severity' => 'none', 'message' => 'SEPA configuration is complete'];
    }

    /**
     * Terminals whose credential looks like it is on more than one device
     * (ADR-0041).
     *
     * The message names the terminal when there is exactly one, because that is
     * the case where an admin can act without opening anything: the alert says
     * which till to go and look at. Beyond one they are counted instead — a
     * banner is not a list.
     *
     * `concurrent_ip` is the only kind that reads as an error. The two cursor
     * kinds have routine innocent causes (a restore from backup, a
     * re-provisioning), so a warning is the honest volume for them.
     *
     * `terminal_count` and `terminal_name` ride alongside `message` so a
     * caller that wants its own wording — the admin frontend renders this in
     * the admin's chosen language, not English — has the pieces to build it
     * without re-deriving "one terminal vs. several" itself.
     *
     * @param list<array<string, mixed>> $openAnomalies rows from terminal_anomalies, unacknowledged
     * @return array{count: int, severity: string, kinds: list<string>, message: string, terminal_count: int, terminal_name: ?string}
     */
    /**
     * Underage sales nobody has looked at yet (#622, ADR-0045 §3).
     *
     * The `jugendschutz_violation` audit entry M7 writes is the **record**, and
     * it is `admin`-only under ADR-0044. This dashboard is `TREASURY`, so this
     * alert is the only surface on which the **Kassenwart** — the office the
     * epic names as the recipient — can learn a violation happened at all.
     *
     * Counting *unacknowledged* violations rather than all of them is what
     * resolves the tension the issue named: invariant 4 says a recorded
     * violation never clears itself, but a red badge that can never be
     * dismissed is one people stop seeing. The two are only in conflict if the
     * record and the alert are the same object. They are not — the audit entry
     * is immutable and untouched, and acknowledgement is an additive row beside
     * it. The incident stays on file forever; the badge goes quiet once a human
     * has dealt with it.
     *
     * **Severity is `error` from the first one.** Every other alert here grades
     * by volume because it is about money or infrastructure drifting; this one
     * is about a minor having been served alcohol, and § 28 JuSchG exposure
     * does not soften because it only happened once.
     *
     * Carries a count and a timestamp and nothing else — no member, no age.
     * This renders on a screen that may be open in the clubroom, and rule 6
     * does not stop at the till.
     *
     * @return array{count: int, severity: string, message: string, latest_occurred_at: string|null}
     */
    public static function jugendschutzViolationAlert(int $unacknowledged, ?string $latestOccurredAt): array
    {
        if ($unacknowledged < 1) {
            return [
                'count' => 0,
                'severity' => 'none',
                'message' => 'No unacknowledged Jugendschutz violations',
                'latest_occurred_at' => null,
            ];
        }

        return [
            'count' => $unacknowledged,
            'severity' => 'error',
            'message' => sprintf(
                '%d age-restricted %s sold below the required age, not yet acknowledged',
                $unacknowledged,
                $unacknowledged === 1 ? 'drink was' : 'drinks were',
            ),
            'latest_occurred_at' => $latestOccurredAt,
        ];
    }

    public static function terminalAnomalyAlert(array $openAnomalies): array
    {
        if ($openAnomalies === []) {
            return [
                'count' => 0,
                'severity' => 'none',
                'kinds' => [],
                'message' => 'No terminal credential anomalies',
                'terminal_count' => 0,
                'terminal_name' => null,
            ];
        }

        $kinds = [];
        foreach ($openAnomalies as $anomaly) {
            $kind = (string) ($anomaly['kind'] ?? '');
            if ($kind !== '' && !in_array($kind, $kinds, true)) {
                $kinds[] = $kind;
            }
        }

        $severity = in_array(TerminalAnomalyKind::CONCURRENT_IP->value, $kinds, true) ? 'error' : 'warning';

        // Distinct terminals, not distinct rows: one till can carry a
        // concurrent-use row and a cursor row about the same incident, and
        // "2 terminals" would then be wrong in the direction that matters.
        $terminalIds = [];
        foreach ($openAnomalies as $anomaly) {
            $terminalIds[(string) ($anomaly['terminal_id'] ?? '')] = true;
        }
        $terminalCount = count($terminalIds);

        if ($terminalCount === 1) {
            $name = (string) ($openAnomalies[0]['terminal_name'] ?? '');
            $message = $name !== ''
                ? sprintf('Terminal "%s" may be in use on more than one device — review it', $name)
                : 'A terminal may be in use on more than one device — review it';
        } else {
            $message = sprintf('%d terminals may be in use on more than one device — review them', $terminalCount);
        }

        return [
            'count' => count($openAnomalies),
            'severity' => $severity,
            'kinds' => $kinds,
            'message' => $message,
            'terminal_count' => $terminalCount,
            'terminal_name' => $terminalCount === 1 ? ($name !== '' ? $name : null) : null,
        ];
    }

    /**
     * @return array{count: int, severity: string, message: string}
     */
    public static function sepaAlert(int $count): array
    {
        if ($count === 0) {
            return ['count' => 0, 'severity' => 'none', 'message' => 'No SEPA data issues'];
        }

        return [
            'count' => $count,
            'severity' => $count <= self::SEPA_WARNING_THRESHOLD ? 'warning' : 'error',
            'message' => "{$count} members missing SEPA data",
        ];
    }

    /**
     * Pick a product's display name out of its translation blob: German first,
     * then English, then nothing.
     */
    public static function displayName(?string $namesJson): ?string
    {
        if ($namesJson === null || $namesJson === '') {
            return null;
        }

        $names = json_decode($namesJson, true);
        if (!is_array($names)) {
            return null;
        }

        return $names['de'] ?? $names['en'] ?? null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentRecentTransaction(array $row): array
    {
        return [
            'id' => $row['id'],
            'member_id' => $row['member_id'],
            'member_name' => $row['member_name'],
            'terminal_name' => $row['terminal_name'],
            'type' => $row['type'],
            'amount_cents' => (int) $row['amount_cents'],
            'product_name' => self::displayName($row['product_names']),
            'timestamp' => $row['timestamp'] ? str_replace(' ', 'T', $row['timestamp']) : null,
        ];
    }

    /**
     * A member's tab as the limit sees it: what they owe, how much of the
     * ceiling that is, and whether the terminal is warning them or has already
     * stopped serving them.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function presentMemberNearLimit(array $row, CreditLimitPolicy $policy): array
    {
        $balanceCents = (int) $row['balance_cents'];
        // The ceiling the query measured this row against — the member's own
        // where they have one, the club default where they do not. Resolved
        // through the policy rather than read raw, so one rule answers this
        // question everywhere (ADR-0046 rule 1).
        $limit = $policy->forMember(isset($row['limit_cents']) ? (int) $row['limit_cents'] : null);

        return [
            'id' => $row['id'],
            'name' => trim((string) $row['name']),
            'balance_cents' => $balanceCents,
            'limit_cents' => $limit->limitCents,
            'percent_of_limit' => $limit->percentOfLimit($balanceCents),
            'status' => $limit->status($balanceCents)->value,
        ];
    }

    /**
     * @param array<string, mixed> $terminal
     * @return array<string, mixed>
     */
    private function presentTerminal(array $terminal, int $now): array
    {
        return [
            'id' => $terminal['id'],
            'name' => $terminal['name'],
            'device_id' => $terminal['device_id'],
            'is_active' => (bool) $terminal['is_active'],
            'last_sync_at' => $terminal['last_sync_at'] ?? null,
            'status' => self::terminalStatus($terminal, $now),
        ];
    }
}
