<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

/**
 * One page of rows and how many there are in total — what a list service
 * returns.
 *
 * It used to serialise itself as `{items,total,limit,offset,has_more}`, which
 * is how two endpoints came to answer in a shape the API spec never described
 * (issue #119). Rendering now belongs to App\Shared\Http\PaginatedResponse;
 * this stays a plain carrier between the repository and the controller.
 */
final readonly class PaginatedResultDto
{
    public function __construct(
        public array $items,
        public int $total,
        public int $limit,
        public int $offset,
    ) {}
}
