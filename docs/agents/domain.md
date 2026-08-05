# Domain Docs

How the engineering skills should consume this repo's domain documentation when exploring the codebase.

## Before exploring, read these

- **`CONTEXT.md`** at the repo root (created lazily by `/domain-modeling`; does not exist yet)
- **`adr/`** — read ADRs that touch the area you're about to work in. This repo keeps its ADRs at the repo root (`adr/`, not `docs/adr/`); 22 ADRs document binding decisions and per CLAUDE.md they must be followed and never modified without explicit user confirmation.

If `CONTEXT.md` doesn't exist, **proceed silently**. Don't flag its absence; don't suggest creating it upfront. The `/domain-modeling` skill (reached via `/grill-with-docs` and `/improve-codebase-architecture`) creates it lazily when terms or decisions actually get resolved.

## File structure

Single-context repo:

```
/
├── CONTEXT.md            ← glossary / ubiquitous language (lazily created)
├── adr/                  ← Architecture Decision Records (0001–0022)
├── docs/                 ← ERMs and data documentation (erm-master.md, erm-frontend.md)
├── use-cases/            ← functional requirements
├── backend/
├── admin-frontend/
└── terminal/
```

New ADRs go in `adr/`, following the existing numbering and the ADR documentation style defined in CLAUDE.md (diagrams over code, tables for data structures, honest consequences section).

## Use the glossary's vocabulary

When your output names a domain concept (in an issue title, a refactor proposal, a hypothesis, a test name), use the term as defined in `CONTEXT.md`. Don't drift to synonyms the glossary explicitly avoids.

If the concept you need isn't in the glossary yet, that's a signal — either you're inventing language the project doesn't use (reconsider) or there's a real gap (note it for `/domain-modeling`).

## Flag ADR conflicts

If your output contradicts an existing ADR, surface it explicitly rather than silently overriding:

> _Contradicts ADR-0004 (immutable transaction storage) — but worth reopening because…_
