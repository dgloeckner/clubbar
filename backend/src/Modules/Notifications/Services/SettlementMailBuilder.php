<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\DTOs\CancellationNoticeDataDto;
use App\Modules\Notifications\DTOs\PreNotificationDataDto;
use App\Modules\Notifications\DTOs\StatementLineDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Enums\MailSubject;
use App\Modules\Notifications\Mail\CancellationNoticeMail;
use App\Modules\Notifications\Mail\PreNotificationMail;
use App\Modules\Settlements\Repositories\SepaConfigRepository;
use App\Modules\Settlements\Repositories\SettlementsRepository;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Mail\MailMessage;

/**
 * Turns one queued row into one message, at send time (ADR-0038 rule 5).
 *
 * Nothing is stored. The outbox has no body column, and the content is
 * reassembled here from the settlement, its items, the creditor configuration
 * and the club's mail settings — which is only sound because ADR-0032 makes a
 * settlement append-only: the amount, the line items and the execution date
 * cannot have drifted between enqueue and send.
 *
 * The one field that is **not** re-derived is the recipient address. That comes
 * from the outbox row, because it is a snapshot on purpose: it is the proof of
 * who was announced to, and `members.email` may have changed or been erased
 * since. Re-reading it here would quietly send the announcement somewhere else.
 *
 * Everything this class produces is a `MailMessage`; it does not send. The
 * drain (#403) sends, and it is the only thing that does.
 */
class SettlementMailBuilder implements MailContentBuilder
{
    public function __construct(
        private SettlementsRepository $settlementsRepository,
        private MembersRepository $membersRepository,
        private SepaConfigRepository $sepaConfigRepository,
        private MailConfigService $mailConfigService,
    ) {}

    /**
     * The three settlement kinds — everything whose subject is a settlement.
     * `payment_request` is claimed here too so #410 lands as a branch in this
     * class rather than a fourth builder for the same subject.
     */
    public function supports(MailKind $kind): bool
    {
        return $kind->subjectType() === MailSubject::SETTLEMENT;
    }

    /**
     * @param array<string,mixed> $outboxRow A row as `claimBatch()` returns it.
     *
     * @throws NotFoundException  When the settlement behind the row is gone —
     *         which cannot happen through the application (the FK cascades), so
     *         it means somebody deleted rows by hand and the message must not
     *         be invented around the gap.
     * @throws \InvalidArgumentException On a kind this builder has no content
     *         for — `payment_request` until #410 lands.
     */
    public function build(array $outboxRow): MailMessage
    {
        $kind = MailKind::from((string) $outboxRow['kind']);
        $settlementId = (string) $outboxRow['subject_id'];
        $memberId = (string) $outboxRow['member_id'];
        $language = MailLanguage::fromPreferred((string) ($outboxRow['language'] ?? null));

        $settlement = $this->settlementsRepository->findById($settlementId);
        if ($settlement === null) {
            throw NotFoundException::forResource('Settlement', $settlementId);
        }

        $items = array_values(array_filter(
            $this->settlementsRepository->findItemsBySettlementId($settlementId),
            static fn (array $item): bool => (string) $item['member_id'] === $memberId,
        ));

        $amountCents = (int) array_sum(array_column($items, 'amount_cents'));
        $member = $this->membersRepository->findMailRecipients([$memberId])[$memberId] ?? null;
        $mailConfig = $this->mailConfigService->getConfig();
        $branding = $mailConfig->toBranding();
        // Read once per message: the drain builds a batch in a loop, and the
        // creditor block contributes three of the announcement's fields.
        $sepa = $this->sepaConfigRepository->getConfig() ?? [];

        // The recipient's *name* may be re-read: it is a courtesy, not
        // evidence. The address may not — see the class docblock.
        //
        // Two different names, on purpose. The envelope carries the full one,
        // because that is what a mailbox lists and what makes the message
        // recognisable in a crowded inbox. The salutation carries the first
        // name alone, because the club addresses its members by it.
        $recipientName = self::nullIfBlank($member === null
            ? null
            : trim(((string) $member['first_name']) . ' ' . ((string) $member['last_name'])));
        $salutation = self::nullIfBlank($member['first_name'] ?? null);

        return match ($kind) {
            MailKind::SEPA_PRENOTIFICATION => PreNotificationMail::render(new PreNotificationDataDto(
                language: $language,
                recipientAddress: (string) $outboxRow['recipient'],
                recipientName: $recipientName,
                salutationName: $salutation,
                branding: $branding,
                amountCents: $amountCents,
                dueDate: (string) $settlement['execution_date'],
                creditorName: self::nullIfBlank($sepa['creditor_name'] ?? null),
                creditorAddress: self::creditorAddress($sepa),
                creditorId: self::nullIfBlank($sepa['creditor_id'] ?? null),
                mandateReference: $member['mandate_reference'] ?? null,
                accountLast4: $member['iban_last4'] ?? null,
                lines: self::statementLines($items),
                periodStart: $settlement['period_start'] ?? null,
                periodEnd: $settlement['period_end'] ?? null,
                treasurerEmail: $mailConfig->replyToAddress,
            )),

            MailKind::CANCELLATION_NOTICE => CancellationNoticeMail::render(new CancellationNoticeDataDto(
                language: $language,
                recipientAddress: (string) $outboxRow['recipient'],
                recipientName: $recipientName,
                salutationName: $salutation,
                branding: $branding,
                amountCents: $amountCents,
                dueDate: (string) $settlement['execution_date'],
                treasurerEmail: $mailConfig->replyToAddress,
            )),

            MailKind::PAYMENT_REQUEST => throw new \InvalidArgumentException(
                'payment_request has no content yet — it lands with #410, and nothing enqueues it before then'
            ),
        };
    }

    /**
     * The member's own bookings, as the Abrechnungsübersicht lists them.
     *
     * A row with no product is a storno or a manual correction; its note is the
     * only description there is, and an unlabelled negative line would be the
     * least explicable thing in the message.
     *
     * @param list<array<string,mixed>> $items
     * @return list<StatementLineDto>
     */
    private static function statementLines(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = new StatementLineDto(
                label: self::itemLabel($item),
                occurredAt: $item['transaction_created_at'] ?? null,
                amountCents: (int) $item['amount_cents'],
            );
        }

        return $lines;
    }

    /** @param array<string,mixed> $item */
    private static function itemLabel(array $item): string
    {
        if (!empty($item['product_names'])) {
            $names = json_decode((string) $item['product_names'], true);
            if (is_array($names) && $names !== []) {
                $name = $names['de'] ?? $names['en'] ?? reset($names);
                if (is_string($name) && trim($name) !== '') {
                    return $name;
                }
            }
        }

        $note = trim((string) ($item['transaction_notes'] ?? ''));

        return $note !== '' ? $note : (string) ($item['transaction_type'] ?? '');
    }

    /**
     * Street and city on one line; either half may be missing.
     *
     * @param array<string,mixed> $sepa
     */
    private static function creditorAddress(array $sepa): ?string
    {
        $parts = array_filter([
            trim((string) ($sepa['creditor_address_street'] ?? '')),
            trim((string) ($sepa['creditor_address_city'] ?? '')),
        ], static fn (string $part): bool => $part !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
