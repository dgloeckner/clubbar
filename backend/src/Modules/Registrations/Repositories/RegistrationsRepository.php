<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Repositories;

use App\Shared\Utils\Uuid;
use PDO;

/**
 * The pending store (migration 059, ADR-0052).
 *
 * Deliberately small. This table is written once by a stranger and read only by
 * an authenticated admin, so there is no update path here at all: a correction
 * before approval replaces a value, approval copies the row out and deletes it,
 * and rejection and the TTL purge delete it. A row that exists is a submission
 * nobody has acted on yet.
 *
 * Every query here is plain, portable SQL, which is what lets the unit suite
 * exercise it against `sqlite::memory:` instead of the Docker MariaDB.
 */
class RegistrationsRepository
{
    public function __construct(
        private PDO $db,
    ) {}

    /**
     * Write one submission.
     *
     * Takes an already-sealed row: the caller holds the plaintext IBAN for the
     * length of its request and this class never sees one, which is what keeps
     * the seam honest — there is no method here that could accidentally be
     * given a readable IBAN to store.
     *
     * @param array<string, mixed> $data
     * @return string the new registration's id
     */
    public function create(array $data): string
    {
        $id = Uuid::v4();

        $stmt = $this->db->prepare(
            'INSERT INTO pending_registrations (
                id, first_name, last_name, email, phone, date_of_birth,
                preferred_language, account_holder_name, mandate_reference,
                iban_ciphertext, iban_last4, iban_fingerprint, encryption_key_id,
                bank_name, privacy_notice_url, privacy_notice_shown_at,
                submitted_at, expires_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $id,
            $data['first_name'],
            $data['last_name'],
            $data['email'],
            $data['phone'] ?? null,
            $data['date_of_birth'],
            $data['preferred_language'],
            $data['account_holder_name'] ?? null,
            $data['mandate_reference'],
            $data['iban_ciphertext'],
            $data['iban_last4'],
            $data['iban_fingerprint'],
            $data['encryption_key_id'],
            $data['bank_name'] ?? null,
            $data['privacy_notice_url'],
            $data['privacy_notice_shown_at'],
            $data['submitted_at'],
            $data['expires_at'],
        ]);

        return $id;
    }

    /**
     * Delete everything past its expiry.
     *
     * Returns a count and nothing else on purpose: the caller logs how many
     * rows went, never who they were. A purge line naming the people it deleted
     * would be a copy of the data outliving the deletion, in the one place
     * nobody thinks to look for personal data.
     */
    public function purgeExpired(string $now): int
    {
        $stmt = $this->db->prepare('DELETE FROM pending_registrations WHERE expires_at <= ?');
        $stmt->execute([$now]);

        return $stmt->rowCount();
    }

    /**
     * How many pending rows share this address.
     *
     * For the review inbox's duplicate flag, never for the public endpoint —
     * answering "do we already know this person?" to an anonymous caller is
     * exactly the disclosure decision 9 forbids.
     */
    public function countByEmail(string $email): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM pending_registrations WHERE email = ?');
        $stmt->execute([$email]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * How many pending rows name the same account.
     *
     * The keyed fingerprint is what makes this answerable at all: sealed boxes
     * are randomized, so two rows holding the same IBAN have different
     * ciphertexts, and only the fingerprint compares equal (ADR-0036).
     */
    public function countByFingerprint(string $fingerprint): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM pending_registrations WHERE iban_fingerprint = ?');
        $stmt->execute([$fingerprint]);

        return (int) $stmt->fetchColumn();
    }
}
