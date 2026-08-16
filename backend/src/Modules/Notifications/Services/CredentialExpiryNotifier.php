<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\DTOs\CredentialExpiryScanResultDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Shared\Logging\Logger;
use App\Shared\Security\CredentialLifecycle;
use DateTimeImmutable;

/**
 * Tells whoever runs the club that a long-lived credential is running out,
 * without waiting for them to open the admin panel (#438, ADR-0036).
 *
 * ## Why this exists at all
 *
 * ADR-0036 computes the 90/30/7 warnings at request time and shows them on the
 * dashboard and in Settings → Security & Credentials. That is unmissable for an
 * admin who opens the panel, and completely silent for one who does not — and
 * the failure it is silent about is the expensive kind: an encryption key that
 * lapses blocks the SEPA export, a terminal token that lapses locks a till out
 * of the bar on an evening somebody is standing at it.
 *
 * ## Why the trigger is the scheduler, not a request
 *
 * #438 guessed this would have to piggyback on an authenticated admin request,
 * because ADR-0031 promises no cron on shared hosting. That guess was right when
 * it was written and is wrong now, and it is worth being explicit about why,
 * because the whole feature turns on it: **a request-time trigger cannot satisfy
 * the acceptance criterion it was proposed for.** "An admin who does not open
 * the admin panel still receives a warning" and "the warning is queued by an
 * admin opening the admin panel" are contradictory. The version of this that
 * hangs off a dashboard load warns exactly the people who did not need warning.
 *
 * What changed in between is ADR-0038: the mail outbox made a scheduler
 * *mandatory* rather than optional — it is the only sending path, the install
 * gate (#405) refuses to finalize a direct debit without one, and the heartbeat
 * notices when it stops. An installation that can send mail at all therefore has
 * a tick, and this rides it, next to the two scans already there.
 *
 * ## Idempotency
 *
 * The same answer ADR-0039 gives, for the same reason: **the tier goes in the
 * `dedup_key`, and the unique index decides.** Nothing here selects to find out
 * whether it has already warned. It offers a message and counts what the
 * database accepted — which is both faster and correct under two overlapping
 * ticks, where a lookup-then-insert would have both passes find nothing and both
 * insert.
 *
 * The tier is {@see CredentialLifecycle::warningTier()}, and it is the tier
 * *entered* rather than the days remaining: a value that changed on every scan
 * would queue a fresh warning every fifteen minutes for ninety days.
 *
 * The dedup key names the credential's **generation** as well as its tier, for
 * terminal tokens where rotation reuses the subject id. See
 * {@see self::occasion()} for why that segment is carried rather than inferred
 * from how long a delivered row happens to survive.
 *
 * ## Staying optional
 *
 * The scan does nothing on an installation with no mail configured. That is the
 * acceptance criterion's second half — *"a deployment with no mail configured
 * behaves exactly as today"* — and without the gate it would be false in a way
 * that matters rather than a way that is merely untidy: {@see \App\Shared\Mail\NullTransport}
 * records a **permanent failure**, so every warning this scan queued would land
 * in the Notifications page as a red row, on an installation whose owner never
 * asked for mail. Better to queue nothing.
 *
 * Never throws. The caller is the cron tick, whose other job is draining the
 * queue, and a scan that could not read a table must not stop the club's
 * announcements from going out.
 */
class CredentialExpiryNotifier
{
    public function __construct(
        private EncryptionKeysRepository $encryptionKeysRepository,
        private TerminalsRepository $terminalsRepository,
        private AdminNotifier $adminNotifier,
        private MailConfigService $mailConfigService,
        private Logger $logger,
    ) {}

