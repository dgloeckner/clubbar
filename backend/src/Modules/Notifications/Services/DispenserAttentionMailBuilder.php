<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\DTOs\DispenserAttentionDataDto;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\DispenserAttentionOccasion;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\DispenserAttentionMail;
use App\Modules\Notifications\Mail\MailFormat;
use App\Modules\Terminals\Enums\DispenserUnavailableReason;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Terminals\Services\DispenserFillService;
use App\Shared\Mail\MailMessage;

/**
 * Renders a dispenser notice at send time (#956, ADR-0038 rule 5).
 *
 * The queue row carries almost nothing: the terminal in `subject_id` and the
 * occasion in `dedup_key`. Everything the reader sees — the condition, how long
 * it has held, how full the hopper probably is — is read here, from the same
 * columns the panel reads.
 *
 * That is the rule every builder follows, and it pays twice in this one:
 *
 * - **A fault that ended is not reported as current.** A jam cleared between
 *   the scan and the drain renders as *the problem has cleared*, the call
 *   {@see BackupHealthMailBuilder} makes for the same situation. Refusing to
 *   render instead would put a red row in the Notifications page for a machine
 *   somebody had just fixed — and an admin who has seen that twice stops
 *   reading the page that is supposed to tell them when mail fails.
 * - **The hopper figure is true when the mail is sent**, not when the scan ran.
 *
 * The **occasion** comes from the `dedup_key`, which is the only place it is
 * recorded — the seam {@see CredentialExpiryMailBuilder} uses for the warning
 * tier. A row whose key names no occasion is not guessed at: it throws, and the
 * drain records that against the message.
 */
class DispenserAttentionMailBuilder implements MailContentBuilder
{
    /**
     * Where the dispenser is shown. The tabs on the settings page are local
     * state rather than routes, so this links the page and names the tab in
     * words — a made-up `#terminals` fragment would be a link that silently
     * lands somewhere else.
     */
    private const PANEL_PATH = '/settings';

    public function __construct(
        private TerminalsRepository $terminals,
        private DispenserFillService $fill,
        private AdminUsersRepository $adminUsersRepository,
        /** The installation's own base URL (`APP_URL`), as the invitation builder takes it. */
        private string $appUrl = '',
    ) {}

    public function supports(MailKind $kind): bool
    {
        return $kind === MailKind::DISPENSER_ATTENTION;
    }

    /**
     * @param array<string,mixed> $outboxRow A row as `claimBatch()` returns it.
     *
     * @throws \RuntimeException When the row names no occasion, or the terminal
     *         it points at is gone. `subject_id` is polymorphic and carries no
     *         foreign key, so the second is reachable — and a notice about a
     *         machine that no longer exists must not be invented around the gap.
     */
    public function build(array $outboxRow, MailConfigDto $mailConfig): MailMessage
    {
        $dedupKey = (string) ($outboxRow['dedup_key'] ?? '');
        $occasion = DispenserAttentionOccasion::fromDedupKey($dedupKey);
        if ($occasion === null) {
            throw new \RuntimeException(sprintf(
                'Cannot build a dispenser notice: %s names no known occasion',
                $dedupKey,
            ));
        }

        $terminalId = (string) $outboxRow['subject_id'];
        $terminal = $this->terminals->findById($terminalId);
        if ($terminal === null) {
            throw new \RuntimeException(sprintf(
                'Cannot build a dispenser notice: terminal %s no longer exists',
                $terminalId,
            ));
        }

        $document = self::document($terminal['dispenser_status'] ?? null);
        $reason = self::reasonOf($document);
        $fill = $this->fill->readFor($terminal);

        $cleared = $occasion === DispenserAttentionOccasion::LOW
            // Somebody refilled, or the estimate lost its anchor. Either way
            // the errand this row was written for is done.
            ? !$fill->isLow()
            // The condition that produced this row is gone — recovered, or
            // replaced by a different one, which gets its own notice with its
            // own episode rather than quietly inheriting this one's.
            : ($reason === null || DispenserAttentionOccasion::forReason($reason) !== $occasion);

        $language = MailLanguage::fromPreferred((string) ($outboxRow['language'] ?? null));

        return DispenserAttentionMail::render(new DispenserAttentionDataDto(
            language: $language,
            // The row's snapshot, never re-read from `admin_users`: it is the
            // record of who was written to, and the address may have changed
            // since — the rule every other builder follows.
            recipientAddress: (string) $outboxRow['recipient'],
            recipientName: $this->recipientName($outboxRow),
            branding: $mailConfig->toBranding(),
            terminalName: (string) $terminal['name'],
            occasion: $occasion,
            cleared: $cleared,
            reason: $reason,
            faultCode: (int) ($document['fault_code'] ?? 0),
            since: MailFormat::dateTime(
                is_string($document['state_since'] ?? null) ? $document['state_since'] : null,
                $language,
            ) ?: null,
            estimatedLeft: $fill->estimatedLeft(),
            lowThreshold: $fill->lowThreshold,
            // ADR-0058's one place where the two facts meet: the machine cannot
            // tell a jam from an empty hopper, so the badge stays „Stau oder
            // leer" and the books add what they suspect beside it.
            probablyEmpty: $occasion === DispenserAttentionOccasion::FAULT
                && $reason === DispenserUnavailableReason::JAM
                && $fill->isExhausted(),
            panelUrl: $this->panelUrl(),
        ));
    }

    private function panelUrl(): ?string
    {
        $base = rtrim(trim($this->appUrl), '/');

        return $base === '' ? null : $base . self::PANEL_PATH;
    }

    /** @return array<string, mixed>|null */
    private static function document(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed>|null $document */
    private static function reasonOf(?array $document): ?DispenserUnavailableReason
    {
        if ($document === null || ($document['configured'] ?? null) !== true) {
            return null;
        }

        $reason = $document['unavailable_reason'] ?? null;

        return is_string($reason) ? DispenserUnavailableReason::tryFrom($reason) : null;
    }

    /** @param array<string,mixed> $outboxRow */
    private function recipientName(array $outboxRow): ?string
    {
        $adminUserId = $outboxRow['admin_user_id'] ?? null;
        if (!is_string($adminUserId) || $adminUserId === '') {
            return null;
        }

        $admin = $this->adminUsersRepository->findById($adminUserId);
        $name = trim((string) ($admin['display_name'] ?? ''));

        return $name !== '' ? $name : null;
    }
}
