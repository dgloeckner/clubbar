<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use PDO;

/**
 * Mandate validity (#164) and the erasure window (#165), as the schema
 * enforces them.
 */
class MandateSchemaTest extends SchemaTestCase
{
    public function test_a_member_has_at_most_one_active_mandate(): void
    {
        $memberId = $this->createMember();
        $this->insertMandate($memberId);

        $this->assertDatabaseRefuses(
            fn() => $this->insertMandate($memberId),
            'two active mandates leave no answer to "which IBAN do we collect from"'
        );
    }

    public function test_a_bank_change_ends_the_old_mandate_and_adds_a_new_one(): void
    {
        $memberId = $this->createMember();
        $oldId = $this->insertMandate($memberId);

        // Append-only: the superseded mandate is ended in place, never rewritten,
        // so a return quoting its MREF+ still resolves after the member moves bank.
        $stmt = $this->db->prepare(
            "UPDATE mandates
                SET active_member_id = NULL, ended_at = NOW(), ended_reason = 'bank_change'
              WHERE id = ?"
        );
        $stmt->execute([$oldId]);

        $newId = $this->insertMandate($memberId, ['iban_last4' => '2051']);

        $stmt = $this->db->prepare(
            'SELECT id, iban_last4, is_active FROM mandates WHERE member_id = ? ORDER BY is_active ASC'
        );
        $stmt->execute([$memberId]);
        $mandates = $stmt->fetchAll();

        $this->assertCount(2, $mandates, 'the old mandate must survive the bank change');
        $this->assertSame($oldId, $mandates[0]['id']);
        // The IBAN itself is sealed (ADR-0036); the last four characters are
        // what any read can see, and they are enough to tell the superseded
        // account from the new one.
        $this->assertSame('3000', $mandates[0]['iban_last4']);
        $this->assertSame($newId, $mandates[1]['id']);
        $this->assertSame(1, (int) $mandates[1]['is_active']);
    }

    public function test_a_mandate_reference_is_unique_across_members(): void
    {
        $reference = 'UMR' . substr($this->generateUuid(), 0, 20);
        $this->insertMandate($this->createMember('One'), ['reference' => $reference]);

        $this->assertDatabaseRefuses(
            fn() => $this->insertMandate($this->createMember('Two'), ['reference' => $reference]),
            'MREF+ on a bank return must resolve to exactly one mandate'
        );
    }

    public function test_banking_data_no_longer_lives_on_the_member(): void
    {
        $columns = $this->db->query('SHOW COLUMNS FROM members')->fetchAll(PDO::FETCH_COLUMN);

        foreach (['iban', 'mandate_reference', 'mandate_signed_at'] as $moved) {
            $this->assertNotContains(
                $moved,
                $columns,
                "{$moved} belongs to the mandate record, which is append-only, not to the mutable member row"
            );
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertMandate(string $memberId, array $overrides = []): string
    {
        $row = array_merge([
            'id' => $this->generateUuid(),
            'member_id' => $memberId,
            'active_member_id' => $memberId,
            'reference' => str_replace('-', '', $this->generateUuid()),
            'iban_ciphertext' => $this->sealIban('DE89370400440532013000'),
            'iban_last4' => '3000',
            'encryption_key_id' => self::DEV_KEY_ID,
            'signed_at' => '2026-01-15',
        ], $overrides);

        $this->track('mandates', $row['id']);

        $columns = implode(', ', array_keys($row));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));
        $stmt = $this->db->prepare("INSERT INTO mandates ({$columns}) VALUES ({$placeholders})");
        $stmt->execute(array_values($row));

        return $row['id'];
    }
}