    /**
     * @param DateTimeImmutable|null $now Passed in, never read from the clock —
     *                                    the testability seam for the tiers.
     */
    public function run(?DateTimeImmutable $now = null): CredentialExpiryScanResultDto
    {
        $now ??= new DateTimeImmutable();

        try {
            if (!$this->mailConfigService->canSend()) {
                return CredentialExpiryScanResultDto::nothingDue('mail not configured');
            }

            $examined = 0;
            $inWindow = 0;
            $queued = 0;
            $alreadyQueued = 0;
            $withoutEmail = 0;

            foreach ($this->credentials() as $credential) {
                $examined++;

                $tier = CredentialLifecycle::warningTier($credential['expires_at'], $now);
                if ($tier === null) {
                    continue;
                }

                $inWindow++;

                $result = $this->adminNotifier->warnAdmins(
                    $credential['kind'],
                    $credential['subject_id'],
                    self::occasion($tier, $credential['generation']),
                );

                $queued += $result->queued;
                $alreadyQueued += $result->alreadyQueued;
                // The high-water mark, not a sum: it is the same handful of
                // addressless admin accounts on every credential, and adding
                // them up would report six unreachable admins for a club that
                // has one and three terminals.
                $withoutEmail = max($withoutEmail, count($result->withoutEmail));

                if ($result->queued > 0) {
                    $this->logger->warning('Credential expiry warning queued', [
                        'kind' => $credential['kind']->value,
                        'subject_id' => $credential['subject_id'],
                        'tier_days' => $tier,
                        // The generation too: a *missing* warning is diagnosed
                        // by comparing this against the dedup keys already in
                        // the outbox, and the tier alone does not identify one.
                        'generation' => $credential['generation'],
                        'expires_at' => $credential['expires_at'],
                        'queued' => $result->queued,
                    ]);
                }
            }

            return new CredentialExpiryScanResultDto(
                credentialsExamined: $examined,
                inWarningWindow: $inWindow,
                queued: $queued,
                alreadyQueued: $alreadyQueued,
                adminsWithoutEmail: $withoutEmail,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Credential expiry scan failed', ['error' => $e->getMessage()]);

            return CredentialExpiryScanResultDto::nothingDue('scan failed: ' . $e->getMessage());
        }
    }

    /**
     * The tier as it appears in the `dedup_key`, optionally scoped to one
     * generation of the credential.
     *
     * `90d` rather than `90`, so a key read out of the outbox in the admin panel
     * is self-describing rather than a bare number next to a UUID.
     *
     * ### Why the generation segment exists
     *
     * An encryption key does not need one: rotating produces a **new row**, so
     * `subject_id` already changes and the successor gets its own three tiers.
     *
     * A terminal token does. Rotating one leaves the terminal — and therefore
     * `subject_id` — exactly as it was, so without a discriminator the second
     * token's warnings share a dedup key with the first token's. Whether the
     * successor is warned about at all then depends on whether
     * {@see MailRetention} has pruned the predecessor's row yet, which is not a
     * property anybody should have to reason about to know if they will be told
     * their till is about to stop working.
     *
     * At the default 365-day TTL the margin is comfortable — the next cycle's
     * 90-day tier lands roughly 190 days after the previous cycle's rows are
     * pruned. At `API_TOKEN_TTL_DAYS=90`, which is what this setting used to
     * default to, the two coincide almost exactly and the outcome is a coin
     * flip. So the generation is carried rather than the margin trusted.
     *
     * ### Length
     *
     * Not incidental: `warnAdmins()` builds the key as `occasion:adminUserId`
     * into a VARCHAR(64) and an admin id is 36 characters, so the occasion has
     * 27 to work with. `90d` + `:` + 14 digits = 18, leaving nine spare — see
     * the same arithmetic in
     * {@see \App\Modules\Terminals\Services\TerminalAnomalyDetector::mail()},
     * where it was tight enough to constrain the design.
     */
    public static function occasion(int $tierDays, ?string $generation = null): string
    {
        $occasion = $tierDays . 'd';

        return $generation === null ? $occasion : $occasion . ':' . $generation;
    }

    /**
     * A terminal token's generation, as a sortable stamp.
     *
     * `token_issued_at` to the second. A rotation is something a person does in
     * the admin panel, so two for the same terminal inside one second is not a
     * case worth widening the column for — and the failure if it ever happened
     * is one suppressed warning, not a wrong one.
     *
     * A terminal with no issuance timestamp answers `0` rather than being
     * skipped: migration 011 backfilled the column, so this is the shape of a
     * row edited by hand, and one dedup namespace for it is the same guarantee
     * the feature had before this segment existed.
     */
    public static function tokenGeneration(?string $issuedAt): string
    {
        $issuedAt = trim((string) $issuedAt);
        if ($issuedAt === '') {
            return '0';
        }

        $timestamp = strtotime($issuedAt);

        return $timestamp === false ? '0' : date('YmdHis', $timestamp);
    }

    /** The tier a `dedup_key` was queued for, or null if it does not name one. */
    public static function tierFromDedupKey(string $dedupKey): ?int
    {
        $occasion = explode(':', $dedupKey)[0] ?? '';

        if (!preg_match('/^(\d+)d$/', $occasion, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Everything with an operational lifetime worth warning about.
     *
     * **The encryption key**: only the ACTIVE one. A PENDING key has no
     * `expires_at` until it is activated, and a RETIRED one is nobody's problem.
     * A club with *no* active key is not warned from here either — that is not
     * an expiry, it is the loudest error the dashboard has, and it means IBANs
     * cannot be stored at this moment rather than at some future date.
     *
     * **Terminal tokens**: active terminals only. A switched-off till's token
     * lapsing is not something to act on, and a mail asking somebody to go and
     * re-pair a terminal that is deliberately in a cupboard is how a warning
     * channel earns a filter rule.
     *
     * A terminal with a **pending token** is skipped as well, and that one is a
     * judgement call rather than an obvious rule: issuing a replacement is
     * exactly the action this mail would ask for, so somebody has already done
     * it and the credential is waiting to be picked up on the till's next sync.
     * The cost of skipping is a terminal that never syncs again and so never
     * promotes its replacement — but that terminal is offline, which the
     * dashboard says in plain language, and it is a better thing to be told than
     * a token date.
     *
     * @return list<array{kind: MailKind, subject_id: string, expires_at: ?string, generation: ?string}>
     */
    private function credentials(): array
    {
        $credentials = [];

        $activeKey = $this->encryptionKeysRepository->findActive();
        if ($activeKey !== null) {
            $credentials[] = [
                'kind' => MailKind::KEY_EXPIRY_WARNING,
                'subject_id' => (string) $activeKey['id'],
                'expires_at' => $activeKey['expires_at'] ?? null,
                // No generation segment: a rotated key is a new row with a new
                // id, so `subject_id` already separates the cycles.
                'generation' => null,
            ];
        }

        foreach ($this->terminalsRepository->findAll() as $terminal) {
            if (!(bool) ($terminal['is_active'] ?? false)) {
                continue;
            }
            if (trim((string) ($terminal['pending_token_hash'] ?? '')) !== '') {
                continue;
            }

            $credentials[] = [
                'kind' => MailKind::TERMINAL_TOKEN_EXPIRY_WARNING,
                'subject_id' => (string) $terminal['id'],
                'expires_at' => $terminal['token_expires_at'] ?? null,
                // A rotation keeps the terminal and its id, so the token's own
                // issuance is what separates one cycle from the next.
                'generation' => self::tokenGeneration($terminal['token_issued_at'] ?? null),
            ];
        }

        return $credentials;
    }
}
