<?php

declare(strict_types=1);

namespace App\Modules\Backups\Repositories;

use PDO;

/**
 * `backup_keys` — what was observed about each recipient key, plus the one
 * thing that was decided about it.
 *
 * Rows are created on first use rather than on configuration, so this table is
 * a projection of what actually happened and cannot drift from it: a key named
 * in `config.php` that has never sealed an archive has no row, which is
 * exactly the state the panel should show as "configured, never used".
 *
 * The exception is `compromised_at`, which is a decision rather than an
 * observation and **outranks `config.php`** — see {@see \App\Modules\Backups\Services\BackupKeyring}.
 *
 * Part of #690, epic #686.
 */
class BackupKeysRepository
{
    public function __construct(private PDO $db) {}

    /**
     * Record that an archive was sealed to this key.
     *
     * `INSERT … ON DUPLICATE KEY UPDATE` for the same reason the heartbeat uses
     * it: the row is created by whatever happens first, and a run must never
     * fail because a projection row was missing.
     *
     * `label` is refreshed on every use because the label lives in
     * `config.php`; renaming a recipient there should change what the decryptor
     * prints, not leave the panel showing a name nobody uses any more.
     */
    public function recordUse(string $fingerprint, string $label, string $usedAt): bool
    {
        $this->db->prepare(
            'INSERT INTO backup_keys (fingerprint, label, first_seen_at, last_used_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE label = VALUES(label), last_used_at = VALUES(last_used_at)'
        )->execute([$fingerprint, $label, $usedAt, $usedAt]);

        // Whether this was the key's first appearance, which is what the
        // caller audits as `backup_key_added`. Read back rather than taken from
        // rowCount(): MariaDB reports 1 for an insert and 2 for an update on a
        // duplicate key, but 0 when the update changed nothing — and a run that
        // seals to the same key twice in the same second would then look like
        // a first use.
        $firstSeen = $this->db->prepare(
            'SELECT first_seen_at = ? FROM backup_keys WHERE fingerprint = ?'
        );
        $firstSeen->execute([$usedAt, $fingerprint]);

        return (bool) $firstSeen->fetchColumn();
    }

    /**
     * The keys no archive may be sealed to any more.
     *
     * @return list<string> fingerprints
     */
    public function compromisedFingerprints(): array
    {
        return $this->db
            ->query('SELECT fingerprint FROM backup_keys WHERE compromised_at IS NOT NULL')
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Mark a holder as having proved they can open a real archive.
     *
     * Until this is set the panel says the key is unverified, and it means it:
     * a recipient nobody has ever decrypted with is a belief about a keypair,
     * not a recipient.
     */
    public function markVerified(string $fingerprint, string $verifiedAt): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE backup_keys SET verified_at = ? WHERE fingerprint = ?'
        );
        $stmt->execute([$verifiedAt, $fingerprint]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Take a key out of use permanently.
     *
     * Idempotent, and deliberately keeps the *first* time it was marked: the
     * compromise date is what bounds which archives are affected, so a second
     * click must not move it forward and shrink that set.
     */
    public function markCompromised(string $fingerprint, string $label, string $at): void
    {
        $this->db->prepare(
            'INSERT INTO backup_keys (fingerprint, label, first_seen_at, compromised_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE compromised_at = COALESCE(compromised_at, VALUES(compromised_at))'
        )->execute([$fingerprint, $label, $at, $at]);
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db
            ->query('SELECT * FROM backup_keys ORDER BY label')
            ->fetchAll(PDO::FETCH_ASSOC);
    }
}
