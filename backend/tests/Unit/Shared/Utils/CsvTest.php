<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Utils;

use App\Shared\Utils\Csv;
use PHPUnit\Framework\TestCase;

class CsvTest extends TestCase
{
    public function test_build_writes_a_header_line_and_one_line_per_row(): void
    {
        $csv = Csv::build(['a', 'b'], [['1', '2'], ['3', '4']]);

        $this->assertSame("a;b\n1;2\n3;4\n", $csv);
    }

    public function test_build_returns_an_empty_string_when_there_is_nothing_to_write(): void
    {
        $this->assertSame('', Csv::build([], []));
    }

    public function test_build_emits_the_header_even_when_there_are_no_rows(): void
    {
        $this->assertSame("a;b\n", Csv::build(['a', 'b'], []));
    }

    /**
     * The bug this writer exists to kill: the hand-rolled `implode(';', …)`
     * in the settlement export corrupted every file containing a member whose
     * name has a semicolon in it (issue #119).
     */
    public function test_build_quotes_values_containing_the_delimiter(): void
    {
        $csv = Csv::build(['name', 'amount'], [['Meier; Hans', '12.34']]);

        $this->assertSame("name;amount\n\"Meier; Hans\";12.34\n", $csv);
    }

    public function test_build_escapes_embedded_quotes_by_doubling_them(): void
    {
        $csv = Csv::build(['name'], [['Hans "Hansi" Meier']]);

        $this->assertSame("name\n\"Hans \"\"Hansi\"\" Meier\"\n", $csv);
    }

    public function test_build_quotes_values_containing_newlines(): void
    {
        $csv = Csv::build(['note'], [["line one\nline two"]]);

        $this->assertSame("note\n\"line one\nline two\"\n", $csv);
    }

    public function test_build_accepts_a_custom_delimiter(): void
    {
        $csv = Csv::build(['a', 'b'], [['1', '2']], ',');

        $this->assertSame("a,b\n1,2\n", $csv);
    }

    public function test_build_renders_null_as_an_empty_field_and_casts_scalars(): void
    {
        $csv = Csv::build(['a', 'b', 'c'], [[null, 42, true]]);

        $this->assertSame("a;b;c\n;42;1\n", $csv);
    }

    public function test_build_writes_associative_rows_in_value_order(): void
    {
        $csv = Csv::build(['x', 'y'], [['x' => 'one', 'y' => 'two']]);

        $this->assertSame("x;y\none;two\n", $csv);
    }

    public function test_money_formats_cents_as_euro_with_two_decimals(): void
    {
        $this->assertSame('0.05', Csv::money(5));
        $this->assertSame('12.34', Csv::money(1234));
        $this->assertSame('50.00', Csv::money(5000));
        $this->assertSame('0.00', Csv::money(0));
    }

    public function test_money_keeps_the_sign_of_a_credit(): void
    {
        $this->assertSame('-27.50', Csv::money(-2750));
    }

    public function test_money_never_groups_thousands(): void
    {
        $this->assertSame('12345.67', Csv::money(1234567));
    }
}
