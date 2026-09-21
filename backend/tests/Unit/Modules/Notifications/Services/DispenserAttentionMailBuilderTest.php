<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\DispenserAttentionMailBuilder;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Terminals\Services\DispenserFillService;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * Rendering a dispenser notice at send time (#956, ADR-0038 rule 5).
 *
 * The queue row carries the terminal and the occasion and nothing else, so
 * everything the reader sees is read here — from the same two columns the panel
 * reads. The gap between the scan and the drain is asymmetric and worth testing
 * in both directions: a machine still jammed is reported as it stands, and one
 * somebody fixed in the intervening quarter of an hour is not reported as
 * broken.
 *
 * Part of #956, epic #944.
 */
class DispenserAttentionMailBuilderTest extends TestCase
{
    public function test_it_claims_its_own_kind_and_no_other(): void
    {
        $builder = (new \ReflectionClass(DispenserAttentionMailBuilder::class))->newInstanceWithoutConstructor();

        $claimed = array_values(array_filter(
            MailKind::cases(),
            static fn (MailKind $kind): bool => $builder->supports($kind),
        ));

        $this->assertSame([MailKind::DISPENSER_ATTENTION], $claimed);
    }

    /** The occasion lives in the dedup key, and the rendering follows from it. */
    public function test_a_fault_row_renders_the_condition_the_terminal_reports(): void
    {
        $message = $this->build('fault:20260921100000000:admin-1', $this->terminal($this->jam()));

        $this->assertSame('Ausgabegerät „Theke“: Stau oder leer', $message->subject);
        $this->assertSame('admin@club.example', $message->to);
        $this->assertStringContainsString('Vorstand', $message->text);
    }

    /**
     * **A jam cleared before the drain ran is good news, not a failed build.**
     * Throwing here would put a red row in the Notifications page for a machine
     * somebody had just fixed — and an admin who has seen that twice stops
     * reading the page that reports real delivery failures.
     */
    public function test_a_fault_that_cleared_before_the_drain_renders_as_cleared(): void
    {
        $message = $this->build('fault:20260921100000000:admin-1', $this->terminal($this->idle()));

        $this->assertSame('Ausgabegerät „Theke“: hat sich erledigt', $message->subject);
    }

    /** Refilled between the scan and the drain: the shortage is over. */
    public function test_a_refilled_hopper_renders_as_cleared(): void
    {
        $message = $this->build(
            'low:20260920180000:admin-1',
            $this->terminal($this->idle(), refilledAt: '2026-09-21 09:00:00', refillTokens: 100),
            sold: 3,
        );

        $this->assertSame('Ausgabegerät „Theke“: hat sich erledigt', $message->subject);
    }

    /** And a hopper still running out reports the figure as it stands now. */
    public function test_a_low_row_reports_the_estimate_at_send_time(): void
    {
        $message = $this->build(
            'low:20260920180000:admin-1',
            $this->terminal($this->idle(), refilledAt: '2026-09-20 18:00:00', refillTokens: 100),
            sold: 93,
        );

        $this->assertSame('Ausgabegerät „Theke“: Token gehen zur Neige', $message->subject);
        $this->assertStringContainsString('Noch etwa 7 Token', $message->text);
    }

    /**
     * A key naming no occasion is not guessed at. It is reachable only by hand
     * or by a future format, and inventing an errand around it would be worse
     * than the row failing loudly where the drain records it.
     */
    public function test_a_row_whose_key_names_no_occasion_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('names no known occasion');

        $this->build('30d:admin-1', $this->terminal($this->jam()));
    }

    /**
     * `subject_id` is polymorphic and carries no foreign key, so a terminal
     * deleted between the scan and the drain is reachable — and a notice about
     * a machine that no longer exists must not be invented around the gap.
     */
    public function test_a_terminal_that_no_longer_exists_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no longer exists');

        $this->build('fault:20260921100000000:admin-1', null);
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string,mixed>|null $terminal */
    private function build(string $dedupKey, ?array $terminal, int $sold = 0): \App\Shared\Mail\MailMessage
    {
        $terminals = $this->createMock(TerminalsRepository::class);
        $terminals->method('findById')->willReturn($terminal);
        $terminals->method('countTokensSoldSinceRefill')->willReturnCallback(
            static fn (?string $id = null): array => [(string) $id => $sold]
        );

        $admins = $this->createMock(AdminUsersRepository::class);
        $admins->method('findById')->willReturn(['display_name' => 'Vorstand']);

        $builder = new DispenserAttentionMailBuilder(
            $terminals,
            new DispenserFillService($terminals, $this->createMock(AuditService::class)),
            $admins,
            'https://club.example',
        );

        return $builder->build([
            'kind' => MailKind::DISPENSER_ATTENTION->value,
            'subject_id' => 't-1',
            'dedup_key' => $dedupKey,
            'recipient' => 'admin@club.example',
            'admin_user_id' => 'admin-1',
            'language' => 'de',
        ], MailConfigDto::fromRow([
            'sender_name' => 'Club Bar',
            'sender_address' => 'bar@club.example',
            'footer_org_name' => 'Club Bar',
        ]));
    }

    /** @return array<string,mixed> */
    private function terminal(array $status, ?string $refilledAt = null, ?int $refillTokens = null): array
    {
        return [
            'id' => 't-1',
            'name' => 'Theke',
            'is_active' => true,
            'dispenser_status' => json_encode($status),
            'dispenser_status_at' => '2026-09-21 11:59:00',
            'dispenser_refilled_at' => $refilledAt,
            'dispenser_refill_tokens' => $refillTokens,
            'dispenser_low_threshold' => 20,
        ];
    }

    /** @return array<string,mixed> */
    private function jam(): array
    {
        return [
            'configured' => true,
            'contact' => 'reported',
            'state' => 'fault',
            'fault' => 'jam',
            'fault_code' => 0,
            'available' => false,
            'unavailable_reason' => 'jam',
            'state_since' => '2026-09-21T10:00:00.000Z',
        ];
    }

    /** @return array<string,mixed> */
    private function idle(): array
    {
        return [
            'configured' => true,
            'contact' => 'reported',
            'state' => 'idle',
            'fault' => 'none',
            'fault_code' => 0,
            'available' => true,
            'unavailable_reason' => null,
            'state_since' => '2026-09-21T11:00:00.000Z',
        ];
    }
}
