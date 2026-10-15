<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

use App\Modules\Terminals\Enums\DispenserUnavailableReason;

/**
 * What a dispenser mail is about, and what makes it *one* notice (#956).
 *
 * There are two occasions in here, not four, and the difference between them is
 * the whole reason this enum exists rather than a string literal in the scan:
 *
 * - **A fault occasion** — {@see FAULT}, {@see OFFLINE}, {@see MISMATCH} — is a
 *   claim the terminal filed about the machine. Its episode is
 *   `dispenser_status.state_since`, which moves when `configured`, `contact`,
 *   `state`, `fault` or `fault_code` changes and not otherwise (ADR-0057). So
 *   one episode is one notice, and the panel dates the same episode the same
 *   way — it renders that field as *seit …*.
 * - **The low occasion** — {@see LOW} — is arithmetic the backend did on its
 *   own books (ADR-0058), and `state_since` is the wrong anchor for it in both
 *   directions: a draining hopper changes none of those five fields, so the
 *   stamp never moves while the estimate falls (one warning, then silence
 *   forever), and when it *does* move it moves for a reason that has nothing to
 *   do with the hopper (a jam, a reboot) — which would mail the same shortage
 *   again. Its episode is `terminals.dispenser_refilled_at`, the moment the
 *   load was replaced, so it warns **at most once per hopper load**.
 *
 * Three reasons map onto {@see FAULT} because they are one errand: somebody
 * walks to the machine, opens it and clears it. The specific reason still
 * reaches the reader — it is rendered in the subject line — but it does not
 * split the deduplication, or a hopper error that turns into a jam while
 * nobody is there would mail twice for one walk.
 */
enum DispenserAttentionOccasion: string
{
    /** A jam, a hopper error, or a `state: fault` nothing named. Somebody has to go there. */
    case FAULT = 'fault';

    /** The terminal did not reach the device at all — power, network, address. */
    case OFFLINE = 'offline';

    /**
     * Something answered in a protocol the terminal does not speak. **Not a
     * device fault**: `fault` is `none`, nothing is broken at the machine and
     * the errand is a deployment one (ADR-0057 context 4). Its own occasion so
     * it can never be folded into "offline" and send somebody looking for a
     * power cable — the defect this epic already found once.
     */
    case MISMATCH = 'mismatch';

    /** The fill estimate is at or below the terminal's threshold (ADR-0058). */
    case LOW = 'low';

    /**
     * The occasion a derived {@see DispenserUnavailableReason} belongs to.
     *
     * An exhaustive `match` on purpose: a reason added to ADR-0057's vocabulary
     * has to state whether it sends somebody to the machine or to a keyboard,
     * rather than defaulting into whichever arm happens to be first.
     */
    public static function forReason(DispenserUnavailableReason $reason): self
    {
        return match ($reason) {
            DispenserUnavailableReason::OFFLINE => self::OFFLINE,
            DispenserUnavailableReason::PROTOCOL_MISMATCH => self::MISMATCH,
            DispenserUnavailableReason::JAM,
            DispenserUnavailableReason::HOPPER_ERROR,
            DispenserUnavailableReason::UNSPECIFIED_FAULT => self::FAULT,
        };
    }

    /**
     * The `occasion` segment of a dedup key: the occasion plus the episode it
     * belongs to.
     *
     * Length is not incidental. `AdminNotifier::warnAdmins()` writes
     * `occasion:adminUserId` into a VARCHAR(64) and an admin id is 36
     * characters, so there are 27 to work with. The longest occasion here is
     * `mismatch:` (9) plus a `state_since` compacted to 17 digits = 26, with
     * one spare — which is why the stamp is stripped to digits rather than
     * carried as the ISO string it is stored as.
     */
    public function withEpisode(string $episode): string
    {
        $digits = preg_replace('/\D/', '', $episode) ?? '';

        return $this->value . ':' . substr($digits, 0, 17);
    }

    /**
     * The occasion a queued row was written for, or null when its key names
     * none.
     *
     * Read from the `dedup_key`, the only place it is recorded — the same seam
     * {@see \App\Modules\Notifications\Services\CredentialExpiryNotifier::tierFromDedupKey()}
     * uses for the warning tier, and for the same reason: the outbox has no
     * column for "which of this kind's occasions is this", and a hand-written
     * row must fail loudly rather than be guessed at.
     */
    public static function fromDedupKey(string $dedupKey): ?self
    {
        return self::tryFrom(explode(':', $dedupKey)[0] ?? '');
    }
}
