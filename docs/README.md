# Documentation

Reference documentation for Club Bar. Start with the
[project README](../README.md) for what the system is and
[`CLAUDE.md`](../CLAUDE.md) for how it is built.

Architectural *decisions* live in [`../adr/`](../adr/) — 47 of them, indexed in
[`../adr/README.md`](../adr/README.md). This directory is everything else.

## Data model

| Document | What it covers |
|---|---|
| [erm-master.md](./erm-master.md) | The backend MariaDB schema — every table, column and relation, with ER diagrams. The authoritative data model |
| [erm-frontend.md](./erm-frontend.md) | The terminal's local SQLite cache: what is replicated, what is deliberately not, and why |
| [icon-registry.md](./icon-registry.md) | Product and category icons, and how the three components stay in agreement about them |

## How the system behaves

| Document | What it covers |
|---|---|
| [flows-member-lifecycle.md](./flows-member-lifecycle.md) | Onboarding and offboarding a member, end to end |
| [flows-settlement.md](./flows-settlement.md) | How a Deckel becomes money — and what happens when it doesn't |
| [notifications-and-mail.md](./notifications-and-mail.md) | The mail outbox: queueing, retries, the periodic Deckelauszug, and why shared hosting forces this design |
| [role-based-access.md](./role-based-access.md) | `admin` / `Kassenwart` / `Getränkewart` — what each office can reach, and how the backend enforces it |

## Security, legal and compliance

| Document | What it covers |
|---|---|
| [security-concept.md](./security-concept.md) | Defence in depth: transport, authentication, authorization, encryption at rest, monitoring |
| [legal-requirements-and-how-we-meet-them.md](./legal-requirements-and-how-we-meet-them.md) | Every legal obligation the system touches, mapped to the mechanism that discharges it |
| [procedures.md](./procedures.md) | Recurring duties a **person** must perform — the software supports these, it does not discharge them |
| [retention-deletion-procedure.md](./retention-deletion-procedure.md) | The annual retention review and deletion run |

## Operations

| Document | What it covers |
|---|---|
| [deployment.md](./deployment.md) | Production deployment on shared hosting, backups, hardening, upgrade and rollback |
| [backup.md](./backup.md) | Encrypted, off-site backups: how the nightly job works, the sealed-box format, key custody and rotation, and where archives go |
| [m365-backup-target.md](./m365-backup-target.md) | Provisioning the optional Microsoft 365 destination backups are pushed to — and the approaches that look reasonable and are not |
| [runbook-admin-lockout.md](./runbook-admin-lockout.md) | An admin cannot get in — the three causes, in the order to check them |
| [runbook-backup-recovery.md](./runbook-backup-recovery.md) | Restore an archive, repair one table, rotate a key on handover, respond to a compromise |

## Hardware (optional token dispenser)

| Document | What it covers |
|---|---|
| [esp8266-firmware-requirements.md](./esp8266-firmware-requirements.md) | What the dispenser firmware must guarantee, above all across a crash |
| [dispenser-crash-recovery-reconciliation.md](./dispenser-crash-recovery-reconciliation.md) | Reconciling tokens against transactions after a power loss mid-dispense |

## Testing

| Document | What it covers |
|---|---|
| [playwright-oas.md](./playwright-oas.md) | Driving the API tests through the generated OpenAPI client |

See also [`../e2etests/patterns/`](../e2etests/patterns/) for the testing
patterns themselves, and [`../backend/patterns/`](../backend/patterns/) and
[`../admin-frontend/patterns/`](../admin-frontend/patterns/) for code patterns.

## Historical

`plans/` and `superpowers/` under this directory hold plans and design notes
from earlier tooling. They are context, not places to add to — new plans go in
[`../plans/`](../plans/), indexed by [`../plans/INDEX.md`](../plans/INDEX.md).
