# AGENTS.md

Agent-specific workflows and debugging patterns for the Club Bar project.

## Overview

This document defines **project-specific agent workflows** that complement the superpowers skills. General workflows like TDD, brainstorming, planning, and systematic debugging are handled by superpowers skills - see the skill list in system reminders.

**Purpose**: Document agent coordination patterns, test result analysis, and project-specific debugging workflows unique to Club Bar.

---

## E2E Testing Patterns (CRITICAL)

**When working on Playwright tests, ALWAYS reference the patterns in `e2etests/patterns/` directory.**

### Pattern Directory Structure

All E2E testing patterns are documented in `e2etests/patterns/`:
- **README.md**: Complete index, quick start guide, and pattern overview
- **Pattern 001**: Test Data Isolation (unique data per test, no shared state)
- **Pattern 002**: Authentication Isolation (session vs bearer token separation)
- **Pattern 003**: Database-Agnostic Assertions (search by ID, not position)
- **Pattern 004**: Parallel Execution Safety (4 workers by default)
- **Pattern 005**: Using Test IDs (data-testid) (semantic selectors)
- **Pattern 006**: Page Object Model (encapsulate interactions)
- **Pattern 007**: Page Object Fixtures (inject page objects)
- **Pattern 008**: Playwright Assertions & Auto-Waiting (use expect(), not try-catch)

### When to Reference E2E Patterns

**ALWAYS reference patterns when**:
- Creating new Playwright tests
- Debugging test failures (especially intermittent failures)
- Reviewing test code
- Converting UI-only tests to full E2E tests
- Fixing tests that fail in parallel (4 workers) but pass serially (1 worker)

### Critical Pattern Requirements

**Pattern 008 (Playwright Assertions)**:
- ✅ Use `await expect(locator).toBeVisible()` for visibility checks
- ❌ NEVER use `try-catch` with `.isVisible()` for visibility checks
- **Why**: Playwright's error messages are far superior and immediately show what went wrong

**Pattern 001 (Test Data Isolation)**:
- ✅ Create unique test data per test (use timestamps, UUIDs, or incremental counters)
- ❌ NEVER share test data across tests
- ❌ NEVER hardcode IDs or names that could collide
- **Why**: Prevents test failures in parallel execution (4 workers)

**End-to-End Integration Requirement**:
- E2E tests MUST verify complete integration: UI → API → Backend → Database
- For create/save operations: Verify form closes → no error → data in list → count increased
- For delete/update operations: Verify data actually changed/removed in UI and database
- **Not E2E**: Tests that only verify UI behavior without confirming backend persistence

### Quick Start Workflow

```bash
# 1. Read pattern documentation first
cat e2etests/patterns/README.md

# 2. Reference specific pattern when implementing
cat e2etests/patterns/001-test-data-isolation.md

# 3. Create test following patterns
# (implement test in tests/api/ or tests/ui/)

# 4. Run test with 4 workers (default)
cd e2etests
npm test -- tests/api/your-test.spec.ts --workers=4

# 5. If test fails in parallel, check isolation (Pattern 001, 002, 003)
npm test -- tests/api/your-test.spec.ts --workers=1

# 6. Verify test passes with 4 workers before committing
npm test -- tests/api/your-test.spec.ts --workers=4
```

### Pattern Violation Symptoms

| Symptom | Likely Pattern Violation | Fix |
|---------|-------------------------|-----|
| Test passes with 1 worker, fails with 4 | Pattern 001 (data isolation) | Use unique data per test |
| Auth errors in some tests | Pattern 002 (auth isolation) | Check auth.setup.ts, separate session/bearer |
| Test assumes data position in list | Pattern 003 (database-agnostic) | Search by ID, not array index |
| Visibility check has poor error message | Pattern 008 (assertions) | Replace try-catch with expect() |
| Page interaction code duplicated | Pattern 006, 007 (page objects) | Extract to page object class/fixture |

**CRITICAL**: Before creating or modifying Playwright tests, read `e2etests/patterns/README.md` to understand all patterns and their purpose.

