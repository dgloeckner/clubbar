<?php

declare(strict_types=1);

namespace App\Modules\Registrations\Repositories;

use App\Shared\Utils\Uuid;
use PDO;

/**
 * The pending store (migration 059, ADR-0052).
 *
 * Deliberately small, and short-lived by design. This table is written once by a
 * stranger and read only by an authenticated admin: a correction before approval
 * replaces a value, approval copies the row out and deletes it, and rejection and
 * the TTL purge delete it. A row that exists is a submission nobody has acted on
 * yet, and no row is meant to be here for long — which is why the write path an
 * admin reaches cannot touch `submitted_at` or `expires_at`.
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
     * One submission, whole, for the review screen.
     *
     * Returns the raw row including the ciphertext, because the caller that
     * approves has to copy it across verbatim. Nothing that reaches an HTTP
     * response is built from here directly — {@see \App\Modules\Registrations\DTOs\PendingRegistrationDto}
     * is what decides which of these columns an admin ever sees.
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM pending_registrations WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * The review inbox, one page at a time.
     *
     * Newest first by default, which is the order the work actually arrives in
     * — this queue is emptied, not browsed.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listPaginated(
        int $limit,
        int $offset,
        string $sortKey = 'submitted_at',
        string $sortOrder = 'desc',
        ?string $search = null,
    ): array {
        // A sort key is a column name, and a column name cannot be a bound
        // parameter — so the allow-list *is* the defence, and an unknown key
        // falls back rather than being interpolated. Same reasoning as
        // `SafeQuery`, spelled out here because the failure mode is silent.
        $sortable = ['submitted_at', 'expires_at', 'last_name', 'first_name', 'email'];
        $column = in_array($sortKey, $sortable, true) ? $sortKey : 'submitted_at';
        $direction = strtolower($sortOrder) === 'asc' ? 'ASC' : 'DESC';

        $where = '';
        $params = [];
        if ($search !== null && trim($search) !== '') {
            $needle = '%' . trim($search) . '%';
            $where = ' WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ?';
            $params = [$needle, $needle, $needle];
        }

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM pending_registrations' . $where);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // A second key after the sort column, so a page boundary is stable when
        // several submissions share a timestamp — without it the same row can
        // appear on page 1 and page 2 of one scan.
        $stmt = $this->db->prepare(
            'SELECT * FROM pending_registrations' . $where
            . " ORDER BY {$column} {$direction}, id ASC LIMIT ? OFFSET ?"
        );
        foreach ($params as $i => $value) {
            $stmt->bindValue($i + 1, $value);
        }
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * Correct a submission before it is approved.
     *
     * The allow-list is narrow on purpose. `submitted_at` and `expires_at` are
     * the retention rule (decision 10) and are not an admin's to move — a queue
     * whose deadline can be pushed out is a queue that never purges. The
     * ciphertext quartet *is* writable, because replacing an IBAN is a real
     * correction, but only as a unit: a row sealed under one key and labelled
     * with another can never be opened again.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null the row as it now stands, or null if there was none
     */
    public function updateById(string $id, array $data): ?array
    {
        $allowed = [
            'first_name', 'last_name', 'email', 'phone', 'date_of_birth',
            'preferred_language', 'account_holder_name', 'bank_name',
            'iban_ciphertext', 'iban_last4', 'iban_fingerprint', 'encryption_key_id',
        ];

        $writes = array_intersect_key($data, array_flip($allowed));

        // All four sealed columns move together or none of them do. They are
        // one fact split across four columns — a ciphertext, what it ends in,
        // what it fingerprints to, and which key can open it — and any subset
        // written alone produces a row that is internally a lie: a key id
        // without its ciphertext labels the old seal with a key that cannot
        // open it, and a ciphertext without its key id cannot be opened at all.
        // Neither is visible until a SEPA export needs the plaintext, months
        // later, by which time the plaintext is gone.
        $sealed = ['iban_ciphertext', 'iban_last4', 'iban_fingerprint', 'encryption_key_id'];
        $present = array_intersect($sealed, array_keys($writes));
        if ($present !== [] && count($present) !== count($sealed)) {
            throw new \InvalidArgumentException(
                'A pending registration\'s sealed IBAN columns must be written as a unit; got: '
                . implode(', ', $present)
            );
        }

        if ($writes === []) {
            return $this->findById($id);
        }

        $set = implode(', ', array_map(static fn(string $c): string => "{$c} = ?", array_keys($writes)));
        $values = array_values($writes);
        $values[] = $id;

        $stmt = $this->db->prepare("UPDATE pending_registrations SET {$set} WHERE id = ?");
        $stmt->execute($values);

        return $this->findById($id);
    }

    /**
     * Delete one row — rejection, and the second half of approval.
     *
     * @return bool whether a row was actually there to delete, which is what
     *         makes a repeated approval a 404 rather than a second member
     */
    public function deleteById(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM pending_registrations WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
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
