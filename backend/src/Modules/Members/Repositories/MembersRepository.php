<?php

declare(strict_types=1);

namespace App\Modules\Members\Repositories;

use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Shared\Exceptions\DuplicateResourceException;
use App\Shared\Security\IbanSealedBox;
use App\Shared\Utils\Uuid;
use PDO;
use App\Modules\Members\Domain\MandateCompleteness;
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
     * @return array<string, array{id: string, first_name: string, last_name: string, email: ?string, preferred_language: ?string, credit_limit_cents: ?int, mandate_reference: ?string, iban_last4: ?string}>
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
            . 'm.credit_limit_cents, '
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
            'INSERT INTO members (id, card_uid, first_name, last_name, email, date_of_birth, preferred_language, credit_limit_cents, is_active, account_holder_name, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        // The member and their mandate are created as one unit: openMandate can
        // refuse (a reference already taken, no operational encryption key), and
        // a refusal after the members row landed would leave behind a member the
        // admin never got told about — created, bankless, and reported as an
        // error.
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $stmt->execute([
                $id,
                $data['card_uid'] ?? null,
                $data['first_name'],
                $data['last_name'],
                $data['email'],
                $data['date_of_birth'] ?? null,
                $data['preferred_language'] ?? 'de',
                $data['credit_limit_cents'] ?? null,
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

            // `??` as well as `?:`: a caller creating a member with no banking
            // data at all omits the key rather than sending an empty one, and
            // every such call raised an "Undefined array key" warning on its way
            // to the correct answer.
            if ((($data['iban'] ?? null) ?: null) !== null && $reference !== null) {
                $this->openMandate($id, ['mandate_reference' => $reference] + $data);
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->logger->info('Member created', ['id' => $id]);
        return $this->findById($id);
    }

    /**
     * Create a member and their mandate from an **already-sealed** submission
     * (ADR-0052 decision 9, #779).
     *
     * The one write path in this class that never sees a plaintext IBAN, and
     * the reason it exists: by the time an admin approves a self-registration
     * the plaintext is months gone — sealed at submission under a key this
     * server does not hold (ADR-0036). There is nothing to re-seal from, so the
     * ciphertext, its last four, its fingerprint and its key id are copied
     * across **byte for byte**.
     *
     * That includes copying the key id rather than the current active key. It
     * looks like a lapse and is the opposite of one: the ciphertext can only be
     * opened by the key that sealed it, so relabelling it with today's active
     * key would produce a mandate nobody can ever collect on. If the sealing key
     * has since been retired, the row is exactly as re-sealable as every other
     * mandate under that key, and the rotation batch is what handles it — with
     * the private key in an operator's hand, which is the only place it exists.
     *
     * The mandate reference is copied too, not minted: it was printed on the
     * paper this member signed (ADR-0006, ADR-0052 decision 4), so the mandate
     * has to carry the reference the bank will see on the collection.
     *
     * Both rows land or neither does. A member created without their mandate is
     * SEPA-invalid, invisible to the next collection, and — since approval
     * deletes the pending row — no longer recoverable from anywhere.
     *
     * @param array<string, mixed> $member  the members-row fields
     * @param array<string, mixed> $mandate reference, sealed quartet, bank name, signed_at
     * @return array<string, mixed> the member as `findById()` sees them
     */
    public function createFromSealedMandate(array $member, array $mandate): array
    {
        $id = $member['id'] ?? Uuid::v4();
        $now = date('Y-m-d H:i:s');

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $this->db->prepare(
                'INSERT INTO members (id, card_uid, first_name, last_name, email, date_of_birth, preferred_language, credit_limit_cents, is_active, account_holder_name, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $id,
                // Always null here. A self-registered member gets their card
                // in a second, deliberate step (ADR-0021), which is also what
                // sends the welcome mail — approval is not an onboarding.
                null,
                $member['first_name'],
                $member['last_name'],
                $member['email'],
                $member['date_of_birth'] ?? null,
                $member['preferred_language'] ?? 'de',
                $member['credit_limit_cents'] ?? null,
                1,
                $member['account_holder_name'] ?? null,
                $now,
                $now,
            ]);

            $mandateId = Uuid::v4();
            $stmt = $this->db->prepare(
                'INSERT INTO mandates (id, member_id, active_member_id, reference, iban_ciphertext, iban_last4, iban_fingerprint, encryption_key_id, bank_name, signed_at, created_by_admin_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            try {
                $stmt->execute([
                    $mandateId,
                    $id,
                    $id,
                    $mandate['reference'],
                    $mandate['iban_ciphertext'],
                    $mandate['iban_last4'],
                    $mandate['iban_fingerprint'],
                    $mandate['encryption_key_id'],
                    $mandate['bank_name'] ?? null,
                    ($mandate['signed_at'] ?? null) ?: null,
                    $mandate['created_by_admin_id'] ?? null,
                ]);
            } catch (\PDOException $e) {
                // The reference was minted at submission and has been sitting
                // in the pending row ever since — long enough for a member to
                // have been created carrying it by some other route. That is
                // the club's collision to resolve, not an internal error.
                if ($this->isDuplicateReference($e)) {
                    throw new DuplicateResourceException(
                        "Mandate reference '{$mandate['reference']}' is already in use"
                    );
                }
                throw $e;
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->logger->info('Member created from a self-registration', ['id' => $id]);

        return $this->findById($id);
    }

    /**
     * Which of these addresses the club already has a member at.
     *
     * Asked once per page of the review inbox rather than once per row: a
     * twenty-row page is one query, not twenty. Anonymized members are excluded
     * — their email is NULL, so they cannot match anyway, and a person who
     * exercised erasure and later re-applies is a new applicant.
     *
     * @param list<string> $emails
     * @return list<string> the subset that exists
     */
    public function findExistingEmails(array $emails): array
    {
        $emails = array_values(array_unique(array_filter($emails, static fn($e): bool => is_string($e) && $e !== '')));
        if ($emails === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($emails), '?'));
        $stmt = $this->db->prepare(
            "SELECT DISTINCT email FROM members WHERE deleted_at IS NULL AND email IN ({$placeholders})"
        );
        $stmt->execute($emails);

        return array_map(static fn(array $row): string => (string) $row['email'], $stmt->fetchAll());
    }

    /**
     * Which of these accounts the club already holds an **active** mandate on.
     *
     * Active only: an ended mandate is a bank the member has left (#165), and
     * flagging a returning applicant because of an account nobody collects from
     * any more would be noise on the one signal that matters.
     *
     * The fingerprint is what makes this answerable without a key — sealed
     * boxes are randomized, so ciphertexts never compare equal (ADR-0036).
     *
     * @param list<string> $fingerprints
     * @return list<string> the subset that exists
     */
    public function findExistingIbanFingerprints(array $fingerprints): array
    {
        $fingerprints = array_values(array_unique(array_filter(
            $fingerprints,
            static fn($f): bool => is_string($f) && $f !== '',
        )));
        if ($fingerprints === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($fingerprints), '?'));
        $stmt = $this->db->prepare(
            'SELECT DISTINCT iban_fingerprint FROM mandates '
            . "WHERE active_member_id IS NOT NULL AND iban_fingerprint IN ({$placeholders})"
        );
        $stmt->execute($fingerprints);

        return array_map(static fn(array $row): string => (string) $row['iban_fingerprint'], $stmt->fetchAll());
    }

    public function updateById(string $id, array $data): ?array
    {
        $allowed = ['card_uid', 'first_name', 'last_name', 'email', 'date_of_birth', 'preferred_language', 'credit_limit_cents', 'is_active', 'account_holder_name', 'deleted_at', 'deleted_by_admin_id'];

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

        // A new account gets a freshly minted reference unless the caller named
        // one; carrying the old one forward is impossible anyway, since the
        // superseded mandate keeps its row — and its UNIQUE reference — after
        // being ended.
        //
        // "Named one" therefore excludes the reference of the mandate being
        // superseded. The admin edit form prefills that field from the mandate
        // in hand, so a plain "this member moved banks" submits the old
        // reference right back; reading that as a request to reuse it hit the
        // UNIQUE key and turned the save into a 500. Echoing it means
        // unchanged, not reuse. A genuinely different reference is still
        // honoured — that is the caller stating what the new mandate was
        // signed under.
        $namedReference = ($data['mandate_reference'] ?? null) ?: null;
        if ($current !== null && $namedReference === $current['reference']) {
            $namedReference = null;
        }

        // End-then-open is one move and must be atomic. openMandate can refuse
        // for reasons the caller cannot see coming — no operational encryption
        // key (ADR-0036), a reference already taken — and a failure landing
        // between the two writes leaves the member with no active mandate at
        // all: SEPA-invalid, excluded from the next collection, from a save
        // that reported an error. That is precisely what the duplicate-reference
        // 500 did before this ran in a transaction.
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            if ($current !== null) {
                $this->endMandate($current['id'], $submittedIban === null ? 'revoked' : 'bank_change');
            }

            if ($submittedIban !== null) {
                $this->openMandate($id, [
                    'iban' => $submittedIban,
                    'bank_name' => $data['bank_name'] ?? null,
                    'mandate_reference' => $namedReference,
                    'mandate_signed_at' => $signedAt,
                ]);
            }

            $this->touchMember($id);

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }
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
        try {
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
        } catch (\PDOException $e) {
            // A reference the caller named that some other mandate already
            // holds is their mistake to correct, and the only way out is to
            // name a different one — so it belongs in the response as a 422
            // naming the collision, not as the unactionable "internal server
            // error" the bare PDOException produced. The minted
            // reference cannot land here: it is a fresh UUID.
            if ($this->isDuplicateReference($e)) {
                throw new DuplicateResourceException("Mandate reference '{$reference}' is already in use");
            }
            throw $e;
        }
    }

    /**
     * Whether a write failed on the UNIQUE key over `mandates.reference`.
     *
     * Matched on the key name rather than the SQLSTATE alone: the table's other
     * unique key, `uq_mandates_active_member`, guards "at most one active
     * mandate per member" and is an invariant of this code, not of the caller's
     * input — reporting that one as a validation error would blame the admin
     * for a bug.
     */
    private function isDuplicateReference(\PDOException $e): bool
    {
        return $e->getCode() === '23000'
            && str_contains($e->getMessage(), 'Duplicate entry')
            && str_contains($e->getMessage(), 'reference');
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

    /**
     * Active members, as the roster defines them.
     *
     * `deleted_at IS NULL` is not decoration: anonymization sets `deleted_at`
     * without necessarily clearing `is_active`, so this used to count members
     * the roster can never show — the dashboard card read 1219 against a list
     * holding 1197. Nobody noticed until #629 put a second, correctly scoped
     * figure on the same screen and the two disagreed by 22.
     */
    public function countActive(): int
    {
        return (int) $this->db
            ->query('SELECT COUNT(*) FROM members WHERE is_active = 1 AND deleted_at IS NULL')
            ->fetchColumn();
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
     * - `date_of_birth` is a direct identifier of the person and OLG Dresden
     *   4 U 1278/21 names it explicitly alongside the name and the address
     *   (ADR-0029). It is `required` when a member is created (ADR-0045) and
     *   nullable in the column purely so this write can happen — which makes a
     *   NULL birth date mean exactly one thing: this member has been erased.
     *   The terminal reads it that way and refuses every restricted product.
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
            'UPDATE members SET first_name = NULL, last_name = NULL, email = NULL, date_of_birth = NULL, account_holder_name = NULL, collection_hold_reason = NULL, card_uid = ?, is_active = 0, deleted_at = ?, deleted_by_admin_id = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL'
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

    /**
     * How many active members are missing each piece of mandatory data (#629).
     *
     * One query rather than four `per_page=1` probes, and **active members
     * only**: an inactive member cannot book at the terminal and is not
     * collected from, so their gaps are not work anyone needs to do — and a
     * headline figure that can never reach zero stops being read. Anonymized
     * members carry `deleted_at` and are excluded by the same clause the
     * roster uses, which is what keeps GDPR erasure from reading as a data
     * quality problem.
     *
     * The four gaps are exactly the four the roster can filter on, so every
     * count has a list behind it that holds precisely the members it counted.
     *
     * @return array{total:int, without_card_uid:int, without_email:int, without_date_of_birth:int, without_mandate:int, incomplete:int}
     */
    public function countDataGaps(): array
    {
        $stmt = $this->db->query(
            'SELECT COUNT(*) AS total,'
            . ' COALESCE(SUM(m.card_uid IS NULL), 0) AS without_card_uid,'
            . ' COALESCE(SUM(m.email IS NULL), 0) AS without_email,'
            . ' COALESCE(SUM(m.date_of_birth IS NULL), 0) AS without_date_of_birth,'
            . ' COALESCE(SUM(' . MandateCompleteness::SQL_INCOMPLETE . '), 0) AS without_mandate,'
            . ' COALESCE(SUM(m.card_uid IS NULL OR m.email IS NULL'
            . ' OR m.date_of_birth IS NULL OR ' . MandateCompleteness::SQL_INCOMPLETE . '), 0) AS incomplete'
            . ' FROM members m ' . self::MANDATE_JOIN
            . ' WHERE m.deleted_at IS NULL AND m.is_active = 1',
        );
        $row = $stmt->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'without_card_uid' => (int) ($row['without_card_uid'] ?? 0),
            'without_email' => (int) ($row['without_email'] ?? 0),
            'without_date_of_birth' => (int) ($row['without_date_of_birth'] ?? 0),
            'without_mandate' => (int) ($row['without_mandate'] ?? 0),
            'incomplete' => (int) ($row['incomplete'] ?? 0),
        ];
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
        // Email presence filter — finds the legacy members predating the
        // required-email rule (#362) so a Kassenwart can backfill them.
        if (isset($filters['has_email'])) {
            if ($filters['has_email']) {
                $where[] = 'm.email IS NOT NULL';
            } else {
                $where[] = 'm.email IS NULL';
            }
        }
        // SEPA status filter. An active mandate is the whole predicate, and
        // `MandateCompleteness` is what "active" means — the record plus its
        // signature date (ADR-0020, #164). The record cannot exist without an
        // IBAN and a reference, since both are NOT NULL on `mandates`; the
        // date is the third fact, and it is nullable, so it has to be asked
        // for. Filtering for `invalid` therefore returns exactly the members
        // the terminal now refuses and the SEPA export now excludes.
        if (isset($filters['sepa_status'])) {
            $where[] = $filters['sepa_status'] === 'valid'
                ? MandateCompleteness::SQL
                : MandateCompleteness::SQL_INCOMPLETE;
        }
        // Birth-date presence filter (#629). Not the date — the roster never
        // carries one (ADR-0045) — only whether there is one, which is what
        // makes the members the terminal will refuse every age-restricted
        // product to findable at all.
        if (isset($filters['has_date_of_birth'])) {
            $where[] = $filters['has_date_of_birth']
                ? 'm.date_of_birth IS NOT NULL'
                : 'm.date_of_birth IS NULL';
        }
        // "Show me everyone with a gap" (#629), as one predicate rather than
        // four requests intersected in the browser — an `OR` cannot be
        // assembled out of the single-gap filters above, and a client-side
        // union would be wrong the moment the result spans a page.
        if (isset($filters['data_status'])) {
            $incomplete = '(m.card_uid IS NULL OR m.email IS NULL'
                . ' OR m.date_of_birth IS NULL OR ' . MandateCompleteness::SQL_INCOMPLETE . ')';
            $where[] = $filters['data_status'] === 'incomplete' ? $incomplete : "NOT {$incomplete}";
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
