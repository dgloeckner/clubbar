<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

/**
 * What one enqueue actually did.
 *
 * `queued` is the number the admin panel is allowed to report after a finalize:
 * *"47 announcements queued, sending"* — never *"47 sent"*, because at that
 * moment no network call has happened and nothing else is true (ADR-0038 rule
 * 4).
 *
 * `withoutEmail` is the interesting field. Those members are being collected
 * from and cannot be told, which is the pre-finalize warning bucket #405 shows
 * and #362 is closing at the source. It never blocks: a member the club cannot
 * announce to still owes the money.
 *
 * `alreadyQueued` matters only to the repeating callers (#438): a scheduled scan
 * that finds the same credential in the same tier on every tick needs to be able
 * to say *"already done"* rather than *"nothing to do"* — the two look identical
 * in a count of zero and mean opposite things when a warning has not arrived.
 */
final readonly class EnqueueResultDto
{
    /**
     * @param list<string> $withoutEmail    Member ids collected from but unreachable
     * @param list<string> $withoutBalance  Member ids that settle at zero or in credit
     */
    public function __construct(
        public int $queued,
        public array $withoutEmail = [],
        public array $withoutBalance = [],
        /** Recipients the unique index refused because this exact message already exists. */
        public int $alreadyQueued = 0,
    ) {}

    public static function empty(): self
    {
        return new self(0);
    }

    public function toArray(): array
    {
        return [
            'queued' => $this->queued,
            'without_email' => $this->withoutEmail,
            'without_balance' => $this->withoutBalance,
            'already_queued' => $this->alreadyQueued,
        ];
    }
}
