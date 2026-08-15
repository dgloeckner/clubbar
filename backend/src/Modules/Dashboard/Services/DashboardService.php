<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Dashboard\Domain\CreditLimit;
use App\Modules\Dashboard\DTOs\DashboardDto;
use App\Modules\Dashboard\Repositories\DashboardRepository;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
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
    ) {}

    public function getDashboard(): DashboardDto
    {
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
        $threshold = CreditLimit::warnAtCents();

        $rows = CreditLimit::isEnforced()
            ? $this->dashboardRepository->findMembersNearCreditLimit($threshold, self::MEMBERS_NEAR_LIMIT_SHOWN)
            : [];

        // A short list is its own total; only a full one can be hiding someone.
        $total = count($rows) < self::MEMBERS_NEAR_LIMIT_SHOWN
            ? count($rows)
            : $this->dashboardRepository->countMembersNearCreditLimit($threshold);

        return [
            'limit_cents' => CreditLimit::LIMIT_CENTS,
            'warn_at_cents' => $threshold,
            'total' => $total,
            'members' => array_map(
                static fn(array $row): array => self::presentMemberNearLimit($row),
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
    private static function presentMemberNearLimit(array $row): array
    {
        $balanceCents = (int) $row['balance_cents'];

        return [
            'id' => $row['id'],
            'name' => trim((string) $row['name']),
            'balance_cents' => $balanceCents,
            'percent_of_limit' => CreditLimit::percentOfLimit($balanceCents),
            'status' => CreditLimit::status($balanceCents),
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
