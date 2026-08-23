# ADR-0046: Project License is Apache-2.0, Not AGPL-3.0

**Status**: Accepted

**Date**: 2026-08-23

**Deciders**: Project Owner

---

## Context

The project stated its license in three places, and they disagreed:

| Source | Said |
|---|---|
| `/LICENSE` | Apache License 2.0 |
| `CLAUDE.md` → *Licensing & Attribution* | Apache-2.0 |
| `backend/composer.json` | `AGPL-3.0-or-later` |

Packagist and any SBOM generated from `backend/composer.json` reported AGPL-3.0 for the
backend while the rest of the repository advertised Apache-2.0. Apache-2.0 and AGPL-3.0 are
not interchangeable: AGPL adds a network-use copyleft obligation (17 U.S.C.-adjacent, GPL
§13-style) — anyone running a *modified* AGPL backend as a network service owes the source of
those modifications to every user interacting with it over the network, even without
distributing the software.

Club Bar is built for **self-hosting by clubs**: a sports club, community center, or member
organization stands up its own instance and may adapt it to local needs (custom product
categories, a club-specific settlement rule, a translated UI string). Under AGPL, a club doing
exactly that — running a lightly modified fork for its own members — would owe its members
(and arguably anyone who can log in) access to its modified source. That obligation runs
directly against the deployment model this project is designed around, and nothing about it
was a deliberate choice; the `AGPL-3.0-or-later` string in `backend/composer.json` was never
reconciled with `/LICENSE` when the project's actual license — Apache-2.0 — was settled.

Filed as [#657](https://github.com/dgloeckner/clubbar/issues/657), part of the
[#645](https://github.com/dgloeckner/clubbar/issues/645) dependency supply-chain hardening
epic.

### Secondary: the one non-permissive dependency

A license inventory of the 44 production PHP packages in `backend/composer.lock` found:

| License | Count |
|---|---|
| MIT | 40 |
| BSD-3-Clause | 3 |
| Apache-2.0 (`chillerlan/php-qrcode`, dual MIT/Apache-2.0) | 1 |
| LGPL-3.0-or-later (`digitick/sepa-xml` 3.1.0) | 1 |

`digitick/sepa-xml` is the only non-permissive license in the tree. It is used unmodified, as
a library dependency, in exactly one place: `src/Modules/Settlements/Services/SepaExportService.php`.

## Decision

**The project license is Apache-2.0, for the whole repository including `backend/composer.json`.
AGPL-3.0 is rejected because its network-copyleft clause is incompatible with the self-hosted,
club-operated deployment model that is this project's reason to exist.**

`digitick/sepa-xml` (LGPL-3.0-or-later) stays as a dependency, used unmodified as a library.
Consuming an LGPL library without modifying it, and without statically linking it into a
compiled binary, does not require the consuming project to adopt LGPL terms — PHP has no
static-linking step, and `composer install` leaves the vendored package's own source and
license file intact and swappable. The obligation this does carry — reproducing the package's
license and copyright notice in any distributed copy — is met by shipping a third-party
notices file with every packaged distribution (`package/THIRD-PARTY-NOTICES.txt`, copied into
`dist/` by `scripts/build-package.sh`), naming `digitick/sepa-xml`, its authors, and its
LGPL-3.0-or-later terms.

No other dependency in the production tree carries a license incompatible with Apache-2.0.

## Consequences

**Positive:**
- A club self-hosting and adapting Club Bar for its own members owes nothing beyond attribution
  — no obligation to publish its modifications, matching how the project is actually deployed.
- License metadata is consistent everywhere it is declared (`/LICENSE`, `CLAUDE.md`, `README.md`,
  `backend/composer.json`), so tooling (Packagist, SBOM generators, license scanners) reports the
  correct license instead of a stale AGPL string nobody chose deliberately.
- The one copyleft dependency is documented rather than incidental: its terms are named, its
  single call site is named, and the distributed package carries its notice.

**Negative / trade-offs:**
- Apache-2.0 has no network-copyleft clause, so a hosting provider could run a modified Club Bar
  as a paid service without ever contributing changes back. Mitigation: this is an accepted
  trade-off of choosing a permissive license for a self-hosting-first project — the goal is
  frictionless adoption by clubs, not preventing commercial hosting.
- `digitick/sepa-xml` remains LGPL-3.0-or-later. If it is ever modified in-tree (a patched fork
  vendored directly into the repository rather than consumed via Composer), that modified copy
  would itself fall under LGPL and this decision would need revisiting.
