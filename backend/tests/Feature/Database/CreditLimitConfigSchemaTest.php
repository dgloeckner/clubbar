<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

/**
 * What migration 052 has to leave behind (ADR-0046, #557).
 *
 * The slice ships without changing anybody's limit, and that claim is only
 * worth anything if the seeded row *is* the constants it replaces. These tests
 * are what says so — plus the one property the per-member column exists for:
 * it is nullable with no default, because `NULL` means "follow the club" and a
 * defaulted column would freeze each member at the default of the day they
 * were created.
 */
class CreditLimitConfigSchemaTest extends SchemaTestCase
{
    public function test_the_singleton_row_ships_todays_constants(): void
    {
        $row = $this->fetchConfig();

        $this->assertSame(1, (int) $row['id']);
        $this->assertSame(10000, (int) $row['default_limit_cents']);
        $this->assertSame(80, (int) $row['warn_threshold_percent']);
    }

    public function test_the_member_override_is_nullable_with_no_default(): void
    {
        $column = $this->memberColumn();

        $this->assertSame('YES', $column['Null'], 'NULL is what "follow the club default" is written as');
        $this->assertNull($column['Default'], 'a default would freeze a member at the day they were created');
    }

    public function test_a_new_member_inherits_rather_than_carrying_a_ceiling(): void
    {
        $memberId = $this->createMember('CreditLimitInherits');

        $stmt = $this->db->prepare('SELECT credit_limit_cents FROM members WHERE id = ?');
        $stmt->execute([$memberId]);

        $this->assertNull($stmt->fetch()['credit_limit_cents']);
    }

    /**
     * Zero is a value the schema must accept, because it is how "no ceiling for
     * this member" is expressed — a distinct answer from NULL, not a synonym
     * for it.
     */
    public function test_zero_and_null_can_both_be_stored(): void
    {
        $memberId = $this->createMember('CreditLimitZero');

        $this->db->prepare('UPDATE members SET credit_limit_cents = 0 WHERE id = ?')->execute([$memberId]);
        $this->assertSame(0, (int) $this->memberOverride($memberId));

        $this->db->prepare('UPDATE members SET credit_limit_cents = NULL WHERE id = ?')->execute([$memberId]);
        $this->assertNull($this->memberOverride($memberId));
    }

    public function test_the_singleton_cannot_gain_a_second_row(): void
    {
        $this->assertDatabaseRefuses(
            fn() => $this->db->exec('INSERT INTO credit_limit_config (id, default_limit_cents) VALUES (1, 500)'),
            'credit_limit_config must hold exactly one row',
        );
    }

    /** @return array<string, mixed> */
    private function fetchConfig(): array
    {
        return $this->db->query('SELECT * FROM credit_limit_config WHERE id = 1')->fetch();
    }

    /** @return array<string, mixed> */
    private function memberColumn(): array
    {
        return $this->db->query("SHOW COLUMNS FROM members LIKE 'credit_limit_cents'")->fetch();
    }

    private function memberOverride(string $memberId): int|string|null
    {
        $stmt = $this->db->prepare('SELECT credit_limit_cents FROM members WHERE id = ?');
        $stmt->execute([$memberId]);

        return $stmt->fetch()['credit_limit_cents'];
    }
}
