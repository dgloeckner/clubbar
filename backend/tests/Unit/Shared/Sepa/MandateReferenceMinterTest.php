<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Sepa;

use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Shared\Sepa\MandateReferenceMinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\CountingMandateReferenceCounter;

/**
 * The formatting half of the minter.
 *
 * The SQL half — the atomic `UPDATE … LAST_INSERT_ID(value + 1)` draw — is
 * covered by `Tests\Feature\Shared\Sepa\MandateReferenceCounterTest`, because
 * `LAST_INSERT_ID()` does not exist in SQLite and mocking it would only assert
 * the mock.
 */
class MandateReferenceMinterTest extends TestCase
{
    private function minter(?string $prefix, CountingMandateReferenceCounter $counter): MandateReferenceMinter
    {
        $config = $this->createMock(SepaConfigRepository::class);
        $config->method('getConfig')->willReturn(['mandate_reference_prefix' => $prefix]);

        return new MandateReferenceMinter($counter, $config);
    }

    public function test_the_first_reference_is_the_default_prefix_and_one(): void
    {
        self::assertSame('CB-000001', $this->minter(null, new CountingMandateReferenceCounter())->mint());
    }

    public function test_consecutive_mints_are_consecutive_numbers(): void
    {
        $minter = $this->minter(null, new CountingMandateReferenceCounter());

        self::assertSame(['CB-000001', 'CB-000002', 'CB-000003'], [$minter->mint(), $minter->mint(), $minter->mint()]);
    }

    public function test_the_clubs_prefix_is_used_when_it_has_chosen_one(): void
    {
        self::assertSame('RVM-000001', $this->minter('RVM', new CountingMandateReferenceCounter())->mint());
    }

    /**
     * A config row predating migration 069 has NULL here, and a club that
     * clears the field is asking for the default back — not for `-000001`.
     */
    public function test_a_blank_prefix_falls_back_to_the_default(): void
    {
        self::assertSame('CB-000001', $this->minter('', new CountingMandateReferenceCounter())->mint());
    }

    /** Padding is a floor, not a cap: past six digits the number simply grows. */
    public function test_a_number_wider_than_the_padding_is_not_truncated(): void
    {
        self::assertSame('CB-000042', MandateReferenceMinter::format('CB', 42));
        self::assertSame('CB-999999', MandateReferenceMinter::format('CB', 999999));
        self::assertSame('CB-1000000', MandateReferenceMinter::format('CB', 1000000));
    }

    /**
     * The SEPA cap. A prefix at the documented maximum leaves 24 characters for
     * the number, which a BIGINT counter cannot reach — so a valid prefix
     * guarantees a valid reference for every number this install will mint.
     */
    public function test_the_widest_reference_this_install_can_mint_fits_in_35_characters(): void
    {
        $widestPrefix = str_repeat('X', MandateReferenceMinter::MAX_PREFIX_LENGTH);
        $widestNumber = PHP_INT_MAX;

        self::assertLessThanOrEqual(
            MandateReferenceMinter::MAX_LENGTH,
            strlen(MandateReferenceMinter::format($widestPrefix, $widestNumber)),
        );
    }

    public function test_the_honeypot_decoy_is_shaped_like_a_reference_and_draws_nothing(): void
    {
        $counter = new CountingMandateReferenceCounter();

        $decoy = $this->minter(null, $counter)->decoy();

        self::assertMatchesRegularExpression('/^CB-[0-9]{6}$/', $decoy);
        self::assertSame(0, $counter->drawn());
    }

    #[DataProvider('validPrefixes')]
    public function test_a_prefix_in_the_sepa_charset_is_accepted(string $prefix): void
    {
        self::assertTrue(MandateReferenceMinter::isValidPrefix($prefix));
    }

    public static function validPrefixes(): array
    {
        return [
            'letters' => ['CB'],
            'digits' => ['2026'],
            'mixed case' => ['RvM'],
            'the rest of the SEPA charset' => ["+?/-:().,'"],
            'at the length limit' => ['XXXXXXXXXX'],
        ];
    }

    #[DataProvider('invalidPrefixes')]
    public function test_a_prefix_outside_the_sepa_charset_or_too_long_is_refused(string $prefix): void
    {
        self::assertFalse(MandateReferenceMinter::isValidPrefix($prefix));
    }

    public static function invalidPrefixes(): array
    {
        return [
            'empty' => [''],
            'umlaut' => ['RÜV'],
            'underscore' => ['CB_'],
            'hash' => ['CB#'],
            'a space is not in the SEPA charset' => ['CB X'],
            'one character past the length limit' => ['XXXXXXXXXXX'],
        ];
    }
}
