<?php

declare(strict_types=1);

namespace App\Modules\Members\Repositories;

use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Shared\Security\IbanSealedBox;
use App\Shared\Utils\Uuid;
use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;
use App\Shared\Repository\UnsettledTransactions;
use App\Shared\Sync\SyncCursor;

class MembersRepository
{
    /**
     * Banking data lives on the append-only `mandates` record, not on the
     * mutable member row (#164). Every read joins the member's active mandate
     * back in.
     *
     * Since ADR-0036 the IBAN itself is sealed (iban_ciphertext) and reads
     * expose only what routine operation needs: the last four characters, the
     * bank name resolved at write time, and presence (`has_iban`). There is no
     * plaintext column to fall back to — it was dropped in migration 020 — so
     * these columns are the whole of what any read can learn about the
     * account. The ciphertext itself is deliberately NOT selected here; the
     * SEPA export reads it through findSealedIban() only.
     */
    private const MANDATE_JOIN =
        'LEFT JOIN mandates md ON md.active_member_id = m.id';

    private const MANDATE_COLUMNS =
        'md.iban_last4, '
        . '(md.iban_ciphertext IS NOT NULL) AS has_iban, '
        . 'md.bank_name, md.reference AS mandate_reference, md.signed_at AS mandate_signed_at';

    /**
     * The member's Deckel: what they have run up that no settlement has
     * collected yet (#371).
     *
     * `members` carries no balance column — #164 moved money out to
     * `transactions` — so the tab is summed per row here. It is deliberately
     * the same expression `TransactionsRepository::getUnsettledMemberBalanceCents`
     * evaluates for a single member and the dashboard's near-limit list uses
     * for its own: three screens naming the same figure must not be able to
     * disagree about it. Every transaction type counts, credits included, so a
     * payout leaves a negative tab rather than vanishing.
     */
    private const BALANCE_EXPRESSION =
        '(SELECT COALESCE(SUM(t.amount_cents), 0) FROM transactions t'
        . ' WHERE t.member_id = m.id'
        . ' AND ' . UnsettledTransactions::UNSETTLED . ')';

    private const BALANCE_COLUMN = self::BALANCE_EXPRESSION . ' AS balance_cents';

    public function __construct(
        private PDO $db,
        private Logger $logger,
        private IbanSealedBox $sealedBox,
        private EncryptionKeysRepository $encryptionKeys,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*, ' . self::MANDATE_COLUMNS . ', ' . self::BALANCE_COLUMN
            . ' FROM members m ' . self::MANDATE_JOIN
            . ' WHERE m.id = ? AND m.deleted_at IS NULL'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Everything an outgoing message needs about a batch of members, in one
     * query (ADR-0038).
     *
     * A settlement run can name fifty members, and the enqueue happens inside
     * the create transaction — the one place in this codebase where an extra
     * fifty round trips are held open against a lock. Hence a batch read rather
     * than the per-member `findById()` the preview path still uses.
     *
     * Deleted members are excluded: an anonymised member has no address to
     * announce to, and a settlement cannot name one anyway.
     *
     * @param list<string> $ids
     * @return array<string, array{id: string, first_name: string, last_name: string, email: ?string, preferred_language: ?string, mandate_reference: ?string, iban_last4: ?string}>
     *         Keyed by member id, so the caller can look a member up rather than search.
     */
    public function findMailRecipients(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            'SELECT m.id, m.first_name, m.last_name, m.email, m.preferred_language, '
            . 'md.reference AS mandate_reference, md.iban_last4 '
            . 'FROM members m ' . self::MANDATE_JOIN
            . " WHERE m.id IN ({$placeholders}) AND m.deleted_at IS NULL"
        );
        $stmt->execute(array_values($ids));

        $byId = [];
        foreach ($stmt->fetchAll() as $row) {
            $byId[(string) $row['id']] = $row;
        }

        return $byId;
    }

