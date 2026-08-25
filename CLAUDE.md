# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Club Bar** is an open-source, member-managed bar/club POS system designed for sports clubs, community centers, and member organizations that need:
- Offline-capable transaction processing
- Granular membership accounting and settlement
- Privacy-first data handling (GDPR-compliant anonymization workflows)
- Flexible deployment on modest infrastructure

**System Components:**
- **Terminal App**: Flutter/Dart kiosk POS for RFID/NFC member identification, product selection, and checkout
- **Admin Panel**: React SPA for member/product management, accounting, and compliance workflows
- **Backend**: PHP 8.3 (Slim 4) / MariaDB REST API for synchronization and data persistence

**Core Design Principles:**
- **Offline-first**: Terminal operates fully offline; syncs periodically when connected
- **Eventually consistent**: Frontend maintains local cache; periodic delta sync with backend
- **Immutable transactions**: Transactions are append-only; a booking is corrected by a *storno* that names it and negates it exactly (see [ADR-0004](./adr/0004-immutable-transaction-storage.md))
- **Conflict-free sync**: Immutable design eliminates UPDATE/DELETE conflicts across terminals
- **User privacy**: Personal data anonymizable (GDPR Art. 17); booking history retained separately
- **Idempotent APIs**: Client-generated UUIDs ensure safe retry semantics

**Status**: In production use. All three components are implemented, covered by unit/API/E2E suites,
and shipped as a self-hosting package (see [`docs/deployment.md`](./docs/deployment.md)).

---

## Project Conventions

**These rules MUST be followed when working on this project:**

### Architecture Decision Records (ADRs)
- **All ADRs must be followed** — they document binding architectural decisions
- **Never modify ADRs without explicit user confirmation** — ADRs represent agreed-upon decisions
- **Reference ADRs** when implementing features that relate to documented decisions
- **Create new ADRs** for significant architectural changes (user must approve)

### Technology Choices
- **Technology stacks are documented in `technologies.md`** for each sub-project:
  - `terminal-frontend/technologies.md` — Terminal App (Flutter/Dart)
  - `admin-frontend/technologies.md` — Admin Panel (React SPA)
  - `backend/technologies.md` — Backend (PHP 8.3, Slim 4)
- **Follow documented patterns** — do not introduce new frameworks or libraries without updating the spec

### Use Cases
- **Implement all use cases** defined in `use-cases/` directory
- **Reference use cases** when implementing features (e.g., "Implements UC-A11")
- **Use cases define acceptance criteria** — implementation must satisfy all stated requirements

### Testing (TDD)
- **Follow Test-Driven Development** — write tests before implementation
- **Test strategy is defined in [ADR-0022](./adr/0022-test-strategy-and-automation.md)** — follow the test pyramid
- **Test categories**: Unit tests (PHPUnit, Vitest), API tests (Playwright), E2E tests (Playwright)
- **All features must have tests** — no merging without test coverage

### Data Model
- **Data model is defined in `docs/`** — see `erm-master.md` (backend) and `erm-frontend.md` (terminal)
- **Never modify the data model without explicit user confirmation**
- **Schema changes require migration scripts** and updates to ERM documentation

### Internationalization (i18n)
- **Product data is multilingual** — names/descriptions stored as JSON with language keys (see [ADR-0002](./adr/0002-product-internationalization.md))
- **Member language preference** — stored in `preferred_language` field (ISO 639-1 codes: `de`, `en`, etc.)
- **Terminal displays in member's language** — reads `preferred_language` from sync response
- **Admin UI supports language switching** — UI strings in JSON locale files
- **API is language-agnostic** — always returns all translations; frontend selects appropriate language

### Code Patterns (Backend)

Reference backend code patterns in `backend/patterns/` directory:
- **Pattern 001**: Input Validation — declarative rule arrays via the shared `Validator` (no Form Request classes; the DTO is where typed access lives)
- **Pattern 002**: Enum for Type-Safe Domain Values — type-safe constants for languages, transaction types, statuses
- **Pattern 003**: Data Transfer Objects (DTOs) — immutable response objects with consistent formatting
- **Pattern 004**: Service Layer — business logic isolated from HTTP, reusable across consumers
- **Pattern 005**: Repository Interface — abstract data access to enable testing and flexibility
- **Pattern 006**: Thin Controllers — controllers route HTTP requests to services (no business logic)
- **Pattern 007**: Centralized Exception Handling — consistent error response format and logging
- **Pattern 008**: ServiceFactory for Dependency Injection — container wiring and service lifetimes
- **Pattern 009**: Module Structure & Organization — the per-module layout ADR-0018 prescribes
- **Pattern 010**: Shared Base Service — the behaviour every module service inherits
- **Pattern 011**: Shared Base Repository — the query helpers every repository inherits
- **Pattern 012**: Terminal API Token Authentication — bearer tokens on the terminal surface
- **Pattern 013**: Admin Session Authentication — session cookies and CSRF on the admin surface
- **Pattern 014**: RFID Member Identification (Not Authentication) — a card names a member, it does not prove one
- **Pattern 015**: Authorization & Access Control — default-deny role checks on every route (ADR-0044)
- **Pattern 016**: Audit Logging — what gets an audit row and what it must carry
- **Pattern 017**: Shared HTTP Layer — one envelope, one parser, one responder
- **Pattern 018**: Custom Domain Exceptions — typed exceptions instead of parsing exception messages

**Important**: All backend work must follow these patterns for consistency with ADR-0018 (Modular Architecture) and to maintain code quality across modules.

### Notification Recipients (ADR-0044, #633)

**A notification is addressed to an *office*, never to a person and never to
"the admins".** Mail is the one surface a role model is easy to forget, and the
failure is silent: a notice reaches somebody it was never meant for, in a
channel that cannot be taken back.

The rule is **mirror the grant on the surface the mail points at**. There is one
source of truth about who may know a thing — `RouteRoleMap` — and a mail about a
screen carries exactly that screen's role set. A second table of who-hears-what
would drift.

1. **Every `MailKind` answers `recipientRoles()` explicitly**, in a `match` with
   no default arm. A kind added later must *state* its audience; it must never
   inherit one from the shape of the kinds around it. The same applies to
   `addressesMember()`, `addressesClub()` and `subjectType()` — all four are
   exhaustive `match` expressions for this reason, and PHP's exhaustiveness check
   is what makes forgetting one a build error rather than a leak.
2. **Derive the set from the route, not from intuition.** The near-limit digest
   is the push half of `GET /api/admin/dashboard`, which is `TREASURY`, so it is
   `[ADMIN, KASSENWART]`. A terminal token's expiry is behind an `admin`-only
   route, so it is `[ADMIN]` — the Kassenwart is narrowed out by the same rule
   that includes them elsewhere, not by a judgement call.
3. **Fan out to every active account holding any of those roles**, one message
   each, via `AdminUsersRepository::findActiveRecipientsWithAnyRole()`. Roles are
   additive and stored one row per role, so the query is `DISTINCT` — an account
   holding two of the roles asked for is one recipient, not two identical mails.
   Never send to a single "the treasurer": an office can be held by several
   people, or by nobody.
4. **Deduplicate per recipient**, not per notice — `dedup_key` is
   `<occasion>:<adminUserId>`. One recipient having already been told must not
   silence the others, and `UNIQUE (kind, subject_id, dedup_key)` is what makes a
   repeating scan idempotent without a lookup (and therefore race-free under two
   overlapping ticks).
5. **Fail closed.** An empty role list selects *nobody*, matching ADR-0044 rule
   1's reading of an unclassified route. When no active account holds the office,
   escalate to the configured club address — never widen back to every admin,
   which is the leak this rule exists to prevent — and log an unreachable notice
   when there is no club address either, so a notice that reached nobody is
   visible rather than silent.
6. **Say nothing when there is nothing to say.** A periodic digest with an empty
   list queues no mail at all. Silence must mean "the condition does not hold";
   the scheduler's own health is the heartbeat's job. A recipient who receives
   "0 items" fifty times has learned to file it unread by the fifty-first.

