<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\CreditLimits\Services\CreditLimitConfigService;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Notifications\DTOs\DeckelStatementDataDto;
use App\Modules\Notifications\DTOs\StatementLineDto;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\MailStrings;
use App\Modules\Notifications\Repositories\DeckelStatementRepository;
use DateTimeImmutable;

/**
 * A member plus a boundary in, one statement's worth of content out
 * (ADR-0039 decision 4).
 *
 * The whole of the netting, the cap and the total lives here, and it is one
 * pass over one set. That is the point rather than an implementation detail:
 * ADR-0039 requires the printed lines and the stated total to be derived from
 * the same collection, so that „no double counting" is a property of the shape
 * of this code rather than something a test has to keep watch over. A second
 * aggregate computed anywhere else — a `SUM()` in SQL beside a netting pass in
 * PHP — could disagree with the lines, and a statement whose column does not
 * add up is the one failure a member is certain to notice.
 *
 * ### Netting, in three cases
 *
 * | Both in the set | A purchase and its Storno cancel; **both** lines vanish |
 * | Only the Storno | Its original was collected earlier, so it stands alone as a negative line naming what it reverses |
 * | Only the purchase | The Storno happened *after* the boundary, and on this date the member had bought a drink. It stands |
 *
 * Removing a matched pair cannot move the total — the two amounts are exact
 * negatives of each other (`TransactionsService::storno()`) — which is why
 * netting is safe to do before summing rather than after.
 *
 * Nothing here reads the clock. The boundary arrives as an argument, from
 * {@see \App\Modules\Notifications\Domain\StatementPeriod::boundary()}, so a
 * statement retried three days late states exactly what it stated on the first
 * attempt.
 */
class DeckelStatementService
{
    public function __construct(
        private DeckelStatementRepository $statementRepository,
        private MembersRepository $membersRepository,
        private MailConfigService $mailConfigService,
        private CreditLimitConfigService $creditLimitConfigService,
    ) {}

    /**
     * @param string $recipientAddress The snapshot from `mail_outbox.recipient`,
     *        never re-read from the member — same rule as the pre-notification:
     *        the column is the proof of who was written to.
     */
    public function forMember(
        string $memberId,
        DateTimeImmutable $boundary,
        MailLanguage $language,
        string $recipientAddress,
    ): DeckelStatementDataDto {
        $t = new MailStrings($language);
        $rows = $this->statementRepository->linesAsOf($memberId, $boundary);
        $netted = self::net($rows);

        $lines = $this->toLines($netted, $boundary, $language, $t);

        // Over the *full* netted set, before the cap touches anything.
        $totalCents = 0;
        foreach ($lines as $line) {
            $totalCents += $line->amountCents;
        }

        $omitted = max(0, count($lines) - DeckelStatementDataDto::MAX_LINES);
        if ($omitted > 0) {
            $lines = array_slice($lines, 0, DeckelStatementDataDto::MAX_LINES);
        }

        // Null for a soft-deleted member — `findMailRecipients()` excludes them,
        // and one who still owes is in scope for a statement (ADR-0039). The
        // greeting degrades to „Hallo," rather than the statement failing, which
        // is the right way round: the tab is what they need, the first name is a
        // courtesy.
        $member = $this->membersRepository->findMailRecipients([$memberId])[$memberId] ?? null;
        $mailConfig = $this->mailConfigService->getConfig();
        // This member's own ceiling where they have one, the club default where
        // they do not (ADR-0046). A statement naming the club figure while the
        // terminal enforces another tells the member a number nobody is using.
        $creditLimit = $this->creditLimitConfigService->policy()->forMember(
            isset($member['credit_limit_cents']) ? (int) $member['credit_limit_cents'] : null,
        );

        return new DeckelStatementDataDto(
            language: $language,
            recipientAddress: $recipientAddress,
            recipientName: self::nullIfBlank($member === null
                ? null
                : trim(((string) $member['first_name']) . ' ' . ((string) $member['last_name']))),
            salutationName: self::nullIfBlank($member['first_name'] ?? null),
            branding: $mailConfig->toBranding(),
            boundaryDate: $boundary->format('Y-m-d'),
            totalCents: $totalCents,
            lines: $lines,
            omittedLines: $omitted,
            creditLimitCents: $creditLimit->limitCents,
            creditStatus: $creditLimit->status($totalCents),
            treasurerEmail: $mailConfig->replyToAddress,
        );
    }

