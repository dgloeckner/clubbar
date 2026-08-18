<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\Dashboard\Domain\CreditLimit;
use App\Modules\Notifications\DTOs\DeckelStatementDataDto;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Services\DeckelStatementMailBuilder;
use App\Modules\Notifications\Services\DeckelStatementService;
use App\Shared\Mail\MailBranding;
use App\Shared\Mail\MailLayout;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What the drain hands the statement renderer (#463 step 12.2).
 *
 * The claim under test is narrow and load-bearing: **everything the message
 * says comes off the queued row**, and nothing comes from today. That is what
 * makes a retried statement say what it said on the first attempt — the
 * property ADR-0039 decision 2 chose a dated predicate for in the first place,
 * and the one a builder that read the clock would quietly undo.
 */
class DeckelStatementMailBuilderTest extends TestCase
{
    /** Unused by this builder — accepted only to satisfy the interface. */
    private function mailConfig(): MailConfigDto
    {
        return MailConfigDto::fromRow([
            'sender_name' => 'FRGS Ruderbar',
            'sender_address' => 'bar@example.org',
            'footer_org_name' => 'FRGS Ruderbar',
        ]);
    }

    public function test_it_claims_the_statement_kind_and_nothing_else(): void
    {
        $builder = new DeckelStatementMailBuilder($this->createMock(DeckelStatementService::class));

        $this->assertTrue($builder->supports(MailKind::DECKEL_STATEMENT));

        foreach (MailKind::cases() as $kind) {
            if ($kind !== MailKind::DECKEL_STATEMENT) {
                $this->assertFalse($builder->supports($kind), $kind->value . ' has its own builder');
            }
        }
    }

    /**
     * The row's period key becomes the boundary; the row's recipient becomes
     * the envelope. Neither is re-derived — the address in particular, which is
     * the proof of who was written to and may since have been erased.
     */
    public function test_it_renders_from_the_row_rather_than_from_today(): void
    {
        $service = $this->createMock(DeckelStatementService::class);
        $service->expects($this->once())
            ->method('forMember')
            ->with(
                'member-1',
                $this->callback(
                    static fn (DateTimeImmutable $boundary): bool => $boundary->format('Y-m-d H:i:s') === '2026-08-01 00:00:00'
                ),
                MailLanguage::English,
                'snapshot@example.org',
            )
            ->willReturn($this->statement());

        $message = (new DeckelStatementMailBuilder($service))->build([
            'kind' => MailKind::DECKEL_STATEMENT->value,
            'subject_id' => 'member-1',
            'member_id' => 'member-1',
            'recipient' => 'snapshot@example.org',
            'language' => 'en',
            'dedup_key' => '2026-08',
        ], $this->mailConfig());

        $this->assertSame('snapshot@example.org', $message->to);
        $this->assertNotSame('', $message->text);
    }

    /** A quarterly key is read as one, whatever the club's cadence is set to now. */
    public function test_a_quarterly_key_states_the_quarters_own_boundary(): void
    {
        $service = $this->createMock(DeckelStatementService::class);
        $service->expects($this->once())
            ->method('forMember')
            ->with(
                $this->anything(),
                $this->callback(
                    static fn (DateTimeImmutable $boundary): bool => $boundary->format('Y-m-d') === '2026-07-01'
                ),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($this->statement());

        (new DeckelStatementMailBuilder($service))->build($this->row('2026-Q3'), $this->mailConfig());
    }

    /**
     * An unreadable period is refused rather than guessed at.
     *
     * The row will stay unsent and be visible to the Kassenwart, which is the
     * right outcome: a statement rendered at an invented boundary would state
     * a number for a date nobody asked about, and the member has no way to tell.
     */
    public function test_an_unreadable_period_key_refuses_to_render(): void
    {
        $service = $this->createMock(DeckelStatementService::class);
        $service->expects($this->never())->method('forMember');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must not invent/');

        (new DeckelStatementMailBuilder($service))->build($this->row('letzter Monat'), $this->mailConfig());
    }

    /** @return array<string,mixed> */
    private function row(string $dedupKey): array
    {
        return [
            'kind' => MailKind::DECKEL_STATEMENT->value,
            'subject_id' => 'member-1',
            'member_id' => 'member-1',
            'recipient' => 'mitglied@example.org',
            'language' => 'de',
            'dedup_key' => $dedupKey,
        ];
    }

    private function statement(): DeckelStatementDataDto
    {
        return new DeckelStatementDataDto(
            language: MailLanguage::German,
            recipientAddress: 'snapshot@example.org',
            recipientName: 'Erika Mustermann',
            salutationName: 'Erika',
            branding: new MailBranding(orgName: 'Beispiel-Ruderverein e.V.', headerStyle: MailLayout::HEADER_PAPER),
            boundaryDate: '2026-08-01',
            totalCents: 0,
            creditLimitCents: CreditLimit::LIMIT_CENTS,
            creditStatus: CreditLimit::STATUS_OK,
        );
    }
}
