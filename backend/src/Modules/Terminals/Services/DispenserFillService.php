<?php

declare(strict_types=1);

namespace App\Modules\Terminals\Services;

use App\Modules\Terminals\DTOs\DispenserFillDto;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Services\AuditService;

/**
 * The hopper's fill estimate, and the refill that resets it (#955, ADR-0058).
 *
 * The dispenser cannot say it is running out — its *empty* switch is a factory
 * option this unit does not have — so the warning is arithmetic:
 *
 *     estimated_left = tokens counted in at the last refill
 *                    − token purchases this terminal booked since that moment
 *
 * **Computed on read, never stored.** Both halves are already in the database:
 * the anchor is two columns on `terminals`, the sales are `transactions` rows.
 * A stored counter would be a third copy that an offline terminal's late sync
 * could put out of step, and there would be no way to tell which copy was
 * right.
 *
 * **The device's own counters are not used, and that is the decision.** They
 * are cumulative, RAM-only and zeroed by a reboot (firmware F7 restarts an
 * unreachable controller on purpose), so a `lifetime.dispensed_tokens` that
 * went *down* since the last report means the device restarted — not that
 * tokens came back. Nothing here can be misled that way, because nothing here
 * reads them: a reboot, an unreachable machine and a dropped report all leave
 * the estimate exactly as it was, still anchored to the last refill.
 *
 * **It is an estimate and must be read as one.** What it cannot see: tokens
 * that coasted out after the motor stop (firmware F5's `overrun_tokens`, real
 * tokens that were never billed), a dispense recovered with
 * `count_reliable = false`, and anybody topping the hopper up without recording
 * it. Every refill is a count, so none of that drift outlives one hopper load —
 * which is exactly why a refill records an exact number rather than "added N".
 */
class DispenserFillService
{
    public function __construct(
        private TerminalsRepository $terminals,
        private AuditService $audit,
    ) {}

    /**
     * The reading for one terminal row, counting its sales.
     *
     * @param array<string, mixed> $row a `terminals` row
     */
    public function readFor(array $row): DispenserFillDto
    {
        $id = (string) $row['id'];
        $counts = $this->terminals->countTokensSoldSinceRefill($id);

        return DispenserFillDto::fromRow($row, $counts[$id] ?? 0);
    }

    /**
     * Record that the hopper now holds exactly this many tokens.
     *
     * The number **replaces** the estimate; it is not added to it. An admin who
     * has just counted a hopper knows something the arithmetic does not, and
     * the point of the refill is to put the estimate back on a known value
     * (owner decision 8). Zero is a legitimate count — a hopper emptied for
     * maintenance — and is why the validation floor is zero rather than one.
     *
     * The audit row is where the drift becomes visible: it carries the estimate
     * as it stood a moment before, beside the count that replaced it. Nothing
     * else in the system ever writes that pair down, and the difference between
     * them is everything the subtraction could not see.
     *
     * It is **not** an acknowledgement. No fault is cleared by it, on this
     * surface or any other: the device has no reset route, and a jam is cleared
     * by a power cycle (owner decision 3).
     *
     * @throws NotFoundException when no such terminal exists
     */
    public function recordRefill(
        string $terminalId,
        int $tokens,
        ?string $adminUserId = null,
        ?\DateTimeImmutable $now = null,
    ): DispenserFillDto {
        $row = $this->terminals->findById($terminalId);
        if (!$row) {
            throw NotFoundException::forResource('Terminal', $terminalId);
        }

        $before = $this->readFor($row);

        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $refilledAt = $now->format('Y-m-d H:i:s');

        $this->terminals->recordDispenserRefill($terminalId, $tokens, $refilledAt);

        $this->audit->log(
            action: AuditAction::TERMINAL_DISPENSER_REFILLED,
            entityType: EntityType::TERMINAL,
            entityId: $terminalId,
            oldValues: [
                'refilled_at' => $before->refilledAt,
                'refill_tokens' => $before->refillTokens,
                'sold_since' => $before->soldSince,
                // Null where there was no earlier refill to estimate from. The
                // first refill of a terminal has no drift to report, and
                // writing a 0 there would invent one.
                'estimated_left' => $before->estimatedLeft(),
            ],
            newValues: [
                'refilled_at' => $refilledAt,
                'refill_tokens' => $tokens,
            ],
            adminUserId: $adminUserId,
        );

        // Counted again rather than assumed to be zero. The anchor has second
        // precision, so a sale timestamped inside the same second as the refill
        // is already on the new load — and a response claiming zero there would
        // disagree with the very next read of the same terminal.
        return new DispenserFillDto(
            refilledAt: $refilledAt,
            refillTokens: $tokens,
            soldSince: $this->terminals->countTokensSoldSinceRefill($terminalId)[$terminalId] ?? 0,
            lowThreshold: $before->lowThreshold,
        );
    }
}
