# AGENTS.md

Agent-specific workflows and debugging patterns for the Ruderbar project.

## Overview

This document defines **project-specific agent workflows** that complement the superpowers skills. General workflows like TDD, brainstorming, planning, and systematic debugging are handled by superpowers skills - see the skill list in system reminders.

**Purpose**: Document agent coordination patterns, test result analysis, and project-specific debugging workflows unique to Ruderbar.

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
docker compose exec database mysql -u ruderbar -p ruderbar -e "SHOW TABLES; SELECT COUNT(*) FROM members;"
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

## Integration with Superpowers Skills

**How AGENTS.md complements superpowers**:

| Superpowers Skill | AGENTS.md Addition |
|-------------------|-------------------|
| `test-driven-development` | JSON reporter workflow for tracking test progress |
| `systematic-debugging` | Playwright test result parsing, backend log correlation |
| `brainstorming` | No addition (skill is complete) |
| `writing-plans` | No addition (skill is complete) |
| `executing-plans` | Backend/E2E pattern integration workflow |
| `verification-before-completion` | JSON reporter verification commands |
| `dispatching-parallel-agents` | Parallel test execution pattern |
| `subagent-driven-development` | Test isolation debugging workflow |

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

---

## Best Practices for Agents

1. **Always preserve test results**: Never run tests without capturing JSON output
2. **Monitor test execution**: Stop immediately if >10 tests fail; indicates systemic issue
3. **Parse before proposing fixes**: Understand the failure pattern before debugging
4. **Correlate across stack**: Test results + backend logs + HTTP codes = complete picture
5. **Follow patterns**: Use E2E patterns (001-008) and backend patterns consistently
6. **Invoke superpowers skills**: For TDD, debugging, planning - don't reinvent workflows
7. **Verify before committing**: Use `verification-before-completion` skill, confirm tests pass with 4 workers
8. **Document findings**: When debugging, document root cause in test results or memory files

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

---

## Summary

**AGENTS.md Purpose**: Project-specific test analysis, debugging commands, and agent coordination patterns.

**Superpowers Skills Purpose**: General development workflows (TDD, brainstorming, planning, debugging methodology).

**Key Takeaways**:
1. **Monitor test execution**: Stop immediately if >10 tests fail; indicates systemic issue requiring root cause analysis
2. **Use JSON reporter**: Always capture test results for systematic analysis
3. **Parse before fixing**: Understand failure patterns before proposing solutions
4. **Correlate across stack**: Test results + backend logs + HTTP codes = complete picture
5. **Invoke superpowers skills**: For TDD, debugging, planning workflows