**Worked example** — `MailKind::CREDIT_LIMIT_DIGEST` is `[ADMIN, KASSENWART]`,
so it reaches *every* active admin and *every* active Kassenwart, one mail each,
in each recipient's own language; the Getränkewart is excluded because member
balances are outside their remit on every surface (ADR-0045 invariant 5).

### E2E Testing Patterns

Reference E2E testing patterns in `e2etests/patterns/` directory (see **`README.md`** for complete index):
- **Pattern 001**: Test Data Isolation — create unique test data per test, avoid shared/mutated state
- **Pattern 002**: Authentication Isolation — properly isolate session-based and bearer token authentication
- **Pattern 003**: Database-Agnostic Assertions — search for specific data by ID instead of assuming position
- **Pattern 004**: Parallel Execution Safety — design tests for safe concurrent execution
- **Pattern 005**: Using Test IDs (data-testid) — use semantic test IDs for reliable, maintainable UI selectors
- **Pattern 006**: Page Object Model — encapsulate page interactions in reusable classes, private locators, public methods
- **Pattern 007**: Page Object Fixtures — inject ready-to-use page objects with Playwright fixtures to eliminate boilerplate
- **Pattern 008**: Playwright Assertions & Auto-Waiting — use `expect()` instead of try-catch visibility checks for clear error messages
- **Pattern 009**: User-Flow-Based Tests — chain related operations into flow tests instead of one assertion per test
- **Pattern 010**: Asserting on Delivered Mail — read the message a real drain delivered to Mailpit, never the outbox row that claims it sent one
- **Pattern 011**: Testing a Role You Are Not — role fixtures for asserting what each admin office can and cannot reach

**Critical**: Pattern 008 fixes a common anti-pattern. Never use `try-catch` for visibility checks. Always use `await expect(locator).toBeVisible()` — Playwright's error messages are far superior and help debug failures immediately.

**CRITICAL: End-to-End Integration Requirement**

**E2E tests MUST verify complete end-to-end integration through the entire stack (frontend → API → backend).**

*Definition of E2E Test*: A test that verifies a complete user workflow from start to finish, including:
1. **User action** via UI (e.g., fill form, click save)
2. **API call** from frontend to backend (HTTP request)
3. **Backend processing** (validation, database write, business logic)
4. **Data persistence** (verify data saved in database)
5. **UI feedback** (form closes, list updates, no errors shown)

*Example (UC-A11: Create Member)*:
- ❌ **NOT E2E**: Test that form fills correctly and closes (UI-only test)
- ✅ **E2E**: Test creates member → verifies API succeeds → verifies member appears in list → verifies no errors → verifies database has new row

*Implementation Checklist for Create/Save Features*:
```typescript
test('should create member and persist without errors', async ({ page, authenticatedMembersPage }) => {
  const testData = { firstName: 'Test', lastName: 'User', iban: 'DE89...', ... }

  // 1. Get baseline state
  const initialCount = await authenticatedMembersPage.getMemberRowCount()

  // 2. Perform user action
  await authenticatedMembersPage.createMember(...)

  // 3. Verify form closes (indicates API call likely succeeded)
  await authenticatedMembersPage.expectFormModalHidden()

  // 4. Verify NO error message shown
  const errorMsg = await authenticatedMembersPage.getErrorMessage()
  expect(errorMsg).toBeNull()

  // 5. Verify data appears in UI (list updated)
  const memberName = await authenticatedMembersPage.getMemberFirstNameInTable(testData.firstName)
  expect(memberName).toContain(testData.firstName)

  // 6. Verify count increased (database write confirmed)
  const newCount = await authenticatedMembersPage.getMemberRowCount()
  expect(newCount).toBe(initialCount + 1)

  // Optional: Verify backend logs show success (if needed)
  // docker compose exec backend tail -20 /app/logs/$(date +%Y-%m-%d).log | jq .
})
```

**Important**: All E2E tests must follow these patterns for reliability, parallel execution safety, and consistent test behavior. **Start here**: `e2etests/patterns/README.md` provides the complete index, quick start guide, and real-world examples. Then reference specific patterns as needed.

### Admin Frontend Patterns

Reference admin frontend patterns in `admin-frontend/patterns/` directory:
- **Test IDs Pattern**: Establish reliable, semantic selectors for E2E tests using `data-testid` attributes
  - Naming conventions (kebab-case, semantic hierarchy)
  - Implementation examples for common components (pages, forms, tables, modals)
  - Best practices for adding test IDs during development
  - Playwright tips and custom locators
- **Table Implementation Pattern**: Build a paginated list page end to end
  - `useListQuery` owns page/page size/sort/filters/search, the search debounce, request aborting and the post-mutation page clamp — **never hand-roll that state on a page**
  - Shared controls: `MobileFilterRow`, `PaginationToolbar`, `SortableTableHeader`, `MobileToolbar`
  - Loading, empty and error states; common pitfalls
- **Data Fetching Pattern**: Cancel superseded requests and guard against stale responses
  - `useLatestRequest` + the orval `signal` option on every generated call
  - `signal.aborted` checks before setters, in `catch`, and around `finally`
  - Claim the signal before a search debounce, one slot per independent stream
  - Spinner ownership when a mutation reload supersedes a loader effect
  - On a list page `useListQuery` already owns this slot; use `useLatestRequest` directly for a page's other streams (a second fetch, an interval, a tab switch)
- **Role-Aware Navigation Pattern**: show each admin office only the sections it can open (ADR-0044)
  - `SECTION_ROLES` in `src/utils/adminRoles.ts` — section→roles, default-deny
  - Per-role landing, and the named `insufficient_role` refusal screen
  - A nav entry left unclassified fails the unit suite, not review
- **Date Field Pattern**: one date control for desktop and mobile — `<input type="date">` is not used anywhere
  - Typed entry first (`23111979` → `23.11.1979`), calendar second; month and year each one tap
  - Popover on desktop, bottom sheet with 44px targets below 768px
  - ISO `YYYY-MM-DD` on the wire, locale order on screen; `min`/`max` enforced in the field
  - `mode="birthdate"` opens on the year view and shows the resulting age (ADR-0045)
  - Assert on `{testId}-value` in E2E, never on the visible (locale-formatted) input
- **Component Patterns**: Index of reusable UI components — check it before writing a new one

**Important**: When building pages and components in the admin frontend, follow the test IDs pattern to ensure E2E tests are reliable and maintainable. See `admin-frontend/patterns/test-ids.md` for comprehensive guide and examples, and `admin-frontend/patterns/table-implementation.md` before touching a list page. Any page that fetches on a filter, search, sort, page or interval must follow `admin-frontend/patterns/data-fetching.md` — without it the page can render the results of a request it has already superseded.

**Downloads**: route every file download through `src/api/client.ts` — `downloadFile(url, fallback)` when you have a URL (it goes through the API client and honours `Content-Disposition`), `downloadBlob(blob, filename)` when a generated endpoint already returned the blob. Do not build `<a download>` elements in pages.

### Branch & PR Workflow

**Never commit directly to `main`. All work happens on a feature branch and lands
through a pull request.** That is what makes the work reviewable and — where an
issue is involved — what links the code back to the requirement that motivated it.

1. **Refresh `main`, then branch off it, before the first commit**, so the branch
   does not carry a stale history into review:

   ```bash
   git checkout main && git pull origin main
   git checkout -b feat/57-terminal-error-plumbing
   ```

   Name the branch after the issue where there is one: `feat/<issue>-<slug>`,
   `fix/<issue>-<slug>`, `docs/<slug>`.

   If `git pull` reports local commits on `main` that are not on the remote, stop
   and surface it rather than merging past it — it usually means earlier work was
   committed to `main` by mistake. To rescue it: `git branch <feature-branch>` at
   the current HEAD, `git checkout <feature-branch>`, then
   `git branch -f main origin/main`.

   If the branch is long-lived and `main` moves underneath it, refresh again
   before opening the PR (`git fetch origin && git merge origin/main`) so CI
   tests the real merge result.
2. **Verify before pushing.** The Test Verification Policy below is not waived by
   the existence of CI — tests must be green locally first.
