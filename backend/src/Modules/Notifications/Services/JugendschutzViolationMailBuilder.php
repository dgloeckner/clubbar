<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\DTOs\JugendschutzViolationDataDto;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\JugendschutzViolationMail;
use App\Modules\Products\Repositories\ProductsRepository;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\Mail\MailMessage;

/**
 * Renders the Jugendschutz notice at send time (#622, ADR-0038 rule 5).
 *
 * `subject_id` is the **transaction** — the first kind for which that is true —
 * and everything the message says is rebuilt from it here rather than carried
 * in the queue row, which is what ADR-0038 asks of every builder: the row holds
 * addressing and identity, the content is regenerated from current data.
 *
 * With one deliberate exception. The **required age comes from the `dedup_key`**,
 * not from the product, because the product's `min_age` is current data and the
 * message is about a past sale. Clearing the restriction tomorrow must not
 * rewrite what this notice says was breached — invariant 4 — and reading it live
 * would let the mail contradict the audit entry it is announcing.
 */
class JugendschutzViolationMailBuilder implements MailContentBuilder
{
    public function __construct(
        private TransactionsRepository $transactionsRepository,
        private ProductsRepository $productsRepository,
        private AdminUsersRepository $adminUsersRepository,
    ) {}

    public function supports(MailKind $kind): bool
    {
        return $kind === MailKind::JUGENDSCHUTZ_VIOLATION;
    }

    /**
     * @param array<string,mixed> $outboxRow
     *
     * @throws \RuntimeException When the transaction is gone. `subject_id`
     *         carries no foreign key — it is polymorphic — so this is reachable,
     *         and with no sale there is nothing truthful left to say.
     */
    public function build(array $outboxRow, MailConfigDto $mailConfig): MailMessage
    {
        $transactionId = (string) $outboxRow['subject_id'];
        $transaction = $this->transactionsRepository->findById($transactionId);

        if ($transaction === null) {
            throw new \RuntimeException(sprintf(
                'Cannot build a Jugendschutz notice: transaction %s no longer exists',
                $transactionId,
            ));
        }

        $language = MailLanguage::from((string) ($outboxRow['language'] ?? MailLanguage::German->value));

        return JugendschutzViolationMail::render(new JugendschutzViolationDataDto(
            language: $language,
            // From the row, never re-read from admin_users: it is the snapshot
            // of who was told, and the address may have moved since.
            recipientAddress: (string) $outboxRow['recipient'],
            recipientName: $this->recipientName($outboxRow),
            branding: $mailConfig->toBranding(),
            transactionId: $transactionId,
            requiredAge: self::requiredAgeFrom((string) ($outboxRow['dedup_key'] ?? '')),
            productName: $this->productName($transaction['product_id'] ?? null, $language),
            occurredAt: (string) ($transaction['created_at'] ?? ''),
            terminalName: null,
        ));
    }

    /**
     * Read `age:18` back out of `age:18:<adminUserId>`.
     *
     * `AdminNotifier` builds the dedup key as `occasion:adminUserId`, so the
     * occasion is everything before the last segment — and the occasion this
     * kind uses is the age the drink required at the time.
     */
    public static function requiredAgeFrom(string $dedupKey): int
    {
        $parts = explode(':', $dedupKey);

        return isset($parts[1]) ? (int) $parts[1] : 0;
    }

    /**
     * The drink's name in the recipient's language, or null if it is gone.
     *
     * A product deleted between the sale and the drain does not stop the
     * message: the incident is what matters, and a notice with a gap in it
     * beats a notice that never arrived. The template says "(deleted since)".
     */
    private function productName(mixed $productId, MailLanguage $language): ?string
    {
        if (!is_string($productId) || $productId === '') {
            return null;
        }

        $product = $this->productsRepository->findById($productId);
        if ($product === null) {
            return null;
        }

        $names = json_decode((string) ($product['names'] ?? '{}'), true);
        if (!is_array($names) || $names === []) {
            return null;
        }

        return (string) ($names[$language->value] ?? reset($names));
    }

    /** @param array<string,mixed> $outboxRow */
    private function recipientName(array $outboxRow): ?string
    {
        $adminUserId = $outboxRow['admin_user_id'] ?? null;
        if (!is_string($adminUserId) || $adminUserId === '') {
            return null;
        }

        foreach ($this->adminUsersRepository->findActiveRecipients() as $admin) {
            if ((string) $admin['id'] === $adminUserId) {
                $name = trim((string) ($admin['display_name'] ?? ''));

                return $name !== '' ? $name : null;
            }
        }

        return null;
    }
}
