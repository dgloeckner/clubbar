<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

/**
 * Collection hold (#148 §1) and the retention expiry (#165, #173), as the
 * `members` table records them.
 */
class MemberSchemaTest extends SchemaTestCase
{
    public function test_a_member_is_not_on_collection_hold_by_default(): void
    {
        $row = $this->fetchMember($this->createMember());

        $this->assertSame(0, (int) $row['collection_hold']);
        $this->assertNull($row['collection_hold_reason']);
        $this->assertNull($row['held_at']);
        $this->assertNull($row['held_by_admin_id']);
    }

    public function test_a_bank_return_puts_a_member_on_hold_and_the_hold_is_later_cleared(): void
    {
        $adminId = $this->createAdminUser();
        $memberId = $this->createMember();

        // A hold stops the next sweep re-debiting exactly the amount the member
        // just disputed — the return-fee loop #148 exists to break.
        $stmt = $this->db->prepare(
            'UPDATE members
                SET collection_hold = 1, collection_hold_reason = ?, held_at = NOW(), held_by_admin_id = ?
              WHERE id = ?'
        );
        $stmt->execute(['bank_return', $adminId, $memberId]);

        $held = $this->fetchMember($memberId);
        $this->assertSame(1, (int) $held['collection_hold']);
        $this->assertSame('bank_return', $held['collection_hold_reason']);
        $this->assertSame($adminId, $held['held_by_admin_id']);
        $this->assertNotNull($held['held_at']);

        $stmt = $this->db->prepare(
            'UPDATE members
                SET collection_hold = 0, cleared_at = NOW(), cleared_by_admin_id = ?
              WHERE id = ?'
        );
        $stmt->execute([$adminId, $memberId]);

        $cleared = $this->fetchMember($memberId);
        $this->assertSame(0, (int) $cleared['collection_hold']);
        $this->assertSame($adminId, $cleared['cleared_by_admin_id']);
        $this->assertNotNull(
            $cleared['held_at'],
            'clearing a hold must not erase the record that one was applied'
        );
    }

    public function test_a_member_carries_the_date_their_retained_records_may_be_deleted(): void
    {
        $memberId = $this->createMember();

        // 31.12. of the year of the last transaction, plus ten years (§ 147 AO).
        // Without a stored date, "delete when the period expires" never happens
        // and the retained residual becomes permanent by accident.
        $stmt = $this->db->prepare('UPDATE members SET retention_expires_at = ? WHERE id = ?');
        $stmt->execute(['2036-12-31', $memberId]);

        $this->assertSame('2036-12-31', $this->fetchMember($memberId)['retention_expires_at']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchMember(string $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM members WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch();
    }
}