---

## Test Result Analysis (Playwright)

**CRITICAL**: Always use Playwright's JSON reporter to preserve test results and analyze failures systematically. Don't lose test results!

### JSON Reporter Workflow

```bash
# Run tests with JSON output
cd e2etests
npm test -- --reporter=json > test-results.json

# Preserve results for analysis
cp test-results.json test-results-$(date +%Y%m%d-%H%M%S).json
```

### Test Execution Monitoring

**CRITICAL RULE**: Monitor test output during execution. If more than 10 tests fail, **STOP immediately** and begin systematic root cause analysis.

**Why**: Mass test failures (>10) indicate systemic issues (backend down, database misconfigured, authentication broken, etc.) rather than individual test problems. Continuing wastes time and obscures the root cause.

#### Monitoring During Execution

**Watch test output in real-time**:
```bash
# Run tests and monitor output
cd e2etests
npm test -- --reporter=json | tee test-results.json

# In separate terminal: Monitor failure count in real-time
watch -n 2 'cat test-results.json | jq ".stats.failed // 0"'
```

**Quick failure count check** (if JSON output is being written):
```bash
# Check current failure count
cat test-results.json | jq '.stats.failed // 0'

# If result is > 10, stop the test run immediately (Ctrl+C)
```

#### When Failure Threshold is Hit (>10 failures)

**Stop execution immediately** and follow this protocol:

1. **Stop the test run**: Press Ctrl+C to terminate
2. **Preserve partial results**:
   ```bash
   cp test-results.json test-results-partial-$(date +%Y%m%d-%H%M%S).json
   ```
3. **Check failure count**:
   ```bash
   cat test-results-partial-*.json | jq '.stats'
   ```
4. **Identify systemic patterns**:
   ```bash
   # Extract all error messages
   cat test-results-partial-*.json | jq -r '.suites[].tests[] | select(.status=="fail") | .error.message' | sort | uniq -c | sort -rn

   # Look for common patterns:
   # - "connection refused" → Backend not running
   # - "timeout" → Backend overloaded or hung
   # - "401 unauthorized" → Auth token expired/invalid
   # - "404" → API routes not registered
   ```
5. **Check backend health**:
   ```bash
   # Is backend running?
   docker compose ps | grep backend

   # Health check
   curl -s http://localhost:8080/api/health | jq .

   # Check for errors in logs
   docker compose exec backend tail -50 /app/storage/logs/laravel.log | grep -i "error\|exception"
   ```
6. **Apply systematic-debugging skill**: Use the superpowers systematic-debugging skill to diagnose and fix the root cause
7. **Resume testing**: Only after fixing systemic issue, run full test suite again

#### Common Systemic Issues

| Failure Pattern | Root Cause | Fix |
|----------------|------------|-----|
| All tests "connection refused" | Backend not running | `docker compose up -d` |
| All tests timeout | Backend hung or overloaded | Restart: `docker compose restart backend` |
| All tests "401 unauthorized" | Auth setup broken | Check `e2etests/auth.setup.ts`, regenerate tokens |
| All tests "404" | Routes not registered | Check `backend/routes/api.php`, restart PHP |
| All tests "database error" | Database migration missing | Run migrations: `docker compose exec backend php artisan migrate` |
| Tests failing with same error message | Code error affecting all endpoints | Check Laravel logs, fix code, restart PHP |

#### Resuming Full Test Execution

**Only resume full test suite after**:
- Root cause identified and fixed
- Backend health check passes
- 3-5 tests run individually and pass
- Backend logs show no errors

```bash
# Verify backend is healthy
curl -s http://localhost:8080/api/health | jq .

# Run small subset first
npm test -- --grep "health" --reporter=json > verify-fix.json

# Check results
cat verify-fix.json | jq '.stats'

# If clean, run full suite
npm test -- --reporter=json > test-results.json
```

### Analyzing Test Results

