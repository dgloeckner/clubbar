<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\DTOs\DispenserAttentionScanResultDto;
use App\Modules\Notifications\Enums\DispenserAttentionOccasion;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Terminals\DTOs\DispenserFillDto;
use App\Modules\Terminals\Enums\DispenserUnavailableReason;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use App\Modules\Terminals\Services\DispenserFillService;
use App\Shared\Logging\Logger;
use DateTimeImmutable;

/**
 * Tells the `admin` office that a dispenser needs a human, without waiting for
 * somebody to open the Terminals page (#956, ADR-0057 / ADR-0058).
 *
 * ## Why this exists
 *
 * #952–#955 made four failures visible — jammed, unreachable, mismatched,
 * nearly empty — to an admin who *happens to look*. A jam on a Friday evening
 * is found by the next member who wants a token. This is the push half, and it
 * rides the mail tick for the reason {@see CredentialExpiryNotifier} and
 * {@see BackupHealthNotifier} already argue: ADR-0038 made a scheduler
 * mandatory, so an installation that can send mail at all has a tick.
 *
 * ## It re-derives nothing
 *
 * Every condition here is read from the class that already owns it, because a
 * mail that disagreed with the cell an admin opens the panel to read would be
 * worse than either being wrong alone:
 *
 * - **The fault** is `unavailable_reason` out of the stored document — the
 *   verdict the backend stamped in on receipt (ADR-0057), the same string the
 *   kiosk badge and the panel cell render.
 * - **The shortage** is {@see DispenserFillDto::isLow()} via
 *   {@see DispenserFillService::readFor()}. The threshold comparison is not
 *   repeated here; there is one place it lives.
 *
 * ## What is deliberately *not* mailed
 *
 * - **A recovered crash** — `state: idle`, `fault: none` — is a working
 *   machine. `unavailable_reason` is null for it, so nothing fires. ADR-0057
 *   context 4 is the whole reason the vocabulary keeps *can it serve a token*
 *   and *does a human have to go there* apart.
 * - **An estimate that does not exist.** `estimatedLeft() === null` means no
 *   refill was ever recorded, which is **not** an empty hopper — opposite
 *   errands, and conflating them is the fastest way to make this channel
 *   untrustworthy in its first week.
 * - **A stale report.** A terminal that stopped syncing is making no claim
 *   about its dispenser at all; that it stopped is the sync status's business,
 *   and the panel already says when the last report arrived. Mailing a jam
 *   somebody may have cleared a week ago would send a person on an errand the
 *   installation has no evidence for.
 * - **An all-clear.** No digest, no "all dispensers OK", and nothing when a
 *   fault ends (ADR-0044 rule 6).
 *
 * ## Persistence, and why the low warning has none
 *
 * A fault must have held for {@see PERSISTENCE_MINUTES} before it is mailed: a
 * dispenser is unreachable for a few seconds during a dispense often enough
 * that an immediate notice would be a WLAN log by email. The shortage has no
 * such window — it is arithmetic on rows that already happened, not an
 * observation that might be a blip, and it is already rate-limited to once per
 * hopper load by its own episode.
 *
 * ## One errand per terminal per pass
 *
 * A fault outranks the shortage on the same terminal. The two are one walk to
 * the same machine — ADR-0058 says the estimate never overrides the availability
 * verdict, and the jam's own message carries the "probably empty" sentence when
 * the books agree — so a second mail would be a second errand invented out of
 * one.
 *
 * Never throws. The caller is the cron tick whose other job is draining the
 * queue, and a scan that could not read a table must not stop the club's
 * announcements from going out.
 */
class DispenserAttentionNotifier
{
    /**
     * How long a fault must have held before it is worth somebody's evening.
     *
     * Measured from `state_since`, which is the backend's own clock (a kiosk
     * whose clock is wrong must not be able to date a fault) and which does not
     * move while the same episode is re-reported every thirty seconds — so this
     * is genuinely "how long has it been like this", not "how long since the
     * last report".
     */
    public const PERSISTENCE_MINUTES = 10;