    public function findByIdIncludingDeleted(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*, ' . self::MANDATE_COLUMNS . ', ' . self::BALANCE_COLUMN
            . ' FROM members m ' . self::MANDATE_JOIN
            . ' WHERE m.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findModifiedSince(int $sinceTimestamp): array
    {
        $sinceDate = SyncCursor::lowerBound($sinceTimestamp);

        // Include both updated and deleted items (tombstones)
        // This enables the terminal to remove deleted items from local cache
        // The bound is inclusive (>=): the column has second precision, so a
        // strict > loses every member written later in the cursor's own second,
        // and loses them for good (#84). SyncCursor::next only moves the cursor
        // past a second once that second is over, so the repeat is bounded.
        $stmt = $this->db->prepare(
            'SELECT m.*, ' . self::MANDATE_COLUMNS . ' FROM members m ' . self::MANDATE_JOIN . '
             WHERE m.updated_at >= ? OR (m.deleted_at >= ? AND m.deleted_at IS NOT NULL)
             ORDER BY COALESCE(m.updated_at, m.deleted_at) ASC'
        );
        $stmt->execute([$sinceDate, $sinceDate]);
        return $stmt->fetchAll();
    }

    public function create(array $data): array
    {
        $id = $data['id'] ?? Uuid::v4();
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO members (id, card_uid, first_name, last_name, email, phone, preferred_language, is_active, account_holder_name, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $id,
            $data['card_uid'] ?? null,
            $data['first_name'],
            $data['last_name'],
            $data['email'],
            $data['phone'] ?? null,
            $data['preferred_language'] ?? 'de',
            $data['is_active'] ?? true ? 1 : 0,
            $data['account_holder_name'] ?? null,
            $now,
            $now,
        ]);

        // Per ADR-0006 a reference is minted from the member id when none is
        // given. A caller who explicitly passes an empty one is saying there is
        // no mandate, and gets none — auto-minting over that is exactly what
        // makes a missing signature invisible (#164).
        $reference = array_key_exists('mandate_reference', $data)
            ? ($data['mandate_reference'] ?: null)
            : str_replace('-', '', $id);

        if (($data['iban'] ?: null) !== null && $reference !== null) {
            $this->openMandate($id, ['mandate_reference' => $reference] + $data);
        }

        $this->logger->info('Member created', ['id' => $id]);
        return $this->findById($id);
    }

    public function updateById(string $id, array $data): ?array
    {
        $allowed = ['card_uid', 'first_name', 'last_name', 'email', 'phone', 'preferred_language', 'is_active', 'account_holder_name', 'deleted_at', 'deleted_by_admin_id'];

        // Banking data lives on the mandate now, so an update may legitimately
        // carry nothing the members row owns — "change this member's IBAN" is
        // one. Only the mandate half runs in that case.
        if (array_intersect_key($data, array_flip($allowed)) !== []) {
            [$set, $values] = SafeQuery::buildUpdate($data, $allowed);
            $values[] = date('Y-m-d H:i:s');
            $values[] = $id;

            $stmt = $this->db->prepare("UPDATE members SET {$set}, updated_at = ? WHERE id = ?");
            $stmt->execute($values);
        }

        $this->applyMandateChange($id, $data);

        $this->logger->info('Member updated', ['id' => $id]);
        return $this->findById($id);
    }

    /**
     * Apply whatever the caller asked of the member's banking data.
     *
     * The IBAN is the field the append-only rule exists for: a bank change ends
     * the current mandate and opens a new one, so a collection returned after
     * the move still resolves its MREF+ to the mandate it was made under
     * (#165). Clearing the IBAN revokes the mandate without a replacement.
     *
     * Correcting the reference or the signature date while the account stays
     * the same is a correction of the mandate in hand, not a new mandate, so it
     * is applied in place — nothing that was ever sent to a bank changes.
     * Whether an admin may rewrite a reference at all is #164's question.
     */
    private function applyMandateChange(string $id, array $data): void
    {
        $touchesMandate = array_key_exists('iban', $data)
            || array_key_exists('mandate_reference', $data)
            || array_key_exists('mandate_signed_at', $data);

        if (!$touchesMandate) {
            return;
        }

        $current = $this->findActiveMandate($id);

        // The stored IBAN is sealed and this code cannot open it (ADR-0036);
        // identity is decided by the keyed fingerprint instead. Every stored
        // mandate has one, so the comparison is a straight lookup.
        $currentFingerprint = $current['iban_fingerprint'] ?? null;

        $submittedIban = array_key_exists('iban', $data) ? ($data['iban'] ?: null) : null;
        $submittedFingerprint = $submittedIban !== null ? $this->sealedBox->fingerprint($submittedIban) : null;

        $keepsCurrentAccount = !array_key_exists('iban', $data)
            || ($submittedFingerprint !== null && $submittedFingerprint === $currentFingerprint);

        $reference = array_key_exists('mandate_reference', $data)
            ? ($data['mandate_reference'] ?: null)
            : ($current['reference'] ?? null);
        $signedAt = array_key_exists('mandate_signed_at', $data)
            ? ($data['mandate_signed_at'] ?: null)
            : ($current['signed_at'] ?? null);

        if ($current !== null && $keepsCurrentAccount) {
            if ($reference === $current['reference'] && $signedAt === $current['signed_at']) {
                return;
            }

            $stmt = $this->db->prepare('UPDATE mandates SET reference = ?, signed_at = ? WHERE id = ?');
            $stmt->execute([$reference ?: $current['reference'], $signedAt, $current['id']]);
            $this->touchMember($id);
            return;
        }

        if ($current !== null) {
            $this->endMandate($current['id'], $submittedIban === null ? 'revoked' : 'bank_change');
        }

        if ($submittedIban !== null) {
            // A new account gets a freshly minted reference unless the caller
            // named one; carrying the old one forward is impossible anyway,
            // since the superseded mandate still holds it.
            $this->openMandate($id, [
                'iban' => $submittedIban,
                'bank_name' => $data['bank_name'] ?? null,
                'mandate_reference' => $current === null ? $reference : ($data['mandate_reference'] ?? null),
                'mandate_signed_at' => $signedAt,
            ]);
        }

        $this->touchMember($id);
    }

