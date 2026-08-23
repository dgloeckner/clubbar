# Use Case Implementation Audit

**Date**: 2026-03-07
**Scope**: All 73 use cases across Admin, Terminal, SEPA, and GDPR domains
**Methodology**: Cross-referenced use case specs against backend API routes, admin frontend pages, terminal app code, E2E tests, and OpenAPI specs. Then conducted stakeholder interview to determine target state per use case.

---

## Legend

| Symbol | Meaning |
|--------|---------|
| [x] | Fully implemented and spec-compliant |
| [~] | Partially implemented or diverges from spec |
| [ ] | Not implemented |
| **B** | Backend | **F** | Frontend | **T** | Tests |

**Decision markers** (from stakeholder interview):
- **CONFIRMED**: Owner confirmed current state meets target — no further work needed
- **ACCEPTED DIVERGENCE**: Implementation differs from spec but owner accepts the current approach
- **ACTION NEEDED**: Gap identified that must be addressed
- **DEFERRED**: Not a priority now, revisit later
- **NICE TO HAVE**: Desired but not critical

---

## 1. Admin Authentication (UC-A01 to UC-A03)

### UC-A01: Login — CONFIRMED
- **B** [x] `POST /api/auth/login` — email/password, session creation
- **F** [x] Login page with email/password form, redirects to /members on success
- **T** [x] `admin-auth.spec.ts` — login/logout flows, session management
- **Status**: Complete. No changes needed.

### UC-A02: Logout — CONFIRMED
- **B** [x] `POST /api/auth/logout` — session destruction
- **F** [x] Logout available in navigation
- **T** [x] `admin-auth.spec.ts` — logout flow tested
- **Status**: Complete. No changes needed.

### UC-A03: Change Password — CONFIRMED
- **B** [x] `PATCH /api/auth/change-password` — requires current_password, validates new
- **F** [x] Profile page has password change form (min 8 chars, 1 upper, 1 lower, 1 digit)
- **T** [x] `admin-users.spec.ts` — password change validation tested
- **Status**: Complete. No changes needed.

---

## 2. Member Management (UC-A10 to UC-A16)

### UC-A10: List Members — CONFIRMED
- **B** [x] `GET /api/admin/members` — paginated, filterable (is_active, language, has_card_uid, sepa_status), sortable, searchable
- **F** [x] Members page with pagination (20/page), filters (status, RFID, SEPA), search, sort (name, card_uid, created_at)
- **T** [x] `admin-members-list.spec.ts`, `admin-members-crud.spec.ts`, `members.spec.ts` (frontend)
- **Status**: Complete. No changes needed.

### UC-A11: Create Member — CONFIRMED
- **B** [x] `POST /api/admin/members` — all required fields (first_name, last_name, iban, mandate_signed_at, preferred_language)
- **F** [x] Create member modal with all fields including email, account_holder_name, card_uid
- **T** [x] `admin-members-crud.spec.ts` (19 tests), `admin-members-persistence.spec.ts` (17 tests), `members.spec.ts` (frontend CRUD lifecycle)
- **Status**: Complete. No changes needed.

### UC-A12: Edit Member — CONFIRMED
- **B** [x] `PATCH /api/admin/members/{memberId}` — partial update of all fields
- **F** [x] Edit member modal with all editable fields
- **T** [x] `admin-members-crud.spec.ts`, `admin-members-persistence.spec.ts` (UPDATE → RETRIEVE round-trip)
- **Status**: Complete. No changes needed.

### UC-A13: Assign RFID Card — ACCEPTED DIVERGENCE
- **B** [x] Card UID is a field on the member entity, updated via `PATCH /api/admin/members/{memberId}`
- **F** [x] Card UID editable in member edit form
- **T** [x] `members.spec.ts` — card UID validation (format, auto-format, duplicate detection)
- **Spec divergence**: UC specifies dedicated "Assign RFID Card" flow with unknown cards picker. Implementation treats card_uid as a regular member field.
- **Decision**: Owner confirmed current approach is sufficient. No dedicated assign-card endpoint needed.
- **Spec update needed**: UC-A13 and OpenAPI (`POST /members/{memberId}/assign-card`) should be updated to reflect field-based approach.

