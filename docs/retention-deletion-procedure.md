# Procedure: Annual Retention Review and Deletion

**For:** Kassenwart (or whoever runs the Jahresabschluss)
**When:** Once a year, as part of the **Kassenprüfung / Jahresabschluss**
**Why:** [ADR-0029](../adr/0029-two-tier-retention-and-erasure.md) — offboarded members leave a retained accounting residual that must be deleted once its retention period ends. Nothing deletes it automatically, deliberately: see §"the date is a floor".

> **This is a written procedure, not a feature.** The system stores the dates and answers the queries; the club performs the review. A retention policy that assumes software will still be running and remembered in twelve years is not a policy.

---

## Step 1 — Find what is due

Offboarded members whose retention period has passed:

```sql
SELECT id, retention_expires_at
FROM   members
WHERE  deleted_at IS NOT NULL          -- offboarded, personal data already erased
  AND  retention_expires_at IS NOT NULL
  AND  retention_expires_at <= CURDATE();
```

If this returns nothing, the review is done. Record that it ran (Step 4) and stop.

**Sanity check** — nothing should be offboarded without a date:

```sql
SELECT id FROM members
WHERE  deleted_at IS NOT NULL AND retention_expires_at IS NULL;
```

Any row here is a bug: the residual has no expiry and would be kept forever. Do not delete it — report it.

---

## Step 2 — Check whether the period is actually over ⚠️

**`retention_expires_at` is the earliest date, not a due date.** § 147 Abs. 3 S. 5 AO suspends expiry while the Festsetzungsfrist is still running — for example where a tax return for one of those years has not yet been finally assessed, or a Betriebsprüfung is open or pending.

**Ask the Steuerberater before deleting anything:** *are the tax years covered by these records finally assessed, with no open or announced Prüfung?*

- **No / unsure** → stop. Leave the data, note the reason, revisit next year.
- **Yes** → continue.

This is the step that cannot be automated, and it is why deletion is a human act.

---

## Step 3 — Delete the residual

For each confirmed member id, in this order (children before parents):

```sql
-- 1. Settlement items referencing that member's transactions
DELETE si FROM settlement_items si
  JOIN transactions t ON si.transaction_id = t.id
 WHERE t.member_id = :member_id;

-- 2. Transactions (purchases, stornos, payouts)
DELETE FROM transactions WHERE member_id = :member_id;

-- 3. Mandates (reference, IBAN, signature date) and any stored document
DELETE FROM mandates WHERE member_id = :member_id;

-- 4. The member row itself
DELETE FROM members WHERE id = :member_id;
```

Run inside one transaction per member, so a failure leaves nothing half-deleted.

> **On append-only.** [ADR-0004](../adr/0004-immutable-transaction-storage.md) forbids UPDATE and DELETE on transactions. That governs their *lifetime*; retention expiry is the end of it. This procedure is the only place deletion is legitimate — nothing in the application may do it.

**Settlement aggregates** (`settlements`) are club-level totals, not personal data, and stay. In practice a member's residual and the settlements containing it age out together, since both clocks run from the same years — but if an aggregate outlives its items, that is expected and not a fault.

---

## Step 4 — Record that the review happened

Note in the Jahresabschluss / Kassenprüfung minutes:

- the date of the review
- how many members were reviewed and how many deleted
- if nothing was deleted, **why** — nothing due, or Step 2 said wait

The record matters: under Art. 5(2) DSGVO the club must be able to demonstrate it manages retention, and "we always meant to" is not a demonstration.

---

## If the software is gone

If this system has been replaced or retired and data was migrated, the obligation moves with the data. Run the equivalent of Step 1 against whatever holds it. If the data was **not** migrated and no longer exists, the obligation is discharged — record that too.
