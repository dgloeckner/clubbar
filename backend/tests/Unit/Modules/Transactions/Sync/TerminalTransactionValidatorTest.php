<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Transactions\Sync;

use App\Modules\Transactions\Sync\TerminalTransactionValidator;
use PHPUnit\Framework\TestCase;

/**
 * What makes one uploaded row unstorable (#259, ruling #143 §1–2).
 *
 * These checks used to live in `SyncController` as a batch-wide gate: one bad
 * row and the whole upload was refused with a 422, nothing stored. Because the
 * terminal treats any non-2xx as transient and retries the batch unchanged,
 * that wedged every good sale queued behind the bad one — permanently, since
 * the bad row could never become storable.
 *
 * The judgement itself is unchanged; what changes is that it now produces a
 * *per-row* verdict the batch can route around.
 */
class TerminalTransactionValidatorTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => '22222222-2222-4222-8222-222222222222',
            'member_id' => '33333333-3333-4333-8333-333333333333',
            'product_id' => '44444444-4444-4444-8444-444444444444',
            'amount_cents' => 350,
            'created_at' => '2026-08-08T18:00:00Z',
        ], $overrides);
    }

    public function test_a_complete_sale_has_no_rejection(): void
    {
        $this->assertNull(TerminalTransactionValidator::rejectionFor($this->row()));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredFields(): array
    {
        return [
            'id' => ['id'],
            'member_id' => ['member_id'],
            'product_id' => ['product_id'],
            'amount_cents' => ['amount_cents'],
            'created_at' => ['created_at'],
        ];
    }

    /**
     * @dataProvider requiredFields
     */
    public function test_a_missing_field_makes_the_row_unstorable(string $field): void
    {
        $row = $this->row();
        unset($row[$field]);

        $rejection = TerminalTransactionValidator::rejectionFor($row);

        $this->assertNotNull($rejection);
        $this->assertSame('unstorable', $rejection['error']);
        $this->assertStringContainsString($field, $rejection['message']);
    }

    /**
     * @dataProvider requiredFields
     */
    public function test_an_empty_field_is_as_unstorable_as_a_missing_one(string $field): void
    {
        $rejection = TerminalTransactionValidator::rejectionFor($this->row([$field => '']));

        $this->assertNotNull($rejection);
        $this->assertSame('unstorable', $rejection['error']);
    }

    /**
     * The id is the idempotency key of ADR-0004. A row without one cannot be
     * deduplicated, which is what made `INSERT IGNORE` report a discarded row
     * as accepted (#82) — so it is reported with a null id rather than not at
     * all, and the terminal quarantines it on that.
     */
    public function test_a_row_with_no_id_is_still_reported_by_id(): void
    {
        $row = $this->row();
        unset($row['id']);

        $rejection = TerminalTransactionValidator::rejectionFor($row);

        $this->assertArrayHasKey('transaction_id', $rejection);
        $this->assertNull($rejection['transaction_id']);
    }

    public function test_a_rejection_names_the_row_it_is_about(): void
    {
        $rejection = TerminalTransactionValidator::rejectionFor($this->row(['amount_cents' => 0]));

        $this->assertSame('22222222-2222-4222-8222-222222222222', $rejection['transaction_id']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableAmounts(): array
    {
        return [
            'zero' => [0],
            'negative' => [-100],
            'negative string' => ['-100'],
            'fractional' => [3.5],
            'fractional string' => ['3.5'],
            'not a number' => ['many'],
            'boolean' => [true],
            'array' => [[350]],
        ];
    }

    /**
     * @dataProvider unusableAmounts
     */
    public function test_an_amount_that_is_not_a_positive_integer_is_unstorable(mixed $amount): void
    {
        $rejection = TerminalTransactionValidator::rejectionFor($this->row(['amount_cents' => $amount]));

        $this->assertNotNull($rejection);
        $this->assertSame('unstorable', $rejection['error']);
        $this->assertStringContainsString('amount_cents', $rejection['message']);
    }

    /**
     * A terminal sends a JSON number, but a client that stringifies it is not
     * lying about the sale — and the sale already happened, so a type
     * technicality must not be what loses it.
     */
    public function test_a_whole_number_sent_as_a_string_is_accepted(): void
    {
        $this->assertNull(TerminalTransactionValidator::rejectionFor($this->row(['amount_cents' => '350'])));
    }

    /**
     * `TerminalTransactionAllowlist::build()` turns a non-array entry into a
     * row carrying only the server-owned fields, so it arrives here looking
     * like a row with everything missing. It must be answered, not fatal.
     */
    public function test_a_row_stripped_to_nothing_is_rejected_rather_than_fatal(): void
    {
        $rejection = TerminalTransactionValidator::rejectionFor([
            'transaction_type' => 'purchase',
            'related_transaction_id' => null,
            'created_by_admin_id' => null,
            'created_by_terminal_id' => 'terminal-1',
        ]);

        $this->assertNotNull($rejection);
        $this->assertNull($rejection['transaction_id']);
    }
}
