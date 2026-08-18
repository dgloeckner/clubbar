<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\Contracts\MailContentBuilder;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\DTOs\TerminalTokenIssuedDataDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\MailFormat;
use App\Modules\Notifications\Mail\TerminalTokenIssuedMail;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Shared\Mail\MailMessage;

/**
 * Renders the issuance notice at send time (ADR-0043, ADR-0038 rule 5).
 *
 * The outbox stores what a message is *about*, never the message: a kind, a
 * subject and a dedup key. Everything else is re-derived here from current
 * state, which is what lets a row survive three retries and still describe the
 * world as it is when it finally leaves.
 *
 * Two facts have to come back out of the `dedup_key`, because there is nowhere
 * else for them to live. `NotificationsService::warnAdmins()` writes that key as
 * `occasion:adminUserId`, and this class owns both halves of the occasion —
 * {@see occasion()} builds it, {@see parseOccasion()} reads it — so the format
 * cannot drift between the service that queues and the builder that renders.
 *
 * **The event** (`enrolled` or `rotated`) is not recoverable from the terminal
 * row: a rotation whose replacement has since been promoted looks exactly like
 * an enrolment. It is therefore carried.
 *
 * **The generation** — the token's `token_issued_at` to the second, the same
 * stamp {@see CredentialExpiryNotifier::tokenGeneration()} produces — does two
 * jobs. It makes a second rotation of one terminal a second message rather than
 * one the unique index swallows, and it is the issuance moment the message
 * reports, so that is read straight back off the key rather than looked up.
 *
 * The **expiry** is the one field that is looked up, and it can legitimately be
 * missing: the credential this message announces may have been revoked, or
 * rotated again, between enqueue and drain. {@see expiresAt()} answers null
 * rather than reaching for whichever token happens to be on the row now — a
 * notice about Friday's credential must not quote Monday's date — and the
 * template drops the row instead of printing a blank.
 */
class TerminalTokenIssuedMailBuilder implements MailContentBuilder
{
    /** A device was enrolled and given its first credential. */
    public const EVENT_ENROLLED = 'enrolled';

    /** An existing terminal had a replacement staged beside its current one. */
    public const EVENT_ROTATED = 'rotated';

    private const EVENTS = [self::EVENT_ENROLLED, self::EVENT_ROTATED];

    public function __construct(
        private TerminalsRepository $terminalsRepository,
        private AdminUsersRepository $adminUsersRepository,
    ) {}

    public function supports(MailKind $kind): bool
    {
        return $kind === MailKind::TERMINAL_TOKEN_ISSUED;
    }

    /**
     * The occasion for one issuance: what happened, and to which generation.
     *
     * `enrolled:20260816142317`. Twenty-three characters, which is the number
     * that mattered: `warnAdmins()` appends `:` and a 36-character admin id into
     * a VARCHAR(64), leaving 27 for the occasion. The same arithmetic constrains
     * {@see CredentialExpiryNotifier::occasion()} and
     * {@see \App\Modules\Terminals\Services\TerminalAnomalyDetector::mail()}.
     *
     * @param string      $event    One of the `EVENT_*` constants.
     * @param string|null $issuedAt `token_issued_at` for an enrolment,
     *                              `pending_token_issued_at` for a rotation.
     */
    public static function occasion(string $event, ?string $issuedAt): string
    {
        return $event . ':' . CredentialExpiryNotifier::tokenGeneration($issuedAt);
    }