**Parse failures systematically**:
```bash
# Find all failed tests with error messages
cat test-results.json | jq '.suites[].tests[] | select(.status=="fail") | {title, error: .error.message}'

# Get test statistics
cat test-results.json | jq '.stats'

# Find slow tests (potential timeouts)
cat test-results.json | jq '.suites[].tests[] | select(.duration > 5000) | {title, duration}'

# Check for specific error patterns
cat test-results.json | jq '.suites[].tests[] | select(.status=="fail") | .error.message' | grep -i "timeout\|connection\|404\|422"
```

### Root Cause Patterns

| Error Pattern | Root Cause | Solution |
|---------------|------------|----------|
| `timeout` | Backend slow or resource contention | Check backend logs, try 1 worker |
| `connection refused` | Backend not running | `docker compose up -d` |
| `404 not found` | API route missing or wrong path | Check routes, verify OpenAPI spec |
| `422 validation error` | Invalid request format | Check request body against spec |
| `Target page closed` | Application crash from API error | Check Laravel logs immediately |
| `database constraint` | Test data isolation issue | Follow E2E Pattern 001 (unique data) |

### Agent Debugging Protocol

When a test fails, agents should:

1. **Capture and preserve test results**:
   ```bash
   npm test -- --reporter=json > test-results.json
   ```

2. **Parse for failures**:
   ```bash
   cat test-results.json | jq '.suites[].tests[] | select(.status=="fail")'
   ```

3. **Correlate with backend logs**:
   ```bash
   docker compose exec backend tail -100 /app/storage/logs/laravel.log | grep -A 10 "ERROR\|Exception"
   ```

4. **Check HTTP status codes**:
   ```bash
   docker compose logs backend | tail -50 | grep "POST\|PUT\|PATCH\|DELETE"
   ```

5. **Document findings** before proposing fixes (use systematic-debugging skill for fix planning)

---

## Agent Coordination Patterns

### Pattern 1: Parallel Test Execution

When running independent test suites in parallel:

```bash
# Agent 1: API tests
cd e2etests && npm test -- tests/api/ --reporter=json > api-results.json

# Agent 2: UI tests
cd e2etests && npm test -- tests/ui/ --reporter=json > ui-results.json

# Coordinator: Merge results
jq -s '{stats: {passed: (.[0].stats.passed + .[1].stats.passed), failed: (.[0].stats.failed + .[1].stats.failed)}}' api-results.json ui-results.json
```

**Use case**: Large test suites that can be split by domain (API vs UI, admin vs terminal)

### Pattern 2: Test-Driven Feature Development

**Integration with superpowers**:
1. User requests feature → invoke `brainstorming` skill
2. Spec approved → invoke `writing-plans` skill
3. Plan ready → invoke `test-driven-development` skill
4. Implementation complete → invoke `verification-before-completion` skill
5. All passing → invoke `requesting-code-review` skill

**Agent-specific addition**: Between steps 3-4, use JSON reporter to track test progress:
```bash
# After each test implementation
npm test -- --grep "feature name" --reporter=json > progress-$(date +%H%M%S).json

# Track progress over time
ls -1 progress-*.json | xargs -I {} sh -c 'echo {}; cat {} | jq .stats'
```

### Pattern 3: Distributed Debugging

When debugging complex failures across stack layers:

**Agent 1 (Frontend)**: Analyze test results
```bash
cat test-results.json | jq '.suites[].tests[] | select(.status=="fail") | .error.message'
```

**Agent 2 (Backend)**: Check application logs
```bash
docker compose exec backend tail -100 /app/storage/logs/laravel.log
```

**Agent 3 (Database)**: Verify schema/data state
```bash
docker compose exec database mysql -u clubbar -p clubbar -e "SHOW TABLES; SELECT COUNT(*) FROM members;"
```

**Coordinator**: Synthesize findings and apply `systematic-debugging` skill for fix

---

## Project-Specific Agent Workflows

### Workflow 1: E2E Test Creation for New Feature

**Prerequisite**: Feature spec approved (via brainstorming skill), implementation plan written

