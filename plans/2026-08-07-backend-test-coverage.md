# Backend Test Coverage

**Issue**: #103 (follow-up) — the backend coverage gate added in `11032e2` was set to 50% without ever being measured. Actual line coverage is **15.11%**.

**Goal**: Raise PHPUnit line coverage from 15.11% toward the >80% target in [ADR-0022](../adr/0022-test-strategy-and-automation.md), starting with the code where a silent bug costs the most: money, credentials, and personal data.

---

## Baseline (measured 2026-08-07)

Full clover run: `149 tests, 6507 assertions, OK` — **797 / 5274 statements = 15.11%**.

| Layer | Covered | Statements | Coverage |
|-------|---------|-----------|----------|
| Controllers (HTTP) | 0 | 1411 | 0% |
| Services (domain) | 453 | 1691 | 27% |
| Repositories | 207 | 867 | 24% |
| DTOs | 34 | 427 | 8% |
| Wiring (`routes.php`, `ServiceFactory`) | 0 | 216 | 0% |
| Utils / Validation / Config | 101 | 278 | 36% |
| Other (LLM & Vision clients) | 1 | 193 | 1% |
| Middleware (HTTP) | 0 | 164 | 0% |
| Exceptions | 1 | 16 | 6% |
| Enums | 0 | 11 | 0% |
| **Total** | **797** | **5274** | **15.11%** |

### Reproducing the measurement locally

One command from the repo root (provisions pcov on demand, runs the suite, enforces the floor, prints the least-covered files):

```bash
./backend/scripts/coverage.sh          # floor defaults to 15; pass a percentage to override
```

Per-file uncovered ranking:

```bash
cd backend && php -r '
$x=simplexml_load_file("coverage/clover.xml");
foreach($x->xpath("//file") as $f){$m=$f->metrics;$s=(int)$m["statements"];$c=(int)$m["coveredstatements"];
if($s>$c) printf("%-70s %5d uncovered of %4d\n",str_replace("/app/src/","",(string)$f["name"]),$s-$c,$s);}' | sort -k2 -rn | head -30
```

---

## Scope decision: PHPUnit owns the HTTP layer — **Option A** (decided 2026-08-07)

Controllers + middleware + wiring are **1791 of 5274 statements (34%)**. If PHPUnit never touches them, the ceiling is **3483/5274 = 66%** — ADR-0022's 80% line-coverage target would be mathematically unreachable.

**Decision: A.** PHPUnit drives controllers and middleware in-process through a Slim request harness (Milestone 6). Playwright asserts the API *contract*; PHPUnit asserts the *branches* — the 400/401/403/404/422 paths, malformed bodies, missing params — in milliseconds and with no server running. `<source>` in `phpunit.xml` stays as-is (all of `src`), and ADR-0022's 80% keeps its plain meaning.

Division of labour, to keep the overlap deliberate rather than accidental:

| | Playwright (`e2etests/`) | PHPUnit (`tests/Feature/Http*`) |
|---|---|---|
| Happy-path endpoint contract | ✅ owns it | ❌ do not duplicate |
| Error/validation branches | spot checks only | ✅ owns it |
| Full stack incl. frontend + real HTTP | ✅ | ❌ in-process only |

**Rejected: Option B** — excluding `Controllers/`, `Middleware/`, `routes.php`, `ServiceFactory.php` from `<source>` and restating ADR-0022 as "80% of non-HTTP code". Defensible and duplication-free, but it blinds the gate to untested controller code and needs an ADR amendment.

---

## Prioritization

Ordered by blast radius, not by statement count:

| Rank | Domain | Why it is first | Uncovered |
|------|--------|-----------------|-----------|
| 1 | Settlements + Transactions | Money. Wrong settlement totals or a broken correction produce real debits against members' bank accounts. Transactions are immutable ([ADR-0004](../adr/0004-immutable-transaction-storage.md)) — a bad write cannot be edited away | ~477 |
| 2 | Auth / AdminUsers / session middleware | Credentials, TOTP secrets, CSRF, rate limiting. A regression is a security incident, not a bug report | ~347 |
| 3 | Members (incl. mandate documents) | GDPR Art. 17 anonymization is irreversible and legally binding. IBAN + mandate data are the most sensitive fields in the system | ~370 |
| 4 | Reports | 242 uncovered statements of raw SQL aggregation, 0% covered — the numbers the club acts on financially | ~230 |
| 5 | Remaining domain + Shared utils | Terminals (token rotation), SEPA config, products, categories, bank codes, audit log, `Validator` | ~800 |
| 6 | HTTP layer | Per scope decision above | ~1791 |