3. **Open the PR with the issue linked** using a closing keyword (`Closes #57`),
   so merging closes the issue. State what changed, call out anything
   deliberately left out of scope, and record the local verification result.
4. **Watch the PR until it is green.** On failure: read the job logs, classify
   the root cause, fix, push to the same branch, re-check. **A PR is not done
   when it is open — it is done when its checks pass.**

Branching, pushing a feature branch and opening a PR are the expected path and
need no separate approval once the work has been asked for. **Merging**,
force-pushing and deleting branches are not — confirm those.

### Destructive Test Cleanup (CRITICAL)

**A `tearDown()` that deletes must never be able to point at a path the test did
not create.**

This rule exists because of a real incident. `DeployRequestScriptTest::setUp()`
called `markTestSkipped()` — `jq` is not installed in the backend container —
*before* assigning `$this->docRoot`. PHPUnit runs `tearDown()` for a skipped test
anyway, so cleanup ran with the property still at its `''` default:

```php
private string $docRoot = '';                       // never assigned when setUp skips

foreach (glob($this->docRoot . '/*') ?: [] as $f) { // glob('/*') — the ROOT directory
    unlink($f);                                     // unlink() removes symlinks without complaint
}
```

`/bin`, `/lib`, `/lib64`, `/sbin` and `/entrypoint` are symlinks at `/` on a
merged-usr image, so all five were deleted. **Losing `/lib64` removes the ELF
interpreter**, after which every dynamically linked binary in that container
fails to exec with `no such file or directory` — including the healthcheck, so
the container reads `Up (unhealthy)` while the already-running php-fpm keeps
serving HTTP. Nothing in the failure names a test; it reads as a broken image.
CI never saw it, because the CI image has `jq` and `setUp()` completes.

1. **Use `Tests\Support\TempTree`** (`backend/tests/Support/TempTree.php`) —
   `makeTempTree()` to create, `removeTempTree()` to delete. It refuses an empty
   path, a non-existent path, and anything not resolving *under* the system temp
   directory. `TempTreeTest` is its regression test.
2. **Never concatenate a path onto a possibly-unset property** and hand the
   result to `glob()`, `unlink()`, `rmdir()` or `rm -rf`. `'' . '/*'` is `/`.
3. **Assign the path before any `markTestSkipped()`** — better, do not depend on
   ordering at all, per rule 1.
4. **A skip is not a "no cleanup" signal.** `tearDown()` runs for skipped,
   errored and incomplete tests alike.
5. **`unlink()` deletes symlinks**, so "delete only files, not directories" is
   not a safety property at `/`. Every dangerous entry in a root directory is a
   symlink.
6. **No destructive cleanup outside `sys_get_temp_dir()`.** Fixtures that must
   live in the repo are removed by naming the exact files, never by globbing.

When reviewing or writing a test that deletes anything, ask the two questions the
incident answers: *what is this path when `setUp()` did not finish?* and *what is
in that directory if the answer is `/`?*

### Development Approach
- **Prefer a planned approach with milestones** over tackling all issues at once
- **Break work into phases** — plan before implementing
- **One feature at a time** — complete and test before moving to the next
- **Validate against use cases** before marking work complete
- **Follow backend patterns** — reference `backend/patterns/` directory for consistent implementation

### MCP Servers

#### sequential-thinking

The `sequential-thinking` MCP server provides a structured, multi-step reasoning tool (`mcp__sequential-thinking__sequentialthinking`). It guides problem-solving through an explicit chain of thoughts that can branch, revise, and self-correct.

**When to use it:**

| Situation | Example |
|-----------|---------|
| Complex debugging with multiple possible root causes | A test fails with a confusing error — use sequential-thinking to systematically rule out causes before touching code |
| Architectural decisions with non-obvious trade-offs | Deciding whether to add a new module vs. extend an existing one |
| Multi-step planning before writing code | Breaking a large feature into safe, ordered implementation steps |
| Algorithms or query logic that are hard to reason about inline | Designing a sync delta algorithm or a SEPA batch generation flow |
| Ambiguous requirements that need decomposition | Translating a vague use case into concrete API contracts and data-model changes |

**When NOT to use it:**

- Straightforward tasks with a clear single solution (editing a typo, renaming a variable)
- Tasks where code exploration tools (Grep, Glob, Read) give the answer directly
- Any task where the action is obvious and the cost of extra reasoning exceeds its benefit

**How to use it:**

1. Call `mcp__sequential-thinking__sequentialthinking` with an initial `thought` describing the problem.
2. Set a rough `totalThoughts` estimate (adjust up/down as reasoning evolves).
3. Each subsequent call builds on, revises, or branches from earlier thoughts.
4. Set `nextThoughtNeeded: false` only when you have a verified, satisfactory conclusion.
5. Use the final conclusion to drive the actual implementation or decision — do not treat intermediate thoughts as the answer.

**Key parameters:**

- `thought` — Current reasoning step (analysis, question, hypothesis, or verification)
- `nextThoughtNeeded` — `true` to continue; `false` when done
- `thoughtNumber` / `totalThoughts` — Track position; `totalThoughts` can be revised mid-stream
- `isRevision` + `revisesThought` — Explicitly revise a previous thought when new evidence changes the conclusion
- `branchFromThought` + `branchId` — Explore an alternative path without discarding the main line

**Example trigger phrases** (when you see these, reach for sequential-thinking):
- "Why is this failing?" with no obvious single cause
- "What's the best approach for…" with real trade-offs
- "How should I structure…" before writing the first line of code
- "Walk me through the logic of…" for non-trivial algorithms

### Debugging & Testing Best Practices

**When tests fail or requests behave unexpectedly, follow this checklist:**

1. **Check Application Logs** (JSON format, daily files)
   ```bash
   TODAY=$(date +%Y-%m-%d)
   docker compose exec backend tail -100 /app/logs/$TODAY.log | jq .
   ```
   - Look for `"level":"ERROR"` and `"level":"CRITICAL"` entries
   - JSON format: `{ts, level, channel, msg, ctx}`
   - Stack traces and context data in `ctx` field
   - Source of truth for debugging application-level issues

2. **Check HTTP Access Logs & Status Codes**
   ```bash
   docker compose logs backend | tail -50 | grep "HTTP/1.1"
   ```
   - Verify actual HTTP response codes (200, 302, 400, 404, 422, 500, etc.)
   - Common issues:
     - `302 Found` → Redirect (often CSRF middleware or route not matching)
     - `404` → Route not found or path parameter mismatched
     - `500` → Application error (check the JSON application log, step 1)
     - `422` → Validation error (check request body format)
   - Compare expected vs actual status codes from logs

3. **Direct Endpoint Testing**
   ```bash
   # Test GET endpoint
   curl -s 'http://localhost:8080/api/endpoint' | jq .

   # Test POST with JSON
   echo '{"key":"value"}' > /tmp/data.json
   curl -X POST http://localhost:8080/api/endpoint -H 'Content-Type: application/json' -d @/tmp/data.json

   # Test with verbose headers
   curl -v -X PATCH http://localhost:8080/api/endpoint -H 'Content-Type: application/json' -d '{}'
   ```
   - Verify endpoint responds with correct format (JSON vs HTML error pages)
   - Check response headers and status code
   - Test before running full test suite to isolate issues

4. **Docker Container Health**
   ```bash
   docker compose ps  # Verify containers are running
   docker compose logs backend | tail -20  # Check for startup errors
   docker compose exec backend curl -s http://localhost/api/health | jq .  # Health check
   ```

5. **Restart Services After Code Changes**
   ```bash
   docker compose exec backend supervisorctl restart php-fpm:php-fpmd
   sleep 2  # Wait for restart to complete
   ```
   - PHP code changes require process restart
   - Always restart after editing service/controller code