    /**
     * Split `event:generation:adminUserId` back into its first two parts.
     *
     * @return array{0: string, 1: string}
     */
    public static function parseOccasion(string $dedupKey): array
    {
        $parts = explode(':', $dedupKey);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    /**
     * @param array<string,mixed> $outboxRow A row as `claimBatch()` returns it.
     *
     * @throws \RuntimeException When the terminal is gone, or the row names no
     *         known event. `subject_id` is polymorphic and carries no foreign
     *         key, so the first is reachable — and a security notice must not be
     *         invented around either gap. The drain records the failure against
     *         the message, where an admin can see that something was meant to be
     *         said.
     */
    public function build(array $outboxRow, MailConfigDto $mailConfig): MailMessage
    {
        $terminalId = (string) $outboxRow['subject_id'];
        $terminal = $this->terminalsRepository->findById($terminalId);

        if ($terminal === null) {
            throw new \RuntimeException(sprintf(
                'Cannot build a terminal token issuance notice: terminal %s no longer exists',
                $terminalId,
            ));
        }

        [$event, $generation] = self::parseOccasion((string) ($outboxRow['dedup_key'] ?? ''));

        if (!in_array($event, self::EVENTS, true)) {
            throw new \RuntimeException(sprintf(
                'Cannot build a terminal token issuance notice: %s names no known issuance event',
                (string) ($outboxRow['dedup_key'] ?? ''),
            ));
        }

        $language = MailLanguage::fromPreferred($outboxRow['language'] ?? null);

        return TerminalTokenIssuedMail::render(new TerminalTokenIssuedDataDto(
            language: $language,
            // The row's snapshot, never re-read from admin_users: it is the
            // record of who was written to, and the address may have changed
            // since — the rule every other builder follows.
            recipientAddress: (string) $outboxRow['recipient'],
            recipientName: $this->recipientName($outboxRow),
            branding: $mailConfig->toBranding(),
            terminalName: (string) $terminal['name'],
            deviceId: (string) $terminal['device_id'],
            event: $event,
            actorLabel: $this->actorLabel($outboxRow),
            issuedOn: MailFormat::dateTime(self::issuedAt($generation), $language),
            expiresOn: MailFormat::date(self::expiresAt($terminal, $generation), $language),
        ));
    }

    /**
     * The issuance moment, back out of the generation stamp.
     *
     * `20260816142317` → `2026-08-16 14:23:17`. Read from the key rather than
     * from the row because the row may no longer hold this generation at all,
     * and because the two would then be two sources for one fact.
     *
     * `0` is the stamp {@see CredentialExpiryNotifier::tokenGeneration()}
     * produces for a row with no issuance timestamp — a hand-edited one. It
     * answers null, and the template drops the line rather than printing an
     * invented date.
     */
    private static function issuedAt(string $generation): ?string
    {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', $generation, $m)) {
            return null;
        }

        return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
    }

    /**
     * When the announced credential expires — or null if the row no longer
     * carries it.
     *
     * Matched by generation rather than taken from whichever column is
     * populated. A rotation stages the new token in the pending columns and the
     * first sync that presents it promotes it into the active ones, so the same
     * credential lives under two names over its life and both have to be
     * checked. A stamp that matches neither means this generation is gone —
     * revoked, or superseded by a later rotation — and there is no honest date
     * to print.
     *
     * @param array<string,mixed> $terminal
     */
    private static function expiresAt(array $terminal, string $generation): ?string
    {
        $active = CredentialExpiryNotifier::tokenGeneration($terminal['token_issued_at'] ?? null);
        if ($generation !== '0' && $active === $generation) {
            return $terminal['token_expires_at'] ?? null;
        }

        $pending = CredentialExpiryNotifier::tokenGeneration($terminal['pending_token_issued_at'] ?? null);
        if ($generation !== '0' && $pending === $generation) {
            return $terminal['pending_token_expires_at'] ?? null;
        }

        return null;
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

    /**
     * Who acted, formatted for the "Ausgeführt von" row — `display name (login)`,
     * or just the login when no display name is set. Empty when the row predates
     * migration 043, or when the actor's admin account was later deleted
     * (`ON DELETE SET NULL`); the template drops the line rather than print one.
     *
     * @param array<string,mixed> $outboxRow
     */
    private function actorLabel(array $outboxRow): string
    {
        $actorId = $outboxRow['actor_admin_user_id'] ?? null;
        if (!is_string($actorId) || $actorId === '') {
            return '';
        }

        $actor = $this->adminUsersRepository->findById($actorId);
        if ($actor === null) {
            return '';
        }

        $login = trim((string) ($actor['email'] ?? ''));
        if ($login === '') {
            return '';
        }

        $name = trim((string) ($actor['display_name'] ?? ''));

        return $name !== '' ? sprintf('%s (%s)', $name, $login) : $login;
    }
}
