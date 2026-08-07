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

The `backend` container has no coverage driver. Until Task 0.2 lands, install one for the session:

```bash
docker compose exec -T backend sh -c 'pecl install pcov && docker-php-ext-enable pcov'
docker compose exec -T -w /app backend php -d pcov.enabled=1 -d pcov.directory=/app/src vendor/bin/phpunit -c phpunit.xml
cd backend && php scripts/check-coverage.php coverage/clover.xml 15
```

Per-file uncovered ranking:

```bash
cd backend && php -r '
$x=simplexml_load_file("coverage/clover.xml");
foreach($x->xpath("//file") as $f){$m=$f->metrics;$s=(int)$m["statements"];$c=(int)$m["coveredstatements"];
if($s>$c) printf("%-70s %5d uncovered of %4d\n",str_replace("/app/src/","",(string)$f["name"]),$s-$c,$s);}' | sort -k2 -rn | head -30
```

---

## Scope decision: does PHPUnit own the HTTP layer?

This has to be settled before Milestone 1, because it decides whether >80% is reachable at all.

Controllers + middleware + wiring are **1791 of 5274 statements (34%)**. If PHPUnit never touches them, the ceiling is **3483/5274 = 66%** — ADR-0022's 80% line-coverage target is mathematically unreachable.

| Option | Ceiling | Trade-off |
|--------|---------|-----------|
| **A (recommended)** — add a Slim request harness (Milestone 6) so PHPUnit drives controllers/middleware in-process | ~95% | Some overlap with Playwright API tests, but PHPUnit covers the error/validation branches Playwright doesn't, in milliseconds and with no server running |
| **B** — HTTP layer stays Playwright's job (ADR-0022 §Test Categories); exclude `Controllers/`, `Middleware/`, `routes.php`, `ServiceFactory.php` from `<source>` in `phpunit.xml` | ~95% *of the reduced 3483* | Honest metric, no duplication, but the gate stops noticing untested controller code and ADR-0022's 80% needs restating as "80% of non-HTTP code" |

**Recommendation: A.** Playwright asserts the API contract; PHPUnit asserts the branches. They answer different questions, and A keeps the ADR-0022 number meaningful. Option B is defensible — it just needs an ADR-0022 amendment, which is a user decision.

Milestones 1–5 are identical under either option. Milestone 6 is where they diverge.

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
- [ ] **0.2** Add `pcov` to the backend Docker image and a `composer test:coverage` script, so coverage is one command locally and not a manual `pecl install`.
- [ ] **0.3** Upload `backend/coverage/clover.xml` as a CI artifact on the `test-backend` job, so a failed floor check can be diagnosed without re-running.
- [ ] **0.4** Settle the scope decision above (Option A or B) and record it — an ADR-0022 amendment if B.

**Success**: `composer test:coverage` prints the percentage locally; CI artifact downloadable; floor step green.

### Milestone 1 — Money: Settlements & Transactions

Target: these files ≥85%. Projected total after milestone: **~24%**.

- [ ] **1.1** `SettlementsService` (142 stmt, 6%) — `tests/Unit/Modules/Settlements/Services/SettlementsServiceTest.php`, repositories mocked.
  Behaviours: `previewSettlement` date/member/`sepaEligibleOnly` filtering and totals; `createSettlement` rejecting already-settled transactions; `createSettlementByFilters` matching `previewByFilters`; `cancelSettlement` idempotency and refusal on an exported settlement; `markExported` state transition; `getCsvData` row shape; `manualReason` required for `settlement_type = manual`; execution-date business-day guard (extends the existing `ExecutionDateInfoTest`).
- [ ] **1.2** `SettlementsRepository` (108 stmt, 0%) — `tests/Feature/Modules/Settlements/Repositories/SettlementsRepositoryTest.php` against the DB, following Pattern 001 (unique data per test).
  Behaviours: create with items; **negative `amount_cents`** on correction items (signed BIGINT, see memory note); pagination/sort/date-range filters; `is_cancelled` exclusion in lookups.
