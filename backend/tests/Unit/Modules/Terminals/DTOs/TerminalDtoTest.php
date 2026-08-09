<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Terminals\DTOs;

use App\Modules\Terminals\DTOs\TerminalDto;
use App\Modules\Terminals\DTOs\TerminalWithTokenDto;
use PHPUnit\Framework\TestCase;

/**
 * The admin API carries a terminal's token lifetime (#106) — without it the
 * panel cannot show an expiry an admin is supposed to act on before it passes.
 */
class TerminalDtoTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => 'terminal-uuid',
            'name' => 'Bar Terminal',
            'device_id' => 'BAR-MAIN-001',
            'is_active' => 1,
            'last_sync_at' => '2026-08-09 08:00:00',
            'last_transaction_at' => '2026-08-09 07:30:00',
            'token_issued_at' => '2026-05-11 09:00:00',
            'token_expires_at' => '2026-08-09 09:00:00',
            'created_at' => '2026-05-11 09:00:00',
            'updated_at' => '2026-08-09 08:00:00',
        ], $overrides);
    }

    public function test_terminal_dto_carries_the_token_lifetime(): void
    {
        $dto = TerminalDto::fromRow($this->row());

        $this->assertSame('2026-05-11 09:00:00', $dto->tokenIssuedAt);
        $this->assertSame('2026-08-09 09:00:00', $dto->tokenExpiresAt);

        $array = $dto->toArray();
        $this->assertSame('2026-05-11T09:00:00Z', $array['token_issued_at']);
        $this->assertSame('2026-08-09T09:00:00Z', $array['token_expires_at']);
    }

    /**
     * A revoked terminal has no token outstanding, so it has no lifetime — and
     * the response says null rather than inventing one.
     */
    public function test_terminal_dto_reports_a_cleared_lifetime_as_null(): void
    {
        $dto = TerminalDto::fromRow($this->row(['token_issued_at' => null, 'token_expires_at' => null]));

        $array = $dto->toArray();
        $this->assertNull($array['token_issued_at']);
        $this->assertNull($array['token_expires_at']);
    }

    public function test_terminal_dto_tolerates_a_row_from_before_the_columns_existed(): void
    {
        $row = $this->row();
        unset($row['token_issued_at'], $row['token_expires_at']);

        $array = TerminalDto::fromRow($row)->toArray();

        $this->assertNull($array['token_issued_at']);
        $this->assertNull($array['token_expires_at']);
    }

    /**
     * Create and rotate answer with the plaintext token exactly once. That is
     * the only moment the operator can write down both the token and the date
     * it stops working, so both travel together.
     */
    public function test_terminal_with_token_dto_states_when_the_new_token_expires(): void
    {
        $dto = TerminalWithTokenDto::fromRowWithToken($this->row(), 'plaintext-token');

        $this->assertSame('2026-05-11 09:00:00', $dto->tokenIssuedAt);
        $this->assertSame('2026-08-09 09:00:00', $dto->tokenExpiresAt);

        $array = $dto->toArray();
        $this->assertSame('plaintext-token', $array['api_token']);
        $this->assertSame('2026-05-11T09:00:00Z', $array['token_issued_at']);
        $this->assertSame('2026-08-09T09:00:00Z', $array['token_expires_at']);
    }

    public function test_terminal_with_token_dto_reports_a_missing_lifetime_as_null(): void
    {
        $row = $this->row();
        unset($row['token_issued_at'], $row['token_expires_at']);

        $array = TerminalWithTokenDto::fromRowWithToken($row, 'plaintext-token')->toArray();

        $this->assertNull($array['token_issued_at']);
        $this->assertNull($array['token_expires_at']);
    }
}
