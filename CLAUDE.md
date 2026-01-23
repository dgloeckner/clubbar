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

## Architecture Quick Reference

### System Topology
```
Terminal (Electron + React)          Backend (PHP 8.1 + MariaDB)
├─ SQLite (local cache)       ─HTTP/HTTPS─────► Apache httpd
├─ React UI (800x480)                           └─ REST API
├─ RFID Reader (node-hid)                       └─ MariaDB
└─ Sync Worker (60s interval)
```

### Data Flow
- **Members/Products**: Backend → Frontend (delta sync via `since` timestamps, read-only on terminal)
- **Transactions**: Frontend → Backend (batch upload, idempotent via UUID-based deduplication)
- **Compression**: All API communication uses gzip (see [ADR-0003](./adr/0003-gzip-compression-http.md)) - ~85% payload reduction

### Core Entities
- **members**: UUID (permanent), card_uid (RFID/NFC identifier), first_name, last_name, preferred_language (ISO 639-1), iban (for payments), mandate_reference (SEPA), sensitive_data (backend-only: contact, etc.), deleted_at (soft-delete flag)
- **products**: UUID, names (JSON: multilingual), prices_cents (integer), category (customizable, not translated), is_active flag
- **transactions**: UUID (immutable), member_id, product_id, amount_cents (integer), timestamp (append-only)
- **settlements**: Periodic accounting records (monthly, quarterly, etc.), generated from unsettled transactions; CSV/XML exports include member IBANs and mandate references (see [ADR-0004](./adr/0004-immutable-transaction-storage.md), [ADR-0005](./adr/0005-iban-storage-and-validation.md), [ADR-0008](./adr/0008-sepa-xml-export-format-selection.md))
- **sepa_config**: Organization-level SEPA settings (Gläubiger-ID, bank account, address) - single row configuration (see [ADR-0007](./adr/0007-organization-sepa-configuration-storage.md))

---

## Frontend Stack

### Terminal App (Electron)
- **Runtime**: Electron 28+
- **UI**: React 18 + Mantine 7 (touch-optimized)
- **DB**: SQLite via better-sqlite3 (synchronous, performant in Electron)
- **RFID**: node-hid (USB HID communication)
- **HTTP**: fetch API, token-based auth (Bearer header)
- **State**: React Context + Hooks (or Zustand if needed)

### Admin Panel (React SPA)
- **Runtime**: React 18 + Vite
- **UI**: Mantine 7, Recharts (for dashboards)
- **HTTP**: Axios (interceptors for auth/errors)
- **State**: Zustand (lightweight)
- **Forms**: React Hook Form + Zod
- **i18n**: i18next (de/en, JSON-based)
- **Routing**: React Router 6

### Key Patterns
- Both frontends read from backend. Terminal reads cache; admin reads live API.
- API calls relative to `/api/...` (host-agnostic)
- Session-based auth for admin (cookies); token-based for terminal

---

## Backend Stack

### Technology
- **Language**: PHP 8.1+ (widely supported, low deployment overhead)
- **Framework**: Minimal (FastRoute or custom router; no heavy frameworks)
- **Database**: MariaDB 10.11+ or MySQL 8.0+
- **Data Access**: PDO wrapper or Medoo (lightweight, stateless, no ORM overhead)
- **Authentication**: PHP sessions (admin UI) + Bearer tokens (terminal API)
- **SEPA Exports**: digitick/sepa-xml (Composer package for pain.008.001.02 XML generation)
- **Design**: Stateless, horizontally scalable; no real-time requirements (polling-based sync)

### Database Schema Highlights
- **members**: id (UUID), card_uid (RFID/NFC), first_name, last_name, preferred_language (ISO 639-1, e.g., 'de'), iban (for payments), mandate_reference (SEPA), is_active, deleted_at, created_at, updated_at (see [ADR-0005](./adr/0005-iban-storage-and-validation.md), [ADR-0006](./adr/0006-sepa-mandate-reference-strategy.md))
- **products**: id (UUID), names (JSON: multilingual), descriptions (JSON: multilingual), price_cents (integer), category, is_active, created_at, updated_at
- **transactions**: id (UUID), member_id, product_id, amount_cents (integer), created_at (immutable, append-only)
- **settlements**: id (UUID), period_start, period_end, sepa_execution_date, sepa_message_id, created_at
- **admin_users**: id (UUID), email, password_hash, role (admin/viewer/auditor), is_active, created_at, updated_at
- **audit_log**: id, admin_user_id, action (create/update/delete/export/anonymize), entity_type, entity_id, changes_json, ip_address, user_agent, created_at

