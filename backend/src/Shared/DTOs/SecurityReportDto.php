<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

use App\Shared\Security\SecurityFinding;

/**
 * The security self-check as an admin reads it (#247, ADR-0031 decision 3).
 *
 * `status` is the worst row in the report, so a caller that only wants a
 * verdict — a badge in the navigation, a monitoring probe — does not have to
 * re-derive one and get it subtly wrong. An `unknown` row counts as a warning:
 * a check that could not be run is never evidence that its subject is fine.
 */
final readonly class SecurityReportDto
{
    /**
     * @param list<SecurityFinding> $findings
     */
    public function __construct(
        public string $generatedAt,
        public array $findings,
        public array $summary,
    ) {}

    public function status(): string
    {
        if (($this->summary[SecurityFinding::FAIL] ?? 0) > 0) {
            return SecurityFinding::FAIL;
        }

        if (($this->summary[SecurityFinding::WARN] ?? 0) > 0 || ($this->summary[SecurityFinding::UNKNOWN] ?? 0) > 0) {
            return SecurityFinding::WARN;
        }

        return SecurityFinding::PASS;
    }

    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt,
            'status'       => $this->status(),
            'summary'      => $this->summary,
            'findings'     => array_map(fn(SecurityFinding $finding) => $finding->toArray(), $this->findings),
        ];
    }
}
