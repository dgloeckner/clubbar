<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

use App\Modules\Notifications\Enums\MailLanguage;
use App\Shared\Mail\MailBranding;

/**
 * Everything a Deckelauszug says, gathered but not yet worded (ADR-0039).
 *
 * The renderer takes this and nothing else — no repository, no PDO, no clock —
 * which is what makes the content rules a unit test rather than an integration
 * one, and what lets the preview script render every variant without a
 * database. Same seam as {@see PreNotificationDataDto}, for the same reasons.
 *
 * Two fields carry the load-bearing invariant of ADR-0039 decision 4, and they
 * are separate on purpose:
 *
 * | | |
 * |---|---|
 * | `lines` | What is **printed** — netted, chronological, capped at {@see MAX_LINES} |
 * | `totalCents` | The Deckel, computed over the **full** netted set |
 *
 * `omittedLines` is how many netted lines the cap removed. The total is never
 * the sum of `lines`, and the mail says so whenever `omittedLines > 0` — the
 * cap is only safe because of that. Assembling both here, from one netting
 * pass, is what makes them structurally unable to disagree; nothing downstream
 * re-derives either.
 */
final readonly class DeckelStatementDataDto
{
    /**
     * Printed lines per statement (ADR-0039 decision 4).
     *
     * A bound on the message rather than on the data: a member who drank three
     * hundred times still gets a correct total, and the honest thing to do with
     * the tail is to say how long it is rather than to send a mail nobody
     * scrolls through.
     */
    public const MAX_LINES = 100;

    /**
     * @param string $boundaryDate  The „Stand" — `Y-m-d`, the period's own first
     *                              day. What the statement describes, never the
     *                              day it was sent.
     * @param list<StatementLineDto> $lines Already netted, already capped.
     * @param int $omittedLines     Netted lines the cap dropped; 0 when all fit.
     * @param string $creditStatus  One of {@see \App\Modules\CreditLimits\Domain\CreditLimitStatus}'s
     *                              values, computed over `totalCents` against the
     *                              ceiling that applies to this member.
     */
    public function __construct(
        public MailLanguage $language,
        public string $recipientAddress,
        public ?string $recipientName,
        public ?string $salutationName,
        public MailBranding $branding,
        public string $boundaryDate,
        public int $totalCents,
        public array $lines = [],
        public int $omittedLines = 0,
        public int $creditLimitCents = 0,
        public string $creditStatus = '',
        public ?string $treasurerEmail = null,
    ) {}
}
