# Implementation Plans Index

This index tracks the status of all implementation plans for Club Bar.

---

## Current Plan

| Plan | Status | Summary |
|------|--------|---------|
| [GDPR Anonymization Fix](./2026-03-08-gdpr-anonymization-fix.md) | Not Started | Fix anonymization: NULL instead of DELETED, audit log scrubbing, pre-deletion checks, E2E tests |
| [CI Pipeline Refactoring](./2026-03-07-ci-pipeline-refactoring.md) | In Progress | Restructure CI: job dependencies, caching, dedup, lint gate, fix silent failures |

---

## Completed Plans

| Plan | Completed | Summary |
|------|-----------|---------|
| [Dashboard Page](./2026-03-08-dashboard-page.md) | 2026-03-08 | Dashboard page (UC-A80): metrics, transactions, terminals, alerts, auto-refresh, mobile responsive |
| [Reports System](./2026-03-07-reports-system.md) | 2026-03-08 | Reports system (UC-A50, UC-A51, UC-A52): backend API, frontend ReportsPage, responsive, E2E tests |
| [ADR Resolution](./2026-03-07-adr-resolution.md) | 2026-03-07 | Resolved all ADR gaps: CSRF, rate limiting, ADR rewrites, use case cleanup |
| [ADR Implementation Status](./adr-implementation-status.md) | 2026-03-07 | All 23 ADRs resolved (22 implemented + 1 superseded) |
| [Use Case Implementation Audit](./use-case-implementation-audit.md) | 2026-03-07 | Audit of all use cases against implementation |

---

## How to Use This Index

1. **Starting work**: Check "Current Plan" for active work
2. **New feature**: Create a plan in `plans/`, add to this index
3. **Completing a plan**: Move from Current to Completed with date