**Steps**:
1. Read OpenAPI spec for endpoint definition (`api/admin-api.yaml` or `api/terminal-api.yaml`)
2. Read backend pattern files (`backend/patterns/`) for implementation guidance
3. Read E2E pattern files (`e2etests/patterns/README.md`) for test structure
4. Create test file following Pattern 001-008
5. Run test with JSON reporter:
   ```bash
   npm test -- tests/api/new-feature.spec.ts --reporter=json > new-feature-results.json
   ```
6. If test fails, parse results and apply systematic-debugging skill
7. Once passing, verify with 4 workers:
   ```bash
   npm test -- tests/api/new-feature.spec.ts --workers=4
   ```
8. Commit only if tests pass (follow verification-before-completion skill)

### Workflow 2: Backend Pattern Implementation

**Prerequisite**: Implementation plan approved, TDD cycle started

**Steps**:
1. Read relevant backend pattern (`backend/patterns/*.md`)
2. Read existing similar code for consistency
3. Implement following pattern structure
4. Write PHPUnit tests for service/repository layer
5. Run E2E tests to verify integration:
   ```bash
   npm test -- --grep "feature name" --reporter=json > integration-results.json
   ```
6. Check Laravel logs for errors:
   ```bash
   docker compose exec backend tail -100 /app/storage/logs/laravel.log
   ```
7. Iterate until E2E tests pass
8. Verify test passes with 4 workers before committing

### Workflow 3: Test Isolation Debugging

**When**: E2E tests pass with 1 worker but fail with 4 workers

**Steps**:
1. Run with JSON reporter to capture failures:
   ```bash
   npm test -- --workers=4 --reporter=json > parallel-failures.json
   ```
2. Identify failing tests:
   ```bash
   cat parallel-failures.json | jq '.suites[].tests[] | select(.status=="fail") | .title'
   ```
3. Run each failing test individually:
   ```bash
   npm test -- --grep "exact test name" --workers=1 --reporter=json > isolated-test.json
   ```
4. If passes in isolation → test isolation issue (Pattern 001 violation)
5. Check test for:
   - Shared test data (not unique per test)
   - Hardcoded IDs or names
   - Missing cleanup
   - Database state assumptions
6. Fix isolation issue, re-run with 4 workers to verify

---

## Library Documentation Lookup (Context7 MCP)

**Purpose**: Access up-to-date documentation and code examples for any library or framework used in the project.

### When to Use Context7 MCP

**ALWAYS use context7 when**:
- Implementing features with unfamiliar libraries (Playwright, PHPUnit, React Testing Library, etc.)
- Debugging library-specific errors or unexpected behavior
- Finding best practices for framework-specific patterns
- Verifying API signatures before implementing (reduces trial-and-error)
- Learning how to use new library features or methods

**Examples of good use cases**:
- "How do I properly use Playwright's expect() for async assertions?"
- "What's the correct Laravel validation syntax for IBAN format?"
- "How do I mock API responses in React Testing Library?"
- "What's the proper PHPUnit syntax for testing exceptions?"

### How to Use Context7 MCP

**Two-step process**:

1. **Resolve library ID** (unless library ID is already known):
   ```
   Use: mcp__context7__resolve-library-id
   Parameters:
   - libraryName: "playwright" (or "react", "laravel", etc.)
   - query: "How do I wait for API responses in Playwright tests?"
   ```

2. **Query documentation**:
   ```
   Use: mcp__context7__query-docs
   Parameters:
   - libraryId: "/microsoft/playwright" (from step 1)
   - query: "How do I wait for API responses in Playwright tests?"
   ```

**Important**: Max 3 calls per question. If you can't find what you need after 3 calls, use the best information available.

### Integration with Project Workflows

#### Workflow: E2E Test Implementation with Context7

**Before implementing Playwright tests**:

1. **Read project patterns first**:
   ```bash
   cat e2etests/patterns/README.md
   cat e2etests/patterns/008-playwright-assertions.md
   ```