---

## Milestones

Status legend: `[ ]` not started · `[~]` in progress · `[x]` passed (verified) · `[!]` failed (documented)

Every task's success criterion is: **the named tests pass in a full `phpunit` run, and the measured file coverage meets its target.** Verify with the reproduction commands above before marking `[x]`.

### Milestone 0 — Make the gate honest and repeatable

- [x] **0.1** Pin the CI floor to measured coverage (15%) so it blocks regressions instead of failing unconditionally — `.github/workflows/build.yaml`. *Done in `6d83f15`.*
- [x] **0.2** `backend/scripts/coverage.sh` provisions pcov on demand, runs the suite, enforces the floor and prints the least-covered files; wired up as `composer test:coverage`. No backend Dockerfile introduced (CLAUDE.md: the backend image is stock, nothing to build).
- [x] **0.3** `test-backend` uploads `backend/coverage/clover.xml` as the `backend-coverage` artifact with `if: always()`, so a failed floor check is diagnosable without a re-run.
- [x] **0.4** Settle the scope decision above. *Option A chosen 2026-08-07: PHPUnit owns the HTTP layer via a Slim harness; no ADR-0022 amendment needed.*

**Success**: verified 2026-08-07 — `./backend/scripts/coverage.sh` prints the percentage locally and the floor step is green.

### Milestone 1 — Money: Settlements & Transactions

**Status: COMPLETE (2026-08-07).** Measured **26.64%** (1405/5274), ahead of the ~24% projection. Suite grew 149 → 340 tests / 7228 assertions, green on three consecutive full runs.

- [x] **1.1** `SettlementsService` (142 stmt, 6%) — `tests/Unit/Modules/Settlements/Services/SettlementsServiceTest.php`, repositories mocked.
  Behaviours: `previewSettlement` date/member/`sepaEligibleOnly` filtering and totals; `createSettlement` rejecting already-settled transactions; `createSettlementByFilters` matching `previewByFilters`; `cancelSettlement`; `markExported` state transition; `getCsvData` row shape; `manualReason` passthrough.
  *Note: the execution-date business-day guard is enforced at the HTTP boundary (`business_day` validator rule, `Settlements/Controllers/AdminController.php:71,104`), not in the service — cover it in Task 6.2, not here.*
- [x] **1.2** `SettlementsRepository` (108 stmt, 0%) — `tests/Feature/Modules/Settlements/Repositories/SettlementsRepositoryTest.php` against the DB, following Pattern 001 (unique data per test).
  Behaviours: create with items; **negative `amount_cents`** on correction items (signed BIGINT, see memory note); pagination/sort/date-range filters; `is_cancelled` exclusion in lookups.
- [x] **1.3** `TransactionsService` (100 stmt, 0%) — `tests/Unit/Modules/Transactions/Services/TransactionsServiceTest.php`.
  Behaviours: `processBatch` idempotency on client-generated UUIDs (re-submitting the same batch must not double-book — the core offline-sync guarantee); partial-failure result shape in `TransactionBatchResultDto`; `recordCorrection` producing a reverse transaction with inverted sign and audit entry; sort-key mapping and filter passthrough in `getTransactions`.
- [x] **1.4** `TransactionsRepository` (177 stmt, 21%) — extend `tests/Feature/Modules/Transactions/Repositories/TransactionsRepositoryTest.php`.
  Behaviours: the `settlement_date` correlated subquery; `sort=member` → `m.last_name` mapping; `page/per_page` and `limit/offset` parameter forms; `since` delta queries at MySQL's **second** DATETIME precision.
- [x] **1.5** Settlement DTOs + enums (`SettlementDto` 46, `SettlementItemDto` 29, `SettlementPreviewDto` 9, `SettlementType`, `ManualReason`) — `tests/Unit/Modules/Settlements/DTOs/`. Assert `toArray()` key set and cents→formatting exactly as the OpenAPI spec declares.
- [x] **1.6** Ratcheted the CI floor to **25** (measured 26.64%).