### API Endpoints (Key Examples)
- `GET /api/sync/members?since={timestamp}` → delta sync members (includes `preferred_language`)
- `GET /api/sync/products?since={timestamp}` → delta sync products (language-agnostic, all translations returned)
- `POST /api/sync/transactions` → upload transactions (batch, idempotent)
- `PATCH /api/members/{id}` → update member (e.g., `preferred_language`, `iban` for SEPA exports)
- `POST /api/auth/login` → admin login (session-based)
- `GET /api/auth/me` → current session info
- `GET/POST/PUT /api/members/{id}` → member CRUD
- `POST /api/members/{id}/export` → GDPR data export (JSON/PDF)
- `POST /api/members/{id}/anonymize` → GDPR anonymization workflow
- `GET /api/settlements` → list settlements with transaction details
- `POST /api/settlements` → create new settlement period

---

## GDPR Compliance (Privacy Workflows)

### Right to Erasure Workflow (GDPR Art. 17)
1. Admin confirms member has zero balance + no outstanding settlements
2. System triggers anonymization: first_name/last_name/account_details → NULL, card_uid → "ANONYMOUS-{uuid}", is_active → false, deleted_at → now
3. Transactions retained (accounting/legal retention requirements; link to anonymous member record)
4. Next terminal sync: member removed from local cache; card scans show "Unknown member"
5. Audit log records anonymization action with masked sensitive data

### Right to Data Portability & Transparency (GDPR Art. 20 / Art. 15)
1. Admin initiates data export for member
2. System generates JSON export: member metadata + transaction history + settlement records
3. Export timestamped and logged; data in human-readable and machine-readable formats
4. Audit log records action: 'export', export_type, requester

### Right to Rectification Workflow (GDPR Art. 16)
1. Standard member edit form (name, contact info, account details)
2. All changes logged: action='update', old_values, new_values, timestamp, editor
3. Sensitive fields (account details) masked in logs

### Data Retention Tiers
- **Transactions/Settlements**: Jurisdiction-dependent (e.g., 7-10 years for accounting records); configurable
- **Anonymized Member Records**: Duration of transaction retention (for linkage)
- **Audit Log**: Retention per policy; configurable rotation
- **Admin Sessions**: 24 hours (automatic cleanup)

---

## Development Workflow

### Prerequisites
- Node.js 20+ (frontend)
- PHP 8.1+ (backend)
- MariaDB 10.11+ (database)
- Docker (recommended for local dev)

### Local Development (Docker)
```bash
docker-compose up -d
# admin-fe:    http://localhost:5173
# terminal-fe: http://localhost:5174
# webserver:   http://localhost:8080
# database:    localhost:3306
```

### Frontend Development
```bash
# Terminal App
cd terminal-frontend
npm install
npm run dev

# Admin Panel
cd admin-frontend
npm install
npm run dev
```

### Backend Development
- Place PHP files in `/backend` directory
- Database migrations/schema in `/backend/migrations` or SQL files
- Configuration in `/backend/config.php` (not in version control)

### Testing Strategy (from `frgs-bar-test-concept.md`)
- **Unit Tests**: Jest/Vitest (frontend), PHPUnit (backend)
- **Integration Tests**: API + DB interaction (Playwright for E2E)
- **E2E Tests**: Critical user journeys (Playwright)
- Test framework configured in docker-compose for CI/CD

---

## Terminal Application (Electron-based)

### Offline-First Capability
- Local SQLite caches member and product data (read-only)
- Transactions queued in local store before upload
- **Fully functional offline**: card scans, product selection, and checkout work without network
- **Network restoration**: Automatic batch sync of queued transactions (with retry logic)
- **Limitations**: New members, product updates, and member status changes require backend connection

