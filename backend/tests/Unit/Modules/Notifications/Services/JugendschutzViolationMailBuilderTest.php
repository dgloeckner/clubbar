<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\JugendschutzViolationMailBuilder;
use App\Modules\Products\Repositories\ProductsRepository;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use PHPUnit\Framework\TestCase;

/**
 * The message that tells a human a minor was served (#622, ADR-0045 §3).
 *
 * The whole risk of this builder is what it *includes*. The message goes to the
 * Kassenwart and to the club address — a list belonging to no account at all —
 * so rule 6 binds it exactly as it binds the terminal screen: name what the
 * *drink* required, never what the member is. The negative assertions below are
 * therefore the point of the file, not padding.
 *
 * (Until #633 it also reached the **Getränkewart**, because the fan-out ignored
 * roles entirely. It no longer does, and the design did not change: the club
 * copy is reason enough on its own.)
 */
class JugendschutzViolationMailBuilderTest extends TestCase
{
    private TransactionsRepository $transactionsRepository;
    private ProductsRepository $productsRepository;
    private AdminUsersRepository $adminUsersRepository;
    private JugendschutzViolationMailBuilder $builder;
    private MailConfigDto $mailConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transactionsRepository = $this->createMock(TransactionsRepository::class);
        $this->productsRepository = $this->createMock(ProductsRepository::class);
        $this->adminUsersRepository = $this->createMock(AdminUsersRepository::class);
        $this->adminUsersRepository->method('findActiveRecipients')->willReturn([]);

        $this->mailConfig = MailConfigDto::fromRow([
            'sender_name' => 'FRGS Ruderbar',
            'sender_address' => 'bar@example.org',
            'footer_org_name' => 'FRGS Ruderbar',
        ]);

