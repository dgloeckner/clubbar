<?php

declare(strict_types=1);

namespace App\Modules\Reports\Domain;

/**
 * The filter set every report query narrows by.
 *
 * It exists so the repository can be handed *what* to filter on rather than a
 * half-built WHERE clause: the SQL that expresses these conditions belongs to
 * the repository, and the decision that a report is filtered this way belongs
 * to the service.
 */
final readonly class ReportFilters
{
    /**
     * @param list<string> $categoryIds
     * @param list<string> $productIds
     */
    public function __construct(
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public array $categoryIds = [],
        public array $productIds = [],
        public ?string $terminalId = null,
    ) {}

    /**
     * Build from the comma-separated id lists the API accepts.
     */
    public static function fromRequest(
        ?string $dateFrom,
        ?string $dateTo,
        ?string $categoryIds = null,
        ?string $productIds = null,
        ?string $terminalId = null,
    ): self {
        return new self(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            categoryIds: self::splitIds($categoryIds),
            productIds: self::splitIds($productIds),
            terminalId: $terminalId,
        );
    }

    /**
     * @return list<string>
     */
    private static function splitIds(?string $ids): array
    {
        if ($ids === null || $ids === '') {
            return [];
        }

        return array_values(array_filter(explode(',', $ids)));
    }
}
