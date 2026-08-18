<?php

declare(strict_types=1);

namespace App\Modules\Reports\Domain;

use App\Modules\AdminUsers\Enums\AdminRole;

/**
 * Which report dimensions each role may group by (ADR-0044, #515).
 *
 * The one place in this epic where a privilege boundary lands on a **query
 * parameter** rather than a route. `/reports/{revenue|consumption|transactions}`
 * is granted to all three offices — it is how per-product sales figures are
 * read — but `group_by=member` turns every row's `dimension` into
 * `CONCAT(first_name, ' ', last_name)`, so the response becomes a ranked list
 * of named members with their personal spend. Gate the route and the
 * Getränkewart loses the sales figures; leave the parameter alone and they get
 * a member spend leaderboard by typing seven characters.
 *
 * ## Why an allow-list
 *
 * A deny-list naming `member` behaves identically today and diverges the first
 * time somebody adds a dimension: under a deny-list the new dimension is
 * visible to the Getränkewart from the moment it merges, and nobody is
 * reminded. Under an allow-list it is invisible until a human adds it, in a
 * diff a reviewer can see.
 *
 * **The rule for this epic: wherever a role boundary lands on a parameter
 * rather than a route, it is an allow-list.**
 *
 * ## Why every role is enumerated, `admin` included
 *
 * So that adding a dimension is a decision rather than an omission.
 * `ReportGroupByPolicyTest` asserts that every value `ReportsService` accepts
 * appears here for `admin`, which means a new dimension fails the build until
 * somebody has written down who may group by it. That is the same property the
 * route→roles map's completeness test buys, applied to the parameter.
 *
 * A role absent from this table may group by nothing at all — the same
 * fail-closed default the route map uses.
 */
final class ReportGroupByPolicy
{
    /**
     * Everything the reports can group by. Named here rather than derived from
     * `ReportsService::VALID_GROUP_BY`, because deriving it would silently
     * widen `admin` — and then the completeness test could never fail.
     */
    private const EVERY_DIMENSION = ['category', 'product', 'member', 'day', 'week', 'month', 'year'];

    /**
     * The Getränkewart's dimensions: the drinks list, and time. Not `member`,
     * which is the whole reason this class exists.
     */
    private const BAR_DIMENSIONS = ['category', 'product', 'day', 'week', 'month', 'year'];

    /** @var array<string, list<string>> */
    private const ALLOWED = [
        AdminRole::ADMIN->value => self::EVERY_DIMENSION,
        AdminRole::KASSENWART->value => self::EVERY_DIMENSION,
        AdminRole::GETRAENKEWART->value => self::BAR_DIMENSIONS,
    ];

    /**
     * Whether a caller holding `$roles` may group by `$groupBy`.
     *
     * The union of what the held roles allow, matching how grants compose
     * everywhere else: somebody covering both lesser offices has the
     * Kassenwart's reach here, not the intersection of the two.
     *
     * @param list<AdminRole> $roles
     */
    public static function permits(array $roles, string $groupBy): bool
    {
        foreach ($roles as $role) {
            if (in_array($groupBy, self::ALLOWED[$role->value] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What this caller may group by, for the completeness test and for any
     * future caller that wants to describe the restriction rather than enforce
     * it.
     *
     * @param list<AdminRole> $roles
     * @return list<string>
     */
    public static function allowedFor(array $roles): array
    {
        $allowed = [];
        foreach ($roles as $role) {
            foreach (self::ALLOWED[$role->value] ?? [] as $dimension) {
                $allowed[$dimension] = true;
            }
        }

        // Declaration order, so the list reads the same way twice.
        return array_values(array_filter(
            self::EVERY_DIMENSION,
            static fn (string $dimension): bool => isset($allowed[$dimension]),
        ));
    }

    /** @return list<string> Every dimension this policy classifies. */
    public static function classifiedDimensions(): array
    {
        return self::EVERY_DIMENSION;
    }
}
