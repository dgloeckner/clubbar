# SEPA Mandate Template URL

**Status**: Implemented

**Issue**: Follow-up to [#360](https://github.com/dgloeckner/clubbar/issues/360) / [PR #456](https://github.com/dgloeckner/clubbar/pull/456)

**Goal**: #360 removed the in-app generation of a blank SEPA mandate PDF; the club now maintains the registration form externally. This plan re-adds a way to reach that form from the admin panel — a configurable URL in SEPA settings, a "SEPA-Vorlage" button on the Members page that opens it, and a block on SEPA export (plus a prominent dashboard warning, mirroring the existing IBAN-encryption-key banner) while it is unset.

**Architecture**: One new nullable column (`sepa_config.mandate_template_url`) threaded through the existing `SepaConfigDto`/`Repository`/`Service`/`Controller` stack, unvalidated for format (matches `mail_config.website_url`/`logo_url`, not the checksummed IBAN columns beside it). It joins `creditor_id`/`creditor_name`/`creditor_iban` as a requirement for `is_configured` and for `SepaExportService::export()`'s existing completeness gate. A new `DashboardService::sepaConfigAlert()` surfaces incompleteness as a top-of-page banner. The Members page opens the URL via `window.open` instead of downloading a generated PDF.

---

## Milestones

### M1: Backend data model and validation — [x] Passed

- [x] Migration 028 adds `sepa_config.mandate_template_url VARCHAR(255) NULL`, with rollback
- [x] `SepaConfigDto`: new `mandateTemplateUrl` property; `is_configured` now also requires it
- [x] `SepaConfigRepository`: `mandate_template_url` added to the `updateConfig()` whitelist; `isConfigured()` requires it
- [x] `SepaConfigController`: `mandate_template_url` validated `nullable|string|max:255` (no dedicated URL format rule — Pattern 001, matches `MailConfigController`)
- [x] Verified: `SepaConfigDtoTest` (16 tests), `SepaConfigServiceTest`, `SepaConfigControllerTest` — all green (`docker compose exec -w /app backend ./vendor/bin/phpunit --filter SepaConfig`)

### M2: SEPA export gate — [x] Passed

- [x] `SepaExportService::export()`'s existing creditor-completeness check extended to also require `mandate_template_url`
- [x] Verified: `SepaExportServiceTest::test_export_refuses_when_the_mandate_template_url_is_missing` and the sibling creditor-completeness test; every other export test's `givenSepaConfig()` fixture updated so the pre-existing suite stays green; `SepaExportPersistenceTest` fixture likewise

### M3: Dashboard warning — [x] Passed

- [x] `DashboardService::sepaConfigAlert()` — mirrors `encryptionKeyAlert()`'s `{severity, message}` shape; wired into `alerts.sepa_config`
- [x] `DashboardPage.tsx`: new top-of-page banner (`dashboard-sepa-config-warning`), same styling/placement as the IBAN-encryption-key banner, link to Settings
- [x] Verified: `DashboardServiceTest` — 4 new tests (missing row, missing creditor fields, missing URL, fully configured) plus an assertion on the existing assembly test

### M4: API spec and generated client — [x] Passed

- [x] `api/admin.yaml`: `mandate_template_url` added to `SepaConfig`/`SepaConfigRequest`/`SepaConfigUpdateRequest`; `alerts.sepa_config` added to `DashboardResponse`
- [x] Orval client regenerated (`npm run generate`) — only the files touched by this change were committed; unrelated pre-existing drift in the checked-in client (mail-config/scheduler paths already in `admin.yaml` but never regenerated) was left untouched, same call made in PR #456

### M5: Settings UI — [x] Passed

- [x] `SepaConfigTab.tsx`: new `FormField` (type `url`, spans both grid columns like `payment_reference_prefix`), not required to save
- [x] `SettingsPage.tsx` + `utils/sepaConfig.ts`: state, load, validate (max length only), save payload builders
- [x] i18n: `settings.mandateTemplateUrl` + placeholders/helpers/validation message, de + en
- [x] Verified: `npx tsc --noEmit` clean; e2e `should persist the mandate template URL` (settings-sepa-config.spec.ts)

### M6: Members page button — [x] Passed

- [x] New `ExternalLinkIcon`; "SEPA-Vorlage" `PageActionButton` back in its pre-#360 slot (between scan and create), now `window.open`s `mandate_template_url` in a new tab instead of downloading a PDF; disabled with a tooltip while unset
- [x] Members page fetches `GET /admin/sepa-config` on mount (own `useLatestRequest` slot, per `data-fetching.md`)
- [x] i18n: `members.openSepaTemplate`, `members.sepaTemplateNotConfigured`, de + en
- [x] E2E: page-object methods (`expectSepaTemplateLinkEnabled/Disabled`, `clickSepaTemplateLinkButton` — asserts the popup's target URL); test in `settings-sepa-config.spec.ts` (saves a known URL, then checks the button opens it, minimising the shared-singleton race window the same way `journal-and-settlements.spec.ts` does around SEPA export)

### M7: Documentation — [x] Passed

- [x] ADR-0007 (SEPA config storage): schema amendment + "Amendment: Mandate Template URL" section
- [x] ADR-0011 (SEPA config admin UI): field table row + "Amendment: Mandate Template URL and the Dashboard Warning" section, noting where the design departed from the ADR's original (unbuilt) wizard/Redux language

---

## Deliberate scope decisions

- **Not required at save time.** A club can configure creditor details before the external form exists; only SEPA **export** is blocked, matching how the three creditor fields already behaved (settlement *creation* was never gated on SEPA completeness, and this does not change that).
- **No e2e coverage of the export-blocked-when-missing path or the dashboard-banner-visible-when-incomplete path.** Both would require breaking the shared singleton `sepa_config` row while dozens of other tests run in parallel assuming it is complete (see `journal-and-settlements.spec.ts`'s existing comments on this same row). The equivalent encryption-key dashboard banner has no e2e coverage for the same reason. Both paths are covered at the unit level instead (`SepaExportServiceTest`, `DashboardServiceTest`).
- **No new ADR.** Extended ADR-0007 and ADR-0011 per explicit user confirmation, rather than writing a new one, since this is additive to decisions those two already own.

---

## Test Commands

```bash
# Backend
docker compose exec -w /app backend ./vendor/bin/phpunit --filter "SepaConfig|SepaExport|DashboardServiceTest|AdminController(List|Validation|CollectionHolds|MandateMissing)Test"
docker compose exec -w /app backend ./vendor/bin/phpunit   # full suite

# Frontend types
cd admin-frontend && npx tsc --noEmit

# E2E
cd e2etests && npx playwright test --project=api-tests
cd e2etests && npx playwright test --project=admin-chromium tests/admin/settings-sepa-config.spec.ts tests/admin/members.spec.ts
```