    /**
     * How old a report may be and still count as a claim about *now*.
     *
     * A terminal reports on its sync cadence and immediately on a state change,
     * so an hour without a word means the terminal is not syncing — a different
     * failure, with a different remedy, that the Terminals page reports in its
     * own column. Deliberately generous: the cost of waiting is a late mail,
     * the cost of being too eager is somebody driving to the bar over a status
     * nothing has confirmed since yesterday.
     */
    public const STALE_AFTER_MINUTES = 60;

    public function __construct(
        private TerminalsRepository $terminals,
        private DispenserFillService $fill,
        private AdminNotifier $adminNotifier,
        private MailConfigService $mailConfigService,
        private Logger $logger,
    ) {}

    /**
     * @param DateTimeImmutable|null $now Passed in, never read from the clock —
     *                                    the testability seam for both windows.
     */
    public function run(?DateTimeImmutable $now = null): DispenserAttentionScanResultDto
    {
        $now ??= new DateTimeImmutable();

        try {
            // The same gate, and the same reason, as the two scans beside this
            // one: `NullTransport` records a *permanent failure*, so on an
            // installation with no mail configured every warning queued here
            // would land in the Notifications page as a red row nobody asked
            // for. Better to queue nothing.
            if (!$this->mailConfigService->canSend()) {
                return DispenserAttentionScanResultDto::nothingDue('mail not configured');
            }

            $examined = 0;
            $needing = 0;
            $waiting = 0;
            $stale = 0;
            $queued = 0;
            $alreadyQueued = 0;
            $withoutEmail = 0;

            foreach ($this->terminals->findAll() as $terminal) {
                // A switched-off till's dispenser is nobody's errand, and a mail
                // asking somebody to walk to a terminal that is deliberately in
                // a cupboard is how a warning channel earns a filter rule —
                // the same call {@see CredentialExpiryNotifier} makes.
                if (!(bool) ($terminal['is_active'] ?? false)) {
                    continue;
                }

                $examined++;

                $document = self::document($terminal['dispenser_status'] ?? null);
                $reason = self::reasonOf($document);

                if ($reason !== null) {
                    if (self::isStale($terminal['dispenser_status_at'] ?? null, $now)) {
                        $stale++;
                        continue;
                    }

                    $since = is_string($document['state_since'] ?? null) ? $document['state_since'] : null;
                    if (!self::hasHeldLongEnough($since, $now)) {
                        $waiting++;
                        continue;
                    }

                    $needing++;
                    $this->mail(
                        $terminal,
                        DispenserAttentionOccasion::forReason($reason),
                        // The episode. `state_since` is carried forward by
                        // `DispenserStatusService` for as long as the five
                        // fields behind `episodeKey()` are unchanged, so this
                        // is one notice per episode per admin — and the panel
                        // dates the same episode from the same field.
                        (string) $since,
                        ['reason' => $reason->value],
                        $queued,
                        $alreadyQueued,
                        $withoutEmail,
                    );

                    continue;
                }

                // No fault, so the hopper is the only thing left to say. A
                // report that says no dispenser is attached ends it here: there
                // is nothing to run out of, whatever an old refill row claims.
                if (($document['configured'] ?? null) === false) {
                    continue;
                }

                $fill = $this->fill->readFor($terminal);

                // `isLow()` includes exhausted, and `estimatedLeft() === null`
                // is excluded inside it — a hopper nobody has counted is not an
                // empty one.
                if (!$fill->isLow() || $fill->refilledAt === null) {
                    continue;
                }

                $needing++;
                $this->mail(
                    $terminal,
                    DispenserAttentionOccasion::LOW,
                    // **Not `state_since`.** A draining hopper changes none of
                    // the five fields that stamp moves with, so a key built on
                    // it would warn once per terminal and then stay silent for
                    // ever. The refill is the moment the load was replaced, so
                    // this is at most one warning per hopper load.
                    $fill->refilledAt,
                    ['estimated_left' => $fill->estimatedLeft(), 'low_threshold' => $fill->lowThreshold],
                    $queued,
                    $alreadyQueued,
                    $withoutEmail,
                );
            }

            return new DispenserAttentionScanResultDto(
                terminalsExamined: $examined,
                needingAttention: $needing,
                waiting: $waiting,
                stale: $stale,
                queued: $queued,
                alreadyQueued: $alreadyQueued,
                adminsWithoutEmail: $withoutEmail,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Dispenser attention scan failed', ['error' => $e->getMessage()]);

            return DispenserAttentionScanResultDto::nothingDue('scan failed: ' . $e->getMessage());
        }
    }

    /**
     * Offer the message and count what the database accepted.
     *
     * Nothing selects first to find out whether this has already been said:
     * `UNIQUE (kind, subject_id, dedup_key)` answers it, which is both faster
     * and correct under two overlapping ticks — where a lookup-then-insert
     * would have both passes find nothing and both insert (ADR-0044 rule 4).
     *
     * @param array<string, mixed> $terminal
     * @param array<string, mixed> $context  Extra fields for the log line only
     */
    private function mail(
        array $terminal,
        DispenserAttentionOccasion $occasion,
        string $episode,
        array $context,
        int &$queued,
        int &$alreadyQueued,
        int &$withoutEmail,
    ): void {
        $result = $this->adminNotifier->warnAdmins(
            MailKind::DISPENSER_ATTENTION,
            (string) $terminal['id'],
            $occasion->withEpisode($episode),
        );

        $queued += $result->queued;
        $alreadyQueued += $result->alreadyQueued;
        // The high-water mark rather than a sum: it is the same handful of
        // addressless admin accounts on every terminal, and adding them up
        // would report six unreachable admins for a club that has two.
        $withoutEmail = max($withoutEmail, count($result->withoutEmail));

        if ($result->queued > 0) {
            $this->logger->warning('Dispenser attention warning queued', [
                'terminal_id' => (string) $terminal['id'],
                'occasion' => $occasion->value,
                // The episode too: a *missing* warning is diagnosed by holding
                // this against the dedup keys already in the outbox, and the
                // occasion alone does not identify one.
                'episode' => $episode,
                'queued' => $result->queued,
            ] + $context);
        }
    }

    /**
     * The stored report, or null where there is none to read.
     *
     * A value that will not decode reads as *never reported*, the way the panel
     * reads it: nothing but `DispenserStatusService` writes this column, so a
     * broken one is a corrupted row rather than an input to validate — and it
     * must not turn a mail scan into an exception.
     *
     * @return array<string, mixed>|null
     */
    private static function document(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The derived reason a dispenser cannot serve, or null when it can — or
     * when there is no dispenser, and when the document predates the field.
     *
     * Read, never re-derived: the backend stamped this in on receipt precisely
     * so that three surfaces cannot describe one machine differently.
     *
     * @param array<string, mixed>|null $document
     */
    private static function reasonOf(?array $document): ?DispenserUnavailableReason
    {
        if ($document === null || ($document['configured'] ?? null) !== true) {
            return null;
        }

        $reason = $document['unavailable_reason'] ?? null;

        return is_string($reason) ? DispenserUnavailableReason::tryFrom($reason) : null;
    }

    private static function isStale(mixed $reportedAt, DateTimeImmutable $now): bool
    {
        $moment = self::instant($reportedAt);

        // A fault with no arrival stamp is not aged out: the verdict in the
        // document is still the last thing the terminal said, and refusing to
        // mail it would lose a real jam over a missing column.
        return $moment !== null
            && $moment->getTimestamp() < $now->getTimestamp() - self::STALE_AFTER_MINUTES * 60;
    }

    private static function hasHeldLongEnough(?string $since, DateTimeImmutable $now): bool
    {
        $moment = self::instant($since);

        // No `state_since` at all: mail it. The stamp is written by the same
        // call that stores the document, so a document without one is older
        // than this feature — and treating "I cannot tell how long" as "not
        // long enough" would silence a fault for ever.
        if ($moment === null) {
            return true;
        }

        return $moment->getTimestamp() <= $now->getTimestamp() - self::PERSISTENCE_MINUTES * 60;
    }

    /**
     * A stored stamp as an instant, or null when it is absent or unreadable.
     *
     * Both shapes this scan meets are UTC: `state_since` is ISO-8601 with a `Z`
     * (ADR-0057), `dispenser_status_at` is the `Y-m-d H:i:s` a DATETIME column
     * holds (Pattern 020). The zone is stated rather than left to the host's
     * php.ini, which is what would otherwise shift every window by an hour.
     */
    private static function instant(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
