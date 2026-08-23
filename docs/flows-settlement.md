# Flows: Settlement

How a member's Deckel becomes money, and what happens when it doesn't.

---

## 1. What a settlement contains

Not "the transactions in a date window" — **every unsettled transaction of each included member** ([#141](https://github.com/dgloeckner/clubbar/issues/141) §1–§2).

```mermaid
flowchart TD
    A["Run started<br/>period is descriptive only"] --> B["Candidate members"]
    B --> C{"Active mandate?"}
    C -->|no| D["EXCLUDED — cannot be billed<br/>⚠️ should be empty in steady state"]
    C -->|yes| E["Total unsettled position<br/>ALL rows, ignoring the window"]

    E --> F{"Balance?"}
    F -->|"> 0"| G["Settled · line in pain.008"]
    F -->|"= 0"| H["Settled · NO line<br/>closes the rows out"]
    F -->|"< 0"| I["EXCLUDED — in credit<br/>carried forward"]

    style D fill:#fdd,stroke:#c00
    style I fill:#ffd,stroke:#aa0
    style G fill:#dfd,stroke:#0a0
```

**Why the window cannot decide the amount:** overcharged €20 in January, drinks €5 in February. Settling February alone computes `+5`, debits €5 the member does not owe, and strands the €20 credit outside the run. Testing the total gives `-15` → excluded, both rows carry forward together.

**Two exclusion buckets, opposite remedies** — never merge them into one warning list:

| Bucket | Remedy |
|---|---|
| No active mandate | Chase the bank details ⚠️ *alarm — SEPA-only means this should be empty* |
| In credit | Pay them back, or let it ride |

---

## 2. Settlement methods

One field. Only `direct_debit` produces a file, and **only `direct_debit` may be exported** — which is what stops a settlement recorded as already-paid being sent to the bank ([#163](https://github.com/dgloeckner/clubbar/issues/163)).

```mermaid
flowchart LR
    A[Settlement] --> B["direct_debit<br/>many members<br/>pain.008"]
    A --> C["bank_transfer<br/>ONE member<br/>money already arrived"]
    A --> D["write_off<br/>ONE member<br/>money never arrives"]

    D -.->|"reachable only via"| E[["Offboarding<br/>#173"]]

    style B fill:#dfd,stroke:#0a0
    style E stroke-dasharray: 5 5
```

`bank_transfer` and `write_off` cover **exactly one member and their whole position**, so a partial payment is *inexpressible* — no picker, no typed amount. Underpaid? Record nothing; the tab stands.

---

## 3. Direct debit lifecycle

```mermaid
stateDiagram-v2
    [*] --> Draft: created
    Draft --> Exported: XML downloaded
    Exported --> Submitted: sent to bank
    Submitted --> Collected: execution date passes

    Draft --> Cancelled: no money moved
    Exported --> Cancelled: no money moved

    Collected --> Reversed: bank return / club error

    note right of Exported
        Downloading does NOT lock it.
        An explicit "submitted" does,
        with execution_date as backstop.
    end note

    note right of Cancelled
        Items are NEVER deleted.
        active_transaction_id is nulled,
        keeping the history and the
        DB-level double-settle guard.
    end note

    note right of Reversed
        Append-only reversal events.
        bank_return ⇒ collection hold,
        so the next sweep cannot
        re-debit the disputed amount.
    end note
```

**One cancellation rule across all methods** — *cancellable while no money has moved*:

| Method | Cancellable |
|---|---|
| `direct_debit` | until **submitted**, execution date as backstop |
| `bank_transfer` | **never** — the money already arrived |
| `write_off` | yes |

---

## 4. From drink to money

```mermaid
sequenceDiagram
    participant M as Member
    participant T as Terminal
    participant B as Backend
    participant K as Kassenwart
    participant Bank

    M->>T: scan card
    T-->>M: refused if no active mandate
    M->>T: pour drink
    T->>B: sync (may be much later)
    Note over B: store and FLAG — never reject.<br/>The drink is already gone.

    K->>B: create settlement
    B-->>K: preview: included · no-mandate · in-credit
    K->>B: export pain.008
    Note over B: only direct_debit is exportable
    K->>Bank: submit file
    Bank-->>M: debit on execution date

    opt collection returns
        Bank-->>K: return (EREF+ / MREF+ / MS03)
        K->>B: record return
        Note over B: reversal event + collection hold.<br/>Member locked out until settled<br/>by bank_transfer.
    end
```

⚠️ The **store-and-flag** step is not a detail. By sync time the beer is gone; rejecting the row destroys the record of a real sale — silent, unrecoverable revenue loss. The terminal is the only place refusal costs nothing ([ADR-0020](../adr/0020-sepa-mandate-requirement-terminal-access.md), amended).

---

## Related

- [ADR-0028](../adr/0028-legal-constraints-on-money-handling.md) · [ADR-0029](../adr/0029-two-tier-retention-and-erasure.md) · [ADR-0004](../adr/0004-immutable-transaction-storage.md) · [ADR-0009](../adr/0009-settlement-lead-times-bank-working-days.md)
- [Member lifecycle flows](./flows-member-lifecycle.md) · [Legal requirements](./legal-requirements-and-how-we-meet-them.md)
- `use-cases/admin/UC-A30`, `UC-A35` · `use-cases/sepa/`
