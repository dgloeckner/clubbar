<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * The one list-response envelope.
 *
 * Four shapes were in production at once (issue #119) — `{items,total,limit,
 * offset,has_more}`, and three mutually incompatible spellings of
 * `{data,pagination}` — so every list screen in the frontend had to know which
 * endpoint it was talking to before it could read the answer.
 *
 * The shape below is the one `api/admin.yaml` has always documented; the
 * divergent endpoints were the ones out of contract, not this one.
 */
final class PaginatedResponse
{
    /**
     * @param iterable<mixed> $items Rows, or DTOs exposing toArray().
     * @return array{data: list<mixed>, pagination: array{page: int, per_page: int, total: int, total_pages: int}}
     */
    public static function of(iterable $items, int $total, int $page, int $perPage): array
    {
        return [
            'data' => self::normalise($items),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
            ],
        ];
    }

    /**
     * @param iterable<mixed> $items
     * @return array{data: list<mixed>, pagination: array{page: int, per_page: int, total: int, total_pages: int}}
     */
    public static function fromQuery(iterable $items, int $total, ListQuery $query): array
    {
        return self::of($items, $total, $query->page, $query->perPage);
    }

    /**
     * @param iterable<mixed> $items
     * @return list<mixed>
     */
    private static function normalise(iterable $items): array
    {
        $normalised = [];

        // array_values, not the original keys: a filtered list has holes, and
        // json_encode turns a holey array into an object the frontend's
        // Array.isArray() check rejects.
        foreach ($items as $item) {
            $normalised[] = is_object($item) && method_exists($item, 'toArray')
                ? $item->toArray()
                : $item;
        }

        return $normalised;
    }
}
