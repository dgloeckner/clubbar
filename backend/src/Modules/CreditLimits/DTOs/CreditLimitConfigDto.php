<?php

declare(strict_types=1);

namespace App\Modules\CreditLimits\DTOs;

use App\Modules\CreditLimits\Domain\CreditLimitPolicy;

final readonly class CreditLimitConfigDto
{
    public function __construct(
        public int $defaultLimitCents,
        public int $warnThresholdPercent,
    ) {}

    /**
     * @param array<string, mixed> $row
     *
     * An absent row falls back to the shipped defaults rather than to zero:
     * zero means "no ceiling", and an installation whose config row has not
     * been read yet must not silently uncap every member.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            defaultLimitCents: isset($row['default_limit_cents'])
                ? (int) $row['default_limit_cents']
                : CreditLimitPolicy::DEFAULT_LIMIT_CENTS,
            warnThresholdPercent: isset($row['warn_threshold_percent'])
                ? (int) $row['warn_threshold_percent']
                : CreditLimitPolicy::DEFAULT_WARN_THRESHOLD_PERCENT,
        );
    }

    public function toPolicy(): CreditLimitPolicy
    {
        return new CreditLimitPolicy($this->defaultLimitCents, $this->warnThresholdPercent);
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'default_limit_cents' => $this->defaultLimitCents,
            'warn_threshold_percent' => $this->warnThresholdPercent,
        ];
    }
}
