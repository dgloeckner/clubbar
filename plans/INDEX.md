# Implementation Plans Index

This index tracks the status of all implementation plans for Club Bar.

---

## Current Plan

| Plan | Status | Summary |
|------|--------|---------|
| [Reset 2FA Button](./2026-03-23-reset-2fa-button.md) | Completed | Add "Reset 2FA" icon button to AdminUsersTab with confirm dialog, API helper, E2E test |
| [TOTP + Session Bug Fixes](./2026-03-23-totp-session-bugfixes.md) | Not Started | Fix SESSION_MAX_AGE not applied to PHP session engine; fix frontend enrollment flow showing dashboard instead of QR code |
| [Install TOTP Key Generation](./2026-03-22-install-totp-key-generation.md) | Completed | Auto-generate TOTP_ENCRYPTION_KEY in package installer (config.sample, install.php, index.php) |
| [Backend Security Review](./2026-03-17-backend-security-review.md) | In Progress | C1/C2/C3/H4 done; H3/H6/M3 this sprint; H1/H2/H5/M1/M2/M4/L1–L4 next sprint |
| [GDPR Anonymization Fix](./2026-03-08-gdpr-anonymization-fix.md) | Not Started | Fix anonymization: NULL instead of DELETED, audit log scrubbing, pre-deletion checks, E2E tests |
| [CI Pipeline Refactoring](./2026-03-07-ci-pipeline-refactoring.md) | In Progress | Restructure CI: job dependencies, caching, dedup, lint gate, fix silent failures |

---

## Completed Plans

| Plan | Completed | Summary |
|------|-----------|---------|
| [IONOS Integration Deployment](../docs/superpowers/plans/2026-03-29-ionos-deployment.md) | 2026-03-29 | Self-destructing deploy.php migration runner + deploy-integration CI job |
| [SEPA Mandate LLM Extraction](../docs/superpowers/plans/2026-03-27-sepa-mandate-extraction.md) | 2026-03-27 | LLM extraction of member fields from mandate scans; Anthropic/OpenAI via cURL; confidence badges; create-member-from-scan flow |
| [SEPA Mandate Upload](../docs/superpowers/plans/2026-03-26-sepa-mandate-upload.md) | 2026-03-27 | Upload scanned mandate documents per member (JPEG/PNG/HEIC/PDF), client-side compression, server-side PDF conversion, view/replace/delete, GDPR anonymization integration |
| [E2E Coverage Gaps](./2026-03-23-e2e-coverage-gaps.md) | 2026-03-23 | Fix broken export URLs in 3 test files, fix meaningless assertions, add UI-driven export/correction/confirm tests |
| [Mandatory TOTP 2FA](./2026-03-22-totp-2fa.md) | 2026-03-22 | Mandatory TOTP 2FA for all admins: backend, OAS, frontend, E2E tests (364 passing) |
| [Security Critical Fixes](./2026-03-17-security-critical-fixes.md) | 2026-03-17 | C1 timing attack, C2 wildcard CORS, C3 APP_DEBUG=true — all fixed |
| [Terminal Auth Rate Limiting](../docs/superpowers/plans/2026-03-17-terminal-rate-limiting.md) | 2026-03-17 | IP-based rate limiting on terminal sync endpoints — 10 failures/15 min → 429 |
| [install.php Security Hardening](./2026-03-17-install-php-security-hardening.md) | 2026-03-17 | Fix Env::get() precedence bug, remove key from query param, block install.php via .htaccess |
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