        $this->builder = new JugendschutzViolationMailBuilder(
            $this->transactionsRepository,
            $this->productsRepository,
            $this->adminUsersRepository,
        );
    }

    /** @param array<string,mixed> $overrides */
    private function outboxRow(array $overrides = []): array
    {
        return $overrides + [
            'kind' => MailKind::JUGENDSCHUTZ_VIOLATION->value,
            'subject_id' => 'tx-1',
            'recipient' => 'kassenwart@example.com',
            'language' => 'de',
            'dedup_key' => 'age:18:admin-1',
            'admin_user_id' => 'admin-1',
        ];
    }

    private function aSale(): void
    {
        $this->transactionsRepository->method('findById')->willReturn([
            'id' => 'tx-1',
            'member_id' => 'member-1',
            'product_id' => 'product-1',
            'created_at' => '2026-08-20 21:14:00',
            'created_by_terminal_id' => 'terminal-1',
        ]);
        $this->productsRepository->method('findById')->willReturn([
            'id' => 'product-1',
            'names' => '{"de":"Kräuterlikör","en":"Herbal liqueur"}',
            'min_age' => 18,
        ]);
    }

    public function test_it_claims_the_kind(): void
    {
        $this->assertTrue($this->builder->supports(MailKind::JUGENDSCHUTZ_VIOLATION));
        $this->assertFalse($this->builder->supports(MailKind::DECKEL_STATEMENT));
    }

    public function test_the_message_names_the_drink_and_the_age_it_required(): void
    {
        $this->aSale();

        $mail = $this->builder->build($this->outboxRow(), $this->mailConfig);

        $this->assertStringContainsString('Kräuterlikör', $mail->html);
        $this->assertStringContainsString('18', $mail->text);
        $this->assertSame('kassenwart@example.com', $mail->to);
    }

    /**
     * Rule 6, in the channel it is easiest to break.
     *
     * A refusal at the till names what the drink required, never what the
     * member is. The same holds here, and more strongly: the mail is read
     * wherever the recipient reads mail, and one of the recipients is an office
     * that may not see members at all.
     */
    public function test_the_message_names_no_member_and_no_age_at_sale(): void
    {
        $this->aSale();

        $mail = $this->builder->build($this->outboxRow(), $this->mailConfig);

        // Identity, across the whole message.
        $everything = $mail->html . $mail->text . $mail->subject;
        foreach (['member-1', 'Geburt', 'birth', 'Alter des'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $everything, "{$forbidden} must not reach an inbox");
        }

        // The member's age at the sale — 15 against a limit of 18 — is checked
        // against the **text** part alone. The HTML carries a stylesheet, and a
        // bare "15" matches a padding value in it; asserting over the markup
        // would be testing the layout's CSS rather than this message's content.
        $this->assertStringNotContainsString('15', $mail->text, 'the member\'s own age must not reach an inbox');
        $this->assertStringContainsString('18', $mail->text, 'but what the drink required must');
    }

    /**
     * The sale is stated in the club's time, not in the UTC it is stored in
     * (#637).
     *
     * The instant here is 21:14 UTC, which is 23:14 in Berlin in August. The
     * message used to print the column verbatim — `2026-08-20 21:14:00` — so a
     * committee member reading it about a sale they watched happen at quarter
     * past eleven had two hours and a database format between them and
     * recognising it.
     */
    public function test_the_sale_is_stated_in_the_clubs_time(): void
    {
        $this->aSale();

        $mail = $this->builder->build($this->outboxRow(), $this->mailConfig);

        $this->assertStringContainsString('20.08.2026, 23:14', $mail->text);
        $this->assertStringNotContainsString('2026-08-20 21:14:00', $mail->text);
        $this->assertStringNotContainsString('2026-08-20 21:14:00', $mail->html);
    }

    /**
     * The required age comes from the occasion, not from the product.
     *
     * Invariant 4 again: un-restricting the drink afterwards must not rewrite
     * what the message says was breached. Reading `min_age` live would do
     * exactly that, and the mail could then contradict the audit entry it is
     * announcing.
     */
    public function test_the_required_age_survives_the_drink_being_un_restricted(): void
    {
        $this->transactionsRepository->method('findById')->willReturn([
            'id' => 'tx-1', 'member_id' => 'm', 'product_id' => 'product-1',
            'created_at' => '2026-08-20 21:14:00', 'created_by_terminal_id' => null,
        ]);
        // Cleared since the sale.
        $this->productsRepository->method('findById')->willReturn([
            'id' => 'product-1', 'names' => '{"de":"Pils","en":"Lager"}', 'min_age' => null,
        ]);

        $mail = $this->builder->build($this->outboxRow(['dedup_key' => 'age:16:admin-1']), $this->mailConfig);

        $this->assertStringContainsString('16', $mail->text);
    }

    /**
     * A product deleted between the sale and the drain still produces a
     * message. The incident is what matters, and a warning with a gap in it
     * beats a warning that never arrived.
     */
    public function test_a_deleted_product_does_not_stop_the_message(): void
    {
        $this->transactionsRepository->method('findById')->willReturn([
            'id' => 'tx-1', 'member_id' => 'm', 'product_id' => 'gone',
            'created_at' => '2026-08-20 21:14:00', 'created_by_terminal_id' => null,
        ]);
        $this->productsRepository->method('findById')->willReturn(null);

        $mail = $this->builder->build($this->outboxRow(), $this->mailConfig);

        $this->assertNotSame('', trim($mail->subject));
        $this->assertStringContainsString('18', $mail->text);
    }

    /**
     * A transaction that is gone is a different matter: with no sale there is
     * nothing truthful to say, and `subject_id` carries no foreign key, so this
     * is reachable rather than theoretical.
     */
    public function test_a_missing_transaction_refuses_to_invent_a_message(): void
    {
        $this->transactionsRepository->method('findById')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->builder->build($this->outboxRow(), $this->mailConfig);
    }

    /**
     * The name in the greeting comes from the **unfiltered** account list, and
     * must keep doing so (#633).
     *
     * Narrowing the fan-out was a change to `AdminNotifier`, not to this: the
     * row already names the admin it was written to, and re-resolving that id
     * through a role filter would blank the greeting for anyone whose roles
     * moved after the row was queued — or, before the notice is even sent, for
     * an account the filter would not write to today.
     */
    public function testItGreetsTheAdminTheRowWasWrittenToWithoutConsultingTheirRoles(): void
    {
        $this->aSale();

        $adminUsers = $this->createMock(AdminUsersRepository::class);
        $adminUsers->expects($this->never())->method('findActiveRecipientsWithAnyRole');
        $adminUsers->method('findActiveRecipients')->willReturn([
            ['id' => 'admin-1', 'email' => 'kassenwart@example.com', 'locale' => 'de', 'display_name' => 'Kassenwart Klaus'],
        ]);

        $builder = new JugendschutzViolationMailBuilder(
            $this->transactionsRepository,
            $this->productsRepository,
            $adminUsers,
        );

        $message = $builder->build($this->outboxRow(), $this->mailConfig);

        $this->assertStringContainsString('Kassenwart Klaus', $message->text);
        $this->assertSame('kassenwart@example.com', $message->to);
    }
}