### Card Reader Integration
- **Input device**: USB RFID/NFC reader (appears as HID keyboard device)
- **Hardware abstraction**: node-hid library handles USB communication in main process
- **IPC bridge**: Main process → Renderer process via contextBridge (secure)
- **Card lookup**: Renderer queries SQLite cache for card_uid → member resolution
- **Customizable**: Designed to work with standard USB card readers (Mifare, NFC, etc.)

### UI/UX Design
- **Touchscreen-first** (800x480 typical; responsive for other sizes)
- **Touch-optimized**: Large tap targets (48px+ buttons), no hover-dependent interactions
- **Mantine components**: Pre-tested for mobile/touch scenarios
- **Accessibility**: High contrast, readable fonts, clear feedback

### Synchronization Cycle (Configurable)
1. **Connectivity check**: Ping backend (or check socket state)
2. **Download members** (delta): `GET /api/sync/members?since={last_sync_ts}`
3. **Download products** (delta): `GET /api/sync/products?since={last_sync_ts}`
4. **Upload transactions** (batch): `POST /api/sync/transactions` (max 100 per request, retryable)
5. **Persist state**: Store new timestamps + mark uploaded transactions as synced
6. **Recommended interval**: 60 seconds (members/products), 30 seconds (transactions); configurable per deployment

---

## Admin Panel (React SPA)

### Authentication & Authorization
- **Session-based**: PHP sessions with secure cookies (HttpOnly, Secure, SameSite=Lax)
- **Login flow**: Email + password → session_regenerate_id → set-cookie → subsequent requests auto-authenticated
- **Role-based access**:
  - **admin**: Full CRUD on all entities, settlements, member anonymization, user management
  - **viewer**: Read-only (dashboard, reports, transaction history)
  - **auditor**: Read-only + audit log access (optional role for compliance)
- **Session timeout**: 2 hours (configurable; auto-logout with warning)

### Core Features
- **Dashboard**: Active members, total outstanding balance, last settlement date, terminal sync status, quick statistics
- **Member Management**: CRUD operations, GDPR export, anonymization workflow, balance tracking, IBAN and mandate reference management (see [ADR-0005](./adr/0005-iban-storage-and-validation.md), [ADR-0006](./adr/0006-sepa-mandate-reference-strategy.md))
- **Product Management**: CRUD + enable/disable (soft toggle preserves history)
  - **Internationalization**: Products support multilingual names/descriptions via JSON fields
  - **Admin UI**: Language tabs allow editing translations (de, en, fr, etc.) in single form
  - See [ADR-0002](./adr/0002-product-internationalization.md) for architecture details
- **Transaction Journal**: Full history, filterable by date/member/product/type; ability to add reverse transactions (corrections)
- **Settlements**: Create periodic settlements (monthly, quarterly, etc.), review before finalization
  - **CSV export**: Traditional format for bank tool imports (member name, IBAN, amount)
  - **SEPA XML export**: pain.008.001.02 format for direct online banking upload with mandate references
  - **Lead time validation**: Automatic business-day calculation (5 days FRST, 2 days RCUR); prevents bank rejection
  - **Mandate health check**: Shows expired/revoked mandates; warns before settlement if issues
  - See [ADR-0004](./adr/0004-immutable-transaction-storage.md), [ADR-0005](./adr/0005-iban-storage-and-validation.md), [ADR-0006](./adr/0006-sepa-mandate-reference-strategy.md), [ADR-0008](./adr/0008-sepa-xml-export-format-selection.md), [ADR-0009](./adr/0009-settlement-lead-times-bank-working-days.md)
- **Terminal Management**: Pair/register terminals, generate API tokens, monitor connectivity and sync status
- **Audit Log**: Searchable, filterable by action/entity/admin/timestamp; diff-view of changes

### Internationalization (i18n)
- **UI/Error messages**: JSON files in `/locales/{lang}/*.json` (UI strings, category labels, error messages)
- **Backend localization**: Accept-Language header → API error messages in requested language
- **Product names/descriptions**: Stored as JSON in database; API is language-agnostic (always returns all translations)
  - Admin UI: Language tabs for creating/editing translations
  - Backend config: Defines enabled languages and default language (fallback if translation missing)
  - Terminal: Displays products based on member's persisted `preferred_language`
  - See [ADR-0002](./adr/0002-product-internationalization.md) for details
