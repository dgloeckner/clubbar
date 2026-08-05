<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Validation;

use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Covers the `business_day` rule, which keeps a SEPA execution_date off
 * weekends and TARGET2 closing days (issue #11).
 */
class ValidatorBusinessDayTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        // The rule under test does not touch the database; `unique` is the only
        // rule that does, and it is not exercised here.
        $this->validator = new Validator($this->createMock(\PDO::class));
    }

    public function test_accepts_an_ordinary_weekday(): void
    {
        $this->assertTrue($this->validator->validate(
            ['execution_date' => '2026-08-10'], // Monday
            ['execution_date' => ['business_day']]
        ));
        $this->assertSame([], $this->validator->errors());
    }

    public function test_rejects_a_saturday(): void
    {
        $this->assertFalse($this->validator->validate(
            ['execution_date' => '2026-08-08'],
            ['execution_date' => ['business_day']]
        ));
    }

    public function test_rejects_a_sunday(): void
    {
        // The exact date from issue #11.
        $this->assertFalse($this->validator->validate(
            ['execution_date' => '2026-08-09'],
            ['execution_date' => ['business_day']]
        ));
    }

    public function test_rejects_a_target2_closing_day_on_a_weekday(): void
    {
        foreach (['2026-01-01', '2026-04-03', '2026-04-06', '2026-05-01', '2026-12-25'] as $holiday) {
            $this->assertFalse(
                $this->validator->validate(
                    ['execution_date' => $holiday],
                    ['execution_date' => ['business_day']]
                ),
                "{$holiday} is a TARGET2 closing day and must be rejected"
            );
        }
    }

    public function test_error_message_names_the_field_and_the_rule(): void
    {
        $this->validator->validate(
            ['execution_date' => '2026-08-09'],
            ['execution_date' => ['business_day']]
        );

        $this->assertSame(
            ['execution_date' => ['execution_date must be a bank business day (Mon-Fri, excluding TARGET2 closing days)']],
            $this->validator->errors()
        );
    }

    public function test_skips_null_so_required_owns_the_missing_case(): void
    {
        $this->assertTrue($this->validator->validate(
            [],
            ['execution_date' => ['business_day']]
        ));
    }

    public function test_unparseable_date_fails_without_throwing(): void
    {
        $this->assertFalse($this->validator->validate(
            ['execution_date' => 'not-a-date'],
            ['execution_date' => ['business_day']]
        ));
    }
}
