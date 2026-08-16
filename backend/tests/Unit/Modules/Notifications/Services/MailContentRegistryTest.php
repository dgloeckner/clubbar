<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\MailContentRegistry;
use App\Modules\Notifications\Services\SettlementMailBuilder;
use App\Shared\Mail\MailMessage;
use PHPUnit\Framework\TestCase;

/**
 * The seam that keeps the drain (#403) from knowing what any of its rows mean.
 *
 * It exists because the outbox is no longer a settlement queue: one claimed
 * batch can hold an announcement, a cancellation notice and — once #438 lands —
 * an expiry warning about an encryption key. The sending loop has to stay the
 * boring part, so the dispatch happens here instead of as a growing `match`
 * inside it.
 */
class MailContentRegistryTest extends TestCase
{
    private function builderFor(MailKind $claims, string $subject = 'built'): MailContentBuilder
    {
        return new class ($claims, $subject) implements MailContentBuilder {
            public function __construct(
                private MailKind $claims,
                private string $subject,
            ) {}

            public function supports(MailKind $kind): bool
            {
                return $kind === $this->claims;
            }

            public function build(array $outboxRow): MailMessage
            {
                return new MailMessage(
                    to: 'someone@example.org',
                    subject: $this->subject,
                    html: '<p>html</p>',
                    text: 'text',
                );
            }
        };
    }

    public function test_it_routes_a_row_to_the_builder_that_claims_its_kind(): void
    {
        $registry = new MailContentRegistry(
            $this->builderFor(MailKind::SEPA_PRENOTIFICATION, 'announcement'),
            $this->builderFor(MailKind::KEY_EXPIRY_WARNING, 'key warning'),
        );

        $this->assertSame(
            'key warning',
            $registry->build(['kind' => 'key_expiry_warning'])->subject,
        );
        $this->assertSame(
            'announcement',
            $registry->build(['kind' => 'sepa_prenotification'])->subject,
        );
    }

    public function test_it_reports_which_kinds_it_can_render(): void
    {
        $registry = new MailContentRegistry($this->builderFor(MailKind::SEPA_PRENOTIFICATION));

        $this->assertTrue($registry->supports(MailKind::SEPA_PRENOTIFICATION));
        $this->assertFalse($registry->supports(MailKind::KEY_EXPIRY_WARNING));
    }

    /**
     * A kind reaches the registry only because something already queued it, so
     * "nothing can render this" is a programming error with a row behind it
     * that somebody is waiting on. Returning null would let the drain skip it
     * silently for ever.
     */
    public function test_an_unrenderable_kind_is_an_error_rather_than_a_silent_skip(): void
    {
        $registry = new MailContentRegistry($this->builderFor(MailKind::SEPA_PRENOTIFICATION));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/key_expiry_warning/');

        $registry->build(['kind' => 'key_expiry_warning']);
    }

    /**
     * The one builder that exists today claims every settlement kind — and
     * there are exactly two of them. `payment_request` was the third until
     * migration `036` removed it: a settlement announces a collection or
     * retracts one, and never asks a member to send money.
     */
    public function test_the_settlement_builder_claims_every_settlement_kind(): void
    {
        // `supports()` is a pure function of the kind and touches none of the
        // repositories, so the real class answers it without a database.
        $real = (new \ReflectionClass(SettlementMailBuilder::class))->newInstanceWithoutConstructor();

        $settlementKinds = array_filter(
            MailKind::cases(),
            static fn (MailKind $kind): bool => $real->supports($kind),
        );

        // Stated as the whole set rather than as three assertions: a kind added
        // to the enum without a branch in the builder would pass those and fail
        // this, which is the failure worth catching.
        $this->assertSame(
            [MailKind::SEPA_PRENOTIFICATION, MailKind::CANCELLATION_NOTICE],
            array_values($settlementKinds),
        );
        $this->assertFalse($real->supports(MailKind::KEY_EXPIRY_WARNING));
        $this->assertFalse($real->supports(MailKind::TERMINAL_TOKEN_EXPIRY_WARNING));
    }
}
