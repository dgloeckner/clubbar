<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\DTOs;

use App\Modules\Members\DTOs\MemberDto;
use PHPUnit\Framework\TestCase;

/**
 * The member payload an offline terminal receives (epic #582 M4, ADR-0045).
 *
 * `api/terminal.yaml` describes this shape, but nothing enforces it at runtime:
 * the OAS response validator is mounted only under `APP_ENV=test`, which no
 * environment sets, and the spec file is not even mounted into the backend
 * container. So the contract is held up by tests rather than by middleware, and
 * this is the fast half of that — the E2E `sync-jugendschutz.spec.ts` is the
 * other.
 *
 * Two things are worth pinning here, and they pull in opposite directions:
 * the birth date **must** be in the payload, because an offline kiosk cannot
 * ask for it later; and it must be the **only** thing that was added, because
 * decision 1 of ADR-0045 traded exactly one field's worth of privacy for
 * offline correctness.
 */
class MemberDtoTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'm-1',
            'card_uid' => '0003195661',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'date_of_birth' => '1985-06-15',
            'preferred_language' => 'de',
            'is_active' => 1,
            'has_iban' => 1,
            'mandate_reference' => 'MANDATE-1',
            'deleted_at' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-02 00:00:00',
        ];
    }

    public function test_the_terminal_receives_the_raw_birth_date(): void
    {
        $payload = MemberDto::fromRow(self::row())->toArray();

        // The date itself — not an age in years, which would be wrong from the
        // member's next birthday until the next sync, and not a boolean, which
        // could not answer two thresholds at once.
        $this->assertSame('1985-06-15', $payload['date_of_birth']);
    }

    /**
     * The whole payload, stated once.
     *
     * A kiosk sits in a room the club controls but does not own the way it owns
     * a server, so what reaches it is a deliberate list rather than whatever the
     * row happened to have. If a future change widens this, it should have to
     * edit this assertion and say why.
     */
    public function test_and_nothing_else_reaches_the_kiosk(): void
    {
        $payload = MemberDto::fromRow(self::row())->toArray();

        $this->assertSame([
            'card_uid',
            'created_at',
            'credit_limit_cents',
            'date_of_birth',
            'deleted_at',
            'first_name',
            'id',
            'is_active',
            'is_sepa_valid',
            'last_name',
            'preferred_language',
            'updated_at',
        ], $this->sortedKeys($payload));

        foreach (['email', 'iban', 'iban_last4', 'mandate_reference', 'balance_cents', 'account_holder_name'] as $withheld) {
            $this->assertArrayNotHasKey($withheld, $payload, "{$withheld} must not reach a terminal");
        }
    }

    /**
     * The **override**, not a resolved ceiling (ADR-0047 decision 1). The
     * terminal coalesces it with the club default from `/sync/config`, and it
     * has to, because this payload is a delta on `updated_at`: a club setting
     * touches no member row, so a figure resolved here would go stale on every
     * terminal until each member changed for some unrelated reason.
     */
    public function test_the_terminal_receives_the_members_own_ceiling_where_they_have_one(): void
    {
        $payload = MemberDto::fromRow(self::row(['credit_limit_cents' => 5_000]))->toArray();

        $this->assertSame(5_000, $payload['credit_limit_cents']);
    }

    public function test_a_member_who_follows_the_club_default_syncs_a_null(): void
    {
        $payload = MemberDto::fromRow(self::row(['credit_limit_cents' => null]))->toArray();

        // Not the club's number, and not zero: null is what "follow the club"
        // is written as, and zero means "no ceiling for this member".
        $this->assertNull($payload['credit_limit_cents']);
    }

    public function test_a_deliberately_uncapped_member_syncs_a_zero(): void
    {
        $payload = MemberDto::fromRow(self::row(['credit_limit_cents' => 0]))->toArray();

        $this->assertSame(0, $payload['credit_limit_cents']);
    }

    /**
     * The compensating control for putting a birth date on a kiosk: erasure
     * rides the ordinary delta, so the field arrives nulled rather than needing
     * a cache-busting mechanism of its own.
     */
    public function test_an_anonymized_member_syncs_with_no_birth_date(): void
    {
        $payload = MemberDto::fromRow(self::row([
            'first_name' => null,
            'last_name' => null,
            'date_of_birth' => null,
            'card_uid' => 'ANON-0123456789abcde',
            'is_active' => 0,
            'deleted_at' => '2026-02-01 12:00:00',
        ]))->toArray();

        $this->assertNull($payload['date_of_birth']);
        $this->assertNotNull($payload['deleted_at'], 'the tombstone is what tells the terminal to forget the card');
    }

    /**
     * A DATE column has no time and no zone. Passing it through the timestamp
     * formatter would be the ordinary way to get this wrong, and it would move
     * the date by a day for half the world — including across a birthday.
     */
    public function test_the_birth_date_is_not_reformatted_as_a_timestamp(): void
    {
        $payload = MemberDto::fromRow(self::row(['date_of_birth' => '2008-02-29']))->toArray();

        $this->assertSame('2008-02-29', $payload['date_of_birth']);
        $this->assertStringNotContainsString('T', $payload['date_of_birth']);
    }

    /** @param array<string, mixed> $payload @return list<string> */
    private function sortedKeys(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);

        return $keys;
    }
}