Achieved per-file coverage:

| File | Before | After |
|------|--------|-------|
| `SettlementsService` | 6% | **100%** |
| `SettlementsRepository` | 0% | **100%** |
| `TransactionsService` | 0% | **97%** |
| `TransactionsRepository` | 21% | **94%** |
| `SettlementDto` / `SettlementItemDto` / `SettlementPreviewDto` / `SepaConfigDto` | 0% | **100%** |
| `SettlementType` / `ManualReason` | 0% | **100%** |

Also fixed in passing: `tests/Feature/DatabaseTestCase.php` now sets `PDO::ATTR_EMULATE_PREPARES => false` to match production `bootstrap.php`. Under emulated prepares PDO binds `LIMIT ?`/`OFFSET ?` as quoted strings, which MariaDB's grammar rejects — so *every* `listPaginated()`-style method was untestable, in six repositories. The test harness had been running against different PDO semantics than production.

### Milestone 2 — Security: Auth, sessions, admin users

Target: these files ≥85%. Projected total: **~29%**.

- [ ] **2.1** `AuthService` + `TokenService` + `SessionRepository` — constant-time credential comparison (regression guard for C1 in `2026-03-17-security-critical-fixes.md`), inactive-admin rejection, session create/lookup/expiry.
- [ ] **2.2** `TotpService` (20 stmt, 0%) — `generateSecret` entropy/format, `verifyCode` accept/reject inside and outside the time window, `encrypt`/`decrypt` round-trip, and `decrypt` returning `false` on a tampered ciphertext.
- [ ] **2.3** `AdminUsersService` (111 stmt, 0%) + `AdminUsersRepository` (65) + `AdminUserDto` (23) — password hashing never round-trips plaintext; **self-deactivation refused**; `resetAdminPassword` clearing the TOTP secret; `verifyCurrentPassword` on wrong password; duplicate-email handling.
- [ ] **2.4** Middleware: `AdminSessionAuth` (27), `TerminalTokenAuth` (26), `CsrfMiddleware` (13), `RateLimitMiddleware` (20) — unauthenticated → 401, bad CSRF → 403, and the 10-failures/15-min → 429 rule. **Depends on Task 6.1** — pull the Slim harness forward to here, since these four are the first tests that need it and Milestone 2 is where they belong by priority.

### Milestone 3 — Privacy: Members & mandate documents

Target: these files ≥85%. Projected total: **~36%**.

- [ ] **3.1** `MembersService` (155 stmt, 4%) — `anonymizeMember` nulling every PII field while preserving booking history (GDPR Art. 17, and the open `2026-03-08-gdpr-anonymization-fix.md`); `createMember` mandate-reference auto-generation only when the key is *absent* vs. explicitly `null`; `''` → `null` normalisation for optional DATE/UNIQUE columns; `deleteMember` pre-deletion checks; `exportMember` payload completeness; `syncSince` delta boundary.
- [ ] **3.2** `MembersRepository` (105 stmt, 45%) — close the gap: search across name/email, sort-key whitelist, IBAN uniqueness.
- [ ] **3.3** `MandateDocumentService` (101 stmt, 0%) + `MandateDocumentRepository` (43) — accepted MIME types, PDF conversion path, replace/delete, and deletion on member anonymization.
- [ ] **3.4** Member DTOs (`MemberAdminDto` 42, `MemberDto` 25, `MandateDocumentDto` 19) — assert IBAN masking is applied wherever the DTO is user-facing.

### Milestone 4 — Reports

Target: ≥80%. Projected total: **~41%**.

- [ ] **4.1** `ReportsService` (242 stmt, 0%) — `tests/Feature/Modules/Reports/Services/ReportsServiceTest.php` against the DB (it is raw SQL over a `PDO`, so a unit test would assert nothing real).
  Behaviours: `getReport` grouping/date-range/empty-range; `getMemberRanking` ordering and tie-breaks; `getTerminalActivity`; `exportCsv` escaping (separators and quotes in member names).
