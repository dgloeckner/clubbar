<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\BankCodes\Services;

use App\Modules\BankCodes\Services\BankCodeService;
use PHPUnit\Framework\TestCase;

class BankCodeServiceTest extends TestCase
{
    // ─── extractBlz ─────────────────────────────────────────��───────────────

    public function test_extractBlz_returns_blz_from_german_iban(): void
    {
        $this->assertSame('37040044', BankCodeService::extractBlz('DE89370400440532013000'));
    }

    public function test_extractBlz_returns_blz_from_another_german_iban(): void
    {
        $this->assertSame('10010010', BankCodeService::extractBlz('DE02100100100006820101'));
    }

    public function test_extractBlz_handles_spaces_in_iban(): void
    {
        $this->assertSame('37040044', BankCodeService::extractBlz('DE89 3704 0044 0532 0130 00'));
    }

    public function test_extractBlz_handles_lowercase_iban(): void
    {
        $this->assertSame('37040044', BankCodeService::extractBlz('de89370400440532013000'));
    }

    public function test_extractBlz_returns_null_for_non_german_iban(): void
    {
        $this->assertNull(BankCodeService::extractBlz('AT611904300234573201'));
        $this->assertNull(BankCodeService::extractBlz('FR7630006000011234567890189'));
        $this->assertNull(BankCodeService::extractBlz('GB29NWBK60161331926819'));
    }

    public function test_extractBlz_returns_null_for_null_input(): void
    {
        $this->assertNull(BankCodeService::extractBlz(null));
    }

    public function test_extractBlz_returns_null_for_short_string(): void
    {
        $this->assertNull(BankCodeService::extractBlz('DE89'));
        $this->assertNull(BankCodeService::extractBlz(''));
    }
}