2. **Query Context7 for up-to-date Playwright docs** (if needed):
   - Resolve library ID: `mcp__context7__resolve-library-id` with libraryName="playwright"
   - Query specific pattern: `mcp__context7__query-docs` with query like:
     - "How to wait for API response with specific URL pattern?"
     - "How to properly assert visibility with auto-retry?"
     - "How to handle authentication in Playwright fixtures?"

3. **Implement test** following both:
   - Project patterns (e2etests/patterns/)
   - Library best practices (from Context7)

4. **Run and verify**:
   ```bash
   npm test -- tests/api/new-test.spec.ts --workers=4 --reporter=json > results.json
   ```

#### Workflow: Backend Pattern Implementation with Context7

**When implementing Laravel features**:

1. **Read backend pattern first**:
   ```bash
   cat backend/patterns/001-form-requests.md
   ```

2. **Query Context7 for Laravel-specific details**:
   - Example queries:
     - "How to create custom validation rules in Laravel?"
     - "How to properly use Laravel service providers for dependency injection?"
     - "What's the best practice for Laravel repository pattern?"

3. **Implement following both**:
   - Project pattern structure (backend/patterns/)
   - Laravel framework conventions (from Context7)

4. **Test integration**:
   ```bash
   # Restart PHP
   docker compose exec backend supervisorctl restart php-fpm:php-fpmd

   # Run E2E tests
   npm test -- --grep "feature name" --reporter=json > results.json
   ```

### Common Library Queries for Club Bar Project

**Playwright (E2E Testing)**:
- Library ID: `/microsoft/playwright`
- Common queries:
  - "How to wait for network response matching URL pattern?"
  - "How to use expect() with async locators?"
  - "How to create reusable fixtures for page objects?"
  - "How to run tests in parallel safely?"
  - "How to handle authentication in Playwright setup?"

**Laravel (Backend)**:
- Library ID: `/laravel/laravel`
- Common queries:
  - "How to create custom validation rules?"
  - "How to use service providers for dependency injection?"
  - "How to properly handle database transactions in tests?"
  - "What's the best practice for repository pattern in Laravel?"
  - "How to create custom middleware for API authentication?"

**React (Admin Frontend)**:
- Library ID: `/facebook/react`
- Common queries:
  - "How to properly use useEffect for data fetching?"
  - "How to handle form state with controlled components?"
  - "What's the best practice for context providers?"
  - "How to test React components with Testing Library?"

**PHPUnit (Backend Testing)**:
- Library ID: `/sebastianbergmann/phpunit`
- Common queries:
  - "How to properly test exceptions in PHPUnit?"
  - "How to create test doubles (mocks/stubs)?"
  - "What's the best practice for testing database interactions?"
  - "How to use data providers for parameterized tests?"

### Best Practices for Context7 Usage

