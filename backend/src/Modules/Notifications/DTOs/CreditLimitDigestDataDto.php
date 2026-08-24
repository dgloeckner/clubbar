<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

use App\Modules\Notifications\Enums\DigestCadence;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Shared\Mail\MailBranding;

/**
 * What the near-limit digest says.
 *
 * Assembled at send time from live data, never from the queue row — the row
 * holds addressing and a window key and nothing else (ADR-0038 rule 5). That
 * is what lets a digest queued on Monday and drained on Tuesday describe
 * Tuesday, and lets a member who settled up in between vanish from it.
 */
final readonly class CreditLimitDigestDataDto
{
    public function __construct(
        public MailLanguage $language,
        public string $recipientAddress,
        public ?string $recipientName,
        public MailBranding $branding,
        public DigestCadence $cadence,
        /**
         * The content, rebuilt from live data by
         * {@see \App\Modules\Notifications\Services\CreditLimitDigestService::collect()}
         * when the drain renders this row — never carried in the queue.
         *
         * Its {@see CreditLimitDigestReportDto::isEmpty()} state is legitimate
         * here, unlike in most builders. The scan will not queue a digest for
         * an empty list, so an empty report is reached only when the last
         * member cleared their tab between the enqueue and the drain. The
         * message then says so in one sentence rather than throwing: the
         * recipient is expecting a digest, and "good news, nobody is near their
         * ceiling" is a truthful answer where a failed row in the Notifications
         * page would be a puzzle.
         */
        public CreditLimitDigestReportDto $report,
    ) {}
}