- [ ] **1.3** `TransactionsService` (100 stmt, 0%) — `tests/Unit/Modules/Transactions/Services/TransactionsServiceTest.php`.
  Behaviours: `processBatch` idempotency on client-generated UUIDs (re-submitting the same batch must not double-book — the core offline-sync guarantee); partial-failure result shape in `TransactionBatchResultDto`; `recordCorrection` producing a reverse transaction with inverted sign and audit entry; sort-key mapping and filter passthrough in `getTransactions`.
- [ ] **1.4** `TransactionsRepository` (177 stmt, 21%) — extend `tests/Feature/Modules/Transactions/Repositories/TransactionsRepositoryTest.php`.
  Behaviours: the `settlement_date` correlated subquery; `sort=member` → `m.last_name` mapping; `page/per_page` and `limit/offset` parameter forms; `since` delta queries at MySQL's **second** DATETIME precision.
- [ ] **1.5** Settlement DTOs + enums (`SettlementDto` 46, `SettlementItemDto` 29, `SettlementPreviewDto` 9, `SettlementType`, `ManualReason`) — `tests/Unit/Modules/Settlements/DTOs/`. Assert `toArray()` key set and cents→formatting exactly as the OpenAPI spec declares.
- [ ] **1.6** Ratchet the CI floor to **23**.

### Milestone 2 — Security: Auth, sessions, admin users

Target: these files ≥85%. Projected total: **~29%**.

- [ ] **2.1** `AuthService` + `TokenService` + `SessionRepository` — constant-time credential comparison (regression guard for C1 in `2026-03-17-security-critical-fixes.md`), inactive-admin rejection, session create/lookup/expiry.
- [ ] **2.2** `TotpService` (20 stmt, 0%) — `generateSecret` entropy/format, `verifyCode` accept/reject inside and outside the time window, `encrypt`/`decrypt` round-trip, and `decrypt` returning `false` on a tampered ciphertext.
- [ ] **2.3** `AdminUsersService` (111 stmt, 0%) + `AdminUsersRepository` (65) + `AdminUserDto` (23) — password hashing never round-trips plaintext; **self-deactivation refused**; `resetAdminPassword` clearing the TOTP secret; `verifyCurrentPassword` on wrong password; duplicate-email handling.
- [ ] **2.4** Middleware: `AdminSessionAuth` (27), `TerminalTokenAuth` (26), `CsrfMiddleware` (13), `RateLimitMiddleware` (20) — unauthenticated → 401, bad CSRF → 403, and the 10-failures/15-min → 429 rule. Needs the Slim harness from Task 6.1 if Option A is chosen; otherwise test the middleware classes directly with hand-built PSR-7 requests.
- [ ] **2.5** Ratchet the CI floor to **28**.

### Milestone 3 — Privacy: Members & mandate documents

Target: these files ≥85%. Projected total: **~36%**.

- [ ] **3.1** `MembersService` (155 stmt, 4%) — `anonymizeMember` nulling every PII field while preserving booking history (GDPR Art. 17, and the open `2026-03-08-gdpr-anonymization-fix.md`); `createMember` mandate-reference auto-generation only when the key is *absent* vs. explicitly `null`; `''` → `null` normalisation for optional DATE/UNIQUE columns; `deleteMember` pre-deletion checks; `exportMember` payload completeness; `syncSince` delta boundary.
- [ ] **3.2** `MembersRepository` (105 stmt, 45%) — close the gap: search across name/email, sort-key whitelist, IBAN uniqueness.
- [ ] **3.3** `MandateDocumentService` (101 stmt, 0%) + `MandateDocumentRepository` (43) — accepted MIME types, PDF conversion path, replace/delete, and deletion on member anonymization.
- [ ] **3.4** Member DTOs (`MemberAdminDto` 42, `MemberDto` 25, `MandateDocumentDto` 19) — assert IBAN masking is applied wherever the DTO is user-facing.
- [ ] **3.5** Ratchet the CI floor to **35**.

### Milestone 4 — Reports

Target: ≥80%. Projected total: **~41%**.

