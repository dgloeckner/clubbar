<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Domain;

use App\Modules\Members\Domain\MandateCompleteness;
use PHPUnit\Framework\TestCase;

/**
 * The one definition of a usable mandate (ADR-0020, #164).
 *
 * It is worth pinning here rather than only through its callers, because the
 * failure this class exists to prevent is *drift*: five call sites each
 * spelling the predicate out, and the SQL saying something a sixth thing again.
 * ADR-0020 records what that cost the last time — a member with `iban = ''`
 * counted as valid on the dashboard and invalid everywhere else.
 */
class MandateCompletenessTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'has_iban' => 1,
            'mandate_reference' => 'MANDATE-1',
            'mandate_signed_at' => '2025-01-15',
        ];
    }

    public function test_all_three_parts_present_is_the_only_complete_mandate(): void
    {
        $this->assertTrue(MandateCompleteness::isComplete(self::row()));
    }

    /**
     * @dataProvider incompleteRows
     * @param array<string, mixed> $overrides
     */
    public function test_a_missing_part_makes_the_mandate_incomplete(array $overrides): void
    {
        $this->assertFalse(MandateCompleteness::isComplete(self::row($overrides)));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function incompleteRows(): array
    {
        return [
            'no iban' => [['has_iban' => 0]],
            'no reference' => [['mandate_reference' => null]],
            // The part that was missing from the predicate until #164 reached
            // it, and the reason the SEPA export needed a fallback at all.
            'no signature date' => [['mandate_signed_at' => null]],
            // Not `isset()`: the empty-string case is the exact divergence
            // ADR-0020 records for the IBAN, and a zero-length reference or
            // date is no more of an answer than a NULL one.
            'blank iban' => [['has_iban' => '']],
            'blank reference' => [['mandate_reference' => '']],
            'blank signature date' => [['mandate_signed_at' => '']],
            // A row with no mandate joined at all: every md.* column is NULL.
            'no mandate row' => [[
                'has_iban' => null,
                'mandate_reference' => null,
                'mandate_signed_at' => null,
            ]],
        ];
    }

    /**
     * A treasurer acting on an exclusion needs to know *which* part is missing:
     * chasing an IBAN that is already on file is wasted effort, and the remedy
     * for a missing date is one field.
     */
    public function test_missingParts_names_what_to_go_and_fix(): void
    {
        $this->assertSame([], MandateCompleteness::missingParts(self::row()));
        $this->assertSame(
            ['mandate_signed_at'],
            MandateCompleteness::missingParts(self::row(['mandate_signed_at' => null])),
        );
        $this->assertSame(
            ['iban', 'mandate_reference', 'mandate_signed_at'],
            MandateCompleteness::missingParts([]),
        );
    }

    /**
     * The SQL rendering has to answer the same question as the PHP one, or a
     * roster filtered for "SEPA invalid" stops holding the members the export
     * excludes — which is the whole point of being able to filter for them.
     */
    public function test_the_sql_renderings_name_the_same_three_columns_and_negate_each_other(): void
    {
        foreach (['md.id', 'md.signed_at'] as $column) {
            $this->assertStringContainsString($column, MandateCompleteness::SQL);
            $this->assertStringContainsString($column, MandateCompleteness::SQL_INCOMPLETE);
        }

        // Same columns, opposite operators — the pair a WHERE clause picks
        // between, so one must not quietly become a subset of the other.
        $this->assertStringContainsString('IS NOT NULL AND', MandateCompleteness::SQL);
        $this->assertStringContainsString('IS NULL OR', MandateCompleteness::SQL_INCOMPLETE);
    }
}