### UC-A14: Remove RFID Card — ACCEPTED DIVERGENCE
- **B** [x] Set card_uid to null via `PATCH /api/admin/members/{memberId}`
- **F** [x] Clear card_uid field in edit form
- **T** [x] Implicitly tested via member edit
- **Decision**: Owner confirmed current approach is sufficient.
- **Spec update needed**: UC-A14 and OpenAPI (`POST /members/{memberId}/remove-card`) should be updated.

### UC-A15: Deactivate Member — CONFIRMED
- **B** [x] `DELETE /api/admin/members/{memberId}` — soft-deactivation (sets is_active=false)
- **F** [x] Deactivate/activate toggle in member list
- **T** [x] `admin-members-crud.spec.ts` — DELETE tested
- **Status**: Complete. No changes needed.

### UC-A16: Import Members (CSV) — NICE TO HAVE
- **B** [ ] Not implemented
- **F** [ ] No import UI
- **T** [ ] No tests
- **Decision**: Owner considers this useful for onboarding existing club members but not critical right now. Would help when a club first adopts the system and needs to import their membership list.
- **Spec update needed**: OpenAPI (`POST /members/import`, `POST /members/import/confirm`) should remain as future targets.

---

## 3. Tab & Transaction Management (UC-A20 to UC-A22)

### UC-A20: View Tab (Member Balance + Transaction History) — ACCEPTED DIVERGENCE
- **B** [x] `GET /api/admin/members/{memberId}/transactions` — paginated transaction history
- **F** [x] Members page shows balance. Transaction history available via Journal page (global) with member filter.
- **T** [x] `admin-members-crud.spec.ts` — member detail includes balance
- **Spec divergence**: UC specifies a dedicated "Tab View" per member. Implementation uses global Journal with member filter.
- **Decision**: Owner confirmed Journal-with-filter approach is sufficient. No dedicated tab view needed.
- **Spec update needed**: UC-A20 should be updated to reflect Journal-based approach.

