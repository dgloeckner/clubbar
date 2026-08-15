# Implementation Plans Index

This index tracks the status of all implementation plans for Club Bar.

---

## Current Plan

| Plan | Status | Summary |
|------|--------|---------|
| [SEPA Mandate Template URL](./2026-08-15-sepa-mandate-template-url.md) | Implemented (M1–M7 done) | Follow-up to [#360](https://github.com/dgloeckner/clubbar/issues/360)/[PR #456](https://github.com/dgloeckner/clubbar/pull/456) — configurable `mandate_template_url` in SEPA config, blocking SEPA export while unset, a dashboard warning banner mirroring the IBAN-encryption-key one, and the Members page's "SEPA-Vorlage" button back as an external link instead of a PDF download |
| [SEPA Pre-Notification Emails](./2026-08-13-sepa-prenotification-emails.md) | In Progress (P0–P4 done — ADR-0038 + amendments, mail transport/layout/`mail_config`, the `mail_outbox` queue written inside the finalize transaction (generalised for #438 by migration 026), the de/en announcement and cancellation content, and now the drain: `bin/cron.php`, `DrainService`, the `flock` and the URL fallback route. **Mail can leave the host.** P5–P10 open; P5's install gate is the next link) | Epic [#361](https://github.com/dgloeckner/clubbar/issues/361) / [ADR-0038](../adr/0038-transactional-mail-outbox-on-shared-hosting.md) — announce every SEPA collection by email 7 days ahead (Nutzungsordnung § 7 Abs. 3) via a transactional outbox written inside the finalize transaction and drained by a mandatory scheduler, the only sending path. |
| [Reversal UI](./2026-08-14-reversal-ui.md) | Implemented (P0–P3 done; 17 admin E2E + 7 API lookup tests green, 295/295 frontend unit) | Epic [#433](https://github.com/dgloeckner/clubbar/issues/433) / [ADR-0032](../adr/0032-settlement-lifecycle.md) §8 — the #196 reversal backend reaches the admin panel: one undo slot per row that offers cancel *or* reverse, a reference lookup for recording a bank return, and the per-member breakdown behind an expandable row. Closes the UI half of [#81](https://github.com/dgloeckner/clubbar/issues/81); builds on [#377](https://github.com/dgloeckner/clubbar/issues/377) Phase 1 |
| [Instance Branding](./2026-08-11-instance-branding.md) | Implemented (M1–M6 done; PHPUnit 1448/1448, admin-chromium 284/284, Flutter 720/720 all green) | [ADR-0034](../adr/0034-instance-branding-configuration.md) — admin-configurable instance name (e.g. "FRGS Ruderbar"), set during install and editable in the admin panel, shown in the admin UI, Terminal UI, and as the TOTP issuer |
| [Excluded from Collection](./2026-08-09-excluded-from-collection.md) | Implemented (M0–M8 merged in [#261](https://github.com/dgloeckner/clubbar/pull/261); M9 — the third section, [#258](https://github.com/dgloeckner/clubbar/issues/258) — implemented, pending merge) | [#188](https://github.com/dgloeckner/clubbar/issues/188) — one Members-area surface for the two standing exclusions (members in credit, members on collection hold), desktop and mobile, plus the missing API coverage for both endpoints. Third bucket (no mandate) follows in [#258](https://github.com/dgloeckner/clubbar/issues/258) |
| [Settlement Member Selection](./2026-08-08-settlement-member-selection.md) | Implemented (M1–M4 done; Playwright pending CI) | [ADR-0030](../adr/0030-settlement-selection-is-a-member-picker.md) — settlement selection becomes a member picker on its own New Settlement screen, and leaves the Journal. The design half of [#128](https://github.com/dgloeckner/ruderbar/issues/128), whose arithmetic half shipped in [#228](https://github.com/dgloeckner/clubbar/pull/228) |
| [Critical Remediation](./2026-08-06-critical-remediation.md) | Decisions substantially complete (21 rulings; 1 open on map #139) — **verification not started** | Sequenced fix plan for the 11 critical issues, each carrying its governing money-semantics ruling. ⚠️ Coordinate with Backend Test Coverage before its M2 — see issue #166 |
| [Backend Test Coverage](./2026-08-07-backend-test-coverage.md) | In Progress (M0 + M1 done — 15.11% → 26.64%; #103 merged in PR #154) | Issue #103 follow-up — raise PHPUnit line coverage from a measured 15.11% toward ADR-0022's 80%, starting with settlements/transactions (money), auth (security), members (GDPR) |
| [SEPA NOTPROVIDED BIC Encoding](./2026-08-05-sepa-notprovided-bic-encoding.md) | In Progress (Tasks 1-4 done; bank file check pending) | Issue #12 — emit `Othr/Id = NOTPROVIDED` instead of `BICFI = NOTPROVIDED` for IBAN-only agents in the pain.008.001.08 export |
| [SEPA Execution Date: Bank Business Day Rule](./2026-08-05-sepa-execution-date-business-day.md) | Implemented (E2E green; A7 follow-up [#113](https://github.com/dgloeckner/clubbar/issues/113) implemented, pending merge) | Issue #11: reject non-business-day `execution_date` (422, weekends + six TARGET2 closing days) on both settlement endpoints, add `GET /settlements/execution-date-info` as the single source of truth, guard the SEPA export. #113 then re-anchored the 7-day lead time on the server's today and removed `settlement_date` from the request |
| [Terminal: Checkout Double-Tap Guard](./2026-08-05-checkout-double-tap-guard.md) | Implemented | Issue #14: re-entrancy guard in `CartProvider.checkout` plus a disabled/loading checkout button, so a double-tap can no longer create duplicate transactions |
| [Terminal: RFID Reader Health Detection](./2026-08-10-rfid-reader-health-detection.md) | Implemented (582/582 unit + widget tests green) | [#35](https://github.com/dgloeckner/clubbar/issues/35): poll `/proc/bus/input/devices` for the configured reader, surface it as a third header pill, and replace the idle screen's invitation with "Scanner nicht verbunden" while the reader is gone |

---

## Previous Current Plans

| Plan | Status | Summary |
|------|--------|---------|
| [SEPA Vision OCR Pipeline](../docs/superpowers/plans/2026-03-30-sepa-vision-ocr-pipeline.md) | Completed | Replace multi-pass LLM extraction with Google Vision OCR + Haiku text pipeline; adds street/zip/city fields, checksumValid, needsReview |
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
| [IBAN Encryption (Sealed Boxes)](./2026-08-13-iban-encryption-sealed-box.md) | 2026-08-14 | Epic [#388](https://github.com/dgloeckner/clubbar/issues/388) / [ADR-0036](../adr/0036-iban-encryption-sealed-box.md) + [ADR-0037](../adr/0037-mandate-documents-not-retained.md) — P0–P8 done: IBANs encrypted at rest with libsodium sealed boxes (server holds public key only; private key offline with the club, supplied per SEPA export/rotation behind TOTP step-up), 365-day key + terminal-token lifecycle with 90/30/7 warnings, last4-only API surfaces, mandate scans extraction-only with no retention, docs + backup/recovery procedures + follow-up issues ([#437](https://github.com/dgloeckner/clubbar/issues/437), [#438](https://github.com/dgloeckner/clubbar/issues/438)) closing out the epic |
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
