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

    // ─── importFromFile ──────────────────────────────────────────────────────

    public function test_importFromFile_parses_fixed_width_fields_with_umlauts(): void
    {
        // Bundesbank BLZ files are ISO-8859-1 fixed-width: umlauts are single
        // bytes, so field offsets are byte positions in the Latin-1 line.
        // "\xE4" = ä, "\xFC" = ü in ISO-8859-1.
        $line = '10070024'                                                          // pos 1-8: BLZ
            . '1'                                                                   // pos 9: Merkmal (main office)
            . str_pad("Deutsche Bank Privat- und Gesch\xE4ftskunden", 58)           // pos 10-67: bank name
            . '10117'                                                               // pos 68-72: postal code
            . str_pad('Berlin', 35)                                                 // pos 73-107: city
            . str_pad("Deutsche Bank PGK M\xFCnchen", 27)                           // pos 108-134: short name
            . '52002'                                                               // pos 135-139: PAN
            . 'DEUTDEDBBER'                                                         // pos 140-150: BIC
            . '09'                                                                  // filler so the line passes the length check
            . "\n";

        $file = tempnam(sys_get_temp_dir(), 'blz');
        file_put_contents($file, $line);

        $captured = [];
        $repository = $this->createMock(\App\Modules\BankCodes\Repositories\BankCodesRepository::class);
        $repository->method('importBatch')->willReturnCallback(function (array $rows) use (&$captured) {
            $captured = array_merge($captured, $rows);
            return count($rows);
        });
        $repository->method('removeStale')->willReturn(0);
        $repository->method('count')->willReturn(1);
        $logger = $this->createMock(\App\Shared\Logging\Logger::class);

        $service = new BankCodeService($repository, $logger);
        $result = $service->importFromFile($file);
        unlink($file);

        $this->assertSame(1, $result['imported']);
        $this->assertCount(1, $captured);
        $row = $captured[0];
        $this->assertSame('10070024', $row['bank_code']);
        $this->assertSame('Deutsche Bank Privat- und Geschäftskunden', $row['bank_name']);
        $this->assertSame('10117', $row['postal_code']);
        $this->assertSame('Berlin', $row['city']);
        $this->assertSame('Deutsche Bank PGK München', $row['short_name']);
        $this->assertSame('DEUTDEDBBER', $row['bic']);
    }
}
