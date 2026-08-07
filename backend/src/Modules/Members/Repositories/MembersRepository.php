<?php

declare(strict_types=1);

namespace App\Modules\Members\Repositories;

use PDO;
use App\Shared\Logging\Logger;
use App\Shared\Repository\SafeQuery;

class MembersRepository
{
    /**
     * Banking data lives on the append-only `mandates` record, not on the
     * mutable member row (#164). Every read joins the member's active mandate
     * back in under the field names the API has always used, so the contract is
     * unchanged while the storage is not; renaming the contract belongs to
     * #164's implementation and #172.
     */
    private const MANDATE_JOIN =
        'LEFT JOIN mandates md ON md.active_member_id = m.id';

    private const MANDATE_COLUMNS =
        'md.iban, md.reference AS mandate_reference, md.signed_at AS mandate_signed_at';

    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*, ' . self::MANDATE_COLUMNS . ' FROM members m ' . self::MANDATE_JOIN
            . ' WHERE m.id = ? AND m.deleted_at IS NULL'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByIdIncludingDeleted(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*, ' . self::MANDATE_COLUMNS . ' FROM members m ' . self::MANDATE_JOIN
            . ' WHERE m.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findAll(): array
    {
        return $this->db->query(
            'SELECT m.*, ' . self::MANDATE_COLUMNS . ' FROM members m ' . self::MANDATE_JOIN
            . ' ORDER BY m.created_at DESC'
        )->fetchAll();
    }

    public function findModifiedSince(int $sinceTimestamp): array
    {
        // Convert milliseconds to seconds for date() function
        $sinceSeconds = (int) ($sinceTimestamp / 1000);
        $sinceDate = date('Y-m-d H:i:s', $sinceSeconds);

        // Include both updated and deleted items (tombstones)
        // This enables the terminal to remove deleted items from local cache
        // Use > (not >=) to avoid re-syncing items at exactly the cursor timestamp
        $stmt = $this->db->prepare(
            'SELECT m.*, ' . self::MANDATE_COLUMNS . ' FROM members m ' . self::MANDATE_JOIN . '
             WHERE m.updated_at > ? OR (m.deleted_at > ? AND m.deleted_at IS NOT NULL)
             ORDER BY COALESCE(m.updated_at, m.deleted_at) ASC'
        );
        $stmt->execute([$sinceDate, $sinceDate]);
        return $stmt->fetchAll();
    }

    public function create(array $data): array
    {
        $id = $data['id'] ?? $this->generateUuid();
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
        $iban = array_key_exists('iban', $data) ? ($data['iban'] ?: null) : ($current['iban'] ?? null);
        $reference = array_key_exists('mandate_reference', $data)
            ? ($data['mandate_reference'] ?: null)
            : ($current['reference'] ?? null);
        $signedAt = array_key_exists('mandate_signed_at', $data)
            ? ($data['mandate_signed_at'] ?: null)
            : ($current['signed_at'] ?? null);

        if ($current !== null && $iban === $current['iban']) {
            if ($reference === $current['reference'] && $signedAt === $current['signed_at']) {
                return;
            }

            $stmt = $this->db->prepare('UPDATE mandates SET reference = ?, signed_at = ? WHERE id = ?');
            $stmt->execute([$reference ?: $current['reference'], $signedAt, $current['id']]);
            $this->touchMember($id);
            return;
        }

        if ($current !== null) {
            $this->endMandate($current['id'], $iban === null ? 'revoked' : 'bank_change');
        }

        if ($iban !== null) {
            // A new account gets a freshly minted reference unless the caller
            // named one; carrying the old one forward is impossible anyway,
            // since the superseded mandate still holds it.
            $this->openMandate($id, [
                'iban' => $iban,
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
        $mandateId = $this->generateUuid();

        // Per ADR-0006 the reference is a UUID without hyphens; it is now minted
        // when the mandate is opened rather than when the member is created, so
        // a member without banking data has no reference at all.
        $reference = ($data['mandate_reference'] ?? null) ?: str_replace('-', '', $mandateId);

        $stmt = $this->db->prepare(
            'INSERT INTO mandates (id, member_id, active_member_id, reference, iban, signed_at, created_by_admin_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $mandateId,
            $memberId,
            $memberId,
            $reference,
            $data['iban'],
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

    public function deleteById(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM members WHERE id = ?');
        $result = $stmt->execute([$id]);
        $this->logger->info('Member deleted', ['id' => $id]);
        return $result && $stmt->rowCount() > 0;
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

    public function anonymize(string $id): bool
    {
        $now = date('Y-m-d H:i:s');
        // card_uid is VARCHAR(20), so use ANON- + 15 chars of UUID = 20 chars max
        $anonCardUid = 'ANON-' . substr(str_replace('-', '', $this->generateUuid()), 0, 15);
        $stmt = $this->db->prepare(
            'UPDATE members SET first_name = NULL, last_name = NULL, email = NULL, phone = NULL, account_holder_name = NULL, card_uid = ?, is_active = 0, deleted_at = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$anonCardUid, $now, $now, $id]);

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

        $columnMap = ['id' => 'm.id', 'first_name' => 'm.first_name', 'last_name' => 'm.last_name', 'balance' => 'balance_cents', 'created_at' => 'm.created_at'];
        $col = SafeQuery::column($sortKey, array_keys($columnMap));
        $sortColumn = $columnMap[$col];
        $dir = SafeQuery::direction($sortOrder);

        $from = 'FROM members m ' . self::MANDATE_JOIN . " {$whereClause}";

        $countStmt = $this->db->prepare("SELECT COUNT(*) {$from}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->db->prepare(
            'SELECT m.*, ' . self::MANDATE_COLUMNS . " {$from} ORDER BY {$sortColumn} {$dir} LIMIT ? OFFSET ?"
        );
        $stmt->execute($dataParams);
        $items = $stmt->fetchAll();

        return ['items' => $items, 'total' => $total];
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