6. **Playwright Test Execution (Default: 4 Workers)**
   ```bash
   # BEFORE running tests: check for existing results first (avoid re-running if nothing changed)
   cat e2etests/results/latest.json | jq '.stats'  # exists after any test run
   git diff --stat HEAD  # if clean + latest.json exists = use existing report, don't re-run

   cd e2etests

   # Default: Run tests with 4 workers (auto-saves results to e2etests/results/latest.json)
   npm test
   # Equivalent to:
   npm test -- --workers=4

   # Run with explicit worker count
   npm test -- --workers=4
   npm test -- --workers=2

   # Analyze results after run
   cat e2etests/results/latest.json | jq '.stats'
   cat e2etests/results/latest.json | jq '.suites[].tests[] | select(.status=="fail") | {title, error: .errors[0].message}'
   ```
   - **Default**: 4 workers balance speed and stability for most test suites
   - **When to use 4 workers**: Normal test runs, CI/CD pipelines, regression testing
   - **Why parallel**: Reduces execution time from 90+ seconds to 20-30 seconds
   - **Test isolation required**: Tests must follow E2E Testing Patterns (001-004) for safe parallel execution
   - **`results/latest.json`**: Auto-saved on every run — always check this before re-running tests

7. **Debugging Parallel Test Failures (Serial: 1 Worker)**
   ```bash
   # Only revert to 1 worker when debugging parallelism issues
   npm test -- --workers=1

   # Use serial execution to:
   # - Rule out resource contention causing false timeouts
   # - Check if test passes without parallelism (indicates isolation issue)
   # - Verify actual error vs resource exhaustion
   ```
   - **When to use 1 worker**: Only when tests fail intermittently in parallel mode
   - **Diagnosis workflow**:
     1) Test fails in parallel (4 workers) → 2) Run with 1 worker
     2) If passes with 1 worker → parallelism issue (check test isolation, database state)
     3) If fails with 1 worker → real bug in code/test logic
   - **After fixing**: Return to 4 workers (default) to ensure fix is stable under parallelism

8. **JSON Test Reporter & Result Parsing**
   ```bash
   # Run tests with JSON output for parsing
   npm test -- --reporter=json > test-results.json

   # Parse results to find root causes
   cat test-results.json | jq '.suites[].tests[] | select(.status=="fail") | {title, error}'

   # Count test results by status
   cat test-results.json | jq '.stats'

   # Find slow tests
   cat test-results.json | jq '.suites[].tests[] | select(.duration > 5000) | {title, duration}'
   ```
   - **Use case**: CI/CD pipelines, automated root cause analysis, performance tracking
   - **JSON structure**: Contains tests, failures, error messages, durations, and annotations
   - **Parsing tips**:
     - `select(.status=="fail")` — Find all failed tests
     - `select(.duration > 5000)` — Find tests taking >5 seconds (potential timeouts)
     - `.error.message` — Extract error message from each failure
     - `.annotations[]` — Check for Playwright-specific issues (network, server, etc.)
   - **Root cause patterns**:
     - `timeout` → Server slow or test resource contention; try 1 worker
     - `connection refused` → Backend not running or restarted during test
     - `404 not found` → API route missing or wrong parameter
     - `422 validation error` → Invalid test data in request
     - `database constraint` → Test data isolation issue; check Pattern 001

9. **Diagnosing a Red E2E Job in CI**

   **Start at the end of the job log and grep for `E2E-FAILURES`.** Every failing
   spec is named there with its location, its error and a copy-pasteable re-run
   command:

   ```
   ========================================================================
   E2E-FAILURES: 1 of 93 specs
   ========================================================================

   FAILED tests/admin/new-settlement.spec.ts:116
     expanding a member shows the transactions
     re-run: npx playwright test tests/admin/new-settlement.spec.ts --grep "expanding a member shows the transactions"
       Error: expect(received).toBe(expected)
       Expected: 3
       Received: 0
   ```

   The same report appears in three places, so pick whichever your tooling can
   reach: the **job log tail**, the **job summary** on the run page, and one
   **annotation per failure** at the top of the job and on the PR diff.

   It is produced by `e2etests/scripts/report-failures.mjs`, which reads the JSON
   reporter's `results/latest.json`. Run it locally the same way:

   ```bash
   cd e2etests
   npx playwright test ...            # writes results/latest.json
   node scripts/report-failures.mjs   # same report you would get from CI
   ```

   **Why the log is ordered the way it is.** Container logs are dumped
   `--tail=150` *before* the report, and services are stopped explicitly, so the
   failure report stays inside the tail. This is deliberate: the job used to end
   with `hoverkraft-tech/compose-action`'s post step dumping the whole container
   log — over a megabyte of per-request access lines — which pushed Playwright's
   summary past what GitHub's job-log API will return. A red run was then
   diagnosable only by a human scrolling the web UI. Keep any new log-dumping
   step bounded, and keep it above the report.

   For the full trace, `playwright-report-<lane>` and
   `playwright-traces-<lane>` are uploaded as artifacts on every run — the lane
   ids are `api-1`, `api-2`, `ui-1`, `ui-2` and `chain` (see below).

   **The CI job runs in three lanes, not two blind shards.** `--shard` splits
   tests but cannot split a **dependency-connected component**, and almost
   every project was in one: `api-ordered`, `api-rotation` and the mail chains
   all declare `api-tests` and `admin-chromium` as dependencies, so Playwright
   had to keep the lot together. Measured on the old matrix: shard 1 got **28**
   tests and ran 52 seconds; shard 2 got **995** and ran 7m54.

   | Lane | Projects | Shape |
   |------|----------|-------|
   | `api` | `api-tests` | 2 shards, 318/318 — homogeneous, so count-balancing is time-balancing |
   | `ui` | `admin-chromium`, `admin-mobile` | 2 shards |
   | `chain` | `api-ordered`, `api-rotation`, `mail-chain`, `mail-statement`, `mail-credentials`, `mail-issuance`, `mail-lifecycle`, `mail-jugendschutz`, `mail-roles`, `mail-digest` | 1 job, no shard: every project in it is deliberately serial |

   Those dependencies are about **one shared database**, not about data, so a
   job of its own satisfies them. `E2E_LANE=chain` is what says so, and it is
   the only thing that relaxes a dependency edge — a local `npx playwright
   test` sets nothing and behaves exactly as before. To reproduce a lane:

   ```bash
   cd e2etests
   E2E_LANE=chain npx playwright test --project=api-ordered --project=api-rotation \
     --project=mail-chain --project=mail-statement --project=mail-credentials --project=mail-issuance \
     --project=mail-lifecycle --project=mail-jugendschutz --project=mail-roles \
     --project=mail-digest
   npx playwright test --project=api-tests --shard=1/2
   ```

   **Reproducing CI locally without Docker** (e.g. in a sandbox that has no
   daemon): install `mariadb-server`, apply `backend/db/migrations/*.sql` then
   `backend/db/seed.sql`, serve the backend with
   `PHP_CLI_SERVER_WORKERS=10 php -S 127.0.0.1:8080 -t backend/public backend/public/index.php`
   (the workers matter — a single-threaded server deadlocks when the browser and
   an API call overlap), `npx vite preview --port 5173` in `admin-frontend`, then
   run Playwright with `ADMIN_URL`/`API_URL` pointing at them. PHP needs
   `bcmath`; without it every member-create fails the IBAN checksum with
   `Call to undefined function bcmod()`.

10. **Debugging E2E API Integration Failures (Frontend → Backend)**

   **When E2E tests fail during form submission (create/save operations):**

   ```bash
   # 1. Check backend is running and healthy
   curl http://localhost:8080/api/health

   # 2. Check recent application errors (JSON logs)
   TODAY=$(date +%Y-%m-%d)
   docker compose exec backend cat /app/logs/$TODAY.log | jq 'select(.level == "ERROR" or .level == "CRITICAL")'

   # 3. Check HTTP response codes from the API
   docker compose logs backend | tail -50 | grep "POST\|PUT\|PATCH\|DELETE"

   # 4. Manually test the API endpoint with test data
   curl -X POST http://localhost:8080/api/admin/members \
     -H "Content-Type: application/json" \
     -d '{"first_name":"Test","last_name":"User","iban":"DE89370400440532013000","mandate_signed_at":"2024-12-15","preferred_language":"de"}'

   # 5. Check browser console for JavaScript errors
   # Open browser DevTools during test → Console tab → look for errors

   # 6. Restart PHP if code was modified
   docker compose exec backend supervisorctl restart php-fpm:php-fpmd
   sleep 2
   ```

   **Common Issues:**
   - ❌ `Target page closed` → API error caused app crash, check the JSON application log
   - ❌ `Test timeout` → Backend not responding, check health endpoint
   - ❌ `422 validation error` → API validation failed, check request format matches OpenAPI spec
   - ❌ `401 Unauthorized` → Auth token expired or invalid, check auth.setup.ts
   - ✅ Form closes + member in list = successful E2E flow

