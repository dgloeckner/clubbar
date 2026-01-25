# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Ruderbar** is an open-source, member-managed bar/club POS system designed for rowing clubs and sports organizations that need:
- Offline-capable transaction processing
- Granular membership accounting and settlement
- Privacy-first data handling (GDPR-compliant anonymization workflows)
- Flexible deployment on modest infrastructure

**System Components:**
- **Terminal App**: Electron-based POS for RFID/NFC member identification, product selection, and checkout
- **Admin Panel**: React SPA for member/product management, accounting, and compliance workflows
- **Backend**: PHP/MariaDB REST API for synchronization and data persistence

**Core Design Principles:**
- **Offline-first**: Terminal operates fully offline; syncs periodically when connected
- **Eventually consistent**: Frontend maintains local cache; periodic delta sync with backend
- **Immutable transactions**: Transactions are append-only; corrections via reverse transactions (see [ADR-0004](./adr/0004-immutable-transaction-storage.md))
- **Conflict-free sync**: Immutable design eliminates UPDATE/DELETE conflicts across terminals
- **User privacy**: Personal data anonymizable (GDPR Art. 17); booking history retained separately
- **Idempotent APIs**: Client-generated UUIDs ensure safe retry semantics

**Status**: Architecture and specifications complete. Implementation ready for contribution.

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
  - `terminal/technologies.md` — Terminal App (Electron)
  - `admin-frontend/technologies.md` — Admin Panel (React SPA)
  - `backend/technologies.md` — Backend (Laravel)
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
- **Pattern 001**: Form Requests for Input Validation — declarative validation with typed accessors
- **Pattern 002**: Enum for Type-Safe Domain Values — type-safe constants for languages, transaction types, statuses
- **Pattern 003**: Data Transfer Objects (DTOs) — immutable response objects with consistent formatting
- **Pattern 004**: Service Layer — business logic isolated from HTTP, reusable across consumers
- **Pattern 005**: Repository Interface — abstract data access to enable testing and flexibility
- **Pattern 006**: Thin Controllers — controllers route HTTP requests to services (no business logic)
- **Pattern 007**: Centralized Exception Handling — consistent error response format and logging
- **Pattern 008**: Service Provider Bindings — dependency injection configuration and lifecycle management

**Important**: All backend work must follow these patterns for consistency with ADR-0018 (Modular Architecture) and to maintain code quality across modules.

### Development Approach
- **Prefer a planned approach with milestones** over tackling all issues at once
- **Break work into phases** — plan before implementing
- **One feature at a time** — complete and test before moving to the next
- **Validate against use cases** before marking work complete
- **Follow backend patterns** — reference `backend/patterns/` directory for consistent implementation

### Debugging & Testing Best Practices

**When tests fail or requests behave unexpectedly, follow this checklist:**

1. **Check Laravel Application Logs**
   ```bash
   docker compose exec backend tail -100 /app/storage/logs/laravel.log
   ```
   - Look for `ERROR` and `Exception` entries
   - Stack traces show exact line numbers where failures occur
   - Type errors (e.g., Carbon vs DateTimeImmutable) appear here first
   - Source of truth for debugging application-level issues

2. **Check HTTP Access Logs & Status Codes**
   ```bash
   docker compose logs backend | tail -50 | grep "HTTP/1.1"
   ```
   - Verify actual HTTP response codes (200, 302, 400, 404, 422, 500, etc.)
   - Common issues:
     - `302 Found` → Redirect (often CSRF middleware or route not matching)
     - `404` → Route not found or path parameter mismatched
     - `500` → Application error (check Laravel logs)
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

6. **Test Execution Order Matters**
   - Run tests serially (`--workers=1`) when debugging
   - Parallel tests can exhaust resources and cause false timeouts
   - Single-worker execution shows actual errors vs resource contention

### Implementation Plans

**Implementation plans in `plans/` directory are the single source of truth for project status and progress. Do NOT create separate summary or status documents.**

#### Core Principles

- **Plans are stored in `plans/`** — each plan is a markdown file with clear milestones
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
| `admin-frontend/` | Admin Panel technology decisions and architecture |
| `adr/` | Architecture Decision Records (22 ADRs documenting key decisions) |
| `api/` | OpenAPI 3.0 specifications for Admin and Terminal APIs |
| `backend/` | Backend technology decisions and architecture |
| `backend/patterns/` | **Code patterns and architectural patterns for backend quality** |
| `docker/` | Docker Compose configuration for local development |
| `docs/` | Entity-Relationship Models and data documentation |
| `e2etests/` | Playwright API and E2E tests |
| `plans/` | Implementation plans with testable milestones |
| `prototypes/` | Interactive UI prototypes (React JSX + standalone HTML) |
| `terminal/` | Terminal App technology decisions and architecture |
| `use-cases/` | Functional requirements organized by domain |

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

See the **Repository Index** section at the top of this file for the complete directory structure.

**Key directories for contributors:**
- `adr/` — Architecture Decision Records (read before making architectural changes)
- `api/` — OpenAPI specs (update when adding/changing endpoints)
- `use-cases/` — Functional requirements (reference when implementing features)
- `docs/` — Data models and ERMs (reference for database work)
- `prototypes/` — UI prototypes (reference for frontend styling)

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
npx playwright test
```

> **Note**: Frontend setup (Admin Panel + Terminal) will be added here once implemented.

### Code Standards

- **PHP**: PSR-12 style, no external dependencies beyond Composer basics; prepared statements for all DB queries
- **React/TypeScript**: ESLint + Prettier configured; no console.logs in production code
- **Tests**: Jest/Vitest for React, PHPUnit for PHP, Playwright for E2E; minimum 80% coverage for new functions
- **Database changes**: Always include migration script; no direct schema edits
- **Security**: No hardcoded credentials, API keys, or sensitive data in code/logs

### Licensing & Attribution

- **License**: [To be specified: AGPL-3.0, MIT, Apache-2.0, etc.]
- **Attribution**: All contributors credited in CONTRIBUTORS.md
- **DCO**: Contributions must include sign-off (e.g., `git commit -s`)
