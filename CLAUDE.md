# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Member Bar System** is an open-source, member-managed bar/club POS system designed for organizations that need:
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

### Development Approach
- **Prefer a planned approach with milestones** over tackling all issues at once
- **Break work into phases** — plan before implementing
- **One feature at a time** — complete and test before moving to the next
- **Validate against use cases** before marking work complete

---

### Directory Purposes

| Directory | Purpose |
|-----------|---------|
| `admin-frontend/` | Admin Panel technology decisions and architecture |
| `adr/` | Architecture Decision Records (22 ADRs documenting key decisions) |
| `api/` | OpenAPI 3.0 specifications for Admin and Terminal APIs |
| `backend/` | Backend technology decisions and architecture |
| `docker/` | Docker Compose configuration for local development |
| `docs/` | Entity-Relationship Models and data documentation |
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
