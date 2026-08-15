<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Enums\CronInterval;
use DateTimeImmutable;

/**
 * The two questions the alarm asks (ADR-0039 decision 5, amending ADR-0038 rule 6).
 *
 * ADR-0038 defined a stall as *"the oldest unsent message is older than 24 h"*.
 * That predicate is incompatible with an exponential retry ladder: a single
 * stubborn mailbox stays `pending` for hours **by design**, and an age-based
 * check fires on it. ADR-0038 itself forbids exactly that outcome — *"a switch
 * that cries wolf for three weeks is a switch someone turns off"* — so the
 * definition changes rather than the tolerance.
 *
 * | Signal | Predicate | What it means |
 * |---|---|---|
 * | Liveness | `now − last_run_at > interval × 2` | The scheduler stopped |
 * | Throughput | a `pending` row with `next_attempt_at ≤ now − interval × 3` | Something was **due** and nothing took it for three ticks |
 *
 * The second is the substance. A row waiting out its backoff has
 * `next_attempt_at` in the *future*, so it is not overdue and cannot trip the
 * alarm however long its ladder runs. **Being due and ignored** replaces
 * **being old** as the definition of a stall.
 *
 * Both scale with the declared interval, so an hourly installation alarms in
 * about three hours and a daily one in about three days — right in each case
 * rather than a compromise between them.
 *
 * Every predicate takes `now` as a parameter. There is no clock abstraction in
 * this codebase (`Shared\Time\Utc` pins the timezone and nothing more), so it
 * is threaded rather than injected — which is also what lets these tests state
 * a whole timeline without sleeping.
 */
final class QueueHealth
{
    /** Ticks of silence before the scheduler counts as stopped. */
    public const LIVENESS_TICKS = 2;

    /**
     * Ticks a due message may go untouched before the queue counts as stalled.
     *
     * Three rather than two: one missed tick is a host under load, and the
     * alarm has to survive that without anyone learning to ignore it. Three
     * missed ticks is a scheduler that runs and achieves nothing, which is the
     * exact failure a pure liveness check cannot see.
     */
    public const THROUGHPUT_TICKS = 3;

    /**
     * Has the scheduler stopped?
     *
     * A `null` last run is **not** a stall. An installation where no run has
     * ever been observed is not a scheduler that died; it is one that was never
     * set up, which the install gate refuses at finalize (#405) and which the
     * external monitor reports by never having been pinged at all. Reporting it
     * here as well would put a second, differently-worded alarm on a state that
     * already has an owner.
     */
    public static function schedulerStopped(
        ?DateTimeImmutable $lastRunAt,
        DateTimeImmutable $now,
        CronInterval $interval,
    ): bool {
        if ($lastRunAt === null) {
            return false;
        }

        return $now->getTimestamp() - $lastRunAt->getTimestamp() > $interval->ticks(self::LIVENESS_TICKS);
    }

    /**
     * Is something due and being ignored?
     *
     * $oldestDueAt is the smallest `next_attempt_at` among `pending` rows —
     * null when the queue holds nothing pending, which is the healthy answer
     * and the usual one for eleven months of the year.
     */
    public static function queueStalled(
        ?DateTimeImmutable $oldestDueAt,
        DateTimeImmutable $now,
        CronInterval $interval,
    ): bool {
        if ($oldestDueAt === null) {
            return false;
        }

        return $now->getTimestamp() - $oldestDueAt->getTimestamp() >= $interval->ticks(self::THROUGHPUT_TICKS);
    }

    /**
     * The observed interval, or null while there is nothing to compare.
     *
     * A single run says nothing about a schedule; two consecutive ones say
     * everything the self-check needs. See {@see CronInterval::fromObservedGap()}
     * for why the classification is generous.
     */
    public static function observedInterval(
        ?DateTimeImmutable $previousRunAt,
        ?DateTimeImmutable $lastRunAt,
    ): ?CronInterval {
        if ($previousRunAt === null || $lastRunAt === null) {
            return null;
        }

        return CronInterval::fromObservedGap($lastRunAt->getTimestamp() - $previousRunAt->getTimestamp());
    }

    /**
     * Does what the scheduler does contradict what it was declared to do?
     *
     * Only a gap *longer* than declared is a disagreement worth reporting. A
     * cron that fires more often than declared makes every threshold here
     * conservative — the alarm is late rather than wrong — while one that fires
     * less often makes the retry ladder and both stall thresholds describe a
     * machine that does not exist.
     */
    public static function intervalDisagrees(CronInterval $declared, ?CronInterval $observed): bool
    {
        return $observed !== null && $observed->seconds() > $declared->seconds();
    }
}