- [ ] **4.1** `ReportsService` (242 stmt, 0%) — `tests/Feature/Modules/Reports/Services/ReportsServiceTest.php` against the DB (it is raw SQL over a `PDO`, so a unit test would assert nothing real).
  Behaviours: `getReport` grouping/date-range/empty-range; `getMemberRanking` ordering and tie-breaks; `getTerminalActivity`; `exportCsv` escaping (separators and quotes in member names).
- [ ] **4.2** Report + dashboard DTOs (`ReportDto` 21, `ReportRowDto` 7, `DashboardDto` 8).
- [ ] **4.3** Ratchet the CI floor to **40**.

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
- [ ] **5.9** Ratchet the CI floor to **52**.

### Milestone 6 — HTTP layer *(shape depends on Task 0.4)*

Target: **~80% total**.

**Under Option A:**

- [ ] **6.1** `tests/Feature/HttpTestCase.php` — boots the real Slim app from `bootstrap.php` against the test DB and dispatches PSR-7 requests in-process, with helpers for an authenticated admin session and a terminal bearer token. This single harness is what makes Milestones 2.4 and 6.2 cheap.
- [ ] **6.2** One controller test class per module (Settlements 178, Dashboard 176, Products 132, Members 126, Transactions 124, AdminUsers 88, Terminals 83, Auth 201, Reports 53, Sync 49+17+11, AuditLog 45, MandateDocument 44, Extraction 36, SepaConfig 21, BankCodes 23, Health 4). Focus on the **error branches** Playwright does not exercise: 400/401/403/404/422 shapes, malformed bodies, missing params. Happy paths stay Playwright's job — do not re-litigate them here.
- [ ] **6.3** `ErrorHandler` (41), `CorsMiddleware` (15), `JsonBodyParser` (14), `TerminalOasValidator` (8) — exercised through the harness.
- [ ] **6.4** `routes.php` (94) and `ServiceFactory` (122) fall out of 6.1–6.3 for free; add a smoke test asserting every registered route resolves and every factory method constructs.
- [ ] **6.5** Ratchet the CI floor to **78**, then to **80** once green twice.

**Under Option B:** replace 6.1–6.4 with a single task — narrow `<source>` in `phpunit.xml` to exclude `Controllers/`, `Middleware/`, `routes.php`, `ServiceFactory.php`; recompute the floor against the reduced denominator; amend ADR-0022 to state the metric excludes the Playwright-owned HTTP layer.

---

## Projected trajectory

| After | Coverage | CI floor |
|-------|----------|----------|
| Baseline | 15.11% | 15 |
| M1 Settlements + Transactions | ~24% | 23 |
| M2 Auth + AdminUsers | ~29% | 28 |
| M3 Members | ~36% | 35 |
| M4 Reports | ~41% | 40 |
| M5 Remaining domain + Shared | ~54% | 52 |
| M6 HTTP layer (Option A) | ~80% | 80 |

Percentages assume ~85% coverage of each file listed. The floor trails the measurement by 1–2 points so ordinary refactoring does not turn CI red.

---

## Conventions for every task

- **TDD** per CLAUDE.md — write the test first; a test that passes before the assertion exists is proving nothing.
- **Unit vs Feature**: pure logic and anything with mockable collaborators → `tests/Unit`. Raw SQL, transactions, and constraint behaviour → `tests/Feature` extending `DatabaseTestCase`.
- **Feature tests follow E2E Pattern 001** — unique data per test, cleaned up in `tearDown`. The `test-backend` job shares one MariaDB service.
- **Verify before committing** — the Test Verification Policy in CLAUDE.md applies. Run the full `phpunit` suite, not just the new file.
- **One commit per completed task**, message format `Backend Test Coverage M<n>.<t>: <what now passes>`.

## References

- [ADR-0022: Test Strategy and Automation](../adr/0022-test-strategy-and-automation.md) — pyramid, 80% line / 70% branch targets
- [ADR-0004: Immutable Transaction Storage](../adr/0004-immutable-transaction-storage.md) — why corrections are reverse transactions
- `backend/patterns/` — Patterns 003 (DTOs), 004 (Service Layer), 005 (Repository Interface) define the seams these tests exploit
- `backend/scripts/check-coverage.php` — the enforcement half of the gate
- Issue #103 — origin of the coverage gate
