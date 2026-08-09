<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Validation;

use App\Shared\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `integer` and `date` rules (issue #117).
 *
 * Both used to wave through values that the code behind them then reinterpreted:
 * `integer` accepted `"1.5"` and every caller cast it with `(int)`, so
 * `amount_cents: "12.9"` booked 12 cents; `date` accepted anything
 * `strtotime()` could parse, including `"next tuesday"`.
 */
class ValidatorRulesTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        // Neither rule touches the database; `unique` is the only one that does.
        $this->validator = new Validator($this->createMock(\PDO::class));
    }

    /** @return array<string, array{0: mixed}> */
    public static function wholeNumbers(): array
    {
        return [
            'int' => [42],
            'negative int' => [-42],
            'zero' => [0],
            'numeric string' => ['42'],
            'signed numeric string' => ['-42'],
            'integral float' => [42.0],
        ];
    }

    #[DataProvider('wholeNumbers')]
    public function test_integer_accepts_whole_numbers(mixed $value): void
    {
        $this->assertTrue(
            $this->validator->validate(['amount_cents' => $value], ['amount_cents' => ['integer']]),
            var_export($value, true) . ' is a whole number and must be accepted'
        );
    }

    /** @return array<string, array{0: mixed}> */
    public static function nonWholeNumbers(): array
    {
        return [
            'decimal string' => ['1.5'],
            'the issue #117 example' => ['12.9'],
            'float' => [1.5],
            'scientific notation with a fraction' => ['1.5e0'],
            'letters' => ['abc'],
            'empty string' => [''],
            'true' => [true],
            'array' => [[1]],
        ];
    }

    #[DataProvider('nonWholeNumbers')]
    public function test_integer_rejects_anything_a_cast_would_change(mixed $value): void
    {
        $this->assertFalse(
            $this->validator->validate(['amount_cents' => $value], ['amount_cents' => ['integer']]),
            var_export($value, true) . ' would not survive an (int) cast and must be rejected'
        );
    }

    public function test_integer_skips_null_so_required_owns_the_missing_case(): void
    {
        $this->assertTrue($this->validator->validate([], ['amount_cents' => ['integer']]));
    }

    public function test_integer_error_message_names_the_field(): void
    {
        $this->validator->validate(['amount_cents' => '12.9'], ['amount_cents' => ['integer']]);

        $this->assertSame(
            ['amount_cents' => ['amount_cents must be an integer']],
            $this->validator->errors()
        );
    }

    /** @return array<string, array{0: string}> */
    public static function calendarDates(): array
    {
        return [
            'plain date' => ['2026-08-10'],
            'leap day' => ['2024-02-29'],
            'sql datetime' => ['2026-08-10 14:30:00'],
            'iso datetime' => ['2026-08-10T14:30:00'],
            'iso with offset' => ['2026-08-10T14:30:00+02:00'],
            'iso with milliseconds and offset' => ['2026-08-10T14:30:00.123+02:00'],
            'iso in zulu' => ['2026-08-10T14:30:00Z'],
            'iso in zulu with milliseconds' => ['2026-08-10T14:30:00.123Z'],
        ];
    }

    #[DataProvider('calendarDates')]
    public function test_date_accepts_the_formats_clients_actually_send(string $value): void
    {
        $this->assertTrue(
            $this->validator->validate(['settlement_date' => $value], ['settlement_date' => ['date']]),
            "{$value} must be accepted"
        );
    }

    /** @return array<string, array{0: mixed}> */
    public static function nonDates(): array
    {
        return [
            'relative expression' => ['next tuesday'],
            'now' => ['now'],
            'offset expression' => ['+1 day'],
            'yesterday' => ['yesterday'],
            'day that does not exist' => ['2026-02-30'],
            'month that does not exist' => ['2026-13-01'],
            'german format' => ['10.08.2026'],
            'us format' => ['08/10/2026'],
            'unpadded' => ['2026-8-1'],
            'prose' => ['not-a-date'],
            'a number' => [20260810],
        ];
    }

    #[DataProvider('nonDates')]
    public function test_date_rejects_anything_that_is_not_a_calendar_date(mixed $value): void
    {
        $this->assertFalse(
            $this->validator->validate(['settlement_date' => $value], ['settlement_date' => ['date']]),
            var_export($value, true) . ' is not a calendar date and must be rejected'
        );
    }

    public function test_date_skips_null_and_empty_so_required_owns_the_missing_case(): void
    {
        $this->assertTrue($this->validator->validate([], ['settlement_date' => ['date']]));
        $this->assertTrue($this->validator->validate(['settlement_date' => ''], ['settlement_date' => ['date']]));
    }

    public function test_date_error_message_names_the_field(): void
    {
        $this->validator->validate(['settlement_date' => 'next tuesday'], ['settlement_date' => ['date']]);

        $this->assertSame(
            ['settlement_date' => ['settlement_date must be a valid date']],
            $this->validator->errors()
        );
    }
}