**DO**:
- ✅ Query Context7 BEFORE implementing unfamiliar patterns
- ✅ Use specific, detailed queries (include context about what you're building)
- ✅ Combine Context7 results with project patterns (Context7 = library best practices, project patterns = project-specific structure)
- ✅ Query once per implementation phase (reduces calls, maximizes value)
- ✅ Include your actual use case in the query (e.g., "How to wait for API response in Playwright when testing form submission?")

**DON'T**:
- ❌ Query Context7 for information already in project patterns
- ❌ Make generic queries like "how to use Playwright" (be specific)
- ❌ Query more than 3 times per question (use best available info after 3 calls)
- ❌ Ignore project patterns in favor of Context7 docs (project patterns take precedence for project-specific conventions)
- ❌ Query for basic syntax you already know

### Debugging with Context7

**When encountering library-specific errors**:

1. **Check error message first**:
   ```bash
   # Playwright errors
   cat test-results.json | jq '.suites[].tests[] | select(.status=="fail") | .error.message'

   # Laravel errors
   docker compose exec backend tail -50 /app/storage/logs/laravel.log
   ```

2. **If error is library-specific** (e.g., "locator.isVisible is not a function", "Undefined method 'validate'"):
   - Query Context7 with the specific error pattern
   - Example: "Why does Playwright throw 'locator.isVisible is not a function'?"
   - Or: "How to properly use Laravel validation in Form Requests?"

3. **Apply fix from Context7 + project patterns**:
   - Context7 provides the correct library API
   - Project patterns provide the structure

4. **Verify fix**:
   ```bash
   # Re-run test
   npm test -- --grep "test name" --reporter=json > fixed-results.json
   ```

### Example: Complete Workflow with Context7

**Scenario**: Implementing a new Playwright test for settlements API with filtering

**Step 1: Read project patterns**
```bash
cat e2etests/patterns/README.md
cat e2etests/patterns/008-playwright-assertions.md
```

**Step 2: Query Context7 for Playwright best practices**
```
mcp__context7__resolve-library-id
- libraryName: "playwright"
- query: "How to wait for API response matching URL pattern when testing filtered list?"

mcp__context7__query-docs
- libraryId: "/microsoft/playwright"
- query: "How to use page.waitForResponse() with URL pattern and verify response body?"
```

**Step 3: Implement test** combining:
- Pattern 008 (use expect() for assertions)
- Pattern 001 (unique test data)
- Context7 guidance (proper waitForResponse syntax)

**Step 4: Run and verify**
```bash
npm test -- tests/api/settlements.spec.ts --workers=4 --reporter=json > results.json
cat results.json | jq '.stats'
```

**Step 5: Debug if needed**
```bash
# If test fails, check error
cat results.json | jq '.suites[].tests[] | select(.status=="fail") | .error'

# Query Context7 if error is Playwright-specific
# Fix and re-run
```

---

## GitHub Actions Pipeline Monitoring

**Skill**: `monitor-github-pipeline`

**When to invoke**: Immediately after every `git push` to a remote — the pipeline starts automatically and the agent should offer to monitor it.

### Overview

After pushing to the remote, GitHub Actions runs a pipeline that builds the backend, executes all tests, creates a deployment package, and deploys to the integration environment.

### Trigger Behavior

After every push (including pushes made via `commit-commands:commit-push-pr`), the agent **must** invoke the `monitor-github-pipeline` skill, which handles:

1. **Ask the user** whether to monitor the pipeline
2. **Poll `gh` API** every 20 seconds until the run completes
3. **Report progress** (job names, elapsed time) while waiting
4. **On success**: report outcome and stop
5. **On failure**: analyze logs, classify the root cause, propose and implement a fix, push, and re-monitor

### Pipeline Anatomy

| Stage | What it does |
|---|---|
| **Build** | `composer install`, compile assets, lint checks |
| **Test** | PHPUnit unit tests + Playwright E2E tests |
| **Package** | Create deployment artifact |
| **Deploy** | Push artifact to integration environment |

### Key Commands

```bash
# Find the latest run for current branch
gh run list --branch "$(git rev-parse --abbrev-ref HEAD)" --limit 1

# Poll a specific run
gh run view <RUN_ID> --json status,conclusion,url

# Get failed job logs
gh run view <RUN_ID> --log-failed

# Re-run failed jobs only (use sparingly — fix root cause instead)
gh run rerun <RUN_ID> --failed
```

### Integration with Superpowers Skills

**How AGENTS.md complements superpowers**:

| Superpowers Skill | AGENTS.md Addition |
|-------------------|-------------------|
| `test-driven-development` | JSON reporter workflow for tracking test progress; Context7 for library-specific test patterns |
| `systematic-debugging` | Playwright test result parsing, backend log correlation, Context7 for library-specific error debugging |
| `brainstorming` | Context7 for discovering library capabilities when designing features |
| `writing-plans` | Context7 for verifying library API availability before planning implementation |
| `executing-plans` | Backend/E2E pattern integration workflow; Context7 for library best practices |
| `verification-before-completion` | JSON reporter verification commands |
| `dispatching-parallel-agents` | Parallel test execution pattern |
| `subagent-driven-development` | Test isolation debugging workflow |
| `monitor-github-pipeline` | Post-push pipeline monitoring, failure analysis, and auto-fix loop |

**Key principle**: Superpowers skills define **how to work**, AGENTS.md defines **project-specific commands and workflows**.

---

## Command Reference

### Quick Test Commands

```bash
# Run tests with JSON reporter (always prefer this)
npm test -- --reporter=json > test-results.json

# Run single test file
npm test -- tests/api/filename.spec.ts --workers=4

# Debug single test (serial execution)
npm test -- --grep "test name" --workers=1

# Parse failures from JSON
cat test-results.json | jq '.suites[].tests[] | select(.status=="fail")'

# Check test stats
cat test-results.json | jq '.stats'
```

### Backend Debugging Commands

```bash
# Check Laravel logs
docker compose exec backend tail -100 /app/storage/logs/laravel.log

# Check HTTP status codes
docker compose logs backend | tail -50 | grep "HTTP/1.1"

# Restart PHP after code changes
docker compose exec backend supervisorctl restart php-fpm:php-fpmd && sleep 2

# Health check
docker compose exec backend curl -s http://localhost/api/health | jq .
```

### Test Result Analysis Commands

```bash
# Find all failed tests with details
cat test-results.json | jq '.suites[].tests[] | select(.status=="fail") | {title, error, duration}'

# Find slow tests (>5s)
cat test-results.json | jq '.suites[].tests[] | select(.duration > 5000)'

# Count by status
cat test-results.json | jq '.suites[].tests | group_by(.status) | map({status: .[0].status, count: length})'

# Extract error messages only
cat test-results.json | jq '.suites[].tests[] | select(.status=="fail") | .error.message'
```

### Context7 MCP Quick Reference

**Common library IDs** (use with `mcp__context7__query-docs`):
```
Playwright: /microsoft/playwright
Laravel: /laravel/laravel
React: /facebook/react
PHPUnit: /sebastianbergmann/phpunit
```

**Query pattern** (when library ID is unknown):
```
1. mcp__context7__resolve-library-id
   - libraryName: "playwright"
   - query: "Your specific question about what you're trying to do"

2. mcp__context7__query-docs
   - libraryId: (from step 1)
   - query: "Your specific question about what you're trying to do"
```

**Example queries by scenario**:
```
Playwright test implementation:
- "How to wait for API response matching specific URL pattern?"
- "How to properly use expect() with locators for visibility assertions?"
- "How to create reusable fixtures for page objects?"

Laravel backend implementation:
- "How to create custom validation rules in Laravel?"
- "How to properly use repository pattern with dependency injection?"
- "How to handle database transactions in service layer?"

Debugging library errors:
- "Why does Playwright throw 'locator.isVisible is not a function'?"
- "What's the correct syntax for Laravel Form Request validation?"
- "How to properly mock dependencies in PHPUnit?"
```

**Best practices**:
- Query BEFORE implementing (not after errors occur)
- Be specific in queries (include your use case)
- Max 3 calls per question
- Combine Context7 results with project patterns

### PHP Logs Skill (Docker Backend Debugging)

**When to invoke**: After test failures, backend errors, or when correlating Playwright failures with backend logs.

**Skill**: `php-logs` (automatically available in Claude Code)

**What it does**:
- Checks ALL 4 log sources (Application JSON, PHP-FPM, Apache, Docker)
- Parses JSON logs with jq for error patterns
- Saves analyzed output to timestamped files (`logs/php-YYYYMMDD-HHMMSS.log`)
- Correlates timestamps with test execution
- Provides both console output AND file preservation

**Key commands** (from skill):
```bash
# All 4 sources checked by default
TODAY=$(date +%Y-%m-%d)
docker compose exec backend tail -100 /app/logs/$TODAY.log | jq .
docker compose exec backend tail -100 /opt/docker/var/log/php-fpm-error.log
docker compose exec backend tail -100 /var/log/apache2/error.log
docker compose logs backend --tail=100

# Analysis (JSON parsing with jq)
docker compose exec backend cat /app/logs/$TODAY.log | \
  jq 'select(.level == "ERROR") | {ts, msg, ctx}'

# Preservation (timestamped file)
mkdir -p logs
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
docker compose exec backend cat /app/logs/$TODAY.log | \
  jq 'select(.level == "ERROR" or .level == "CRITICAL")' | \
  tee logs/php-$TIMESTAMP.log
```

**Integration with Playwright**: Automatically invoked when >10 tests fail (when playwright-testing skill is implemented).

**Red flags the skill counters**:
- "Application logs have everything" → Checks all 4 sources
- "Console output is enough" → Saves to timestamped file
- "Manual JSON reading" → Uses jq to parse/filter
- "Recent logs are fine" → Filters by precise timestamp

---

## Best Practices for Agents

1. **Always preserve test results**: Never run tests without capturing JSON output
2. **Monitor test execution**: Stop immediately if >10 tests fail; indicates systemic issue
3. **Parse before proposing fixes**: Understand the failure pattern before debugging
4. **Correlate across stack**: Test results + backend logs + HTTP codes = complete picture
5. **Follow patterns**: Use E2E patterns (001-008) and backend patterns consistently
6. **Invoke superpowers skills**: For TDD, debugging, planning - don't reinvent workflows
7. **Query Context7 before implementing**: Get up-to-date library docs BEFORE writing unfamiliar code; reduces trial-and-error
8. **Verify before committing**: Use `verification-before-completion` skill, confirm tests pass with 4 workers
9. **Document findings**: When debugging, document root cause in test results or memory files

---

## Anti-Patterns (What NOT to Do)

❌ **Running tests without JSON reporter**: Loses failure details, makes debugging harder
❌ **Continuing test execution with >10 failures**: Wastes time; stop and fix systemic issue first
❌ **Proposing fixes before analyzing test results**: Guessing at root cause wastes time
❌ **Ignoring backend logs**: Test failures often have clear explanations in Laravel logs
❌ **Committing without verification**: Untested code breaks CI/CD and future development
❌ **Reinventing TDD/debugging workflows**: Use superpowers skills instead
❌ **Assuming tests pass after code changes**: Always run and verify, especially with 4 workers
❌ **Debugging in parallel mode first**: Start with 1 worker to isolate actual errors from resource contention
❌ **Fixing individual tests when many are failing**: Fix the systemic issue first, then re-run all tests
❌ **Implementing unfamiliar library patterns without Context7**: Trial-and-error wastes time; query docs first
❌ **Querying Context7 for info in project patterns**: Project patterns are the source of truth for project conventions
❌ **Making >3 Context7 calls per question**: Use best available info after 3 calls; don't over-query
❌ **Ignoring Context7 library best practices**: Combining project patterns + library best practices = highest quality code
❌ **Not offering to monitor the pipeline after a push**: Always invoke `monitor-github-pipeline` skill after every push
❌ **Re-running the pipeline without fixing the root cause**: Fix the code, then push — don't blindly retry
❌ **Expanding scope when fixing CI failures**: Only fix what caused the pipeline to fail

---

## Summary

**AGENTS.md Purpose**: Project-specific test analysis, debugging commands, agent coordination patterns, and library documentation workflows.

**Superpowers Skills Purpose**: General development workflows (TDD, brainstorming, planning, debugging methodology).

**Context7 MCP Purpose**: Up-to-date library documentation and code examples for framework-specific implementation.

**Key Takeaways**:
1. **Monitor test execution**: Stop immediately if >10 tests fail; indicates systemic issue requiring root cause analysis
2. **Use JSON reporter**: Always capture test results for systematic analysis
3. **Parse before fixing**: Understand failure patterns before proposing solutions
4. **Correlate across stack**: Test results + backend logs + HTTP codes = complete picture
5. **Invoke superpowers skills**: For TDD, debugging, planning workflows
6. **Query Context7 before implementing**: Get library-specific best practices BEFORE writing unfamiliar code; reduces trial-and-error and improves code quality
7. **Monitor the pipeline after every push**: Invoke `monitor-github-pipeline` skill — ask, poll, analyze, fix, repeat
