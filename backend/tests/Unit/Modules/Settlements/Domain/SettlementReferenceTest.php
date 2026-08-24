<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Settlements\Domain;

use App\Modules\Settlements\Domain\EndToEndId;
use App\Modules\Settlements\Domain\SettlementReference;
use App\Shared\Utils\SepaSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * The canonical rendering of a settlement's identity.
 *
 * These tests exist to pin the two properties the whole consolidation rests
 * on: the form fits every SEPA field it is written into, and a human who
 * retypes it in a different shape still finds the same settlement.
 */
class SettlementReferenceTest extends TestCase
{
    private const SETTLEMENT_ID = '3f9c2d1e-7b4a-4c8d-9e2f-1a5b6c7d8e9f';

    public function test_the_canonical_form_is_the_uuid_without_hyphens(): void
    {
        $this->assertSame(
            '3f9c2d1e7b4a4c8d9e2f1a5b6c7d8e9f',
            SettlementReference::of(self::SETTLEMENT_ID),
        );
        $this->assertSame(SettlementReference::LENGTH, strlen(SettlementReference::of(self::SETTLEMENT_ID)));
    }

    /**
     * ISO 20022 caps MsgId, PmtInfId and EndToEndId at 35. A hyphenated UUID is
     * 36, which is why the canonical form is the short one rather than the
     * shape the database column happens to store.
     */
    public function test_it_fits_every_sepa_identifier_field_and_uses_only_sepa_characters(): void
    {
        $reference = SettlementReference::of(self::SETTLEMENT_ID);

        $this->assertLessThanOrEqual(SepaSanitizer::MAX_ID, strlen($reference));
        $this->assertLessThanOrEqual(EndToEndId::MAX_LENGTH, strlen($reference));
        $this->assertTrue(SepaSanitizer::isValid($reference));
        $this->assertSame($reference, SepaSanitizer::sanitizeId($reference), 'sanitising must not alter it');
    }

    public function test_it_is_deterministic_and_lowercase(): void
    {
        $this->assertSame(
            SettlementReference::of(self::SETTLEMENT_ID),
            SettlementReference::of(strtoupper(self::SETTLEMENT_ID)),
        );
        $this->assertSame(
            SettlementReference::of(self::SETTLEMENT_ID),
            SettlementReference::of(self::SETTLEMENT_ID),
        );
    }

    /**
     * The payoff of the whole change: what a member reads off a bank statement
     * finds the run in the admin panel. Bank portals differ on case, and a
     * pasted Verwendungszweck arrives with whitespace around it.
     */
    public function test_a_pasted_reference_normalises_to_the_canonical_form(): void
    {
        $canonical = SettlementReference::of(self::SETTLEMENT_ID);

        foreach ([
            self::SETTLEMENT_ID,
            strtoupper(self::SETTLEMENT_ID),
            '  ' . $canonical . '  ',
            strtoupper($canonical),
            '3f9c2d1e 7b4a 4c8d 9e2f 1a5b6c7d8e9f',
        ] as $typed) {
            $this->assertSame($canonical, SettlementReference::normalise($typed), "failed for: {$typed}");
        }
    }

    /**
     * The documented exception. EndToEndId names a member as well as a run and
     * still has to fit 35 characters, so it cannot be two canonical references
     * — 64 would not fit. This test is here so that a future attempt to
     * "finish the consolidation" fails loudly rather than silently breaking
     * return matching.
     */
    public function test_two_canonical_references_do_not_fit_one_end_to_end_id(): void
    {
        $this->assertGreaterThan(
            EndToEndId::MAX_LENGTH,
            SettlementReference::LENGTH * 2,
        );
    }
}