- [ ] **4.2** Report + dashboard DTOs (`ReportDto` 21, `ReportRowDto` 7, `DashboardDto` 8).

### Milestone 5 — Remaining domain + Shared

Target: ≥80% each. Projected total: **~54%**.

- [ ] **5.1** `TerminalsService` (78) + `TerminalsRepository` (63) + DTOs (42) — token rotation invalidating the old token, `revokeAccess`, `TerminalWithTokenDto` exposing the plaintext token exactly once.
- [ ] **5.2** `SepaConfigService` (59) + `SepaConfigRepository` (15) + `SepaConfigDto` (32) — masking in `getConfig(masked: true)`, `isConfigured` edge cases, 35-char creditor-ID limit.
- [ ] **5.3** `Shared\Validation\Validator` (97 stmt, 24%) — every rule and its failure message. Highest leverage in Shared: it guards every endpoint's input.
- [ ] **5.4** `BankCodeService` (124 stmt, 40%) + `BankCodesRepository` (39) — Latin-1 fixed-width Bundesbank parsing, malformed-row handling.
- [ ] **5.5** Products/Categories gap-closing (`CategoriesService` 54 uncov, `ProductsService` 37, `ProductsRepository` 38, `CategoriesRepository` 19, `CategoryDto` 23, `IconNameValidator` 13).
- [ ] **5.6** `AuditLogRepository` (54 stmt, 28%) + `AuditLogDto` (27) — including audit-log scrubbing on anonymization.
- [ ] **5.7** Shared plumbing: `Logger` (29), `Env` (28, 36%), `SafeQuery` (26, 38%), `AppConfig` (14), `PaginatedResultDto` (14), `HealthCheckService` (9), exception classes (16). Mostly cheap wins that also protect the `Env::get()` precedence bug fixed in `2026-03-17-install-php-security-hardening.md`.
- [ ] **5.8** *(lower priority)* LLM/Vision clients — `AnthropicClient` (95), `OpenAiClient` (71), `LlmClientFactory` (17), `GoogleVisionClient` (27). These wrap cURL; they need an injectable transport seam first. Treat the refactor as part of the task, or defer and accept ~4% of total coverage staying at 0.

### Milestone 6 — HTTP layer

Target: **~80% total**.

- [ ] **6.1** `tests/Feature/HttpTestCase.php` — boots the real Slim app from `bootstrap.php` against the test DB and dispatches PSR-7 requests in-process, with helpers for an authenticated admin session and a terminal bearer token. This single harness is what makes Tasks 2.4 and 6.2 cheap. **Built early, in Milestone 2** — listed here for cohesion.
- [ ] **6.2** One controller test class per module (Settlements 178, Dashboard 176, Products 132, Members 126, Transactions 124, AdminUsers 88, Terminals 83, Auth 201, Reports 53, Sync 49+17+11, AuditLog 45, MandateDocument 44, Extraction 36, SepaConfig 21, BankCodes 23, Health 4). Focus on the **error branches** Playwright does not exercise: 400/401/403/404/422 shapes, malformed bodies, missing params. Happy paths stay Playwright's job — do not re-litigate them here.
- [ ] **6.3** `ErrorHandler` (41), `CorsMiddleware` (15), `JsonBodyParser` (14), `TerminalOasValidator` (8) — exercised through the harness.
- [ ] **6.4** `routes.php` (94) and `ServiceFactory` (122) fall out of 6.1–6.3 for free; add a smoke test asserting every registered route resolves and every factory method constructs.

---

## Coverage trajectory

The scheduled ratchet targets that used to sit here (M2.5 → 28, M3.5 → 35, M4.3 → 40, M5.9 → 52, M6.5 → 78/80) are gone. They were predictions made before anyone knew what would be skippable under the ruling protocol — #166 abandoned them. The CI floor is no longer a schedule; it is the never-decrease value checked into `backend/.coverage-floor` (see below), raised only by the PR that earns it.

**Current headroom: 1.64 points** — floor 25, measured 26.64%.

| After | Coverage (estimate) |
|-------|---------------------|
| Baseline | 15.11% |
| M1 Settlements + Transactions | **26.64% (actual)** |
| M2 Auth + AdminUsers | ~29% |
| M3 Members | ~36% |
| M4 Reports | ~41% |
| M5 Remaining domain + Shared | ~54% |
| M6 HTTP layer | ~80% |

