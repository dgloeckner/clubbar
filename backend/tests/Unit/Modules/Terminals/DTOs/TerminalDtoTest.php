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

    /**
     * ADR-0054 requirement 10. The comparison is made on the server, once,
     * against the one number that decides it — this backend's own version — so
     * the panel renders a verdict rather than re-deriving one.
     */
    public function test_a_terminal_running_the_backends_version_reads_as_current(): void
    {
        $array = TerminalDto::fromRow($this->row(['reported_version' => 'v1.0.7']), 'v1.0.7')->toArray();

        $this->assertSame('current', $array['version_state']);
        $this->assertSame('v1.0.7', $array['reported_version']);
        $this->assertSame('v1.0.7', $array['backend_version']);
    }

    public function test_a_terminal_frozen_by_a_failed_update_reads_as_blocked(): void
    {
        $array = TerminalDto::fromRow(
            $this->row(['reported_version' => 'v1.0.6', 'blocked_version' => 'v1.0.7']),
            'v1.0.7',
        )->toArray();

        $this->assertSame('blocked', $array['version_state']);
        // The tag itself travels: "blocked" without it tells an admin nothing
        // they can act on or repeat over the phone.
        $this->assertSame('v1.0.7', $array['blocked_version']);
    }

    /**
     * A row from before migration 065, or a terminal that has never reported.
     * Neither is an error, and neither may read as agreement.
     */
    public function test_a_terminal_that_has_never_reported_reads_as_unknown(): void
    {
        $array = TerminalDto::fromRow($this->row(), 'v1.0.7')->toArray();

        $this->assertSame('unknown', $array['version_state']);
        $this->assertNull($array['reported_version']);
        $this->assertNull($array['reported_version_at']);
        $this->assertNull($array['blocked_version']);
    }

    /**
     * Every other reader of this DTO constructs it without a backend version —
     * they must keep working, and must not claim agreement they never checked.
     */
    public function test_omitting_the_backend_version_reads_as_unknown_not_as_current(): void
    {
        $array = TerminalDto::fromRow($this->row(['reported_version' => 'v1.0.7']))->toArray();

        $this->assertSame('unknown', $array['version_state']);
        $this->assertNull($array['backend_version']);
    }

    public function test_the_version_stamp_is_rendered_as_utc(): void
    {
        $array = TerminalDto::fromRow(
            $this->row(['reported_version' => 'v1.0.7', 'reported_version_at' => '2026-09-06 04:03:11']),
            'v1.0.7',
        )->toArray();

        $this->assertSame('2026-09-06T04:03:11Z', $array['reported_version_at']);
    }
}
