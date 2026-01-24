# Phase 1: Backend Foundation

**Goal**: Working backend with OAS-driven endpoints, mock data, and verified Playwright API tests.

**Status**: Not Started

---

## Progress Summary

| Milestone | Status | Tests Passed |
|-----------|--------|--------------|
| 1. Docker Infrastructure | [ ] | 0/3 |
| 2. Mock Controllers | [ ] | 0/6 |
| 3. Playwright Tests | [ ] | 0/7 |
| 4. End-to-End Verification | [ ] | 0/1 |

---

## Milestone 1: Docker Infrastructure

**Objective**: Containers start and backend responds.

**Note**: Build tools run on host machine. Results are mounted into containers.

### Tasks

| # | Task | Test Command | Expected Result | Status |
|---|------|--------------|-----------------|--------|
| 1.1 | Install backend dependencies (host) | `cd backend && composer install && ls vendor/autoload.php` | File exists | [ ] |
| 1.2 | Start Docker containers | `docker compose up -d && docker compose ps` | All containers show "running" | [ ] |
| 1.3 | Backend health check | `curl -s http://localhost:8080/api/health \| jq .status` | Returns `"ok"` | [ ] |

### Failures

_None yet_

---

## Milestone 2: Mock Controllers per OAS

**Objective**: All Terminal API endpoints return valid mock responses matching OpenAPI spec.

### Tasks

| # | Task | Test Command | Expected Result | Status |
|---|------|--------------|-----------------|--------|
| 2.1 | GET /api/health | `curl -s http://localhost:8080/api/health` | `{"status":"ok","timestamp":"..."}` | [ ] |
| 2.2 | GET /api/sync/members | `curl -s http://localhost:8080/api/sync/members \| jq '.members[0].id'` | Returns UUID string | [ ] |
| 2.3 | GET /api/sync/categories | `curl -s http://localhost:8080/api/sync/categories \| jq '.categories[0].names'` | Returns i18n object | [ ] |
| 2.4 | GET /api/sync/products | `curl -s http://localhost:8080/api/sync/products \| jq '.products[0].price_cents'` | Returns integer | [ ] |
| 2.5 | PATCH /api/sync/members/{id}/language | `curl -s -X PATCH -H "Content-Type: application/json" -d '{"language":"de"}' http://localhost:8080/api/sync/members/test-uuid/language -w "%{http_code}"` | Returns 204 | [ ] |
| 2.6 | POST /api/sync/transactions | `curl -s -X POST -H "Content-Type: application/json" -d '[{"id":"...","member_id":"...","product_id":"...","amount_cents":350,"transaction_type":"purchase","created_at":"..."}]' http://localhost:8080/api/sync/transactions -w "%{http_code}"` | Returns 201 | [ ] |

### Failures

_None yet_

---

## Milestone 3: Playwright Test Suite

**Objective**: Complete API test coverage for all Terminal endpoints.

**Note**: Tests run on host machine against Docker backend at localhost:8080.

### Tasks

| # | Task | Test Command | Expected Result | Status |
|---|------|--------------|-----------------|--------|
| 3.0 | Install test dependencies (host) | `cd e2etests && npm install && ls node_modules/.bin/playwright` | File exists | [ ] |
| 3.1 | health.spec.ts passes | `cd e2etests && npx playwright test tests/api/health.spec.ts` | All tests pass | [ ] |
| 3.2 | sync-members.spec.ts passes | `cd e2etests && npx playwright test tests/api/sync-members.spec.ts` | All tests pass | [ ] |
| 3.3 | sync-categories.spec.ts passes | `cd e2etests && npx playwright test tests/api/sync-categories.spec.ts` | All tests pass | [ ] |
| 3.4 | sync-products.spec.ts passes | `cd e2etests && npx playwright test tests/api/sync-products.spec.ts` | All tests pass | [ ] |
| 3.5 | member-language.spec.ts passes | `cd e2etests && npx playwright test tests/api/member-language.spec.ts` | All tests pass | [ ] |
| 3.6 | transactions.spec.ts passes | `cd e2etests && npx playwright test tests/api/transactions.spec.ts` | All tests pass | [ ] |

### Failures

_None yet_

---

## Milestone 4: End-to-End Verification

**Objective**: Full stack works from clean state.

### Tasks

| # | Task | Test Command | Expected Result | Status |
|---|------|--------------|-----------------|--------|
| 4.1 | All tests pass from clean start | `docker compose down -v && cd backend && composer install && cd .. && docker compose up -d && sleep 10 && cd e2etests && npm install && npx playwright test` | 0 failed tests | [ ] |

### Failures

_None yet_

---

## Test Coverage Requirements

Each endpoint needs these test cases:

### health.spec.ts
- [ ] Returns 200 with status "ok"
- [ ] Includes ISO8601 timestamp
- [ ] Responds within 500ms

### sync-members.spec.ts
- [ ] Returns 200 with members array
- [ ] Supports `?since=` delta query parameter
- [ ] Includes cursor for pagination
- [ ] Members have required fields: id, card_uid, display_name, preferred_language, is_active, is_sepa_valid

### sync-categories.spec.ts
- [ ] Returns 200 with categories array
- [ ] Categories have i18n names (JSON object with language keys)
- [ ] Includes cursor for delta sync

### sync-products.spec.ts
- [ ] Returns 200 with products array
- [ ] Products have i18n names and descriptions
- [ ] Includes price_cents as integer
- [ ] Supports delta sync with cursor

### member-language.spec.ts
- [ ] Returns 204 on valid language code (de, en, fr, it)
- [ ] Returns 422 on invalid language code
- [ ] Returns 404 on unknown member ID

### transactions.spec.ts
- [ ] Accepts single transaction, returns 201
- [ ] Accepts batch up to 100 transactions
- [ ] Returns 422 on missing required fields
- [ ] Returns 422 on invalid amount (zero/negative for purchase)
- [ ] Returns 400 on empty array
- [ ] Returns 413 on batch > 100
- [ ] Idempotent: same UUID returns same result

---

## Commands Reference

All build commands run on the host machine. Docker containers mount the built artifacts.

```bash
# 1. Install backend dependencies (host)
cd backend && composer install

# 2. Start Docker containers
docker compose up -d

# 3. Check backend health
curl -s http://localhost:8080/api/health | jq

# 4. Install test dependencies (host)
cd e2etests && npm install

# 5. Run all API tests (host)
npx playwright test

# Run specific test file
npx playwright test tests/api/health.spec.ts

# Run with verbose output
npx playwright test --reporter=list
```

---

## Completion Criteria

Phase 1 is complete when:
- [ ] All Milestone 1 tasks: [x]
- [ ] All Milestone 2 tasks: [x]
- [ ] All Milestone 3 tasks: [x]
- [ ] All Milestone 4 tasks: [x]
- [ ] No unresolved failures in any section