These are rough estimates assuming ~85% coverage of each file listed, kept for planning purposes only — they no longer schedule floor raises.

---

## Ruled surfaces — do not pin

A row means the surface is governed by a ruling on [map #139](https://github.com/dgloeckner/ruderbar/issues/139), so coverage work must **not** pin its current behaviour there. A missing row means "pin", which is today's behaviour — the table degrades safely. Keep it current as rulings close.

Two treatments: **fix-first** — the ruling is decided and its fix is unblocked, so fix the code first, then cover the fixed behaviour. **skip** — the ruling is open or blocked, so skip the unit with a named declarative skip: `markTestSkipped('ruled by #NNN; see map #139')`.

| Surface | Ruling | Treatment |
|---|---|---|
| `RateLimitMiddleware`, login/MFA attempt counting (M2.4) | [#145](https://github.com/dgloeckner/ruderbar/issues/145) → fix [#78](https://github.com/dgloeckner/ruderbar/issues/78) | fix-first |
| `MembersService::createMember` mandate auto-generation (M3.1) | [#164](https://github.com/dgloeckner/ruderbar/issues/164) *(open)* | skip |
| `MembersService::anonymizeMember` (M3.1) | [#165](https://github.com/dgloeckner/ruderbar/issues/165) *(open)* | skip |
| `MembersRepository` sort-key whitelist (M3.2) | [#112](https://github.com/dgloeckner/ruderbar/issues/112) | fix-first |
| `ReportsService` revenue aggregation (M4) | [#116](https://github.com/dgloeckner/ruderbar/issues/116), plus the `payout` type from [#141](https://github.com/dgloeckner/ruderbar/issues/141) §4 | skip |
| `TransactionsService::recordCorrection` (M6.2) | [#158](https://github.com/dgloeckner/ruderbar/issues/158) *(open)* | skip |
| `TransactionsService::processBatch` sync gate (M6.2) | [#143](https://github.com/dgloeckner/ruderbar/issues/143) → fix [#162](https://github.com/dgloeckner/ruderbar/issues/162) | fix-first |
| ~~`SettlementsService` create/preview~~ | [#141](https://github.com/dgloeckner/ruderbar/issues/141) → [#161](https://github.com/dgloeckner/ruderbar/issues/161) | **fixed** — exclude-and-flag is implemented and covered; pin freely |
| `SettlementsService::cancelSettlement` | [#142](https://github.com/dgloeckner/ruderbar/issues/142) → [#81](https://github.com/dgloeckner/ruderbar/issues/81)/[#86](https://github.com/dgloeckner/ruderbar/issues/86) | fix-first |

## Coverage/ruling mechanism (landed in #168)

- **`backend/.coverage-floor`** — the checked-in never-decrease floor. `backend/scripts/check-coverage.php` reads it when no explicit percentage is passed on the command line, so the floor lives in one file instead of a workflow argument. It is raised only by the PR that earns it; a drop shows up as a diff, not a silent CI change.
- **Patch coverage** — 80% of changed lines, blocking, run against the PR merge-base by `scripts/check-patch-coverage.php`, gating backend and admin-frontend together from their clover reports. Its scope **inherits each project's declared measurement scope**: backend all of `src`; frontend `src/utils/**` + `src/hooks/**`. It is deliberately **not** extended over frontend pages/components — see the `vite.config.ts` comment and Milestone-0-adjacent scope decision above.
- **Skip lint** — `e2etests/eslint-rules/no-data-dependent-skip.js` ([#146](https://github.com/dgloeckner/ruderbar/issues/146)) bans data-dependent `test.skip()`. Declarative/environmental skips stay legal but must carry a reason, per the `markTestSkipped('ruled by #NNN; ...')` convention above.
- **Frontend and backend coverage numbers measure different scopes and are not comparable.** Backend counts all of `src`; frontend counts only `utils`/`hooks`, with pages/components delegated to Playwright. They must never be averaged or given a shared target.

## Conventions for every task

> ⚠️ **Before pinning any behaviour, check whether it is already ruled.** [Map #139](https://github.com/dgloeckner/ruderbar/issues/139) holds nine money-semantics rulings, and M2–M6 run straight through the code they govern. Pinning ruled behaviour writes a test that must later be deleted — and reads to a future maintainer as deliberate specification. The protocol is decided in [#166](https://github.com/dgloeckner/ruderbar/issues/166): **no ruling → pin as usual; ruling decided and its fix unblocked → fix first, then cover; ruling decided but blocked, or still open → skip the unit with a named declarative skip** (`markTestSkipped('ruled by #164; see map #139')`). The "Ruled surfaces — do not pin" table and the scheduled-ratchet removal landed in [#168](https://github.com/dgloeckner/ruderbar/issues/168); **M2.4 collides with [#145](https://github.com/dgloeckner/ruderbar/issues/145) today.**


- **TDD** per CLAUDE.md — write the test first; a test that passes before the assertion exists is proving nothing.
- **Unit vs Feature**: pure logic and anything with mockable collaborators → `tests/Unit`. Raw SQL, transactions, and constraint behaviour → `tests/Feature` extending `DatabaseTestCase`.
- **Feature tests follow E2E Pattern 001** — unique data per test, cleaned up in `tearDown`. The `test-backend` job shares one MariaDB service.
- **Verify before committing** — the Test Verification Policy in CLAUDE.md applies. Run the full `phpunit` suite, not just the new file.
- **One commit per completed task**, message format `Backend Test Coverage M<n>.<t>: <what now passes>`.

## Findings from Milestone 1

Coverage work surfaced three defects in code that had never been exercised. Per the plan's rules no `src/` file was modified; the tests assert **actual current behaviour** with `// NOTE:` comments, so fixing any of these will show up as a deliberate test change rather than a silent one. Each warrants a GitHub issue.

1. **`SettlementsRepository::listPaginated()` ignores the sort key** — `SettlementsRepository.php:159` reads
   `$sortCol = $sortKey === 'created_at' ? 's.created_at' : 's.created_at';`
   Both ternary branches are identical, so no requested sort column can ever take effect; only the direction works. User-visible in the admin settlements list. Pinned by `test_listPaginated_sortKey_parameter_has_no_effect_on_order`.
2. **`SettlementsService::cancelSettlement()` has no state guard** — it calls the repository unconditionally regardless of current `is_cancelled` / `exported_at`. Re-cancelling an already-cancelled settlement, or cancelling one already SEPA-exported, both "succeed": settlement items are deleted and a second audit entry is written. On an exported settlement the local record then no longer matches a file already sent to the bank.
3. **`TransactionsService::recordCorrection()` writes no audit entry and derives no sign** — there is no `AuditService` in the service's constructor at all. The amount is persisted exactly as passed with `transaction_type = 'correction'`; sign convention is entirely the controller's responsibility and zero/positive corrections are accepted silently. Notable given transactions are append-only ([ADR-0004](../adr/0004-immutable-transaction-storage.md)) and corrections are the only remedy.

Verified as **not** defects, having been checked directly against the source:

- **Batch idempotency holds.** `TransactionsRepository::insertTransaction` uses `INSERT IGNORE`; a duplicate client-generated UUID reports as accepted without double-booking. This is the guarantee the offline-first sync architecture rests on, and it is now locked in by `test_processBatch_duplicate_client_uuid_is_idempotent_not_double_booked`.
- **The execution-date business-day guard exists**, at the HTTP boundary (`business_day` validator rule, `Settlements/Controllers/AdminController.php:71,104`) rather than in the service. Covered in Task 6.2.

## References

- [Map #139: money-semantics rulings](https://github.com/dgloeckner/ruderbar/issues/139) — binding on M2–M6; see #166 for the protocol
- [ADR-0022: Test Strategy and Automation](../adr/0022-test-strategy-and-automation.md) — pyramid, 80% line / 70% branch targets
- [ADR-0004: Immutable Transaction Storage](../adr/0004-immutable-transaction-storage.md) — why corrections are reverse transactions
- `backend/patterns/` — Patterns 003 (DTOs), 004 (Service Layer), 005 (Repository Interface) define the seams these tests exploit
- `backend/scripts/check-coverage.php` — the enforcement half of the gate
- Issue #103 — origin of the coverage gate
