<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Validation;

use App\Shared\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shared `past_date` rule (epic #582, ADR-0045).
 *
 * A date of birth in the future is not a typo the database can catch — the
 * column takes it happily, and the member is then younger than zero and
 * younger than every `min_age` forever. The rule is shared rather than a
 * one-off check in the members service because `mandate_signed_at` carries
 * only `['nullable', 'date']` today and has exactly the same requirement: a
 * mandate cannot have been signed tomorrow.
 *
 * The rule owns *only* "not in the future". A malformed value is the `date`
 * rule's business, and a missing one is `required`'s — a field must be able to
 * carry all three rules without any of them reporting another's failure twice.
 */
class ValidatorPastDateTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator($this->createMock(\PDO::class));
    }

    private static function today(): string
    {
        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    private static function daysFromToday(int $days): string
    {
        return (new \DateTimeImmutable('today'))
            ->modify(sprintf('%+d days', $days))
            ->format('Y-m-d');
    }

    public function test_rejects_tomorrow(): void
    {
        $valid = $this->validator->validate(
            ['date_of_birth' => self::daysFromToday(1)],
            ['date_of_birth' => ['nullable', 'date', 'past_date']],
        );

        $this->assertFalse($valid);
        $this->assertArrayHasKey('date_of_birth', $this->validator->errors());
    }

    public function test_rejects_a_date_far_in_the_future(): void
    {
        $valid = $this->validator->validate(
            ['date_of_birth' => self::daysFromToday(3650)],
            ['date_of_birth' => ['nullable', 'date', 'past_date']],
        );

        $this->assertFalse($valid);
    }

    /**
     * Today is not the future. A mandate signed this morning is the ordinary
     * case, and a member born today is absurd but not a validation error — it
     * is a data-entry mistake nothing here can distinguish from a real one.
     */
    public function test_accepts_today(): void
    {
        $valid = $this->validator->validate(
            ['mandate_signed_at' => self::today()],
            ['mandate_signed_at' => ['nullable', 'date', 'past_date']],
        );

        $this->assertTrue($valid, implode(' ', $this->validator->errors()['mandate_signed_at'] ?? []));
    }

    public function test_accepts_yesterday(): void
    {
        $this->assertTrue($this->validator->validate(
            ['date_of_birth' => self::daysFromToday(-1)],
            ['date_of_birth' => ['nullable', 'date', 'past_date']],
        ));
    }

    public function test_accepts_a_plausible_birth_date(): void
    {
        $this->assertTrue($this->validator->validate(
            ['date_of_birth' => '1815-12-10'],
            ['date_of_birth' => ['nullable', 'date', 'past_date']],
        ));
    }

    /** @return array<string, array{0: mixed}> */
    public static function absentValues(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
        ];
    }

    /**
     * Presence is `required`'s job. If `past_date` also rejected an absent
     * value, an optional field could never carry the rule at all.
     */
    #[DataProvider('absentValues')]
    public function test_passes_an_absent_value(mixed $value): void
    {
        $this->assertTrue($this->validator->validate(
            ['date_of_birth' => $value],
            ['date_of_birth' => ['nullable', 'past_date']],
        ));
    }

    /** @return array<string, array{0: mixed}> */
    public static function malformedValues(): array
    {
        return [
            'prose' => ['next tuesday'],
            'relative' => ['+1 day'],
            'non-existent day' => ['2026-02-30'],
            'an integer' => [20260101],
            'an array' => [['2026-01-01']],
        ];
    }

    /**
     * A malformed value produces exactly one error, from the `date` rule.
     * Reporting it twice under two names would make a 422 read as though two
     * separate things were wrong with one field.
     */
    #[DataProvider('malformedValues')]
    public function test_leaves_a_malformed_value_to_the_date_rule(mixed $value): void
    {
        $this->validator->validate(
            ['date_of_birth' => $value],
            ['date_of_birth' => ['nullable', 'date', 'past_date']],
        );

        $errors = $this->validator->errors()['date_of_birth'] ?? [];

        $this->assertCount(1, $errors, 'past_date must not double-report what `date` already rejected');
        $this->assertStringContainsString('valid date', $errors[0]);
    }

    /** A timestamp is accepted by the `date` rule, so `past_date` must read one too. */
    public function test_reads_a_timestamp_shaped_value(): void
    {
        $this->assertFalse($this->validator->validate(
            ['mandate_signed_at' => self::daysFromToday(2) . ' 09:00:00'],
            ['mandate_signed_at' => ['nullable', 'date', 'past_date']],
        ));

        $this->assertTrue($this->validator->validate(
            ['mandate_signed_at' => self::daysFromToday(-2) . ' 09:00:00'],
            ['mandate_signed_at' => ['nullable', 'date', 'past_date']],
        ));
    }

    public function test_the_message_names_the_field_and_the_problem(): void
    {
        $this->validator->validate(
            ['date_of_birth' => self::daysFromToday(1)],
            ['date_of_birth' => ['nullable', 'date', 'past_date']],
        );

        $this->assertSame(
            ['date_of_birth must not be in the future'],
            $this->validator->errors()['date_of_birth'],
        );
    }
}