    /**
     * The terminal decides bar access from the member's SEPA validity, which now
     * lives one table away. Without bumping the member's own `updated_at`, the
     * sync cursor would never see a bank change, and a revoked mandate would go
     * on serving drinks.
     */
    private function touchMember(string $id): void
    {
        $stmt = $this->db->prepare('UPDATE members SET updated_at = ? WHERE id = ?');
        $stmt->execute([date('Y-m-d H:i:s'), $id]);
    }

    private function openMandate(string $memberId, array $data): void
    {
        $mandateId = Uuid::v4();

        // Per ADR-0006 the reference is a UUID without hyphens; it is now minted
        // when the mandate is opened rather than when the member is created, so
        // a member without banking data has no reference at all.
        $reference = ($data['mandate_reference'] ?? null) ?: str_replace('-', '', $mandateId);

        // ADR-0036: the plaintext IBAN is sealed under the ACTIVE public key
        // and never stored. Writing plaintext "just this once" is exactly the
        // regression the schema keeps nullable `iban` around to migrate away
        // from, not to feed — so a missing or expired key refuses the write
        // with an actionable 409 rather than storing anything.
        $key = $this->encryptionKeys->requireOperationalActive();

        $stmt = $this->db->prepare(
            'INSERT INTO mandates (id, member_id, active_member_id, reference, iban_ciphertext, iban_last4, iban_fingerprint, encryption_key_id, bank_name, signed_at, created_by_admin_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $mandateId,
            $memberId,
            $memberId,
            $reference,
            $this->sealedBox->seal($data['iban'], $key['public_key']),
            IbanSealedBox::lastFour($data['iban']),
            $this->sealedBox->fingerprint($data['iban']),
            $key['id'],
            $data['bank_name'] ?? null,
            ($data['mandate_signed_at'] ?? null) ?: null,
            $data['created_by_admin_id'] ?? null,
        ]);
    }

    private function endMandate(string $mandateId, string $reason): void
    {
        $stmt = $this->db->prepare(
            'UPDATE mandates SET active_member_id = NULL, ended_at = ?, ended_reason = ? WHERE id = ?'
        );
        $stmt->execute([date('Y-m-d H:i:s'), $reason, $mandateId]);
    }

    public function findActiveMandate(string $memberId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM mandates WHERE active_member_id = ?');
        $stmt->execute([$memberId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The sealed IBAN material of a member's active mandate — the ONLY read
     * that touches the ciphertext. Sole consumer is the SEPA export, which
     * opens it with the temporarily supplied private key (ADR-0036). There is
     * no other way back to the plaintext: no column holds it, and this process
     * has no key.
     */
    public function findSealedIban(string $memberId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT iban_ciphertext, encryption_key_id FROM mandates WHERE active_member_id = ?'
        );
        $stmt->execute([$memberId]);
        return $stmt->fetch() ?: null;
    }

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM members')->fetchColumn();
    }

    public function countActive(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM members WHERE is_active = 1')->fetchColumn();
    }

    public function exists(string $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM members WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return (bool) $stmt->fetch();
    }

    /**
     * Erase the person from the member row (GDPR Art. 17).
     *
     * Every column of the row that says something about the human being is
     * nulled, not only the obvious contact fields (#115):
     *
     * - `collection_hold_reason` is free text composed at a bank return and
     *   quotes the bank's reference for it. It is a narrative about this
     *   person's payment history sitting on their own row, and it used to
     *   survive the erasure that removed their name.
     * - `deleted_by_admin_id` records *who* performed the erasure. It is the
     *   admin's id rather than the member's data, and it was previously left
     *   NULL here — the erasure had no accountable actor on the record at all,
     *   because a member must still be live (`deleted_at IS NULL`) to be
     *   anonymized, so nothing had ever written it.
     *
     * Banking data is deliberately *not* covered: `iban`, `mandate_reference`
     * and `mandate_signed_at` are not columns of this table any more, they are
     * read through the active-mandate join, and ending the mandate below is
     * what makes all three read as NULL for the erased member. The mandate row
     * itself is retained — see the note at the end of this method.
     */
    public function anonymize(string $id, ?string $adminUserId = null): bool
    {
        $now = date('Y-m-d H:i:s');
        // card_uid is VARCHAR(20), so use ANON- + 15 chars of UUID = 20 chars max
        $anonCardUid = 'ANON-' . substr(str_replace('-', '', Uuid::v4()), 0, 15);
        $stmt = $this->db->prepare(
            'UPDATE members SET first_name = NULL, last_name = NULL, email = NULL, phone = NULL, account_holder_name = NULL, collection_hold_reason = NULL, card_uid = ?, is_active = 0, deleted_at = ?, deleted_by_admin_id = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$anonCardUid, $now, $adminUserId, $now, $id]);

        if ($stmt->rowCount() === 0) {
            return false;
        }

        // Ending the mandate makes the member SEPA-invalid, exactly as nulling
        // iban/mandate_reference used to. The record itself survives: a return
        // arriving inside the SEPA window still has to resolve its MREF+, and
        // how long that residual is kept is the erasure window of #165, not a
        // decision this migration makes.
        $active = $this->findActiveMandate($id);
        if ($active !== null) {
            $this->endMandate($active['id'], 'offboarded');
        }

        return true;
    }

    public function listPaginated(int $limit, int $offset, array $filters = [], string $sortKey = 'created_at', string $sortOrder = 'desc', ?string $search = null): array
    {
        $where = ['m.deleted_at IS NULL'];
        $params = [];

        if (isset($filters['is_active'])) {
            $where[] = 'm.is_active = ?';
            $params[] = $filters['is_active'] ? 1 : 0;
        }
        if (isset($filters['language'])) {
            $where[] = 'm.preferred_language = ?';
            $params[] = $filters['language'];
        }
        // Card UID filter
        if (isset($filters['has_card_uid'])) {
            if ($filters['has_card_uid']) {
                $where[] = 'm.card_uid IS NOT NULL';
            } else {
                $where[] = 'm.card_uid IS NULL';
            }
        }
        // SEPA status filter. An active mandate is now the whole predicate: the
        // record cannot exist without both an IBAN and a reference, since both
        // are NOT NULL on `mandates`.
        if (isset($filters['sepa_status'])) {
            $where[] = $filters['sepa_status'] === 'valid' ? 'md.id IS NOT NULL' : 'md.id IS NULL';
        }
        if ($search) {
            $escaped = SafeQuery::escapeLike($search);
            $where[] = "(CONCAT(m.first_name, ' ', m.last_name) LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ? OR m.email LIKE ?)";
            $params = array_merge($params, ["%{$escaped}%", "%{$escaped}%", "%{$escaped}%", "%{$escaped}%"]);
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Each key maps to the columns it orders by, in order. `name` is what
        // the admin list sends for its Name column, and a member list sorted by
        // name is expected to read like a phone book: last name first, first
        // name to break the tie.
        $columnMap = [
            'id' => ['m.id'],
            'name' => ['m.last_name', 'm.first_name'],
            'first_name' => ['m.first_name'],
            'last_name' => ['m.last_name'],
            'card_uid' => ['m.card_uid'],
            'balance' => [self::BALANCE_EXPRESSION],
            'created_at' => ['m.created_at'],
        ];
        $col = SafeQuery::column($sortKey, array_keys($columnMap));
        $dir = SafeQuery::direction($sortOrder);

        $terms = array_map(fn(string $column): string => "{$column} {$dir}", $columnMap[$col]);

        // Most members have no card, and MariaDB sorts NULL first ascending —
        // which would fill the first page of a "sort by Card-UID" with rows
        // that have none. Cardless members go last in both directions instead.
        if ($col === 'card_uid') {
            array_unshift($terms, '(m.card_uid IS NULL) ASC');
        }

        // `created_at` has second resolution and an import creates many members
        // inside the same second, so an id tiebreaker is what keeps a row from
        // appearing on two pages of the same listing — or on neither.
        $terms[] = 'm.id ASC';
        $orderBy = implode(', ', $terms);

        $from = 'FROM members m ' . self::MANDATE_JOIN . " {$whereClause}";

        $countStmt = $this->db->prepare("SELECT COUNT(*) {$from}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->db->prepare(
            'SELECT m.*, ' . self::MANDATE_COLUMNS . ', ' . self::BALANCE_COLUMN
            . " {$from} ORDER BY {$orderBy} LIMIT ? OFFSET ?"
        );
        $stmt->execute($dataParams);
        $items = $stmt->fetchAll();

        return ['items' => $items, 'total' => $total];
    }
}
