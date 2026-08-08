<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Exceptions\InvalidQueryParameterException;

/**
 * The one list-query parser.
 *
 * Six controllers each parsed pagination and sorting themselves, and each did
 * it differently: some capped `per_page`, some clamped it, most did neither,
 * and only one rejected a non-numeric value (issue #119). The missing caps
 * meant a single request could ask the database for every row it had.
 *
 * Two dialects reached the backend and both are still accepted, because
 * clients in the field use both:
 *
 *   page / per_page   sort / order          — the admin frontend
 *   offset / limit    sort_key / sort_order — direct API callers and tests
 *
 * They are normalised here once, so every endpoint answers to both and every
 * endpoint applies the same cap.
 */
final readonly class ListQuery
{
    public const DEFAULT_PER_PAGE = 50;
    public const MAX_PER_PAGE = 100;

    public function __construct(
        public int $page,
        public int $perPage,
        public int $offset,
        public string $sortKey,
        public string $sortOrder,
        public ?string $search,
    ) {}

    /**
     * @param array<string, mixed> $params Raw query parameters.
     *
     * @throws InvalidQueryParameterException When a parameter is malformed or
     *         asks for more rows than the endpoint is willing to serve.
     */
    public static function fromParams(
        array $params,
        int $defaultPerPage = self::DEFAULT_PER_PAGE,
        string $defaultSortKey = 'created_at',
        string $defaultSortOrder = 'desc',
        int $maxPerPage = self::MAX_PER_PAGE,
    ): self {
        [$perPage, $perPageParam] = self::readPerPage($params, $defaultPerPage);
        self::assertWithinCap($perPage, $maxPerPage, $perPageParam);

        $page = self::readPositiveInt($params, 'page', 1);

        if (self::isPresent($params, 'offset')) {
            $offset = self::readInt($params, 'offset');
            if ($offset < 0) {
                throw self::reject('offset', 'offset must not be negative');
            }
            // Report the page the caller will actually receive, so a
            // pagination footer built from the response cannot contradict it.
            $page = $perPage > 0 ? intdiv($offset, $perPage) + 1 : 1;
        } else {
            $offset = ($page - 1) * $perPage;
        }

        $search = $params['search'] ?? null;
        $search = is_string($search) && trim($search) !== '' ? trim($search) : null;

        [$sortKey, $sortOrder] = self::readSort($params, $defaultSortKey, $defaultSortOrder);

        return new self(
            page: $page,
            perPage: $perPage,
            offset: $offset,
            sortKey: $sortKey,
            sortOrder: $sortOrder,
            search: $search,
        );
    }

    /**
     * Sorting arrives in three spellings and all three are honoured:
     * `sort`+`order`, `sort_key`+`sort_order`, and the combined
     * `sort_by=name_asc` the admin frontend sends. Before they were unified
     * here, `sort_by` was understood by exactly one endpoint and silently
     * ignored by the rest, so half the list screens did not sort at all.
     *
     * @param array<string, mixed> $params
     * @return array{0: string, 1: string}
     */
    private static function readSort(array $params, string $defaultKey, string $defaultOrder): array
    {
        if (!self::isPresent($params, 'sort') && !self::isPresent($params, 'sort_key')
            && self::isPresent($params, 'sort_by') && is_string($params['sort_by'])) {
            $combined = $params['sort_by'];

            foreach (['asc', 'desc'] as $direction) {
                if (str_ends_with($combined, '_' . $direction)) {
                    return [substr($combined, 0, -strlen($direction) - 1), $direction];
                }
            }

            return [$combined, strtolower($defaultOrder)];
        }

        return [
            self::readString($params, ['sort', 'sort_key'], $defaultKey),
            self::readSortOrder($params, $defaultOrder),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: int, 1: string} The value and the parameter it came from.
     */
    private static function readPerPage(array $params, int $default): array
    {
        foreach (['per_page', 'limit'] as $name) {
            if (!self::isPresent($params, $name)) {
                continue;
            }

            $value = self::readInt($params, $name);
            if ($value < 1) {
                throw self::reject($name, "{$name} must be a positive integer");
            }

            return [$value, $name];
        }

        return [$default, 'per_page'];
    }

    private static function assertWithinCap(int $perPage, int $maxPerPage, string $param): void
    {
        if ($perPage > $maxPerPage) {
            throw self::reject($param, "{$param} must not exceed {$maxPerPage}");
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function readPositiveInt(array $params, string $name, int $default): int
    {
        if (!self::isPresent($params, $name)) {
            return $default;
        }

        $value = self::readInt($params, $name);
        if ($value < 1) {
            throw self::reject($name, "{$name} must be a positive integer");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function readInt(array $params, string $name): int
    {
        $raw = $params[$name];

        if (!is_numeric($raw) || (float) (int) $raw !== (float) $raw) {
            throw self::reject($name, "{$name} must be a positive integer");
        }

        return (int) $raw;
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string> $names
     */
    private static function readString(array $params, array $names, string $default): string
    {
        foreach ($names as $name) {
            if (self::isPresent($params, $name) && is_string($params[$name])) {
                return $params[$name];
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function readSortOrder(array $params, string $default): string
    {
        $order = strtolower(self::readString($params, ['order', 'sort_order'], $default));

        return in_array($order, ['asc', 'desc'], true) ? $order : strtolower($default);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function isPresent(array $params, string $name): bool
    {
        return isset($params[$name]) && $params[$name] !== '';
    }

    /**
     * @return InvalidQueryParameterException
     */
    private static function reject(string $param, string $message): InvalidQueryParameterException
    {
        return new InvalidQueryParameterException($message, [$param => [$message]]);
    }
}
