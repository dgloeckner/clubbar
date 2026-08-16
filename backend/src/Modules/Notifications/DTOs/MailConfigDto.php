<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

use App\Modules\Notifications\Enums\CronInterval;
use App\Modules\Notifications\Enums\StatementCadence;
use App\Shared\Mail\MailBranding;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailSender;

final readonly class MailConfigDto
{
    /**
     * Messages claimed per drain round when the column says nothing — a row
     * written before migration 029, or a restore that lost it.
     */
    public const DEFAULT_DRAIN_BATCH_SIZE = 100;

    /**
     * The ceiling the API enforces and this DTO clamps to.
     *
     * Not a performance limit — the transport is serial either way — but a
     * bound on how many messages a killed run can leave claimed, and a guard
     * against a typo turning a batch dial into an hour-long run.
     */
    public const MAX_DRAIN_BATCH_SIZE = 1000;

    /**
     * Wall-clock seconds one drain run may spend when the column says nothing.
     *
     * Twenty-five rather than the fifty this was before #473, because the
     * number that decides it is a timeout we cannot see: the trigger's. An
     * external HTTP scheduler commonly gives a request 30 seconds, IONOS's own
     * cron 60. Overshooting means a run killed *mid-send*, whose claimed rows
     * come back after the stale window and are offered to the transport again —
     * one announcement delivered twice. Undershooting only means the next tick
     * finishes the queue. The default is therefore safe under the tightest
     * known trigger, and a host with a roomier one raises it.
     */
    public const DEFAULT_DRAIN_BUDGET_SECONDS = 25;

    /**
     * The floor the API enforces.
     *
     * Ten seconds is enough for a handful of messages on a slow relay; below it
     * a run would spend more of itself starting up than sending, and a queue
     * that only ever clears one message per tick is indistinguishable from a
     * stalled one.
     */
    public const MIN_DRAIN_BUDGET_SECONDS = 10;

    /**
     * The ceiling the API enforces and this DTO clamps to.
     *
     * Fifty-five keeps the value under the most generous trigger timeout this
     * project supports (IONOS's 60-second cron cap, ADR-0031). A budget above
     * whatever is triggering the run does not buy a longer run; it buys a run
     * that is killed rather than one that stops.
     */
    public const MAX_DRAIN_BUDGET_SECONDS = 55;

    public function __construct(
        public string $senderName,
        public string $senderAddress,
        public ?string $replyToAddress,
        public string $headerStyle,
        public string $footerOrgName,
        public ?string $footerAddressLine,
        public ?string $websiteUrl,
        public ?string $logoUrl,
        public CronInterval $cronInterval = CronInterval::DEFAULT,
        public int $drainBatchSize = self::DEFAULT_DRAIN_BATCH_SIZE,
        public int $drainBudgetSeconds = self::DEFAULT_DRAIN_BUDGET_SECONDS,
        /**
         * How often a Deckelauszug goes out (ADR-0039 decision 3). Defaults to
         * `off` rather than to {@see StatementCadence::DEFAULT}: a value this
         * build could not read is not a reason to mail an entire membership.
         */
        public StatementCadence $statementCadence = StatementCadence::OFF,
        /**
         * SHA-256 hex, never a plaintext secret. `null` means no rotation from
         * the admin panel has ever happened — `config.php`'s `cron.secret` is
         * still the fallback (#473). Not part of {@see toArray()}: a hash has
         * no operator value once past the constant-time comparison it exists
         * for, and there is no reason to widen its exposure.
         */
        public ?string $cronSecretHash = null,
        public ?string $cronSecretRotatedAt = null,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self(
            senderName: (string) ($row['sender_name'] ?? ''),
            senderAddress: (string) ($row['sender_address'] ?? ''),
            replyToAddress: self::nullIfBlank($row['reply_to_address'] ?? null),
            headerStyle: (string) ($row['header_style'] ?? MailLayout::DEFAULT_HEADER_STYLE),
            footerOrgName: (string) ($row['footer_org_name'] ?? ''),
            footerAddressLine: self::nullIfBlank($row['footer_address_line'] ?? null),
            websiteUrl: self::nullIfBlank($row['website_url'] ?? null),
            logoUrl: self::nullIfBlank($row['logo_url'] ?? null),
            cronInterval: CronInterval::fromDeclared($row['cron_interval'] ?? null),
            drainBatchSize: self::batchSize($row['drain_batch_size'] ?? null),
            drainBudgetSeconds: self::budgetSeconds($row['drain_budget_seconds'] ?? null),
            statementCadence: StatementCadence::fromDeclared($row['statement_cadence'] ?? null),
            cronSecretHash: self::nullIfBlank($row['cron_secret_hash'] ?? null),
            cronSecretRotatedAt: self::nullIfBlank($row['cron_secret_rotated_at'] ?? null),
        );
    }

    /**
     * Can this configuration address an envelope at all?
     *
     * The sender address is the one field with no workable default: the
     * display name can fall back to the instance name and the footer to the
     * club name, but nothing can invent a mailbox. An install that never set
     * it is reported by the self-check rather than sending From: <>.
     */
    public function isComplete(): bool
    {
        return $this->senderAddress !== '';
    }

    public function toSender(): MailSender
    {
        return new MailSender(
            address: $this->senderAddress,
            name: $this->senderName !== '' ? $this->senderName : $this->footerOrgName,
            replyTo: $this->replyToAddress,
        );
    }

    /**
     * @param array<string,string> $footerLinks Label => URL, supplied by the caller
     *                                          because the labels are translated (#404)
     */
    public function toBranding(array $footerLinks = []): MailBranding
    {
        return new MailBranding(
            orgName: $this->footerOrgName,
            addressLine: $this->footerAddressLine,
            baseUrl: $this->websiteUrl,
            logoSrc: $this->logoUrl,
            headerStyle: $this->headerStyle,
            footerLinks: $footerLinks,
        );
    }

    public function toArray(): array
    {
        return [
            'sender_name' => $this->senderName,
            'sender_address' => $this->senderAddress,
            'reply_to_address' => $this->replyToAddress,
            'header_style' => $this->headerStyle,
            'footer_org_name' => $this->footerOrgName,
            'footer_address_line' => $this->footerAddressLine,
            'website_url' => $this->websiteUrl,
            'logo_url' => $this->logoUrl,
            'cron_interval' => $this->cronInterval->value,
            'drain_batch_size' => $this->drainBatchSize,
            'drain_budget_seconds' => $this->drainBudgetSeconds,
            'statement_cadence' => $this->statementCadence->value,
            // Never the hash — only whether one exists and when it was made,
            // which is what the admin panel needs to show a rotate button
            // and a "last rotated" line without any secret value at all.
            'cron_secret_configured' => $this->cronSecretHash !== null,
            'cron_secret_rotated_at' => $this->cronSecretRotatedAt,
            'is_complete' => $this->isComplete(),
        ];
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);
        return $value === '' ? null : $value;
    }

    /**
     * A missing, zero or absurd value falls back rather than being honoured.
     *
     * Zero would claim nothing and drain nothing while looking configured,
     * which is the one failure mode a batch dial must not have.
     */
    private static function batchSize(mixed $value): int
    {
        $size = (int) $value;

        if ($size < 1) {
            return self::DEFAULT_DRAIN_BATCH_SIZE;
        }

        return min($size, self::MAX_DRAIN_BATCH_SIZE);
    }

    /**
     * Same shape as {@see batchSize()}, and clamped rather than trusted for the
     * same reason: a column written before migration 040 reads as 0, and a run
     * with a zero-second budget stops before claiming anything while looking
     * perfectly configured. A value above the ceiling is a run that gets killed
     * instead of one that stops, so it is brought back down rather than
     * honoured.
     */
    private static function budgetSeconds(mixed $value): int
    {
        $seconds = (int) $value;

        if ($seconds < self::MIN_DRAIN_BUDGET_SECONDS) {
            return self::DEFAULT_DRAIN_BUDGET_SECONDS;
        }

        return min($seconds, self::MAX_DRAIN_BUDGET_SECONDS);
    }
}