11. **Re-Run Single Failing Tests Quickly (Playwright --grep)**
   ```bash
   # Run only one test by name (exact match)
   cd e2etests && npm test -- --grep "GET /api/admin/categories returns category list"

   # Run tests matching a pattern (partial match)
   npm test -- --grep "Categories API"

   # Run specific tests with parallel execution (default 4 workers)
   npm test -- --grep "Create Product" --workers=4

   # Serial execution for isolated debugging
   npm test -- --grep "rejects empty names" --workers=1

   # JSON output for single test failure analysis
   npm test -- --grep "test name" --reporter=json | jq '.suites[].tests[].error'
   ```
   - **Use case**: After fixing a bug, quickly re-run the specific test without waiting for the full suite
   - **Time saving**: 30-second test vs 20+ seconds for full suite (4 workers default)
   - **Pattern matching**: Use partial test names to run related tests (e.g., all validation tests)
   - **Iteration workflow**: 1) Make code change → 2) Restart PHP → 3) `npm test -- --grep "test name"` → Repeat
   - **Validation before full run**: Once the grep test passes, run the full suite to ensure no regressions
   - **Debugging**: If single test passes but suite fails, run full suite with 1 worker to isolate parallelism issues

### ⚠️ Test Verification Policy (CRITICAL)

**This is a hard rule: Tests must be verified as PASSING before committing, unless the user explicitly approves committing red/failing tests.**

#### Why This Matters
- **Unverified tests are technical debt**: Tests that haven't run may contain syntax errors, fixture issues, or logic bugs
- **Red tests hide problems**: A failing test committed to the repo obscures real bugs and confuses future developers
- **Trust in the test suite**: The entire development process depends on tests being a reliable verification mechanism
- **Prevents regressions**: Only verified passing tests can catch future bugs

#### Before Every Commit

**Always verify tests are passing using this workflow:**

1. **Identify which tests to run**:
   ```bash
   # For new/modified E2E tests
   cd e2etests && npm test -- tests/api/filename.spec.ts --workers=4

   # For new/modified PHP tests — php8.3, never plain `php` (8.4 has no bcmath)
   cd backend && php8.3 vendor/bin/phpunit --filter FeatureName
   # …or in the container, which is what Feature tests need for the database
   docker compose exec -w /app backend ./vendor/bin/phpunit --filter FeatureName

   # For entire test suite
   cd e2etests && npm test -- --workers=4
   ```

2. **Verify all tests pass** (look for `passed` in output, not `failed`):
   - All tests show ✓ or PASS status
   - No red/failed test output
   - No timeout errors (if timeout occurs, check backend health first)

3. **Only then commit**:
   ```bash
   git add .
   git commit -m "Feature description"
   ```

#### Specific Scenarios

**Scenario 1: New E2E Tests Created**
```bash
# BEFORE committing new tests:
cd e2etests
npm test -- tests/api/settlements.spec.ts --workers=4

# Expected output: All tests pass (e.g., "30 passed")
# If any fail: Debug and fix BEFORE committing
# If connection refused: Start backend first (docker compose up -d)
# If timeout: Check backend logs and PHP processes
```

**Scenario 2: Modified Backend Code (Services, Controllers, Repositories)**
```bash
# After modifying code:
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2

# Then run tests:
cd e2etests
npm test -- --grep "feature name" --workers=4

# All tests for that feature must pass
```

**Scenario 3: Debugging a Failing Test**
```bash
# Run serially to isolate issues:
cd e2etests
npm test -- tests/api/filename.spec.ts --workers=1

# Check application logs (JSON):
TODAY=$(date +%Y-%m-%d)
docker compose exec backend tail -100 /app/logs/$TODAY.log | jq .

# Fix the issue, restart PHP, try again
# Once test passes with 1 worker, verify it passes with 4 workers:
npm test -- tests/api/filename.spec.ts --workers=4
```

**Scenario 4: User Explicitly Approves Red Tests**
```bash
# If user says "it's ok to commit failing tests" or "commit as-is for investigation"
# Then commit with a note in the message:
git commit -m "Feature: WIP - tests currently failing, pending review

Tests not yet passing - see #123 for context.
User approved committing for debugging/investigation.

[reference any issue or approval note]"
```

#### Common Issues

| Issue | Solution |
|-------|----------|
| **Connection refused** | Backend not running: `docker compose up -d && sleep 3` |
| **All tests timeout** | PHP process hung: `docker compose exec backend supervisorctl restart php-fpm:php-fpmd` |
| **Tests pass with 1 worker, fail with 4** | Test isolation issue: Check Pattern 001 (unique data per test), verify no shared database state |
| **Test syntax error** | Missing import or typo: Check error output carefully, fix code, retry |
| **Fixture not found** | Fixture not created: Check `e2etests/fixtures/` for required setup, create if missing |
| **`Call to undefined function bcmod()`** | You ran `php`, which is 8.4 and has no bcmath. Use `php8.3` (see *Run backend PHP tests*) |
| **`phpunit` never returns when piped** (`phpunit \| tail`) | A test leaked a `php -S` child that still holds the output pipe, so it never reaches EOF. Start servers only via `Tests\Support\LocalWebServer` — a *string* command to `proc_open()` re-creates this. Check with `pgrep -af '[p]hp -S'` |
| **`Could not authenticate against github.com`** during `composer install` | Not an auth problem, and a token will not fix it — it is the session's GitHub proxy refusing an out-of-scope repository. Composer dists work; see [`docs/agents/cloud-environment.md`](./docs/agents/cloud-environment.md) |
| **`access denied by the git proxy: <other>/<repo> is not in this session's authorized repository set`** on `git push` | Reads as permissions, is almost always a **clobbered remote** left by a composer source-install (often one started from the repo root instead of `backend/`). Check `git remote -v`, then `git remote set-url origin https://github.com/dgloeckner/clubbar` |

#### Red Flags (STOP and Debug)

These situations require investigation before committing:
- ❌ Test creates but isn't executed before commit
- ❌ Test passes locally but structure looks suspicious (e.g., empty test body)
- ❌ Batch of tests created without running any
- ❌ Commit message says "tests added" but they haven't been verified
- ❌ Test timeouts or connection errors (indicates backend issue, not just test)

#### Verification Commands Reference

```bash
# Health check (is backend running?)
docker compose exec backend curl -s http://localhost/api/health | jq .

# Run single test file (4 workers)
cd e2etests && npm test -- tests/api/settlements.spec.ts --workers=4

# Run single test by name
cd e2etests && npm test -- --grep "test name here" --workers=4

# Run serially for debugging
cd e2etests && npm test -- tests/api/settlements.spec.ts --workers=1

# Run with JSON reporter to parse results
cd e2etests && npm test -- --reporter=json > results.json
cat results.json | jq '.stats'

# Check for test failures in JSON
cat results.json | jq '.suites[].tests[] | select(.status=="fail")'
```

### Implementation Plans

**Implementation plans in `plans/` directory are the single source of truth for project status and progress. Do NOT create separate summary or status documents.**

#### Core Principles

- **Plans are stored in `plans/`** — each plan is a markdown file with clear milestones.
  New plans go here. Three other directories hold historical plans and are **read-only
  context, not places to add to**: `docs/plans/` and `docs/superpowers/` (earlier tooling
  wrote there) and `terminal-frontend/docs/plans/` (terminal-local design notes)
- **Actionable items with testable results** — every task must have a verifiable outcome
- **Progress evaluated by tests** — success is determined by passing tests, not subjective assessment
- **Clear success/failure tracking**:
  - `[ ]` — Not started
  - `[~]` — In progress
  - `[x]` — Passed (test verified)
  - `[!]` — Failed (documented with reason)