- **User language preference**: Persisted in member record (`preferred_language` field)
  - Terminal reads from member sync response
  - Terminal can update via API (if operator changes language in settings)
- **Backend configuration**: Controls which languages are available
  ```php
  // config/languages.php
  'enabled_languages' => ['en', 'de', 'fr'],
  'default_language' => 'en'
  ```
- **Default language**: English; can be configured per deployment; used as fallback for missing translations
- **Note**: As the project is translated to English, documentation and UI strings will be English-first

---

## SEPA Settlement Workflow

The system supports SEPA Direct Debit collections for automated member billing:

### SEPA Prerequisites (Outside System)

- **Gläubiger-ID**: Apply at https://www.glaeubiger-id.bundesbank.de (free, few days processing)
- **Bank account**: SEPA-enabled business account with collection rights
- **Member mandates**: Original signed SEPA mandates (collected and stored separately from system)

### System Configuration

Admin panel → Settings → SEPA Configuration:
- **Gläubiger-ID**: Organization's creditor identifier (immutable after set)
- **Organization IBAN**: Bank account for receiving payments
- **Organization name**: Name for SEPA records
- **Address**: Street, city, country

See [ADR-0007](./adr/0007-organization-sepa-configuration-storage.md).

### Member SEPA Data

Each member record stores:
- **IBAN**: Member's bank account (for debits)
- **Mandate reference**: SEPA mandate identifier (editable; default = UUID without hyphens)

See [ADR-0005](./adr/0005-iban-storage-and-validation.md), [ADR-0006](./adr/0006-sepa-mandate-reference-strategy.md).

### Settlement Sequence

1. **Create settlement**: Admin chooses settlement period
2. **Preview**: System shows members, totals, outstanding balance
3. **Choose execution date**:
   - Minimum: TODAY + 7 calendar days
   - System suggests earliest valid date
   - No holiday calendar needed (fixed 7-day rule)
   - See [ADR-0009](./adr/0009-settlement-lead-times-bank-working-days.md)
4. **Finalize**: System marks outstanding transactions as settled
5. **Export**:
   - **CSV**: Download for manual verification or bank tool import
   - **SEPA XML** (pain.008.001.02): Upload directly to online banking
   - See [ADR-0008](./adr/0008-sepa-xml-export-format-selection.md)
6. **Bank upload**: Admin uploads XML to bank online portal, approves with TAN
7. **Collections**: Bank processes on execution date; money received 1-2 days later

### SEPA XML Export Implementation

The backend uses **digitick/sepa-xml** (Composer package) for pain.008.001.02 SEPA Direct Debit XML generation:

```bash
# Install during backend setup
composer require digitick/sepa-xml
```

Benefits:
- ✅ Tested, production-ready library
- ✅ Automatic XSD validation
- ✅ Handles complex SEPA rules automatically
- ✅ No need for custom XML generation code
- ✅ Community-maintained with regular updates

See [ADR-0008](./adr/0008-sepa-xml-export-format-selection.md) for implementation details.

### Key Rules

- **No BIC**: Modern SEPA derives BIC from IBAN automatically
- **Mandate reference**: Editable field per member; default = UUID without hyphens
- **Sequence type**: Always RCUR (recurring); pragmatic for small organizations
- **Lead time**: Fixed 7 calendar days minimum (TODAY + 7)
- **Minimal debtor data**: Only name and IBAN in XML (no address; privacy-first)
- **Compliance**: Records retained 10 years per German tax code (§ 147 AO)

---

## Security Checklist

### Transport
- HTTPS only (TLS 1.2+)
- Terminal uses Bearer token auth
- Admin uses session cookies (HttpOnly, Secure, SameSite=Lax)

### Data Protection
- IBAN/BIC masked in logs and standard views (show last 4 digits only)
- Passwords: bcrypt with cost ≥ 12
- UUIDs for most IDs (random, not sequential)
- Terminal only stores: member UUID, card_uid, first_name, last_name, registration date (sensitive data stays backend-only)
- Monetary values: Always stored as integer cents (never floating-point; see ADR-0001)

