<?php

declare(strict_types=1);

namespace App\Modules\Settlements\DTOs;

/**
 * The earliest valid SEPA execution date, per ADR-0009.
 *
 * This is the single source of truth for the rule: the admin frontend renders
 * `minimumDate` rather than recomputing it, so the TARGET2 calendar lives in
 * one place instead of being mirrored in TypeScript.
 */
final readonly class ExecutionDateInfoDto
{
    public function __construct(
        public string $minimumDate,
        public int $leadTimeDays,
        public string $rule,
    ) {}

    public function toArray(): array
    {
        return [
            'minimum_date' => $this->minimumDate,
            'lead_time_days' => $this->leadTimeDays,
            'rule' => $this->rule,
        ];
    }
}
