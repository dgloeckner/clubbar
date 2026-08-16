<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Repositories;

use App\Shared\Repository\UnsettledTransactions;
use DateTimeImmutable;
use PDO;

/**
 * Who receives a Deckelauszug for a given period (ADR-0039 decision 3).
 *
 * The whole ruling is one query, and it is worth reading as prose because every
 * clause in it was argued:
 *
 * | Case | Ruling | Why |
 * |---|---|---|
 * | Active, owes money | **Send** | The point |
 * | Active, owes nothing | **Send** | A statement that arrives only when you owe something is a nudge wearing a statement's clothes. Boring and predictable is the product |
 * | Active, in credit | **Send** | Stated as a credit, promising no Payout — that is a separate act |
 * | Inactive or deleted, tab is zero | Skip | Nothing to say, and nobody expecting to hear it |
 * | Inactive or deleted, tab is **not** zero | **Send** | Deactivating a member does not cancel what they owe, and going dark on a debt you will still collect is the one case where silence is wrong |
 * | No email address | **Skip, silently** | In practice an anonymised member (ADR-0029 clears `email`). There is nobody to write to and nothing an admin could fix |
 *
 * "Silently" is meant literally: no log line per member, no counter, no
 * self-check finding. A member whose address someone blanked in the admin panel
 * is indistinguishable from an erased one, and ADR-0039 accepts that rather
 * than build a warning surface for a case that should not occur.
 *
 * The tab is evaluated **at the period boundary**, not now, using
 * {@see UnsettledTransactions::balanceAsOf()} — the same instant the statement
 * will state. Scope and content therefore cannot disagree: an inactive member
 * who is in this list is exactly an inactive member whose statement will have
 * something on it.
 */
class StatementRecipientsRepository
{
    public function __construct(
        private PDO $db,
    ) {}

    /**
     * Every member in scope for a statement dated `$boundary`.
     *
     * Read in one go rather than streamed. A club large enough for that to
     * matter does not exist in the deployment ADR-0031 describes — three
     * hundred members is a few tens of kilobytes — and the enqueue that consumes
     * this deliberately runs outside a transaction, so holding a cursor open
     * across it would buy nothing and cost a connection.
     *
     * Ordered by id so two runs walk the members in the same order. Nothing
     * depends on it — the unique index makes order irrelevant to correctness —
     * but a killed run resumed by the next tick then covers the same ground
     * first, which is one less thing to wonder about when reading a partial
     * scan.
     *
     * @return list<array{id:string,email:string,preferred_language:?string}>
     */
    public function findForBoundary(DateTimeImmutable $boundary): array
    {
        [$balance, $params] = UnsettledTransactions::balanceAsOf($boundary);

        $stmt = $this->db->prepare(
            'SELECT m.id, m.email, m.preferred_language
               FROM members m
              WHERE m.email IS NOT NULL AND TRIM(m.email) <> \'\'
                AND ((m.is_active = 1 AND m.deleted_at IS NULL) OR ' . $balance . ' <> 0)
              ORDER BY m.id ASC'
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