### Validation & Injection
- All SQL via prepared statements (PDO/Medoo)
- React escapes by default (XSS)
- CSRF tokens for state-changing requests (session-based)
- Rate limiting on login endpoint (brute force protection)

### Access Control
- Terminal: API token per device
- Admin: session + role-based (admin/viewer)
- No direct user authentication at terminal (RFID lookup only)

---

## Documentation References

**Note**: Original documentation is in German; translation to English and restructuring for open-source audience is in progress.

See `/docs/` for architecture specifications (currently in German; English versions TBD):
- **architecture.md** (EN in progress): System design, sync mechanism, component interaction
- **terminal-design.md** (EN in progress): Terminal/Electron-specific architecture, offline-first patterns
- **admin-design.md** (EN in progress): Admin SPA architecture, authentication, access control
- **privacy-compliance.md** (EN in progress): GDPR workflows, data anonymization, audit logging
- **api-reference.md** (EN in progress): OpenAPI/Swagger specs for Terminal and Admin APIs
- **database-schema.md** (EN in progress): Entity-Relationship Model, table descriptions, migration strategy
- **testing-strategy.md** (EN in progress): Unit/integration/E2E test approach, Docker test environment

**Contribution note**: As the project opens to community, documentation will be unified in English with clear code examples and contributor guidelines.

---

## Common Development Workflows

### Add a New Product Category or Custom Field
1. **Database**: Add enum value or column with migration script (see `/backend/migrations/`)
2. **Backend API**: Update endpoint to handle new field (add validation, audit logging)
3. **Translations**: Add UI strings in `/locales/{lang}/products.json` for each supported language
4. **Admin Panel**: Update product form/filter to expose new field
5. **Terminal**: Product list automatically reflects next sync; no terminal rebuild needed

### Implement GDPR Member Anonymization
1. **Prechecks**: Verify member has zero balance (`SELECT SUM(amount) FROM transactions WHERE member_id = ? AND settlement_id IS NULL`)
2. **If balance exists**: Require admin to settle or reverse transactions first
3. **Anonymize**: Execute SQL update (first_name/last_name/account_details → NULL, card_uid → "ANONYMOUS-{uuid}", is_active → false, deleted_at → now)
4. **Audit log**: Record action='anonymize', entity_type='member', old_values (with masked sensitive data), new_values
5. **Terminal sync**: Next sync removes member from local cache; card scans return "Unknown member"

### Add a New API Endpoint
1. **Specification**: Document in OpenAPI spec (`/docs/api-reference.md`)
2. **Backend implementation** (`/backend/api/`):
   - Define route handler with input validation
   - Use prepared statements for all database queries
   - Return consistent JSON structure with status codes
   - Add audit logging for state-changing operations (create/update/delete/export)
3. **Frontend integration**: Use Axios (admin) or fetch (terminal) with error handling
4. **Testing**: Add Playwright integration test covering happy path + error cases
5. **Documentation**: Update API reference with curl example and error codes

### Debug a Synchronization Issue
1. **Connectivity**: Verify backend is reachable (check Docker/network status)
2. **Terminal logs**: Check sync-worker console for errors and delta-sync responses
3. **Backend logs**: Verify endpoint correctly parses `since` parameter and returns delta
4. **Test offline mode**: Use Docker to simulate connectivity loss (`docker-compose stop webserver`), verify queued transactions persist
5. **Deduplication**: Confirm transaction UUIDs prevent double-insertion on retry
6. **Audit trail**: Check audit log for failed sync attempts

---

## Design Philosophy & Principles

### Architectural Patterns
- **High-trust, low-bandwidth system**: Card identifiers are user-facing, not cryptographic secrets. Security relies on HTTPS + API token authentication, not card randomness.
- **Offline-first, eventually consistent**: Terminal operates offline indefinitely; syncs when network available. Expect temporary inconsistency across devices.
- **Immutable transaction log**: All financial transactions are append-only (see [ADR-0004](./adr/0004-immutable-transaction-storage.md)). Corrections made via reverse transactions, never updates/deletes. Benefits:
  - Complete audit trail (compliance-ready)
  - Conflict-free sync (no UPDATE/DELETE conflicts between terminals)
  - Idempotent operations (safe retries)
  - Tax/GDPR compliant (10-year retention)
  - Dispute resolution (full history preserved)
