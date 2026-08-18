<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Reports\Domain;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\Reports\Domain\ReportGroupByPolicy;
use App\Modules\Reports\Services\ReportsService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The `group_by` allow-list (ADR-0044, #515).
 *
 * The one boundary in this epic that lands on a parameter, and — per the ADR —
 * the one place the model can regress *silently*, because it regresses by
 * omission: a reviewer has to notice that a new dimension was **not** added,
 * which is the harder thing to notice. The completeness test at the bottom is
 * what turns that into a build failure instead.
 */
class ReportGroupByPolicyTest extends TestCase
{
    /** The reason the class exists. */
    public function test_the_getraenkewart_cannot_group_by_member(): void
    {
        $this->assertFalse(ReportGroupByPolicy::permits([AdminRole::GETRAENKEWART], 'member'));
    }

    public function test_the_getraenkewart_groups_by_the_drinks_list_and_by_time(): void
    {
        foreach (['category', 'product', 'day', 'week', 'month', 'year'] as $dimension) {
            $this->assertTrue(
                ReportGroupByPolicy::permits([AdminRole::GETRAENKEWART], $dimension),
                "the drinks list needs {$dimension}",
            );
        }
    }

    public function test_admin_and_kassenwart_keep_every_dimension(): void
    {
        foreach ([AdminRole::ADMIN, AdminRole::KASSENWART] as $role) {
            foreach (ReportGroupByPolicy::classifiedDimensions() as $dimension) {
                $this->assertTrue(
                    ReportGroupByPolicy::permits([$role], $dimension),
                    "{$role->value} lost {$dimension}",
                );
            }
        }
    }

    /** Grants compose, here as everywhere: the union, not the intersection. */
    public function test_holding_both_lesser_roles_takes_the_wider_of_the_two(): void
    {
        $both = [AdminRole::KASSENWART, AdminRole::GETRAENKEWART];

        $this->assertTrue(ReportGroupByPolicy::permits($both, 'member'));
        $this->assertSame(ReportGroupByPolicy::classifiedDimensions(), ReportGroupByPolicy::allowedFor($both));
    }

    public function test_a_caller_holding_no_role_may_group_by_nothing(): void
    {
        $this->assertFalse(ReportGroupByPolicy::permits([], 'month'));
        $this->assertSame([], ReportGroupByPolicy::allowedFor([]));
    }

    public function test_an_unrecognised_dimension_is_refused_for_every_role(): void
    {
        foreach (AdminRole::cases() as $role) {
            $this->assertFalse(
                ReportGroupByPolicy::permits([$role], 'iban'),
                "{$role->value} must not group by a dimension that does not exist",
            );
        }
    }

    /**
     * The allow-list property itself, stated as a test rather than as a
     * comment: a dimension the policy has never heard of is refused for the
     * Getränkewart. Under a deny-list naming `member` this would pass for any
     * new dimension the day it merged.
     */
    public function test_a_dimension_the_policy_does_not_know_is_refused_rather_than_allowed(): void
    {
        $this->assertFalse(ReportGroupByPolicy::permits([AdminRole::GETRAENKEWART], 'member_iban'));
        $this->assertFalse(ReportGroupByPolicy::permits([AdminRole::GETRAENKEWART], 'household'));
    }

    /**
     * Completeness, against what the service actually accepts.
     *
     * Adding a `group_by` value to `ReportsService` without classifying it here
     * fails this test — so the new dimension arrives with a decision attached
     * rather than as an omission nobody noticed. Same property the route→roles
     * map's completeness test buys, applied to the parameter.
     */
    public function test_every_dimension_the_service_accepts_is_classified(): void
    {
        $accepted = (new ReflectionClass(ReportsService::class))->getConstant('VALID_GROUP_BY');

        $unclassified = array_values(array_diff($accepted, ReportGroupByPolicy::classifiedDimensions()));

        $this->assertSame([], $unclassified, sprintf(
            "ReportsService accepts group_by values the role policy does not classify:\n  %s\n\n"
            . "Add each to ReportGroupByPolicy and decide, deliberately, whether the Getränkewart "
            . "may group by it — remembering that a dimension naming individual members turns the "
            . "report into a personal-spend leaderboard.",
            implode("\n  ", $unclassified),
        ));
    }

    /** And the other direction, so the policy cannot drift into fiction. */
    public function test_the_policy_classifies_nothing_the_service_rejects(): void
    {
        $accepted = (new ReflectionClass(ReportsService::class))->getConstant('VALID_GROUP_BY');

        $this->assertSame([], array_values(array_diff(ReportGroupByPolicy::classifiedDimensions(), $accepted)));
    }
}
