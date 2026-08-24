<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

/**
 * The digest's content, with nothing about who is reading it.
 *
 * Split from {@see CreditLimitDigestDataDto} — which adds the recipient, their
 * language and the branding — because the two are computed at different times
 * and for different reasons. This half is what
 * {@see \App\Modules\Notifications\Services\CreditLimitDigestService::collect()}
 * produces, and it is asked for **twice**: once by the scan, to find out
 * whether there is anything worth queueing at all, and once per message by the
 * builder, to render what is true at send time. Keeping it recipient-free is
 * what makes the first of those calls sensible.
 */
final readonly class CreditLimitDigestReportDto
{
    /**
     * @param list<CreditLimitDigestLineDto> $lines Fullest Deckel first, capped at
     *                                              {@see \App\Modules\Notifications\Services\CreditLimitDigestService::MAX_LINES}.
     * @param int $omitted How many members were left out by that cap. Reported
     *                     rather than swallowed: a list that silently stops at
     *                     a hundred names reads as "that is everybody", and the
     *                     one club where it is not is the club that most needs
     *                     to know.
     */
    public function __construct(
        public array $lines,
        public int $clubDefaultLimitCents,
        public int $warnThresholdPercent,
        public int $totalOwedCents,
        public int $exceededCount,
        public int $omitted = 0,
    ) {}

    /** Nothing to report — the ordinary state of a club whose members settle up. */
    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /** How many members this digest names. */
    public function count(): int
    {
        return count($this->lines);
    }

    /** For the log line the scan writes; never for the message body. */
    public function toArray(): array
    {
        return [
            'members' => $this->count(),
            'omitted' => $this->omitted,
            'exceeded' => $this->exceededCount,
            'total_owed_cents' => $this->totalOwedCents,
        ];
    }
}
