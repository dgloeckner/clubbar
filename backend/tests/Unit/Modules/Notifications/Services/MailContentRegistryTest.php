<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\DTOs\MailConfigDto;
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
    private function mailConfig(): MailConfigDto
    {
        return MailConfigDto::fromRow([
            'sender_name' => 'FRGS Ruderbar',
            'sender_address' => 'bar@example.org',
            'footer_org_name' => 'FRGS Ruderbar',
        ]);
    }

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

            public function build(array $outboxRow, MailConfigDto $mailConfig): MailMessage
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
            $registry->build(['kind' => 'key_expiry_warning'], $this->mailConfig())->subject,
        );
        $this->assertSame(
            'announcement',
            $registry->build(['kind' => 'sepa_prenotification'], $this->mailConfig())->subject,
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

        $registry->build(['kind' => 'key_expiry_warning'], $this->mailConfig());
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

    /**
     * Every kind the enum declares can actually be rendered by the registry
     * the application wires up (ADR-0051).
     *
     * The gap this closes: adding a `MailKind` case is checked in four places
     * by the compiler, because {@see MailKind}'s four `match` expressions are
     * exhaustive — but *nothing* checked that a builder claims it. A kind that
     * something enqueues and no builder renders fails at drain time, in
     * `builderFor()`, against a row a member is already waiting on. That is the
     * one part of adding a notification type PHP cannot catch, so it is caught
     * here instead.
     *
     * The builder list is read from `ServiceFactory::getMailContentRegistry()`
     * rather than restated, so a builder written and never registered fails
     * this test too — which is the other half of the same mistake.
     *
     * Builders are instantiated without their constructors, as
     * {@see test_the_settlement_builder_claims_every_settlement_kind} already
     * does: `supports()` answers from the kind alone, so no repository, no
     * config and no database is involved in asking.
     */
    public function test_the_wired_registry_can_render_every_kind(): void
    {
        $registry = new MailContentRegistry(...array_map(
            static fn(string $class) => (new \ReflectionClass($class))->newInstanceWithoutConstructor(),
            self::wiredBuilderClasses(),
        ));

        foreach (MailKind::cases() as $kind) {
            $this->assertTrue(
                $registry->supports($kind),
                $kind->value . ' is queueable and nothing can render it: give it a MailContentBuilder '
                . 'and register it in ServiceFactory::getMailContentRegistry()'
            );
        }
    }

    /**
     * The builder classes `ServiceFactory::getMailContentRegistry()` actually
     * passes to the registry, read from the factory rather than duplicated.
     *
     * @return list<class-string<MailContentBuilder>>
     */
    private static function wiredBuilderClasses(): array
    {
        $factory = new \ReflectionClass(\App\ServiceFactory::class);
        $method = $factory->getMethod('getMailContentRegistry');

        $source = implode('', array_slice(
            file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        preg_match_all('/\$this->(get\w+)\(\)/', $source, $matches);

        $classes = [];
        foreach (array_unique($matches[1]) as $getter) {
            $returns = $factory->getMethod($getter)->getReturnType();
            if (!$returns instanceof \ReflectionNamedType) {
                continue;
            }

            $class = $returns->getName();
            if (is_subclass_of($class, MailContentBuilder::class)) {
                $classes[] = $class;
            }
        }

        // A regex that silently matched nothing would make this test pass while
        // asserting about an empty registry.
        self::assertNotEmpty($classes, 'no builders were read out of ServiceFactory');

        return $classes;
    }
}