### UC-A21: ~~Manual Booking (Correction Transaction)~~ — SUPERSEDED, THEN REJECTED
- **Status**: The free-amount correction audited here was removed by [#158](https://github.com/dgloeckner/clubbar/issues/158)/[#169](https://github.com/dgloeckner/clubbar/issues/169); the endpoint, the Journal form and their tests are gone. UC-A21 was briefly renarrowed to "manual purchase" and then **rejected outright 2026-08-08** — see the tombstone at [UC-A21](../use-cases/admin/UC-A21-manual-purchase.md).
- Corrections are now [UC-A23: Storno](../use-cases/admin/UC-A23-storno.md) only: `POST /api/admin/transactions/{id}/storno`, amount derived, no typed amount anywhere.

### UC-A22: Export Transactions — ACCEPTED DIVERGENCE
- **B** [x] `GET /api/admin/transactions/export` — CSV export with date range, member, type filters
- **F** [~] No dedicated export button in Journal UI. Settlement CSV exports cover the primary need.
- **T** [~] Settlement CSV export tested
- **Decision**: Owner confirmed settlement export is sufficient. No standalone Journal export button needed.
- **Spec update needed**: UC-A22 should note that transaction export is covered via settlement exports.

---

## 4. Settlements (UC-A30 to UC-A35)

### UC-A30: Create Settlement (SEPA) — CONFIRMED
- **B** [x] `POST /api/admin/settlements` — creates settlement with transaction IDs, settlement_type (sepa/manual)
- **B** [x] `POST /api/admin/settlements/settle-filter` — create by filter criteria
- **F** [x] Journal page: select transactions → settle. Settle-all by filter. Settlement confirm modal with preview.
- **T** [x] `settlements.spec.ts` (43 tests), `journal-and-settlements.spec.ts` (5 flow tests)
- **Status**: Complete. No changes needed.

### UC-A31: Download SEPA XML — CONFIRMED
- **B** [x] `GET /api/admin/settlements/{id}/export-sepa` — generates pain.008.001.02 XML
- **F** [x] SEPA XML download button on settlements page
- **T** [x] `settlements.spec.ts`, `journal-and-settlements.spec.ts`
- **Status**: Complete. No changes needed.

### UC-A32: Download CSV — CONFIRMED
- **B** [x] `GET /api/admin/settlements/{id}/export-csv` (summary) + `GET .../export-transactions` (detail)
- **F** [x] CSV download buttons on settlements page (summary + detail)
- **T** [x] `settlements.spec.ts`, `journal-and-settlements.spec.ts`
- **Status**: Complete. Exceeds spec with two CSV formats.

### UC-A33: Settlement History — CONFIRMED
- **B** [x] `GET /api/admin/settlements` — paginated list with date/status filters
- **F** [x] Settlements page with table, pagination, date range filter, status filter
- **T** [x] `settlements.spec.ts`
- **Status**: Complete. No changes needed.

### UC-A34: Settlement Details — CONFIRMED
- **B** [x] `GET /api/admin/settlements/{id}` — full settlement with items
- **F** [x] Settlement detail view accessible from list
- **T** [x] `settlements.spec.ts`
- **Status**: Complete. No changes needed.

### UC-A35: Manual Settlement — CONFIRMED
- **B** [x] `POST /api/admin/settlements` with `settlement_type: 'manual'` and `manual_reason`
- **F** [x] Manual settlement flow in Journal with reason field
- **T** [x] `settlements.spec.ts`
- **Status**: Complete. No changes needed.

---

## 5. Product Management (UC-A40 to UC-A44)

### UC-A40: List Products — CONFIRMED
- **B** [x] `GET /api/admin/products` — paginated, filterable, sortable, searchable
- **F** [x] Products page with all filters, sort options, pagination (25/page)
- **T** [x] `products.spec.ts`
- **Status**: Complete. No changes needed.

### UC-A41: Create Product — CONFIRMED
- **B** [x] `POST /api/admin/products` — multilingual names/descriptions, price_cents, category_id, icon_name
- **F** [x] Create product modal with i18n name tabs, price, category, icon, requires_dispenser toggle
- **T** [x] `products.spec.ts`
- **Status**: Complete. No changes needed.

### UC-A42: Edit Product — CONFIRMED
- **B** [x] `PATCH /api/admin/products/{productId}` — partial update
- **F** [x] Edit product modal
- **T** [x] `products.spec.ts`
- **Status**: Complete. No changes needed.

### UC-A43: Deactivate Product — CONFIRMED
- **B** [x] `PATCH /api/admin/products/{productId}/status` — toggle active/inactive
- **F** [x] Status toggle in product list
- **T** [x] `products.spec.ts`
- **Status**: Complete. No changes needed.

### UC-A44: Manage Categories — CONFIRMED
- **B** [x] Full CRUD with status toggle
- **F** [x] Categories page with CRUD, drag-and-drop reorder, i18n names, icons, activation toggle
- **T** [x] `categories.spec.ts`
- **Status**: Complete. No changes needed.

---

## 6. Reporting (UC-A50 to UC-A52)

### UC-A50: Reports (Unified Reporting) — ACTION NEEDED
- **B** [~] `GET /api/admin/statistics/monthly` exists but no unified `/reports/{reportType}` system
- **F** [~] Statistics page with monthly revenue chart, top 10 products/members. Not a flexible multi-report system.
- **T** [~] `statistics.spec.ts` — basic statistics tested
- **Decision**: Owner wants the full reports system as specified in the UC — revenue, consumption, and transaction reports with chart type selection and CSV export per report.
- **Work needed**:
  - Backend: New `/api/admin/reports/{reportType}` and `/api/admin/reports/{reportType}/export` endpoints
  - Frontend: Reports page with report type selector, date range, chart options, CSV export
  - Tests: E2E tests for each report type

### UC-A51: Member Ranking — ACTION NEEDED
- **B** [~] Top 10 members bundled into monthly stats
- **F** [~] Shown on Statistics page
- **Decision**: Owner wants a dedicated member ranking report with time range selection and anonymization option.
- **Work needed**:
  - Backend: Dedicated ranking endpoint (or as part of unified reports)
  - Frontend: Member ranking view with configurable time range and anonymization toggle

### UC-A52: Terminal Activity — ACTION NEEDED
- **B** [ ] Not implemented
- **F** [ ] Not implemented
- **T** [ ] No tests
- **Decision**: Owner wants terminal activity reporting (transaction sessions, peak hours, terminal utilization).
- **Work needed**:
  - Backend: New endpoint with terminal usage metrics
  - Frontend: Terminal activity view with session/peak hour data

---

## 7. Settings & Admin (UC-A60 to UC-A71)

### UC-A60: Edit Organization (SEPA Configuration) — CONFIRMED
- **B** [x] `GET/PUT /api/admin/sepa-config`
- **F** [x] Settings page → SEPA Configuration tab
- **T** [x] `settings-sepa-config.spec.ts`, `settlements.spec.ts`
- **Status**: Complete. No changes needed.

### UC-A61: Manage Admins — CONFIRMED
- **B** [x] `GET /api/admin/admin-users`
- **F** [x] Settings page → Admin Users tab
- **T** [x] `admin-users.spec.ts` (21 tests), `settings-admin-users.spec.ts`
- **Status**: Complete. No changes needed.

### UC-A62: Create Admin — CONFIRMED
- **B** [x] `POST /api/admin/admin-users`
- **F** [x] Create admin modal with password display
- **T** [x] `admin-users.spec.ts`
- **Status**: Complete. No changes needed.

### UC-A63: Reset Admin Password — CONFIRMED
- **B** [x] `POST /api/admin/admin-users/{id}/reset-password`
- **F** [x] Reset action with password display modal
- **T** [x] `admin-users.spec.ts`
- **Status**: Complete. No changes needed.

### UC-A70: Unassigned Cards — DEFERRED
- **B** [ ] Not implemented
- **F** [ ] Not implemented
- **T** [ ] No tests
- **Decision**: Owner deferred. Not a priority right now.

### UC-A71: Block Card — DEFERRED
- **B** [ ] Not implemented
- **F** [ ] Not implemented
- **T** [ ] No tests
- **Decision**: Owner deferred. Not a priority right now.

---

## 8. System (UC-A80 to UC-A82)

### UC-A80: Dashboard — ACTION NEEDED
- **B** [x] `GET /api/admin/dashboard` — metrics, alerts, monthly stats (backend ready)
- **F** [ ] No dedicated dashboard page. Users land on Members page.
- **T** [~] `dashboard.spec.ts` — API-level tests exist
- **Decision**: Owner wants a proper dashboard page as the landing page with overview metrics, recent activity, and alerts.
- **Work needed**:
  - Frontend: New Dashboard page consuming existing `/api/admin/dashboard` endpoint
  - Route change: Make `/dashboard` the post-login landing page instead of `/members`
  - Tests: E2E tests for dashboard display

### UC-A81: Audit Log — CONFIRMED
- **B** [x] `GET /api/admin/audit-log` — paginated, filterable, searchable
- **F** [x] Audit Log page with filters, search, pagination, color-coded badges
- **T** [x] `admin-members-audit.spec.ts`, `audit-log.spec.ts`
- **Status**: Complete. Inline detail view is sufficient (no separate detail endpoint needed).
- **Spec update needed**: OpenAPI (`GET /audit-log/{entryId}`) can be removed.

### UC-A82: SEPA Issues Report — ACCEPTED DIVERGENCE
- **B** [~] No dedicated endpoint. Covered by member list filter `sepa_status=missing`.
- **F** [x] Members list with SEPA status filter
- **Decision**: Owner confirmed member list filter is sufficient. No dedicated SEPA issues report needed.
- **Spec update needed**: UC-A82 and OpenAPI (`GET /reports/sepa-issues`) should be updated to note this is covered by member list filtering.

---

## 9. Terminal Use Cases (UC-T01 to UC-T14)

### UC-T01: Book Product to Tab — CONFIRMED
- **B** [x] `POST /api/sync/transactions` — batch upload, idempotent
- **Terminal** [x] Full flow: RFID scan → category browse → product select → add to cart → checkout
- **T** [x] Comprehensive unit + integration tests
- **Status**: Complete. No changes needed.

### UC-T02: View Tab Balance — ACCEPTED DIVERGENCE
- **B** [x] `GET /api/terminal/transactions/{memberId}` — transaction history endpoint exists
- **Terminal** [x] Balance displayed in MemberBar header
- **Spec divergence**: UC specifies scrollable 90-day transaction history. Implementation shows balance only.
- **Decision**: Owner confirmed balance display is sufficient. No transaction history list needed on terminal.
- **Spec update needed**: UC-T02 should be simplified to reflect balance-only display.

### UC-T03: Change Language — ACCEPTED DIVERGENCE
- **Terminal** [x] Auto-switches locale on member scan based on `preferred_language`
- **B** [x] `PATCH /api/sync/members/{memberId}/language` — update language preference
- **Spec divergence**: UC mentions manual language selector. Implementation is auto-switch only.
- **Decision**: Owner confirmed auto-switch is sufficient. No manual toggle needed.
- **Spec update needed**: UC-T03 should remove manual language selector requirement.

### UC-T11: Shopping Cart — CONFIRMED
- **Terminal** [x] Full cart with add/remove/decrease, balance preview, limit validation, sounds
- **T** [x] Comprehensive tests (9K+ lines)
- **Status**: Complete. No changes needed.

### UC-T12: Error Scenarios — CONFIRMED
- **Terminal** [x] All error scenarios handled: unknown card, balance limit, inactive member, network error, timeout, SEPA invalid
- **Decision**: Owner confirmed behavior is sufficient. Error codes E1-E6 are spec reference labels, not runtime codes — actual error handling logic covers all scenarios.
- **Status**: Complete. No changes needed.

### UC-T13: Fetch Recent Transactions — ACCEPTED DIVERGENCE
- **B** [x] `GET /api/terminal/transactions/{memberId}` — endpoint exists
- **Terminal** [~] Endpoint called but UI display minimal
- **Decision**: Covered by UC-T02 decision — balance display is sufficient, transaction list not needed.
- **Spec update needed**: UC-T13 scope should be adjusted.

### UC-T14: Update Balance on Sync — CONFIRMED
- **Terminal** [x] Delta sync with balance recalculation
- **T** [x] Comprehensive sync tests
- **Status**: Complete. No changes needed.

---

## 10. GDPR Use Cases (UC-DSGVO-01 to UC-DSGVO-06)

### UC-DSGVO-01: Right to Access (Art. 15) — ACTION NEEDED
- **B** [x] `POST /api/admin/members/{memberId}/export` — full data export (backend ready)
- **F** [ ] No export button in admin frontend
- **T** [x] `admin-members-gdpr.spec.ts` (8 tests)
- **Decision**: Owner needs UI buttons for GDPR operations. Admins must be able to trigger export from the frontend.
- **Work needed**:
  - Frontend: Add "Export Data" button to member detail/edit view
  - Trigger the existing backend endpoint and download the result

### UC-DSGVO-02: Right to Erasure (Art. 17) — ACTION NEEDED
- **B** [x] `POST /api/admin/members/{memberId}/anonymize` — anonymization (backend ready)
- **F** [ ] No anonymize button in admin frontend
- **T** [x] `admin-members-gdpr.spec.ts`, `admin-members-persistence.spec.ts`
- **Decision**: Owner needs UI button. Admins must be able to trigger anonymization from the frontend.
- **Work needed**:
  - Frontend: Add "Anonymize" button with confirmation dialog to member detail/edit view
  - Confirmation should explain that personal data will be irreversibly replaced

### UC-DSGVO-03: Right to Rectification (Art. 16) — CONFIRMED
- **B** [x] Via `PATCH /api/admin/members/{memberId}`
- **F** [x] Member edit form
- **T** [x] `admin-members-crud.spec.ts`
- **Status**: Complete. Standard member edit with audit trail covers rectification.

### UC-DSGVO-04: Right to Portability (Art. 20) — ACCEPTED DIVERGENCE
- **B** [x] `POST /api/admin/members/{memberId}/export` — exports all data including system fields
- **Spec divergence**: Art. 20 technically requires excluding system-generated fields. Current export includes everything.
- **Decision**: Owner confirmed current export format is acceptable. Including system fields is fine.
- **Spec update needed**: UC-DSGVO-04 should note that full export (including system fields) is the accepted format.

### UC-DSGVO-05: Right to Restriction (Art. 18) — CONFIRMED
- **B** [x] Via member deactivation
- **F** [x] Deactivate member action
- **T** [x] Covered by deactivation tests
- **Status**: Complete. No changes needed.

### UC-DSGVO-06: Audit Log Access (Art. 30) — CONFIRMED
- **B** [x] `GET /api/admin/audit-log` with filtering
- **F** [x] Audit Log page
- **T** [x] `admin-members-audit.spec.ts`, `audit-log.spec.ts`
- **Status**: Complete. No changes needed.

---

## 11. SEPA Use Cases (UC-SEPA-01 to UC-SEPA-09)

### UC-SEPA-01: SEPA Configuration Setup — CONFIRMED
- **B** [x] `PUT /api/admin/sepa-config`
- **F** [x] Settings → SEPA Configuration tab
- **T** [x] `settings-sepa-config.spec.ts`, `settlements.spec.ts`
- **Status**: Complete. No changes needed.

### UC-SEPA-02: SEPA Configuration Update — CONFIRMED
- **B** [x] `PUT /api/admin/sepa-config` with creditor_id edit warning
- **F** [x] Edit with warning on creditor_id change
- **T** [x] `settings-sepa-config.spec.ts`
- **Status**: Complete. Allowing creditor_id edit with warning is the accepted approach.

### UC-SEPA-03: Member IBAN Management — CONFIRMED
- **B** [x] IBAN field with mod-97 validation
- **F** [x] IBAN field in member forms
- **T** [x] `admin-members-crud.spec.ts`
- **Status**: Complete. No changes needed.

### UC-SEPA-04: Member Mandate Reference — CONFIRMED
- **B** [x] Auto-generated mandate_reference
- **F** [x] Mandate reference field in member forms
- **T** [x] Tested via member CRUD
- **Status**: Complete. No changes needed.

### UC-SEPA-05: Settlement Creation — ACCEPTED DIVERGENCE
- **B** [x] `POST /api/admin/settlements` — single-step creation with preview
- **F** [x] Settlement creation flow in Journal with preview modal
- **T** [x] `settlements.spec.ts`
- **Spec divergence**: UC specifies draft → finalize two-step workflow. Implementation creates in one step (with preview).
- **Decision**: Owner confirmed single-step creation with preview is sufficient. No draft state needed.
- **Spec update needed**: UC-SEPA-05 and OpenAPI (`POST /settlements/{id}/finalize`) should be updated.

### UC-SEPA-06: Settlement Preview — CONFIRMED
- **B** [x] `POST /api/admin/settlements/preview` and `GET /api/admin/settlements/filter-preview`
- **F** [x] Preview modal before settlement creation
- **T** [x] `settlements.spec.ts`
- **Status**: Complete. No changes needed.

### UC-SEPA-07: Settlement Finalization — ACCEPTED DIVERGENCE
- **Spec divergence**: No separate finalize endpoint. Settlements created in final state.
- **Decision**: Owner confirmed one-step approach. Finalize endpoint not needed.
- **Spec update needed**: UC-SEPA-07 should be merged into UC-SEPA-05. OpenAPI endpoint can be removed.

### UC-SEPA-08: SEPA XML Export — CONFIRMED
- **B** [x] `GET /api/admin/settlements/{id}/export-sepa` — pain.008.001.02
- **F** [x] Download button on settlements page
- **T** [x] `settlements.spec.ts`, `journal-and-settlements.spec.ts`
- **Status**: Complete. No changes needed.

### UC-SEPA-09: CSV Export — CONFIRMED
- **B** [x] Two CSV formats (summary + transaction detail)
- **F** [x] Two CSV download options
- **T** [x] `settlements.spec.ts`, `journal-and-settlements.spec.ts`
- **Status**: Complete. Exceeds spec.

---

## Summary

### Decision Statistics

| Decision | Count | Use Cases |
|----------|-------|-----------|
| **CONFIRMED** (complete, no action) | 39 | A01-A03, A10-A12, A15, A20-A21, A30-A35, A40-A44, A60-A63, A81, T01, T11, T12, T14, DSGVO-03/05/06, SEPA-01/02/03/04/06/08/09 |
| **ACCEPTED DIVERGENCE** (works differently but OK) | 10 | A13, A14, A22, A82, T02, T03, T13, DSGVO-04, SEPA-05, SEPA-07 |
| **ACTION NEEDED** (must be built) | 5 | A50, A51, A52, A80, DSGVO-01, DSGVO-02 |
| **DEFERRED** (not now) | 2 | A70, A71 |
| **NICE TO HAVE** (useful, not critical) | 1 | A16 |

### Action Items (Priority Work)

| # | Use Case | What's Needed | Backend | Frontend | Effort |
|---|----------|---------------|---------|----------|--------|
| 1 | **UC-A50** | Full reports system (revenue, consumption, transactions) with chart types and CSV export | New endpoints | New Reports page | High |
| 2 | **UC-A51** | Member ranking report with time range and anonymization | Part of reports | Part of Reports page | Medium |
| 3 | **UC-A52** | Terminal activity report (sessions, peak hours) | New endpoint | Part of Reports page | Medium |
| 4 | **UC-A80** | Dashboard page as landing page with metrics and alerts | Backend ready | New Dashboard page | Medium |
| 5 | **UC-DSGVO-01** | "Export Data" button in member view | Backend ready | Add button + download | Low |
| 6 | **UC-DSGVO-02** | "Anonymize" button with confirmation in member view | Backend ready | Add button + confirm dialog | Low |

### Spec Updates Needed

The following specs should be updated to match confirmed design decisions:

| Spec Type | Item | Change |
|-----------|------|--------|
| **Use Case** | UC-A13 | Change from dedicated assign flow to field-based approach |
| **Use Case** | UC-A14 | Change from dedicated remove flow to field clear |
| **Use Case** | UC-A20 | Change from dedicated tab view to Journal-with-filter |
| **Use Case** | UC-A22 | Note coverage via settlement exports |
| **Use Case** | UC-A82 | Note coverage via member list SEPA filter |
| **Use Case** | UC-T02 | Simplify to balance-only display |
| **Use Case** | UC-T03 | Remove manual language selector requirement |
| **Use Case** | UC-T13 | Adjust scope (balance sufficient) |
| **Use Case** | UC-SEPA-05 | Merge finalization into creation (one-step) |
| **Use Case** | UC-SEPA-07 | Merge into UC-SEPA-05 |
| **Use Case** | UC-DSGVO-04 | Accept full export format (including system fields) |
| **OpenAPI** | `POST /members/{id}/assign-card` | Remove |
| **OpenAPI** | `POST /members/{id}/remove-card` | Remove |
| **OpenAPI** | `POST /settlements/{id}/finalize` | Remove |
| **OpenAPI** | `POST /settlements/{id}/cancel` | Change to `DELETE` |
| **OpenAPI** | `GET /auth/me` | Change to `GET /auth/profile` |
| **OpenAPI** | `GET /audit-log/{entryId}` | Remove (inline details sufficient) |
| **OpenAPI** | `GET /reports/sepa-issues` | Remove (covered by member list filter) |
| **OpenAPI** | `GET /unknown-cards` | Keep but mark deferred |
| **OpenAPI** | `GET /blocked-cards`, `DELETE /blocked-cards/{cardUid}` | Keep but mark deferred |
| **OpenAPI** | `POST /members/import`, `POST /members/import/confirm` | Keep but mark nice-to-have |
| **OpenAPI** | `GET /reports/{reportType}`, `GET /reports/{reportType}/export` | Keep — to be implemented |
| **OpenAPI** | `GET /reports/member-ranking` | Keep — to be implemented |
| **OpenAPI** | `GET /reports/terminal-activity` | Keep — to be implemented |

### Implementation Progress (Post-Interview)

| Category | Total | Done | Accepted Divergence | Action Needed | Deferred | Nice to Have |
|----------|-------|------|---------------------|---------------|----------|--------------|
| Admin Auth | 3 | 3 | 0 | 0 | 0 | 0 |
| Members | 7 | 5 | 2 | 0 | 0 | 1 (import) |
| Transactions | 3 | 2 | 1 | 0 | 0 | 0 |
| Settlements | 6 | 6 | 0 | 0 | 0 | 0 |
| Products | 5 | 5 | 0 | 0 | 0 | 0 |
| Reporting | 3 | 0 | 0 | 3 | 0 | 0 |
| Settings | 7 | 4 | 1 | 0 | 2 | 0 |
| System | 3 | 1 | 1 | 1 | 0 | 0 |
| Terminal | 7 | 4 | 3 | 0 | 0 | 0 |
| GDPR | 6 | 3 | 1 | 2 | 0 | 0 |
| SEPA | 9 | 7 | 2 | 0 | 0 | 0 |
| **TOTAL** | **59** | **40** | **11** | **6** | **2** | **1** |

**Effective completion: 51/59 (86%)** — 40 confirmed + 11 accepted divergences are considered "done".
**Remaining work: 6 action items** (Reports system, Dashboard page, 2 GDPR buttons).
**Parked: 3** (2 deferred card management features, 1 nice-to-have CSV import).
