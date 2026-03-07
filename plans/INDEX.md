# Implementation Plans Index

This index tracks the status of all implementation plans for Club Bar.

---

## Current Plan

None active.

---

## Outdated Plans (Not Finished)

These plans were written but never completed. They may contain useful context but their task lists and assumptions are stale.

| Plan | Written | Goal | Notes |
|------|---------|------|-------|
| [Mobile Responsive Design](./2026-03-02-mobile-responsive-design.md) | 2026-03-02 | Mobile-friendly admin frontend (375px) | 0/14 tasks done |
| [Terminals Settings Tab](./2026-02-28-terminals-settings-tab.md) | 2026-02-28 | Terminal CRUD UI in Settings page | 0/9 tasks done, backend API ready |
| [Bugfix Batch](./2026-02-25-bugfix-batch.md) | 2026-02-25 | 6 bugs from bugs.txt | Never started |
| [Flutter Terminal Frontend](./2026-02-01-flutter-terminal-frontend.md) | 2026-02-01 | Full POS workflow in Flutter | Never started |
| [Flutter Terminal i18n](./flutter-i18n.md) | — | Multi-language terminal support | Never started |
| [Table Design System](./PHASE4_TABLE_DESIGN_SYSTEM.md) | — | Generic table components | Never started, tables already built ad-hoc |
| [Skill: Playwright-Testing](./skill-playwright-testing-tdd.md) | — | Playwright testing skill (TDD) | Never started, skill exists but plan not executed |
| [Skill: PHP-Logs](./skill-php-logs-tdd.md) | — | PHP log checking skill (TDD) | Never started, skill exists but plan not executed |
| [Transactions Module Refactoring](./transactions-module-refactor.md) | — | ADR-0018 compliance | Already done separately, plan outdated |
| [Phase 2.A Terminal UI](./phase2-terminal-balance-transactions.md) | — | Terminal transaction history screen | Backend done, UI deferred indefinitely |
| [Phase 4 Admin Frontend (original)](./phase4-admin-frontend.md) | — | React admin UI roadmap | All pages built, plan outdated |

---

## Completed Plans

| Plan | Completed | Summary |
|------|-----------|---------|
| [V1.0 Release Preparation](./2026-03-04-v1-release.md) | 2026-03-04 | Terminal E2E tests, Recharts, deployment docs |
| [E2E Test Quality Improvement](./2026-02-28-e2e-test-quality.md) | 2026-03-04 | POM cleanup, weak assertions fixed, console.log removed |
| [Shared Hosting Package](./2026-03-01-shared-hosting-package.md) | 2026-03-04 | ZIP installer for shared hosting (cPanel/Plesk) |
| [Members Test Consolidation](./2026-02-28-members-test-consolidation.md) | 2026-02-28 | 4 bloated files → 3 flow tests + 1 stats test |
| [Journal & Settlements Test Consolidation](./2026-02-28-journal-settlements-consolidation.md) | 2026-02-28 | 3 files → 4 flow tests, 2372 lines deleted |
| [Icon Selection (Phase 4.2c)](./PHASE4_CATEGORIES_PRODUCTS_FIX.md) | 2026-01-28 | Icon system + categories/products integration, 41/41 tests |
| [Categories & Products (Phase 4.2b)](./PHASE4_CATEGORIES_PRODUCTS_FIX.md) | 2026-01-27 | Categories CRUD + products integration, 41/41 tests |
| [Phase 4 UI System](./phase4-admin-frontend.md) | 2026-01-26 | Icons, loading, responsive nav, dashboard stats, 31 tests |
| [Backend Core Modules](./backend-core-modules.md) | 2026-01-26 | All 9 modules complete, 120+ E2E tests |
| [Terminals Module](./backend-core-modules.md) | 2026-01-26 | CRUD, token gen/rotation, 17/17 tests |
| [Settlements Module](./backend-core-modules.md) | 2026-01-26 | SEPA XML, preview, cancel, 24/24 tests |
| [Admin-Users Module](./backend-core-modules.md) | 2026-01-25 | CRUD, password mgmt, 20/20 tests |
| [Transactions Refactoring](./backend-core-modules.md) | 2026-01-25 | ADR-0018 compliant, 41/41 tests |
| [Phase 3: Backend Products](./phase3-backend-products-module.md) | 2026-01-25 | Product/category APIs, 50/50 tests |
| [Phase 2.A Backend](./phase2-terminal-balance-transactions.md) | 2026-01-25 | Sync/balance APIs, 25/25 tests |
| [Terminal Auth (Pattern 012)](./phase1-backend-foundation.md) | 2026-01-24 | Bearer token auth for terminal API |
| [Phase 1: Backend Foundation](./phase1-backend-foundation.md) | 2026-01-25 | Modular backend, 112/112 tests |

---

## How to Use This Index

1. **Starting work**: Check "Current Plan" for active work
2. **New feature**: Create a plan in `plans/`, add to this index
3. **Completing a plan**: Move from Current to Completed with date
4. **Outdated plans**: May contain useful context but don't trust task lists