- **Idempotent APIs**: All state changes must be safely retryable. Use client-generated UUIDs (not server auto-increment) for deduplication.

### Data & Privacy
- **Audit trail is critical**: Every state change (CRUD, export, anonymization) logged with who/what/when/before/after. These logs justify member billing and enable GDPR compliance.
- **Sensitive data isolation**: Terminal never stores member bank details, contact info, or account data. Only cached: UUID, card_uid, first_name, last_name, registration date, preferred_language. Backend keeps sensitive data, exposes to admin only via HTTPS.
- **Anonymization, not deletion**: Per GDPR, members can request erasure. System anonymizes (clears name/contact/account details), but keeps transaction history for accounting + audit trail.
- **No backend secrets in logs**: Never log passwords, API tokens, or encryption keys. Sanitize sensitive fields before writing to audit_log.

### Technology Choices
- **Stateless backend**: PHP with sessions for admin UI (state held server-side). Terminal uses tokens (stateless). Enables horizontal scaling, simple deployment.
- **Lightweight dependencies**: Minimal PHP framework (no Symfony/Laravel overhead). Medoo or PDO wrapper (no Doctrine ORM). Vite + React (not Next.js). Reduces complexity, simplifies self-hosting.
- **No real-time sync**: No WebSockets or Server-Sent Events. Terminal polls backend (60s default). Simpler deployment on shared hosting; works through firewalls/proxies.
- **Touch-first UI**: Terminal is touchscreen-only; no hover states, large buttons (48px+). Admin is desktop (mouse+keyboard).

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

### ADR Template Sections

```
# ADR-NNNN: Title

**Status**: Proposed/Accepted/Deprecated/Superseded
**Date**: YYYY-MM-DD

## Context
(Problem statement, constraints, why this decision needed)

## Decision
(What was decided, in clear language)

### Core Principles
(1-6 key principles guiding the decision)

### Data Structures
(Tables or JSON examples describing data models)

### Example Flows
(Mermaid sequence diagrams showing key workflows)

## Consequences
(Positive/negative outcomes, mitigations)

## Alternatives Considered
(Options evaluated and why they were rejected)

## Related Decisions
(Links to other ADRs)

## References
(External docs, standards, articles)

## Post-Implementation Monitoring
(Checklist for tracking real-world impact)
```

---

## Open Source Contributions

This project is designed for self-hosting and open-source community contributions. Below is guidance for contributors.

### Repository Structure
```
/
├── backend/                    # PHP REST API
│   ├── api/                   # Endpoint handlers
│   ├── src/                   # Business logic, helpers
│   ├── migrations/            # SQL schema migrations
│   ├── config/                # Config template (not in git)
│   └── tests/                 # PHPUnit tests
├── admin-frontend/            # React SPA (Vite)
│   ├── src/                   # React components, hooks
│   ├── public/locales/        # i18n JSON files
│   └── tests/                 # Jest/Vitest tests
├── terminal-frontend/         # Electron + React
│   ├── src/                   # Electron main + React renderer
│   ├── public/locales/        # i18n JSON files
│   └── tests/                 # Playwright E2E tests
├── docs/                      # Architecture & spec (English in progress)
├── CLAUDE.md                  # This file (Claude Code developer guide)
├── README.md                  # Project overview, quick start
├── docker-compose.yml         # Local dev environment
└── CONTRIBUTING.md            # Contributor guidelines (EN/DE)
```

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

### Architecture Decision Records (ADRs)

When adding or updating ADRs, follow the guidelines in the **Architecture Decision Records (ADRs)** section above:

- Minimal code; pseudo-code and diagrams preferred
- Use Mermaid for all diagrams (sequences, flowcharts, ER diagrams)
- Tables/JSON for data structures
- Focus on requirements and rationale, not implementation details
- No approval sections (one-person team)

### Licensing & Attribution

- **License**: [To be specified: AGPL-3.0, MIT, Apache-2.0, etc.]
- **Attribution**: All contributors credited in CONTRIBUTORS.md
- **DCO**: Contributions must include sign-off (e.g., `git commit -s`)
