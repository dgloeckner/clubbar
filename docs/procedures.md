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

---

## Why these are written down

A retention policy that assumes software will still be running, and remembered, in twelve years is not a policy. The system stores the dates and answers the queries; **the club performs the reviews**. Under Art. 5(2) DSGVO it must be able to *demonstrate* it manages retention — and "we always meant to" is not a demonstration.

Things needing the Steuerberater, the bank or the Vorstand are tracked as issues labelled **`owner-action`**.
