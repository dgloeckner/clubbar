# Action Items from Use Case Audit

**Created**: 2026-03-07
**Source**: [Use Case Implementation Audit](./use-case-implementation-audit.md)

---

## Priority Action Items

### 1. Reports System (UC-A50, UC-A51, UC-A52)

**Status**: Not implemented (monthly statistics page exists as partial substitute)
**Priority**: High — owner explicitly requested full reports system

**What's needed**:
- **UC-A50 (Unified Reporting)**: Revenue, consumption, and transaction reports with chart type selection and CSV export per report type
- **UC-A51 (Member Ranking)**: Dedicated ranking report with configurable time range and anonymization option
- **UC-A52 (Terminal Activity)**: Transaction sessions, peak hours, terminal utilization

**Current state**:
- Backend: `GET /api/admin/statistics/monthly` provides monthly stats with daily breakdown, top products, top members
- Frontend: Statistics page with monthly revenue chart, top 10 products/members
- No flexible report system, no terminal activity data

**Target state**:
- Backend: New `/api/admin/reports/{reportType}` and `/api/admin/reports/{reportType}/export` endpoints
- Frontend: Reports page with report type selector, date range picker, chart options, CSV export
- All three report types (revenue, member ranking, terminal activity) accessible

**Effort**: High

---

### 2. Dashboard Page (UC-A80)

**Status**: Backend API ready, no frontend page
**Priority**: Medium

**What's needed**:
- Frontend: New Dashboard page consuming existing `GET /api/admin/dashboard` endpoint
- Route change: Make `/dashboard` the post-login landing page (currently lands on `/members`)
- Display: Overview metrics, recent activity, alerts

**Current state**:
- Backend: `GET /api/admin/dashboard` returns metrics, alerts, monthly stats
- Frontend: No dashboard page — users land on Members page after login

**Target state**:
- Dashboard page as landing page with key metrics (total members, active, total balance, recent transactions, alerts)

**Effort**: Medium

---

### 3. GDPR Export Button (UC-DSGVO-01)

**Status**: Backend ready, no frontend UI
**Priority**: Medium

**What's needed**:
- Frontend: Add "Export Data" button to member detail/edit view
- Trigger existing `POST /api/admin/members/{memberId}/export` endpoint
- Download the result as JSON file

**Current state**:
- Backend: Endpoint fully implemented and tested (8 E2E tests)
- Frontend: No button — operation only possible via direct API call

**Target state**:
- Button in member edit/detail UI that triggers export and downloads the file

**Effort**: Low

---

### 4. GDPR Anonymize Button (UC-DSGVO-02)

**Status**: Backend ready, no frontend UI
**Priority**: Medium

**What's needed**:
- Frontend: Add "Anonymize Member" button to member detail/edit view
- Confirmation dialog explaining irreversibility (personal data will be replaced, transaction history preserved)
- Trigger existing `POST /api/admin/members/{memberId}/anonymize` endpoint

**Current state**:
- Backend: Endpoint fully implemented and tested
- Frontend: No button — operation only possible via direct API call

**Target state**:
- Button with confirmation dialog in member UI

**Effort**: Low

---

## Deferred Items

### 5. Unassigned Cards (UC-A70) — DEFERRED

**What**: View cards scanned at terminal that aren't assigned to any member
**Why deferred**: Not a priority right now
**Revisit**: When terminal is deployed and card management becomes operationally important

### 6. Block Card (UC-A71) — DEFERRED

**What**: Add lost/stolen cards to a blocklist to prevent unauthorized use
**Why deferred**: Not a priority right now
**Revisit**: When terminal is deployed and card security becomes operationally important

---

## Nice to Have

### 7. Import Members CSV (UC-A16) — NICE TO HAVE

**What**: CSV upload with validation preview and confirmation step for bulk member onboarding
**Why nice-to-have**: Useful when a club first adopts the system, but members can be added one by one
**Revisit**: When onboarding a club with a large existing membership roster
