# Plan: Instance Branding

**Issue**: Lack of Club Bar Branding — Admin UI, Terminal UI, and TOTP/authenticator onboarding should all show a configurable instance name (e.g. "FRGS Ruderbar"), set during first-time installation and editable later in the admin panel.

**ADR**: [ADR-0034: Instance Branding Configuration](../adr/0034-instance-branding-configuration.md)
**Use Case**: [UC-A64: Manage Instance Branding](../use-cases/admin/UC-A64-manage-instance-branding.md)

---

## Milestone 1: Backend storage + API

- [x] M1.1: Migration `013_instance_config.sql` — singleton table, seeded `instance_name = 'Club Bar'`
- [x] M1.2: `InstanceConfig` module (Repository, Service, DTO, Controller) following the `SepaConfig` pattern (backend patterns 001–008)
- [x] M1.3: Routes — `GET /instance-config` (public), `PATCH /admin/instance-config` (session-auth)
- [x] M1.4: `EntityType::INSTANCE_CONFIG` audit enum case; updates audit-logged like SEPA config
- [x] M1.5: `api/admin.yaml` + `api/terminal.yaml` updated with the new endpoint(s) and `instance_name` on the health response
- [x] M1.6: PHPUnit coverage — repository/service/controller/DTO, TOTP issuer, health response; full suite 1448/1448 green

**Test**: `docker compose exec -w /app backend ./vendor/bin/phpunit --filter InstanceConfig` — passing (verified against live dev stack)

## Milestone 2: TOTP issuer

- [x] M2.1: `TotpService` reads `instance_name` (via `InstanceConfigService`) instead of the hardcoded `'Ruderbar'` / `'Ruderbar (dev)'`, falling back to `'Club Bar'`
- [x] M2.2: PHPUnit — issuer reflects configured name; falls back when unset

**Test**: `docker compose exec -w /app backend ./vendor/bin/phpunit --filter Totp` — passing

## Milestone 3: Install wizard

- [x] M3.1: `package/install.php` Step 4 collects an "Instance name" field alongside admin account creation and writes it to `instance_config`

**Test**: The exact `UPDATE instance_config ...` code path was run against a real throwaway MariaDB schema (migrated with all 13 migration files) — confirmed a filled field updates `instance_name` and `updated_by_admin_id`, and a blank field leaves the migration's seeded `'Club Bar'` default untouched. A full click-through of the wizard's key/session state machine was not exercised (would require tearing down or duplicating the shared dev stack's install state).

## Milestone 4: Admin frontend

- [x] M4.1: `orval` regenerated client for the new endpoint(s)
- [x] M4.2: Instance branding fetched once at app bootstrap (public endpoint, no auth) and made available to `MainLayout` and `LoginPage` via `InstanceConfigContext`
- [x] M4.3: `MainLayout.tsx` header/footer and the browser tab title use the fetched name instead of the "Club Bar" literal (logo graphic unchanged — only text)
- [x] M4.4: `LoginForm.tsx` uses the fetched name
- [x] M4.5: New Settings → Instance tab (`InstanceBrandingTab.tsx`), mirroring `SepaConfigTab.tsx`'s load/edit/save shape, with test IDs per `admin-frontend/patterns/test-ids.md`
- [x] M4.6: Playwright E2E (`settings-instance-branding.spec.ts`) — settings tab loads current name, saves a new name, verifies persistence via re-fetch, verifies header/title update within the same session; login page (unauthenticated) renders configured name

**Test**: `npx playwright test --project=admin-chromium --grep "Instance Branding"` — 5/5 passing; full `admin-chromium` project 284 passed / 7 pre-existing skips / 0 failed

## Milestone 5: Terminal

- [x] M5.1: `HealthCheckService` / `HealthController` add `instance_name` to the `/health` response
- [x] M5.2: `NetworkService.fetchInstanceName()` parses `instance_name` from the raw health response (same hand-rolled approach `fetchBackendVersion` already uses)
- [x] M5.3: `ConfigService` exposes a backend-sourced name with precedence: local `config.json` `displayName` (explicit override) > backend `instance_name` > stock "Club Bar" fallback
- [x] M5.4: `SyncProvider`'s existing health-check cycle feeds the fetched name into `ConfigService`; `ClubBarHeader` reflects it
- [x] M5.5: Flutter unit/widget tests — `ConfigService` precedence table, header shows backend name when no local override, local override still wins

**Test**: `cd terminal-frontend && flutter test` — 720 passed, 0 failed; `flutter analyze` clean on all touched files

## Milestone 6: Docs

- [x] M6.1: `plans/INDEX.md` updated
- [x] M6.2: `docs/erm-master.md` gets an `instance_config` entry (mirroring `sepa_config`'s entry, including the ERM mermaid diagram and FK table)

---

## Notes

- Terminal propagation is eventually-consistent (next health poll), not instant — documented in ADR-0034 as an accepted characteristic, not a defect.
- The per-terminal `config.json` `displayName` override from issue #297 is preserved and takes precedence over the org-wide name — a terminal that already customized its header keeps doing so.
