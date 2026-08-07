# ADR-0029: Two-Tier Retention and Erasure

**Status**: Accepted
**Date**: 2026-08-07

## Context

A member has the right to erasure under Art. 17 DSGVO. The club has a record-keeping duty under § 63 Abs. 3 AO and a retention duty under § 147 AO. These meet on the same rows.

[ADR-0028](./0028-legal-constraints-on-money-handling.md) §5 establishes the periods — **10 years** for the transaction journal as *Aufzeichnungen* under § 147 Abs. 1 Nr. 1, running from 31.12. of the year of the last entry. That is long enough that "delete when the period expires" is not a workable answer on its own: it must be designed for, or it never happens.

Two things in the code today are wrong in opposite directions, which is what makes this worth an ADR rather than a bug fix:

- `MembersService::anonymizeMember()` refuses to run at all if the member appears in **any** non-cancelled settlement, with no time bound. After the first settlement run, erasure becomes permanently impossible for every member ever billed.
- `MembersRepository::anonymize()` NULLs `iban` and `mandate_reference` — both Beleg-bearing and both legally required to survive — while `docs/erm-master.md` records `mandate_signed_at` as retained.

So the system currently both over-blocks erasure and, when it does run, deletes the wrong fields.

Researched in [#174](https://github.com/dgloeckner/ruderbar/issues/174); decided in [#165](https://github.com/dgloeckner/ruderbar/issues/165).

## Decision

**Erasure deletes the *person*. It does not delete the *record*.**

Member data is split into two tiers, and the boundary is **what the application can read**.

| Tier | Contents | On erasure |
|---|---|---|
| **Operational** | What the admin UI and terminal use to run the club | **Deleted.** Nulled, gone, not recoverable |
| **Retention** | The accounting record | **Restricted.** Kept until the retention period expires, then deleted |

### The field split

Retention is bounded by the *recording* duty — **GoBD Rz. 113**: *„Der sachliche Umfang der Aufbewahrungspflicht in § 147 Absatz 1 AO besteht grundsätzlich nur im Umfang der Aufzeichnungspflicht"*. So the split is at **field** level, not record level.

**OLG Dresden, 14.12.2021, 4 U 1278/21** decides it at that granularity: the deletion duty *„beschränkt sich auf den Namen, die Anschrift und das Geburtsdatum"*, with identifying data **redacted** on retained business records rather than the records destroyed.

| Delete — no Belegfunktion | Retain (restricted) — Beleg-bearing |
|---|---|
| Email, phone, `preferred_language` | Per-transaction records: item, quantity, price, timestamp, member link |
| RFID/NFC card UID, PIN, credentials, sessions | Settlement and monthly totals; payment, return and reversal records |
| Photo/avatar, free-text notes, marketing flags | IBAN, mandate reference (UMR), the mandate document |
| Postal address, date of birth ⚠️ | The identifier tying transactions to the record (GoBD Rz. 64 Ordnungskriterium) |

⚠️ **Address and date of birth are deletable only because this club issues no invoices.** If it ever issues a Rechnung with USt, § 14 Abs. 4 Nr. 1 UStG pulls the address onto a retained Beleg and it moves columns.

### Restriction is enforced by access, not by a flag

A restricted record is **not** shown in member lists, **not** searchable, **not** exported, and **not** synced to any terminal. It is reachable only for a tax audit.

This must be a property of how the data is reached, not a boolean that every future query is expected to remember. A flag that four call sites check and a fifth forgets is how the current `is_sepa_valid` divergence happened.

`MembersRepository::listPaginated()` already filters `deleted_at IS NULL`, so the mechanism is half-built.

### A retention-expiry date is stored per member

Computed at offboarding: **31.12. of the year of the member's last transaction, plus 10 years.** When it passes, the residual is deleted.

Without a stored date, the deletion step has no trigger and retained data becomes permanent by accident — which is a GDPR failure, not merely untidiness.

### But the date is a floor, and the trigger must be a human

**A stamped date is not a trigger.** Two things make unattended deletion the wrong design here:

1. **The period is not computable.** § 147 Abs. 3 S. 5 AO suspends expiry while the Festsetzungsfrist runs, so `retention_expires_at` is the **earliest** the residual may go, not the date it must. Something has to check whether the suspension applies before anything is deleted — and no scheduled job can know that.
2. **Twelve years is longer than the software.** Any mechanism relying on this system running continuously until 2038 will fail silently: hosting moves, the stack gets rewritten, the treasurer changes. A cron job authored today is not a policy.

So the trigger is a **written procedure**, not a feature: [`docs/retention-deletion-procedure.md`](../docs/retention-deletion-procedure.md). It runs annually as part of the **Kassenprüfung / Jahresabschluss** — the one thing in a Verein that reliably happens every year and already involves someone examining the books.

The system's only obligations are to **store the dates** and **answer the query**. It builds no sweep, no dashboard and no deletion UI: a feature that must survive a decade of hosting changes and rewrites is a worse bet than a page in the annual checklist, and an automated sweep would delete on a date the law can extend.

The procedure covers the step no machine can take — asking the Steuerberater whether the relevant tax years are finally assessed with no Prüfung open, since that is what governs whether the Ablaufhemmung still applies.

⚠️ **The retention obligation is organisational, not technical.** The club must carry the deletion review in its documented annual process; the system merely makes it answerable.

### The blocking guard tests for an unresolved position

Erasure is blocked only by a position that is **still collectable or reversible** — not by historical participation in a settlement. A settlement completed years ago blocks nothing.

## Legal mechanism

This is **Art. 17(3)(b) DSGVO** — for the compelled data the erasure obligation simply never arises:

> „Die Absätze 1 und 2 gelten nicht, soweit die Verarbeitung erforderlich ist … zur Erfüllung einer rechtlichen Verpflichtung …"

It is **not** an Art. 18(1) case. Lit. b requires the processing to be *unlawful* (it is compelled); lit. c requires the *data subject* to need the data for legal claims (here the controller is compelled). "Restrict rather than delete" then rests on Art. 5(1)(b)/(c)/(e) — Zweckbindung, Datenminimierung, Speicherbegrenzung.

⚠️ **No settled case law squarely holds that Art. 17(3)(b) covers § 147 AO.** It is the universal supervisory view and uncontroversial in practice, but it has not been adjudicated head-on — no BFH, BAG or EuGH decision found.

## Consequences

**Positive**

- A member can be erased **immediately** in the sense that matters: they disappear as a person, from every screen and every terminal.
- The two bugs above are structurally excluded rather than fixed — the guard tests the right thing, and the field split is written down where the next implementer will find it.
- The stored expiry plus a written procedure makes deletion an act someone is accountable for, on a schedule that already exists.

**Negative**

- **A departing member cannot be fully erased for up to ten years.** This is the honest consequence and it must be **disclosed to members**, in the privacy notice and on the offboarding screen. Implying erasure is complete when it is not is worse than the delay itself. See [#175](https://github.com/dgloeckner/ruderbar/issues/175).
- Restriction-by-access costs more than a flag, and every new read path must go through it.
- **Deletion depends on the club, not the software.** If nobody runs the annual review, data outlives its basis and the system will not stop it. That is inherent: the alternative — an unattended sweep — would delete on a date the law can extend. Mitigation is that the review sits on the Kassenprüfung checklist and its outcome is minuted, so a missed year is visible in the club's own records rather than nowhere.
- The Art. 17(3)(b) reading is settled in practice but unadjudicated.

**Neutral**

- Anonymisation stops being a single `UPDATE`. It becomes: resolve balance → delete operational tier → restrict retention tier → stamp expiry. That is [offboarding](https://github.com/dgloeckner/ruderbar/issues/173), and it is atomic.

## Related

- [ADR-0028: Legal Constraints on Money Handling](./0028-legal-constraints-on-money-handling.md) — §5 retention periods, §8 the erasure split
- [ADR-0004: Immutable Transaction Storage](./0004-immutable-transaction-storage.md) — why the records cannot simply be deleted
- [ADR-0006](./0006-sepa-mandate-reference-strategy.md) — the mandate record whose fields are Beleg-bearing
- `use-cases/dsgvo/uc-dsgvo-02-right-to-erasure.md`
- [#165](https://github.com/dgloeckner/ruderbar/issues/165) the ruling · [#173](https://github.com/dgloeckner/ruderbar/issues/173) offboarding · [#151](https://github.com/dgloeckner/ruderbar/issues/151) the retention-expiry column
