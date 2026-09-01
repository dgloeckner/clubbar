<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Repositories;

use App\Modules\Registrations\DTOs\SelfRegistrationConfigDto;
use PDO;

/**
 * The single-row switch and poster secret (migration 059).
 *
 * Same shape as `SepaConfigRepository` and `CreditLimitConfigRepository`: one
 * row, id 1, read on every request that touches the public surface.
 */
class SelfRegistrationConfigRepository
{
    public function __construct(
        private PDO $db,
    ) {}

    public function get(): SelfRegistrationConfigDto
    {
        $row = $this->db->query('SELECT * FROM self_registration_config WHERE id = 1')->fetch();

        return SelfRegistrationConfigDto::fromRow($row ?: null);
    }

    /**
     * Write a new poster secret, replacing whatever was there.
     *
     * Both forms in one statement, because they are one fact: the hash is what
     * a presented secret is checked against, and the cipher is what lets an
     * admin **reprint** the poster without rotating it. Writing one without the
     * other leaves a club that can either verify a secret it cannot show, or
     * show one it cannot verify.
     *
     * Rotation is this same call: there is no history, so the old secret stops
     * working the moment this commits — which is exactly what "the poster on
     * the wall is now dead" has to mean.
     */
    public function replaceSecret(string $hash, string $cipher, ?string $adminUserId): void
    {
        $this->upsert(
            'secret_hash = ?, secret_cipher = ?, secret_rotated_at = ?, updated_by_admin_id = ?',
            [$hash, $cipher, date('Y-m-d H:i:s'), $adminUserId],
        );
    }

    /**
     * Switch registration on or off.
     *
     * The reason travels with the switch rather than in a call of its own: a
     * club that is off without a sentence to show is the blank wall decision 1
     * exists to prevent, and two writes make that state reachable between them.
     */
    public function setAvailability(bool $enabled, ?string $disabledReason, ?string $adminUserId): void
    {
        $this->upsert(
            'enabled = ?, disabled_reason = ?, updated_by_admin_id = ?',
            [$enabled ? 1 : 0, $disabledReason, $adminUserId],
        );
    }

    /**
     * The row is seeded by migration `059`, so an UPDATE is the ordinary path —
     * but an installation whose row was emptied must not silently do nothing,
     * because "nothing" here reads as a club that stayed switched off (or worse,
     * stayed switched on) with no error to explain it.
     *
     * @param list<mixed> $values
     */
    private function upsert(string $set, array $values): void
    {
        $statement = $this->db->prepare("UPDATE self_registration_config SET {$set} WHERE id = 1");
        $statement->execute($values);

        if ($statement->rowCount() > 0) {
            return;
        }

        // `rowCount()` is 0 both for "no such row" and for "the values were
        // already exactly this", so the insert has to tolerate the second.
        $exists = (int) $this->db->query('SELECT COUNT(*) FROM self_registration_config WHERE id = 1')
            ->fetchColumn();
        if ($exists > 0) {
            return;
        }

        $this->db->prepare('INSERT INTO self_registration_config (id, enabled) VALUES (1, 0)')->execute();
        $this->db->prepare("UPDATE self_registration_config SET {$set} WHERE id = 1")->execute($values);
    }
}