- **Document remaining failures** — failed items include error details and next steps
- **Git commits for completed items** — when a task is marked `[x]` (passed):
  - Create a git commit referencing the plan name, task number, and **specific achievement**
  - Commit message format: `[Plan Name] [Milestone/Task]: Clear description of what passed`
  - **Examples**:
    - `Phase 1 Milestone 1.1: Composer dependencies installed and vendor/autoload.php verified`
    - `Phase 1 Milestone 2.2: GET /api/sync/members returns valid member array with correct schema`
    - `Phase 1 Milestone 3.1: health.spec.ts test suite passes (5/5 checks)`
  - **What to include**: The specific check result, test output, or verification that confirms success
  - **Purpose**: Commit history becomes a detailed record of what was achieved; useful for debugging or resuming mid-milestone
- **INDEX.md for plan tracking** — `plans/INDEX.md` must be maintained with:
  - **Completed plans** — list of finished plans with link and completion date
  - **Current plan** — the plan currently in progress (link and status summary)
  - **Future plans** — roadmap of planned work (brief descriptions)
  - **Purpose**: When Claude is asked to continue work, INDEX.md provides quick context on project status and which plan to resume

#### Single Source of Truth

- **Plan file contains everything**: milestones, tasks, status, success criteria, references, test commands
- **INDEX.md provides navigation**: shows which plan is current (don't duplicate status here)
- **Avoid separate documents**: Never create `*-SUMMARY.md`, `*-STATUS.md`, `*-PROGRESS.md`, or similar
- **Update the plan itself**: As work progresses, update the plan file; don't create parallel documents
- **Why**: Multiple status documents create inconsistency, duplication, and confusion about which is current

#### When Adding Features

1. Create ADRs in `adr/` for architectural decisions
2. Create use cases in `use-cases/` for functional requirements
3. **Extend the implementation plan** in `plans/` to add new milestones/tasks
4. Update `plans/INDEX.md` to reflect current plan status
5. **Do NOT create** summary documents for the feature
6. Add missing implementation patterns for backend, frontends (e.g. `backend/patterns`) in their respective directories, when missing.

---

### Directory Purposes

| Directory | Purpose |
|-----------|---------|
| `admin-frontend/` | Admin Panel (React SPA) — source, technology decisions, architecture |
| `admin-frontend/patterns/` | **Design patterns and best practices for admin frontend development** |
| `adr/` | Architecture Decision Records — see `adr/README.md` for the index |
| `api/` | OpenAPI 3.0 specifications for Admin and Terminal APIs |
| `artwork/` | Logo and brand assets |
| `backend/` | Backend (PHP 8.3, Slim 4) — source, technology decisions, architecture |
| `backend/patterns/` | **Code patterns and architectural patterns for backend quality** |
| `docker/` | Docker Compose configuration for local development |
| `docs/` | Data model, flows, security, legal and operations docs — see `docs/README.md` |
| `e2etests/` | Playwright API and E2E tests |
| `e2etests/patterns/` | **E2E testing patterns for robust, isolated, parallel-safe tests** |
| `package/` | The self-hosting release package (web installer, upgrade script, sample config) |
| `plans/` | Implementation plans with testable milestones — see `plans/INDEX.md` |
| `research/` | Background research behind legal and regulatory decisions |
| `scripts/` | Developer and CI shell scripts (`dev-setup.sh`, `dev-stack.sh`, image mirroring) |
| `terminal-frontend/` | Terminal App (Flutter/Dart) — source, install guide, architecture |
| `tools/` | Standalone browser tools (e.g. the encryption keypair generator) |
| `use-cases/` | Functional requirements organized by domain — see `use-cases/README.md` |

---

### For Contributors
- **English-first code**: All source files, commit messages, comments, error messages in English
- **Document as you code**: Update `/docs/` for architectural changes; add examples to CLAUDE.md for new workflows
- **Test new features**: Add Playwright E2E or Jest/PHPUnit unit tests; run existing test suite before submitting PR
- **Translations welcome**: i18n files (`/locales/{lang}/`) can be translated by community, but core code stays English
- **Privacy by default**: Assume all member data is sensitive. Require explicit justification (and audit log entry) for any data export or admin access

---

## Architecture Decision Records (ADRs)

ADRs document important architectural decisions, their rationale, and trade-offs. See `/adr/` directory.

### ADR Documentation Style

**Goal**: Clarity and decision rationale, not implementation guides.

**Guidelines**:

1. **Minimal Code**
   - Avoid code examples in ADRs
   - Pseudo-code is acceptable to illustrate concepts
   - Focus on requirements and architecture, not implementation details
   - **Example**: Describe that the system needs "a function for SEPA Gläubiger configuration UI" but don't provide React component templates

2. **Diagrams Over Code**
   - Use **Mermaid diagrams** for all visual representations
   - Prefer **sequence diagrams** to explain flows (e.g., settlement workflow, sync cycles, GDPR anonymization)
   - Use **flowcharts** for decision trees (e.g., settlement validation logic)
   - Use **ER diagrams** for data relationships (optional; tables below may suffice)

3. **Data Structures**
   - Describe data models using **tables**: column name, type, description
   - Include mock **JSON examples** to show API responses/payloads
   - Use tables instead of entity descriptions for clarity

   **Example table**:
   ```
   | Column | Type | Description |
   |--------|------|-------------|
   | id | UUID | Unique member identifier |
   | iban | VARCHAR(34) | Member bank account (SEPA format) |
   | mandate_reference | VARCHAR(35) | SEPA mandate ID; default = UUID without hyphens |
   ```

4. **Focus on Requirements**
   - Document **what** needs to happen, not **how** to code it
   - Include constraints, validation rules, edge cases
   - Link to related ADRs for context
   - **Example**: "SEPA XML must include only debtor name and IBAN (no address) for privacy"

5. **Consequences Section**
   - Always include positive and negative consequences
   - Be honest about trade-offs
   - Include mitigations for negative consequences

6. **No Approval Sections**
   - Skip approval/sign-off blocks (one-person team for now)
   - Focus on decision rationale in the "Decided by" context
---

## Open Source Contributions

This project is designed for self-hosting and open-source community contributions. Below is guidance for contributors.

### Repository Structure

See **Directory Purposes** above for the complete directory structure.

**Key directories for contributors:**
- `adr/` — Architecture Decision Records (read before making architectural changes)
- `api/` — OpenAPI specs (update when adding/changing endpoints)
- `use-cases/` — Functional requirements (reference when implementing features)
- `docs/` — Data models and ERMs (reference for database work)
- `admin-frontend/patterns/` — UI patterns and the component index (reference before building a page)

### Getting Started with Contributions

1. **Fork the repository** and create a feature branch (`git checkout -b feature/my-feature`)
2. **Local development**: Run `docker-compose up -d` to start all services
3. **Code in English**: Variables, function names, comments, error messages all in English
4. **Test before submitting**: Run test suites; add new tests for features
5. **Commit message format**: Clear, descriptive, referencing issue if applicable
6. **Create a pull request**: Link to issues, describe what changed and why

### Development Environment

- **Build tools installed locally**: For testing and development, all build tools (Composer, npm, Node.js) are installed on the local machine
- **Mount build results into containers**: Build artifacts are mounted into Docker containers rather than building inside containers
- **Faster dev cycle**: This approach enables faster iteration since you don't need to rebuild containers for code changes
- **No docker compose build needed**: Backend uses standard PHP image with mounted code; no custom image build required

#### Initial Setup

```bash
# 1. Install backend dependencies (host machine)
cd backend && composer install && cd ..

# 2. Start containers (no build needed - uses standard PHP image with mounted code)
docker compose up -d

# 3. Verify backend health
curl http://localhost:8080/api/health
```

#### Updating After Changes

```bash
# After PHP dependency changes (composer.json/composer.lock)
cd backend && composer install && cd ..

# After docker-compose.yml changes
docker compose down && docker compose up -d

# NO docker compose build needed for backend - code is mounted!
```

#### Running Tests

```bash
# Run E2E tests (after setup is complete)
cd e2etests
npm install

# Default: Run tests with 4 workers (parallel execution)
npm test

# Run specific test file
npm test -- tests/api/health.spec.ts

# Run tests matching a pattern
npm test -- --grep "Products API"

# Run serially (1 worker) for debugging
npm test -- --workers=1

# Run with JSON reporter for parsing results
npm test -- --reporter=json > test-results.json

# Parse results to find failures
cat test-results.json | jq '.suites[].tests[] | select(.status=="fail")'
```

**Test Execution Strategy**:
- **Default (4 workers)**: Fast parallel execution; use for normal runs
- **Serial (1 worker)**: Only for debugging parallelism issues
- **JSON reporter**: Use in CI/CD to parse and analyze results
- **grep pattern**: Quick iteration on specific tests during development

> **Note**: Frontend setup (Admin Panel + Terminal) will be added here once implemented.

### Code Standards

- **PHP**: PSR-12 style, no external dependencies beyond Composer basics; prepared statements for all DB queries
- **React/TypeScript**: ESLint + Prettier configured; no console.logs in production code
- **Tests**: Jest/Vitest for React, PHPUnit for PHP, Playwright for E2E; minimum 80% coverage for new functions
  - **Playwright E2E Tests** (CRITICAL):
    - Must follow E2E Testing Patterns (001-008) for safe parallel execution with 4 workers
    - **MUST verify end-to-end integration** (frontend → API → backend → database)
    - **For create/save operations**: Verify data actually persisted (count increased, item in list, no errors)
    - **For delete/update operations**: Verify data actually changed/removed (list updated, count changed)
    - **Example**: Creating a member must verify: form closes → no error → member appears in list → count increased
    - Patterns ensure test isolation, no shared state, database-agnostic assertions, and proper authentication handling
    - Tests that violate patterns will fail intermittently in parallel mode; use `--workers=1` to identify isolation issues
  - **UI-only tests are NOT E2E**: Tests that only verify form fills/closes but don't confirm backend persistence are insufficient
- **Database changes**: Always include migration script; no direct schema edits
- **Security**: No hardcoded credentials, API keys, or sensitive data in code/logs

### Licensing & Attribution

- **License**: Apache-2.0
- **Attribution**: Contributors are credited through the git history and the GitHub contributors list
- **DCO**: Contributions must include sign-off (e.g., `git commit -s`)

## Cloud sessions

Applies to Claude Code cloud sessions (`CLAUDE_CODE_REMOTE=true`). On a local machine none of this runs — the scripts below are no-ops off the cloud.

### dockerd is not started for you

The session image ships Docker but does not run the daemon: the environment cache snapshots the filesystem, not running processes, so every session — and every *resume* — starts with no `dockerd` and `docker` fails with **"Cannot connect to the Docker daemon"**. Setup scripts are skipped once the cache exists, so the fix lives in the repo instead.

`scripts/ensure-docker.sh` starts the daemon and is wired in as a **SessionStart hook** (`.claude/settings.json`, matcher `startup|resume`). It is idempotent, waits for readiness with a bounded timeout (`ENSURE_DOCKER_TIMEOUT`, default 60s), and **always exits 0** so a Docker problem can never block the session from starting.

**How to tell whether the hook ran:**

```bash
docker info > /dev/null 2>&1 && echo "daemon up" || echo "daemon DOWN"
tail -5 logs/ensure-docker.log     # "Docker daemon ready after Ns." / "already running"
```

If the daemon is down, run the hook by hand — it is safe to run at any time:

```bash
scripts/ensure-docker.sh
```

### The compose stack is NOT running at session start

The hook starts **only the daemon**. It deliberately does not start the containers: it runs on every session and resume and blocks Claude from launching, while the stack costs 30–90s and a chunk of RAM that most sessions never need.

**Use `scripts/dev-setup.sh` — it does the whole setup in one go** and every step is idempotent, so re-running it is cheap:

```bash
scripts/dev-setup.sh                  # API tests + backend tests
scripts/dev-setup.sh --with-frontend  # also builds and serves the admin UI on :5173

cd e2etests && npx playwright test --project=api-tests     # chromium
cd e2etests && npx playwright test --project=admin-mobile  # webkit, needs --with-frontend
cd e2etests && npx playwright test --project=mail-chain --no-deps  # finalize → cron.php → Mailpit
cd e2etests && npx playwright test --project=mail-statement --no-deps  # cadence → cron.php → Mailpit
```

It covers, in order: the Docker daemon → `composer install` → making `backend/logs` and `backend/storage` writable → `dev-stack.sh up` + `wait` → migrate + seed → `npm install` and the Playwright browsers → optionally the admin frontend → a verification pass that reports what is missing.

Five of those steps exist because a fresh clone fails without them, and none is obvious:

| Step | Why it is not optional |
|------|------------------------|
| `chmod 777 backend/logs backend/storage` | The backend container runs as uid 1000; a fresh clone is owned by root. Without it mandate uploads return 500 (CI has the same step) |
| `rm -f backend/storage/.installed` | `install.php` refuses to migrate while the marker exists — and the marker is **tracked in git**, so every fresh clone starts blocked. The script clears it, migrates, seeds, then `touch`es it back so the tree stays clean |
| `npx playwright install chromium` | The image ships a pre-built Chromium, but `@playwright/test` resolves through its caret range to a newer Playwright that wants a newer browser build. Without this, `api-tests` and `admin-chromium` cannot start |
| `npx playwright install webkit` **+ `install-deps`** | `admin-mobile` uses `devices['iPhone 14']` and `terminal-touch` uses `devices['iPad Pro 11']` — both default to **WebKit, not Chromium**. Installing only Chromium leaves every test in those projects failing at launch with `Executable doesn't exist at .../webkit-XXXX/pw_run.sh`. The deps matter separately: WebKit links against libwoff, libopus, libharfbuzz-icu, libenchant, libsecret, libmanette and friends, and without them the binary is on disk and still refuses to start |
| Playwright browsers + admin frontend | The `admin-chromium` and `admin-mobile` projects drive `http://localhost:5173`; nothing serves it by default |
| `mailpit` up, with chaos enabled | The `mail-chain` project (#409) and the `mail-statement` project (#463) read what a drain actually delivered from Mailpit's API on `:8025`, and the first produces a permanent delivery failure by asking that server to answer 550 — which needs `MP_ENABLE_CHAOS`. A container started from an older compose file is healthy, reachable and still refuses the chaos call, so the verify pass asks for both |

**The stack's `MAIL_DSN` is empty on purpose, Mailpit or not.** A drain claims the *whole* queue, so a transport wired into the long-running backend would let the URL-trigger spec's drains sweep announcements the admin suite is asserting on. The chain suite hands the DSN to the run it triggers instead (`docker compose exec -e MAIL_DSN=… backend php /app/bin/cron.php`), so exactly one drain in a run delivers, and it is the one making the assertions. To watch real mail arrive while developing, start the stack with `MAIL_DSN=smtp://mailpit:1025 docker compose up -d` and open <http://localhost:8025>.

The browser steps are why a local run could once be green on a shard CI failed: CI installs the full browser set, `dev-setup.sh` used to install Chromium only. The verify pass now *launches* each browser rather than looking for its directory — an unpacked WebKit with a missing system library passes a file check and still cannot run a test.

**Run backend PHP tests with `php8.3`, or in the container:**

```bash
cd backend && php8.3 vendor/bin/phpunit -c phpunit.xml --testsuite Unit   # ~12s, no stack
docker compose exec -w /app backend ./vendor/bin/phpunit                  # anything needing the database
```

**Not plain `php`.** `php` is 8.4 and has no **bcmath**, so `Validator.php`'s
`bcmod()` call kills every SEPA/Validator test with `Call to undefined function
bcmod()` — ten errors that look like broken code. bcmath cannot be added to that
8.4: `php8.4-bcmath` exists only in the `ondrej/php` PPA, for which the egress
policy returns 403 (see below).

`php8.3` is the fix, and it is also what the project targets — `composer.json`
pins the platform to 8.3.30. Both `php8.3-cli` and `php8.3-bcmath` come from
`archive.ubuntu.com`, which is allowed; `.claude/cloud-setup.sh` installs them,
and pinning the unreachable PPA out is what makes them resolvable at all. See
[`docs/agents/cloud-environment.md`](./docs/agents/cloud-environment.md).

The container is still right for **Feature** tests: those need the `database`
hostname, which only exists on the compose network. Run them there, or start the
stack and point `DB_HOST` at it. Since #696 the Feature suite fails in 0.2s with
a message naming the unreachable host, rather than 748 identical PDO traces.

Because of that instruction, the backend service also mounts `./scripts` at `/scripts` read-only. `CheckPatchCoverageScriptTest` shells out to `scripts/check-patch-coverage.php`, which lives *above* `./backend` and is therefore invisible through the `/app` mount — without it those six tests fail with `Could not open input file` in the workflow this file recommends, while passing in CI, where phpunit runs inside a full checkout.

`scripts/dev-stack.sh`:

| Command | Behaviour |
|---------|-----------|
| `up [SERVICE...]` | Calls `ensure-docker.sh`, prefetches the images (see below), then `docker compose up -d`. `dev-setup.sh` calls this for you |
| `wait [SERVICE...]` | Blocks until every service is healthy. Bounded by `DEV_STACK_TIMEOUT` (default 180s); exits **2** on timeout and prints which service failed plus its last 30 log lines |
| `down [ARG...]` | `docker compose down` (a no-op if the daemon is not running) |

`up` is not enough on its own: `docker compose up -d` returns once containers are *created*, well before the backend answers HTTP. Readiness comes from compose healthchecks — `database` (`healthcheck.sh --connect`), `backend` (`/api/health`), `admin-frontend` (HTTP 200 on `/`). Note that `wait` does **not** use `docker compose up --wait`: that flag reports success for a container still inside its healthcheck `start_period`, which is exactly the window that matters here.

### A container that installs packages needs the proxy's CA

Outbound HTTPS from the *session* is intercepted by the agent proxy, which
signs with a CA at `/root/.ccr/ca-bundle.crt`. The host trusts it; a container
you start does not. So `npm install` inside `docker run` or a `docker build`
hits the registry and fails — sometimes honestly:

```
npm error errno SELF_SIGNED_CERT_IN_CHAIN
npm error request to https://registry.npmjs.org/... failed,
npm error reason: self-signed certificate in certificate chain
```

and sometimes not, which is the part that wastes an hour. On a large install
the same failure surfaces as npm's internal crash —

```
npm error Exit handler never called!
npm error This is an error with npm itself.
```

— which reads like a broken lockfile or a bad base image, and reproduces on
every Node version, so it is easy to blame whichever image you just changed.

Mount the CA and pass the proxy through instead. Never disable TLS
verification to get past it:

```bash
docker run --rm -v "$PWD":/app -w /app \
  -v /root/.ccr/ca-bundle.crt:/ca.crt:ro -e NODE_EXTRA_CA_CERTS=/ca.crt \
  -e HTTPS_PROXY="$HTTPS_PROXY" -e HTTP_PROXY="$HTTP_PROXY" -e NO_PROXY="$NO_PROXY" \
  node:22-alpine npm ci
```

CI has no such proxy, so this is a sandbox-only step: nothing in the repo
should carry it.

### Container registry and egress allowlist

Image pulls and outbound HTTPS go through the environment's allowlist. **That allowlist is configured in the Claude Code cloud environment settings, not in this repo.** If a fetch fails with 403/407, it is fixed by an admin in the environment settings — do not work around it from code: no daemon mirror configuration, no registry rewriting, no insecure-registry entries, no vendored binaries.

To confirm a failure is a policy denial rather than a network fault, check the proxy's own record — it names the blocked host:

```bash
curl -sS "$HTTPS_PROXY/__agentproxy/status"   # see recentRelayFailures
```

Non-default hosts this project needs:

| Host | Why |
|------|-----|
| `pkg-containers.githubusercontent.com` | GHCR blobs |
| `production.cloudfront.docker.com` | Docker Hub legacy CDN |
| `*.r2.cloudflarestorage.com` | Docker Hub R2 layer storage |
| `quay.io`, `*.quay.io` | Keycloak |
| `*.azurecr.io`, `*.blob.core.windows.net` | ACR manifests / layers |
| `maven.pkg.github.com` | Defaults cover only `npm.pkg.github.com` |
| `ppa.launchpadcontent.net` | The `ondrej/php` PPA — the only source of `php8.4-bcmath`, for the 8.4 that `php` points at. Currently **denied** (403). Not a blocker: `php8.3-bcmath` comes from `archive.ubuntu.com` instead, so run the suite with `php8.3` (above). `.claude/cloud-setup.sh` pins this PPA out so those packages resolve |

The `githubusercontent.com` hosts, `codeload.github.com`, `packagist.org`,
`archive.ubuntu.com` and `security.ubuntu.com` are on the **default** Trusted
allowlist — there is never anything to add for them.

### Docker Hub rate limits and the GHCR mirror

Docker Hub rate limits anonymous pulls **per source IP**, and every cloud session on a host shares one. Several agents starting the stack at the same time exhaust the budget together, and from then on `docker compose up` fails with **429 Too Many Requests** — for hours. Unlike a 403 from the egress policy this is not an allowlist problem, and there is nothing to fix from inside the session: you cannot wait it out, and there are no Hub credentials to authenticate with.

The escape hatch is a second registry. The images are mirrored into a public GHCR namespace (`ghcr.io/dgloeckner/*`), which has no anonymous pull limit, and `scripts/dev-stack.sh up` prefetches through `scripts/pull-images.sh`:

| | |
|---|---|
| Order | Docker Hub first, then the mirror. `CLUBBAR_IMAGE_MIRROR=1` reverses it — worth setting when several agents share an IP, so the Hub budget is left for images that have no mirror |
| Retries | 4 attempts with 2/4/8/16s backoff. A **429 breaks out immediately** and goes to the mirror: Hub's window is hours long, so retrying it only delays the fallback that would have worked |
| Handover | A mirror image is `docker tag`ged with the upstream name, so `docker-compose.yml` never mentions the mirror and CI is unaffected. Compose's default pull policy is `missing`, so it uses the local tag as is |
| Failure | Best effort — it always exits 0 and lets compose produce the authoritative error |
| Opt out | `CLUBBAR_SKIP_PREPULL=1` |

**A mirror must be multi-arch, and this is the trap.** `docker pull` + `docker push` from an Apple Silicon laptop publishes an **arm64-only** image. It pulls without complaint on an amd64 cloud session and then dies at startup with `exec format error`, which reads as a broken container rather than a bad mirror — the same failure the `platform:` comment in `docker-compose.yml` already warns about. So:

- `scripts/mirror-images.sh` copies with `docker buildx imagetools create`, which duplicates the manifest list untouched — every architecture survives whatever machine runs the copy.
- `scripts/pull-images.sh` compares the mirror's architecture against the host's and **discards a mismatch** rather than tagging it, falling back to Docker Hub instead.
- `.github/workflows/mirror-images.yaml` does the copying on a runner (manual, weekly, and whenever the image list changes), so the result never depends on a laptop's architecture.

`scripts/mirrored-images.txt` is the list — one upstream reference per line; the mirror name is the last path segment plus the same tag. `mcr.microsoft.com/playwright` is deliberately absent: Microsoft's registry is not rate limited.

```bash
scripts/mirror-images.sh --check   # what each mirror holds vs. what upstream has
scripts/mirror-images.sh           # copy (needs docker login ghcr.io)
```

## Agent skills

### Issue tracker

Issues are tracked as GitHub Issues on `dgloeckner/clubbar`. A cloud session has **no `gh`** — use the GitHub MCP tools (`mcp__github__*`); reuse the repo's existing type/priority/area labels. See `docs/agents/issue-tracker.md`.

### Triage labels

The five canonical triage roles use their default names (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`), alongside the repo's type/priority/area labels. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context layout — `CONTEXT.md` at the repo root and ADRs in `adr/`. See `docs/agents/domain.md`.
