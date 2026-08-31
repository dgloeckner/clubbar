<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Repositories;

use PDO;

/**
 * The public surface's own rate-limit meter (migration 059).
 *
 * Its own table rather than a share of `login_attempts`, for the reason the
 * terminal surface has its own: these budgets protect different things, and one
 * exhausting the other is a denial of service on the surface that did nothing
 * wrong.
 *
 * Two meters, told apart by `outcome`. Counting refusals alone — what the login
 * surface does — would miss the caller this endpoint is most exposed to: one
 * holding the real poster secret, submitting valid registrations as fast as the
 * network allows, who never fails a single attempt.
 */
class RegistrationAttemptsRepository
{
    public const REFUSED = 'refused';
    public const ACCEPTED = 'accepted';

    public function __construct(
        private PDO $db,
    ) {}

    public function record(string $ip, string $outcome): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO registration_attempts (ip_address, outcome, attempted_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$ip, $outcome, date('Y-m-d H:i:s')]);
    }

    /** How many attempts of one kind this address has made since `$since`. */
    public function countRecent(string $ip, string $outcome, string $since): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM registration_attempts
             WHERE ip_address = ? AND outcome = ? AND attempted_at > ?'
        );
        $stmt->execute([$ip, $outcome, $since]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Drop rows the window has moved past.
     *
     * These are IP addresses, which are personal data, so they are not kept a
     * moment longer than the meter needs them — the cron tick prunes them
     * beside the login and terminal meters.
     */
    public function pruneOlderThan(string $cutoff): int
    {
        $stmt = $this->db->prepare('DELETE FROM registration_attempts WHERE attempted_at < ?');
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }
}
