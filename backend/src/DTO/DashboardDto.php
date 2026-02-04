<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class DashboardDto
{
    public function __construct(
        public array $metrics,
        public array $recentTransactions,
        public array $terminalStatus,
        public array $systemStatus,
        public array $alerts,
    ) {}

    public function toArray(): array
    {
        return [
            'metrics' => $this->metrics,
            'recent_transactions' => $this->recentTransactions,
            'terminal_status' => $this->terminalStatus,
            'system_status' => $this->systemStatus,
            'alerts' => $this->alerts,
        ];
    }
}
