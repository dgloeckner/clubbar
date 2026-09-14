<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

/**
 * Migration `069` as an upgrade sees it (#936).
 *
 * The question this answers is not "does a fresh install work" — the rest of
 * the suite covers that — but "what happens to a club that has been collecting
 * for a year". The answer has to be: it gains a counter, and every reference it
 * has already sent to a bank is untouched. A re-minted reference would destroy
 * the key that matches a return arriving months later (#165).
 */
class MandateReferenceCounterSchemaTest extends SchemaTestCase
{
    public function test_the_counter_exists_with_exactly_one_row(): void
    {
        $rows = $this->db->query('SELECT id, value FROM mandate_reference_counter')->fetchAll();

        $this->assertCount(1, $rows, 'the counter is a singleton; a second row would mint duplicates');
        $this->assertSame(1, (int) $rows[0]['id']);
        $this->assertGreaterThanOrEqual(0, (int) $rows[0]['value']);
    }

    public function test_the_config_row_carries_a_prefix_column_defaulting_to_nothing(): void
    {
        $column = $this->db->query("SHOW COLUMNS FROM sepa_config LIKE 'mandate_reference_prefix'")->fetch();

        $this->assertNotFalse($column, 'migration 069 did not add the prefix column');
        $this->assertSame('YES', $column['Null'], 'NULL is what selects the default prefix');
        $this->assertNull($column['Default'], 'an install that never chose a prefix must read as NULL, not as an empty one');
    }

    /**
     * A 32-hex reference from before `069` is still a valid stored reference,
     * and the column did not narrow underneath it.
     */
    public function test_a_reference_minted_before_the_counter_still_fits_and_is_left_alone(): void
    {
        $memberId = $this->createMember();
        $legacy = str_replace('-', '', $this->generateUuid());

        $mandateId = $this->insertMandate($memberId, $legacy);

        $stmt = $this->db->prepare('SELECT reference FROM mandates WHERE id = ?');
        $stmt->execute([$mandateId]);
        $this->assertSame($legacy, $stmt->fetchColumn());

        // Nothing in 069 rewrites a stored reference, so the two shapes coexist
        // under the same UNIQUE key.
        $short = $this->insertMandate($this->createMember(), 'CB-' . random_int(100000, 999999));
        $stmt->execute([$short]);
        $this->assertMatchesRegularExpression('/^CB-[0-9]{6}$/', (string) $stmt->fetchColumn());
    }

    /** A minimal sealed mandate carrying the reference under test. */
    private function insertMandate(string $memberId, string $reference): string
    {
        // A sealed mandate names the key generation it was sealed under, and
        // the FK insists that key exists. CI applies migrations without the
        // seed, so nothing else puts it there (ADR-0036).
        $this->ensureActiveEncryptionKey();

        $id = $this->generateUuid();
        $this->track('mandates', $id);

        $stmt = $this->db->prepare(
            'INSERT INTO mandates (id, member_id, active_member_id, reference, iban_ciphertext, iban_last4, encryption_key_id, signed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $memberId,
            $memberId,
            $reference,
            $this->sealIban('DE89370400440532013000'),
            '3000',
            self::DEV_KEY_ID,
            '2026-01-15',
        ]);

        return $id;
    }
}