    /**
     * Drop every purchase whose Storno is in the same set, and the Storno with
     * it.
     *
     * A pair matches only when **both** halves are in this set. That single
     * condition is the difference between the two cases ADR-0039 separates: a
     * Storno whose original is here cancels it, and a Storno whose original was
     * collected in an earlier settlement finds nothing to match and survives as
     * the orphan.
     *
     * No counting is needed: `related_transaction_id` is unique among stornos
     * (#169), so a purchase can be cancelled at most once.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function net(array $rows): array
    {
        $present = [];
        foreach ($rows as $row) {
            $present[(string) $row['id']] = true;
        }

        // Both halves, and only when both are here. A Storno whose original was
        // collected in an earlier settlement finds nothing in `$present` and is
        // therefore not matched — it is the orphan, and it stays.
        $matched = [];
        foreach ($rows as $row) {
            $original = $row['related_transaction_id'] ?? null;
            if (self::isStorno($row) && is_string($original) && isset($present[$original])) {
                $matched[$original] = true;
                $matched[(string) $row['id']] = true;
            }
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => !isset($matched[(string) $row['id']]),
        ));
    }

    /**
     * @param list<array<string,mixed>> $rows Already netted.
     * @return list<StatementLineDto>
     */
    private function toLines(array $rows, DateTimeImmutable $boundary, MailLanguage $language, MailStrings $t): array
    {
        $orphanOriginals = [];
        foreach ($rows as $row) {
            $original = $row['related_transaction_id'] ?? null;
            if (self::isStorno($row) && is_string($original) && $original !== '') {
                $orphanOriginals[] = $original;
            }
        }

        // Usually empty, and then this does not query at all.
        $originals = $orphanOriginals === []
            ? []
            : $this->statementRepository->originals($orphanOriginals, $boundary);

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = new StatementLineDto(
                label: $this->label($row, $originals, $language, $t),
                occurredAt: $row['occurred_at'] ?? null,
                amountCents: (int) $row['amount_cents'],
            );
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string, array{product_names: ?string, notes: ?string, settlement_date: ?string}> $originals
     */
    private function label(array $row, array $originals, MailLanguage $language, MailStrings $t): string
    {
        $product = self::productName($row['product_names'] ?? null, $language);
        if ($product !== null) {
            return $product;
        }

        if (self::isStorno($row)) {
            $original = $originals[(string) ($row['related_transaction_id'] ?? '')] ?? null;

            $reversed = $original === null
                ? null
                : (self::productName($original['product_names'], $language) ?? self::nullIfBlank($original['notes']));

            // The Storno's own note is the reason it was booked; without a
            // product to name it is the only description there is.
            $label = $t->t('statement.line_storno', [
                'label' => $reversed ?? trim((string) ($row['notes'] ?? '')),
            ]);

            $settlementDate = $original['settlement_date'] ?? null;
            if (is_string($settlementDate) && $settlementDate !== '') {
                $label = $t->t('statement.line_storno_from', [
                    'label' => $label,
                    'period' => date('m/Y', (int) strtotime($settlementDate)),
                ]);
            }

            return trim($label);
        }

        $note = trim((string) ($row['notes'] ?? ''));

        return $note !== '' ? $note : (string) ($row['transaction_type'] ?? '');
    }

    /** The product's name in the member's language, falling back the way ADR-0002 does. */
    private static function productName(mixed $names, MailLanguage $language): ?string
    {
        if (!is_string($names) || trim($names) === '') {
            return null;
        }

        $decoded = json_decode($names, true);
        if (!is_array($decoded) || $decoded === []) {
            return null;
        }

        $name = $decoded[$language->value] ?? $decoded['de'] ?? $decoded['en'] ?? reset($decoded);

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    /** @param array<string,mixed> $row */
    private static function isStorno(array $row): bool
    {
        return ($row['transaction_type'] ?? null) === 'storno';
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
