<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\AdminUsers\Enums;

use App\Modules\AdminUsers\Enums\AdminRole;
use PHPUnit\Framework\TestCase;

/**
 * The three roles of ADR-0044, as a hardcoded enum (Pattern 002).
 *
 * They are deliberately not a seeded table: the ADR rejects permissions-as-data
 * so that the set is greppable and diffable in review, and so that nobody can
 * assemble a fourth role at runtime that no human ever looked at.
 *
 * The stored values are what the `admin_user_roles.role` column enumerates, so
 * a rename here without a migration silently stops matching the database — this
 * pins them.
 */
class AdminRoleTest extends TestCase
{
    public function test_exactly_three_roles_exist_with_their_stored_values(): void
    {
        $this->assertSame(
            ['admin', 'kassenwart', 'getraenkewart'],
            AdminRole::values(),
        );
    }

    public function test_a_role_is_recovered_from_its_stored_value(): void
    {
        $this->assertSame(AdminRole::ADMIN, AdminRole::from('admin'));
        $this->assertSame(AdminRole::KASSENWART, AdminRole::from('kassenwart'));
        $this->assertSame(AdminRole::GETRAENKEWART, AdminRole::from('getraenkewart'));
    }

    /**
     * Fail closed on input, in the one place a string crosses into a role.
     *
     * `fromValues()` is what turns a request body or a database row into roles,
     * and ADR-0044's rule 1 says an unrecognised value is never treated as
     * "some role" — it is not a role at all.
     */
    public function test_unknown_values_are_dropped_rather_than_becoming_a_role(): void
    {
        $this->assertSame([], AdminRole::fromValues(['superuser', '', 'ADMIN']));
    }

    public function test_a_value_list_becomes_a_deduplicated_role_list(): void
    {
        $roles = AdminRole::fromValues(['kassenwart', 'getraenkewart', 'kassenwart']);

        $this->assertSame([AdminRole::KASSENWART, AdminRole::GETRAENKEWART], $roles);
    }

    public function test_roles_are_rendered_back_to_their_stored_values(): void
    {
        $this->assertSame(
            ['kassenwart', 'getraenkewart'],
            AdminRole::toValues([AdminRole::KASSENWART, AdminRole::GETRAENKEWART]),
        );
    }
}
