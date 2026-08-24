<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\CreditLimits\Domain\CreditLimitStatus;
use App\Modules\CreditLimits\Repositories\NearLimitRepository;
use App\Modules\CreditLimits\Services\CreditLimitConfigService;
use App\Modules\Notifications\DTOs\CreditLimitDigestLineDto;
use App\Modules\Notifications\DTOs\CreditLimitDigestReportDto;

/**
 * Who is near their Deckel ceiling right now, as the digest reports it.
 *
 * The one place that question is turned into a message's worth of content, and
 * it is asked twice per cycle by two callers with different jobs:
 *
 *   * {@see CreditLimitDigestNotifier} asks before queueing anything, because a
 *     digest naming nobody is worse than no digest — it trains the recipient to
 *     stop opening them.
 *   * {@see CreditLimitDigestMailBuilder} asks again when the drain renders each
 *     row, because ADR-0038 rule 5 says content is regenerated at send time.
 *
 * The two answers can differ, and that is the point rather than a race: a
 * member who settled up between the tick and the drain should not be named.
 *
 * **It reads {@see NearLimitRepository}, the same query the dashboard reads.**
 * That is the invariant this whole feature rests on — a treasurer who opens the
 * panel after reading the mail must see the same names. A second, "simpler"
 * query here would be the bug: the boundary cent is decided by an integer
 * division (`CreditLimit::warnAtCents()`), and two spellings of that rounding
 * disagree exactly at the edge, which is where every member on this list sits.
 */
class CreditLimitDigestService
{
    /**
     * The most members one digest names.
     *
     * A hundred is far past any list a treasurer will act on in one sitting,
     * and it is not a display choice so much as a bound on the mail: the body
     * is assembled in memory, once per recipient, inside a drain run that has a
     * wall-clock budget measured in seconds (`MailConfigDto::DEFAULT_DRAIN_BUDGET_SECONDS`).
     * A club whose entire membership is over the warning band would otherwise
     * render a few thousand rows per recipient and spend the run doing it.
     *
     * Whatever the cap drops is **counted and stated in the message**
     * ({@see CreditLimitDigestReportDto::$omitted}), never silently trimmed.
     */
    public const MAX_LINES = 100;

    public function __construct(
        private NearLimitRepository $nearLimitRepository,
        private CreditLimitConfigService $creditLimitConfigService,
    ) {}

    public function collect(): CreditLimitDigestReportDto
    {
        $policy = $this->creditLimitConfigService->policy();
        $clubDefault = $policy->clubDefault();

        // Deliberately no short-circuit when the club's own default caps
        // nobody: a member carrying an override still has a ceiling and is
        // still being refused at the bar. The query decides per row (ADR-0047).
        $rows = $this->nearLimitRepository->findNearLimit(
            $policy->defaultLimitCents,
            $policy->warnThresholdPercent,
            self::MAX_LINES,
        );

        $lines = [];
        $totalOwedCents = 0;
        $exceededCount = 0;

        foreach ($rows as $row) {
            $balanceCents = (int) $row['balance_cents'];
            // The ceiling the query measured this row against — the member's
            // own where they have one, the club default where they do not.
            // Resolved through the policy rather than read raw, so one rule
            // answers this question everywhere (ADR-0047 rule 1).
            $limit = $policy->forMember(isset($row['limit_cents']) ? (int) $row['limit_cents'] : null);
            $status = $limit->status($balanceCents);

            $lines[] = new CreditLimitDigestLineDto(
                memberId: (string) $row['id'],
                name: trim((string) $row['name']),
                balanceCents: $balanceCents,
                limitCents: $limit->limitCents,
                percentOfLimit: $limit->percentOfLimit($balanceCents),
                status: $status,
            );

            $totalOwedCents += $balanceCents;

            if ($status === CreditLimitStatus::EXCEEDED) {
                $exceededCount++;
            }
        }

        return new CreditLimitDigestReportDto(
            lines: $lines,
            clubDefaultLimitCents: $clubDefault->limitCents,
            warnThresholdPercent: $clubDefault->warnThresholdPercent,
            totalOwedCents: $totalOwedCents,
            exceededCount: $exceededCount,
            // The count is only worth a second query when the first one came
            // back full — a short list is its own total, and asking anyway
            // would put a COUNT over every unsettled transaction in the club
            // on the hot path of a scan that almost always finds nothing.
            omitted: count($rows) < self::MAX_LINES
                ? 0
                : max(0, $this->nearLimitRepository->countNearLimit(
                    $policy->defaultLimitCents,
                    $policy->warnThresholdPercent,
                ) - count($rows)),
        );
    }
}
