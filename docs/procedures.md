# Recurring Procedures

What someone has to *do*, on what rhythm, to keep the system lawful and the books straight. These are **organisational** duties — the software supports them, it does not discharge them.

| Rhythm | Procedure | Owner |
|---|---|---|
| Per event | [Onboarding a member](#onboarding-a-member) | Admin |
| Per event | [Offboarding a member](#offboarding-a-member) | Kassenwart |
| Per event | [A returned direct debit](#a-returned-direct-debit) | Kassenwart |
| Monthly | [The settlement run](#monthly-the-settlement-run) | Kassenwart |
| Annually | [Retention deletion review](./retention-deletion-procedure.md) | Kassenwart |
| Annually | [Data-protection review](#annually-data-protection-review) | Vorstand |
| Annually | [The backup restore drill](#annually-the-backup-restore-drill) | Admin |
| Quarterly | [The offline copy](#quarterly-the-offline-copy) | Admin |
| Once, after upgrading | [Moving product sizes out of their names](#once-after-upgrading-moving-product-sizes-out-of-their-names) | Getränkewart |

---

## Onboarding a member

1. Create the member record. **They cannot use the bar yet** — this is deliberate.
2. Have them sign the onboarding packet: **Art. 13 Datenschutzhinweis** (checkbox, never signed), the **SEPA mandate** (its own signature), and any **optional consents** (separately tickable).
3. Record the mandate: IBAN and **signature date**. Scanning the paper is optional — OCR prefills, but **an admin must always confirm** the extracted values.
4. Bar access opens **at the next terminal sync**, not immediately.

⚠️ Never record a signature date that was not actually signed. The pain.008 asserts it to the bank.

## Offboarding a member

One atomic action — see [the member lifecycle flows](./flows-member-lifecycle.md). It **cannot complete with an unresolved balance**.

1. Open offboarding; the system shows the member's whole final position.
2. Resolve it: **bank transfer** if they pay · **write-off** if they will not · **payout** if they are in credit (enter the transfer date and a bank reference).
3. The system erases contact data, restricts the accounting record, stamps the retention expiry and marks them gone.

⚠️ **Tell the member the truth**: they disappear from the system immediately, but the drink records survive — restricted — for up to ten years. This belongs in the privacy notice, not just here.

**Not the same as deactivation.** `is_active = false` is temporary (a lost card) and reversible, and it must never suppress collection of debt already owed.

## A returned direct debit

1. Record the return against the settlement, using `EREF+` / `MREF+` from the bank booking. Expect reason code **`MS03`** domestically — Germany suppresses the informative codes.
2. The member goes on **collection hold**, which locks them out of the bar. That is intended: it stops the debt growing while payment is failing.
3. They square up by **bank transfer**, recorded as a one-member settlement.
4. Access is restored.

⚠️ The original Verwendungszweck is **never returned by the bank** — it can never be a matching key.

## Monthly: the settlement run

1. Create the run. It sweeps **every unsettled transaction of each included member**, not just the period's — the period is descriptive.
2. Check the preview's two exclusion buckets:
   - **No active mandate** — ⚠️ should be **empty**. Anyone here is inside the terminal's offline sync window or on a post-return hold. Investigate; do not treat as routine.
   - **In credit** — normal. Carried forward, or paid out at offboarding.
3. Export the pain.008, submit it, and **mark it submitted**. Until then it can still be cancelled.
4. Watch for returns over the following weeks.

⚠️ Respect the lead time ([ADR-0009](../adr/0009-settlement-lead-times-bank-working-days.md)). An execution date that has passed by the time you submit will be rejected by the bank — or booked immediately, defeating the pre-notification.

## Annually: retention deletion review

Full procedure with SQL: **[retention-deletion-procedure.md](./retention-deletion-procedure.md)**. Runs with the **Kassenprüfung / Jahresabschluss**.

In one line: find offboarded members past their retention expiry, **ask the Steuerberater whether the tax years are finally assessed**, delete, and minute the outcome — including when nothing was deleted and why.

## Annually: data-protection review

Alongside the deletion review, since the same person is already looking:

1. **Is the Verzeichnis von Verarbeitungstätigkeiten (Art. 30) still accurate?** New processing, new recipients, changed retention.
2. **Is the Datenschutzhinweis still true?** Especially the profiling statement — Art. 13(2)(f) says no profiling occurs, and that is only true while no consumption-profile views exist ([ADR-0029](../adr/0029-two-tier-retention-and-erasure.md)).
3. **Audit admin-panel logins.** ⚠️ The § 38 BDSG Datenschutzbeauftragter threshold counts people *ständig* working with the automated processing. The club sits at roughly 3–6 against a threshold of **20** — but handing out logins freely would manufacture the obligation.
4. **Are optional consents still current**, and can withdrawals be demonstrated (Art. 7(1))?
5. Minute it.

## Annually: the backup restore drill

Runs with the Kassenprüfung, alongside the reviews above. **The Admin owns it**,
because backup keys are the Admin's and the Kassenwart's are the IBAN keys
([ADR-0049](../adr/0049-encrypted-offsite-backups-on-shared-hosting.md)
decision 2).

An untested backup is a belief. This is the hour a year that turns it into a
backup.

1. **Walk [runbook §1](./runbook-backup-recovery.md#1-restore-end-to-end)** —
   decrypt a real archive and import it into a scratch database. Not the live
   one.
2. **Walk [runbook §2](./runbook-backup-recovery.md#2-repair-one-table)** as
   well. Drop one table from that scratch restore and bring it back from its
   section alone. This is the path you are far likelier to need, and the one
   with a wrong-looking-right failure mode; walking only §1 leaves it untested.
3. **Test the private-key archive, don't just trust it.** Pull the archived
   backup private key and confirm it opens the archive you just decrypted. A key
   corrupted on write, saved with the wrong encoding, or quietly the *previous*
   rotation's is indistinguishable from a good one until the day it is needed.
   (Adopts the duty [`deployment.md`](./deployment.md) states for the IBAN key —
   do both, they are different keys held by different people.)
4. **Test that the audit log restores.** Confirm `audit_log` came back with its
   rows: it is the one table whose loss is undetectable from the application,
   because nothing else references it. (Adopts the annual duty
   [ADR-0013](../adr/0013-audit-logging.md) states and nobody owned.)
5. **Minute the result in the club's key register** — which archive, which key,
   who performed it, and anything that did not work. The application does not
   track key verification and deliberately never will (ADR-0049 decision 4).

If any step fails, it is an incident now, not at the next Jahresabschluss.

## Quarterly: the offline copy

Download one archive to a medium **the server cannot write to** — a USB stick in
the club safe, a private laptop, anything not reachable from the host.

This is a duty and not a suggestion, because it is the only thing covering the
gap the design cannot close on its own: the backup credential can delete what it
wrote. `Sites.Selected` on Microsoft 365 restricts *which* site, but the per-site
role is a fixed `read`/`write` enum and `write` includes delete
([#691](https://github.com/dgloeckner/clubbar/issues/691)). Library retention
makes such a delete *recoverable*, not impossible, and only where the tenant
allows it. An attacker holding the webspace holds the upload credential; a copy
they cannot reach is what survives them.

One archive, once a quarter. Note the date and the filename in the key register.
No decryption needed — an unopened `.cbb` is still a backup, and opening it
outside the drill only spreads the plaintext around.

---

## Once, after upgrading: moving product sizes out of their names

A product's size used to have nowhere to go but its name — `Weizenbier (0,5l)`,
`Pils 0,5L`, `Bier 0,5 l`. Since [ADR-0056](../adr/0056-product-volume.md) it has
a field of its own, and **nothing was moved automatically**: a rule that guessed
would mangle a minority of names silently, on a screen members read. So it is a
one-off pass through the product list, by hand.

**Per product, one save:**

1. Open the product in *Produkte*.
2. Delete the size from the name, **in every language tab** — `Weizenbier (0,5l)`
   becomes `Weizenbier`, `Wheat beer (0.5l)` becomes `Wheat beer`.
3. Choose the size in **Größe**: `500 ml`. The list offers the sizes a club
   pours — 1000, 500, 330, 300, 250 and 200 ml — and the preview beside the form
   shows what a member will read on the terminal: `0,5 l`.
4. Save.

Doing both in one save is what keeps a product from being left half-renamed.

**What to expect afterwards**

- The terminal picks the change up on its next delta sync — no restart, no
  redeploy. The tile then shows the name on one line with the size as a badge
  under it, and the price gets the room that frees.
- Every surface that prints the product prints the size after it — the
  Deckelauszug, settlement mail, the journal, reports and the CSV exports — each
  in its reader's own notation.
- **Leave the size empty for anything that has none.** A Sauna-Token, a Kaffee, a
  Portion Nüsse. Empty is not the same as `0`, and `0` is refused.
- Something that is not a volume — a Portion, a Stück — stays in the name. This
  field is millilitres only.
- A size the list does not offer stays on any product that already has one, and
  can be re-selected there; it just cannot be set on a new product. If your club
  pours a size that is missing, ask for it to be added to the list rather than
  writing it back into the name.
- Editing a size changes how *past* bookings read, exactly as renaming a product
  already does. That is worth knowing before you edit a product with a long
  history; it is not new behaviour.

⚠️ Do the pass before a settlement run rather than during one, so a member
comparing a Deckelauszug against the mail beside it sees the same names on both.

## Why these are written down

A retention policy that assumes software will still be running, and remembered, in twelve years is not a policy. The system stores the dates and answers the queries; **the club performs the reviews**. Under Art. 5(2) DSGVO it must be able to *demonstrate* it manages retention — and "we always meant to" is not a demonstration.

Things needing the Steuerberater, the bank or the Vorstand are tracked as issues labelled **`owner-action`**.
