<?php

declare(strict_types=1);

namespace App\Modules\Auth\Repositories;

use PDO;

/**
 * Failed authentication attempts, counted on two independent dimensions.
 *
 * Ruling #145: an attempt is counted per source IP *and* per account, both
 * windowed (5 / 15 min), never a permanent lock. Rows are cleared only after
 * a *full* authentication and only for that account — attempts against other
 * accounts from the same host keep counting toward the IP dimension.
 *
 * The table is a constructor argument so the same logic serves the terminal
 * attempts table, which has no `email` column: pass `$email = null` to
 * `record()` and never call the email-scoped methods.
 */
class LoginAttemptsRepository
{
    public function __construct(
        private PDO $db,
        private string $table = 'login_attempts',
    ) {}

    /**
     * Record one failed attempt. `$email` is omitted for tables without the column.
     */
    public function record(string $ip, ?string $email = null): void
    {
        if ($email === null) {
            $stmt = $this->db->prepare("INSERT INTO {$this->table} (ip_address) VALUES (:ip)");
            $stmt->execute(['ip' => $ip]);
            return;
        }

        $stmt = $this->db->prepare("INSERT INTO {$this->table} (ip_address, email) VALUES (:ip, :email)");
        $stmt->execute(['ip' => $ip, 'email' => $email]);
    }

    public function countRecentByIp(string $ip, int $windowMinutes): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE ip_address = :ip AND attempted_at > DATE_SUB(NOW(), INTERVAL :window MINUTE)"
        );
        $stmt->execute(['ip' => $ip, 'window' => $windowMinutes]);

        return (int) $stmt->fetchColumn();
    }

    public function countRecentByEmail(string $email, int $windowMinutes): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE email = :email AND attempted_at > DATE_SUB(NOW(), INTERVAL :window MINUTE)"
        );
        $stmt->execute(['email' => $email, 'window' => $windowMinutes]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Forget an account's failed attempts. Called only after full authentication
     * (password *and* second factor) — never on password success alone, which is
     * what let an attacker mint unlimited fresh MFA windows (#78).
     *
     * Scoped by email, not by IP: each row carries both, so this removes that
     * admin's contribution to both counters and nothing else.
     */
    public function clearForEmail(string $email): void
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE email = :email");
        $stmt->execute(['email' => $email]);
    }
}
