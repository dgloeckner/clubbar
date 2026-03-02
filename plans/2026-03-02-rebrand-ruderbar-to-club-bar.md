# Rebrand "Ruderbar" to "Club Bar" — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Rebrand the entire project from "Ruderbar" to "Club Bar" across all documentation, configuration, source code, and deployment artifacts.

**Architecture:** This is a pure find-and-replace + documentation update task with no functional changes. The rebrand touches ~90 files across documentation, config, source code, i18n, and deployment. We proceed layer by layer: documentation first (low risk, high visibility), then configuration, then source code, then build/deploy.

**Tech Stack:** Markdown, TypeScript/React, PHP, Dart/Flutter, Docker, YAML, SQL, Xcode project files

---

## Naming Convention

| Context | Old | New |
|---------|-----|-----|
| Brand name (display) | Ruderbar | Club Bar |
| Brand name (single word, code/packages) | ruderbar | clubbar |
| Brand name (snake_case) | ruderbar_terminal | clubbar_terminal |
| Brand name (kebab-case) | ruderbar-admin | clubbar-admin |
| Brand name (PascalCase) | RuderbarDatabase | ClubBarDatabase |
| Brand name (camelCase) | ruderbarTerminal | clubbarTerminal |
| Tagline | "Where rowing meets refreshments" | *(remove — no longer relevant)* |
| Target audience | "rowing clubs and sports organizations" | "sports clubs, community centers, and member organizations" |
| Logo file | artwork/logo1.svg | artwork/clubbar-logo.svg |
| Docker containers | ruderbar-db, ruderbar-backend, etc. | clubbar-db, clubbar-backend, etc. |
| Database name | ruderbar | clubbar |
| DB user | ruderbar | clubbar |

**IMPORTANT:** The SEPA example names like "Ruderbar Frankfurter Rudergesellschaft" in ADRs are **fictional examples** — replace with a generic club name like "SV Musterstadt" or "Sportverein Beispiel e.V.".

---

## Task 1: Replace Logo File

**Files:**
- Replace: `artwork/logo1.svg` → use `artwork/clubbar-logo.svg` as the new main logo
- Modify: `README.md` (line 2) — update logo path
- Modify: `admin-frontend/public/logo.svg` — replace with new logo content
- Modify: `admin-frontend/src/components/layout/MainLayout.tsx` — update alt text
- Modify: `admin-frontend/src/components/forms/LoginForm.tsx` — update alt text

**Step 1: Copy new logo to replace old logo references**

```bash
cp artwork/clubbar-logo.svg artwork/logo1.svg
cp artwork/clubbar-logo.svg admin-frontend/public/logo.svg
```

Update the `aria-label` in both copied files from `"club bar logo"` to `"Club Bar app icon"`.

**Step 2: Update README logo reference**

In `README.md` line 2, change:
```html
<img src="artwork/logo1.svg" alt="Ruderbar Logo" width="128" height="128">
```
to:
```html
<img src="artwork/clubbar-logo.svg" alt="Club Bar Logo" width="128" height="128">
```

**Step 3: Update admin frontend alt text**

In `admin-frontend/src/components/forms/LoginForm.tsx`, change `alt="Ruderbar Logo"` to `alt="Club Bar Logo"`.

In `admin-frontend/src/components/layout/MainLayout.tsx`, change `alt="Ruderbar Logo"` to `alt="Club Bar Logo"`.

**Step 4: Commit**

```bash
git add artwork/ admin-frontend/public/logo.svg admin-frontend/src/components/forms/LoginForm.tsx admin-frontend/src/components/layout/MainLayout.tsx README.md
git commit -m "rebrand: replace Ruderbar logo with Club Bar logo"
```

---

## Task 2: Rebrand README.md and CLAUDE.md

**Files:**
- Modify: `README.md`
- Modify: `CLAUDE.md` (line 7 only)
- Modify: `AGENTS.md`
- Modify: `SETUP.md`
- Modify: `todo.txt`

**Step 1: Update README.md**

Replace all occurrences:
- `<h1 align="center">Ruderbar</h1>` → `<h1 align="center">Club Bar</h1>`
- `Ruderbar is a complete point-of-sale` → `Club Bar is a complete point-of-sale`
- `## Why Ruderbar?` → `## Why Club Bar?`
- `*"Ruderbar"* — Where rowing meets refreshments.` → `*"Club Bar"* — Your members. Your bar. Your system.` (or similar — remove rowing reference)
- `Built for rowing clubs and sports organizations` → `Built for sports clubs and member organizations`
- Any remaining `Ruderbar` → `Club Bar`

**Step 2: Update CLAUDE.md line 7**

```
**Club Bar** is an open-source, member-managed bar/club POS system designed for sports clubs, community centers, and member organizations that need:
```

**Step 3: Update AGENTS.md**

Replace all `Ruderbar` with `Club Bar` (lines 3, 9, 316, 489).
Replace `ruderbar` in database commands with `clubbar`.

**Step 4: Update SETUP.md**

Replace any `Ruderbar` references with `Club Bar`.

**Step 5: Update todo.txt**

Replace `ruderbar` with `clubbar` in lines 1-2.

**Step 6: Commit**

```bash
git add README.md CLAUDE.md AGENTS.md SETUP.md todo.txt
git commit -m "rebrand: update README, CLAUDE.md, AGENTS.md with Club Bar branding"
```

---

## Task 3: Rebrand Architecture Decision Records (ADRs)

**Files:**
- Modify: All 22 ADR files in `adr/`
- Modify: `adr/README.md`

**Step 1: Bulk replace in all ADR files**

For each ADR file, apply these replacements:
- `the Ruderbar system` → `the Club Bar system`
- `The Ruderbar system` → `The Club Bar system`
- `the Ruderbar project` → `the Club Bar project`
- `for Ruderbar` → `for Club Bar`
- `Ruderbar Frankfurter Rudergesellschaft` → `Sportverein Beispiel e.V.`
- `Ruderbar Bar Deckel` → `Club Bar Abrechnung`
- `Ruderbar-Kühlschrank` → `Club-Bar-Kühlschrank`
- `de.clubbar.ruderbarTerminal` → `de.clubbar.clubbarTerminal`
- `No functional advantage for Ruderbar` → `No functional advantage for Club Bar`
- Any remaining `Ruderbar` → `Club Bar`
- Any remaining `ruderbar` → `clubbar`

**Step 2: Verify no broken markdown links**

Spot-check a few ADR files to ensure formatting is intact.

**Step 3: Commit**

```bash
git add adr/
git commit -m "rebrand: update all ADRs from Ruderbar to Club Bar"
```

---

## Task 4: Rebrand API Specifications

**Files:**
- Modify: `api/admin.yaml`
- Modify: `api/terminal.yaml`

**Step 1: Update api/admin.yaml**

- `title: Ruderbar - Admin API` → `title: Club Bar - Admin API`
- `REST API for the Admin Panel of Ruderbar.` → `REST API for the Admin Panel of Club Bar.`
- `name: Ruderbar` → `name: Club Bar`
- `url: https://github.com/ruderbar/ruderbar` → `url: https://github.com/clubbar/clubbar` (or keep generic)

**Step 2: Update api/terminal.yaml**

- `title: Ruderbar - Terminal API` → `title: Club Bar - Terminal API`
- `name: Ruderbar Project` → `name: Club Bar Project`
- `url: https://github.com/ruderbar/ruderbar` → update accordingly

**Step 3: Commit**

```bash
git add api/
git commit -m "rebrand: update OpenAPI specs from Ruderbar to Club Bar"
```

---

## Task 5: Rebrand Docker & Database Configuration

**Files:**
- Modify: `docker-compose.yml`
- Modify: `docker/init.sql`
- Modify: `docker/admin-frontend.Dockerfile`
- Modify: `backend/.env`
- Modify: `backend/.env.example`
- Modify: `reset-db.sh`

**Step 1: Update docker-compose.yml**

Replace all container names and env vars:
- `# Ruderbar - Development Environment` → `# Club Bar - Development Environment`
- `container_name: ruderbar-db` → `container_name: clubbar-db`
- `MYSQL_DATABASE: ruderbar` → `MYSQL_DATABASE: clubbar`
- `MYSQL_USER: ruderbar` → `MYSQL_USER: clubbar`
- `MYSQL_PASSWORD: ruderbar` → `MYSQL_PASSWORD: clubbar`
- `container_name: ruderbar-backend` → `container_name: clubbar-backend`
- `DB_NAME: ruderbar` → `DB_NAME: clubbar`
- `DB_USER: ruderbar` → `DB_USER: clubbar`
- `DB_PASS: ruderbar` → `DB_PASS: clubbar`
- `container_name: ruderbar-admin` → `container_name: clubbar-admin`
- `container_name: ruderbar-terminal` → `container_name: clubbar-terminal`
- `container_name: ruderbar-e2etests` → `container_name: clubbar-e2etests`

**Step 2: Update docker/init.sql**

- `-- Ruderbar Database Initialization` → `-- Club Bar Database Initialization`
- `ALTER DATABASE ruderbar` → `ALTER DATABASE clubbar`
- `GRANT ALL PRIVILEGES ON ruderbar.*` → `GRANT ALL PRIVILEGES ON clubbar.*`
- `'ruderbar'@'%'` → `'clubbar'@'%'`

**Step 3: Update docker/admin-frontend.Dockerfile**

- `# Ruderbar Admin Frontend` → `# Club Bar Admin Frontend`

**Step 4: Update backend/.env and .env.example**

- `APP_NAME=Ruderbar` → `APP_NAME=ClubBar`
- `DB_DATABASE=ruderbar` → `DB_DATABASE=clubbar`
- Update user/password references

**Step 5: Update reset-db.sh**

- `Resetting Ruderbar backend database` → `Resetting Club Bar backend database`
- `mysql -u root -proot ruderbar` → `mysql -u root -proot clubbar`

**Step 6: Recreate Docker environment**

⚠️ **IMPORTANT:** After changing database names and credentials, the existing Docker volumes must be recreated:

```bash
docker compose down -v   # removes volumes (destroys local dev data!)
docker compose up -d     # recreates with new names
```

**Step 7: Commit**

```bash
git add docker-compose.yml docker/ backend/.env backend/.env.example reset-db.sh
git commit -m "rebrand: update Docker, database config from ruderbar to clubbar"
```

---

## Task 6: Rebrand Backend (PHP)

**Files:**
- Modify: `backend/composer.json`
- Modify: `backend/db/migrations/001_initial_schema.sql`
- Modify: `backend/tests/Feature/DatabaseTestCase.php`
- Modify: `backend/patterns/README.md`
- Modify: `backend/patterns/pattern-012-terminal-api-token-authentication.md`
- Modify: `backend/patterns/pattern-014-rfid-member-identification.md`
- Modify: `backend/patterns/pattern-015-authorization-access-control.md`

**Step 1: Update composer.json**

- `"name": "frgs/ruderbar-backend"` → `"name": "frgs/clubbar-backend"`
- `"description": "Ruderbar POS Backend - Slim 4"` → `"description": "Club Bar POS Backend - Slim 4"`

**Step 2: Update migration SQL comment**

- `Ruderbar v1.0` → `Club Bar v1.0`
- `Ruderbar (Vereinsbar)` → `Club Bar`

**Step 3: Update DatabaseTestCase.php defaults**

- `'ruderbar'` → `'clubbar'` (all 3 occurrences: DB_NAME, DB_USER, DB_PASS)

**Step 4: Update backend patterns**

Replace `Ruderbar` → `Club Bar` in all pattern files.

**Step 5: Run composer update to refresh lock file**

```bash
cd backend && composer update --lock && cd ..
```

**Step 6: Commit**

```bash
git add backend/
git commit -m "rebrand: update backend package name and docs from Ruderbar to Club Bar"
```

---

## Task 7: Rebrand Admin Frontend (React)

**Files:**
- Modify: `admin-frontend/package.json`
- Modify: `admin-frontend/index.html`
- Modify: `admin-frontend/public/.htaccess`
- Modify: `admin-frontend/README.md`
- Modify: `admin-frontend/src/components/layout/MainLayout.tsx` — brand text + footer
- Modify: `admin-frontend/src/components/forms/LoginForm.tsx` — brand text
- Modify: `admin-frontend/src/components/settings/SepaConfigTab.tsx` — placeholder

**Step 1: Update package.json**

- `"name": "ruderbar-admin-frontend"` → `"name": "clubbar-admin-frontend"`

**Step 2: Update index.html**

- `<title>Ruderbar - Admin Panel</title>` → `<title>Club Bar - Admin Panel</title>`

**Step 3: Update .htaccess**

- `# Ruderbar Admin Frontend` → `# Club Bar Admin Frontend`

**Step 4: Update README.md**

- `React-based admin panel for Ruderbar POS system` → `React-based admin panel for Club Bar POS system`
- `Same as Ruderbar project` → `Same as Club Bar project`

**Step 5: Update MainLayout.tsx**

- `Ruderbar` (nav title, line 133) → `Club Bar`
- `Ruderbar Admin © 2026` → `Club Bar Admin © 2026`

**Step 6: Update LoginForm.tsx**

- `Ruderbar` (heading text, line 89) → `Club Bar`

**Step 7: Update SepaConfigTab.tsx**

- `placeholder="z.B. Ruderbar Abrechnung"` → `placeholder="z.B. Club Bar Abrechnung"`

**Step 8: Regenerate package-lock.json**

```bash
cd admin-frontend && npm install && cd ..
```

**Step 9: Commit**

```bash
git add admin-frontend/
git commit -m "rebrand: update admin frontend from Ruderbar to Club Bar"
```

---

## Task 8: Rebrand Terminal Frontend (Flutter)

This is the largest task — the Flutter codebase has RuderbarDatabase, RuderbarTerminalApp, and ruderbar_terminal throughout.

**Files:**
- Modify: `terminal-frontend/pubspec.yaml`
- Modify: `terminal-frontend/lib/config/app_config.dart`
- Modify: `terminal-frontend/lib/main.dart`
- Modify: `terminal-frontend/lib/database/database.dart`
- Modify: `terminal-frontend/lib/widgets/ruderbar_header.dart` → **rename to** `terminal-frontend/lib/widgets/clubbar_header.dart`
- Modify: `terminal-frontend/lib/widgets/main_layout.dart`
- Modify: `terminal-frontend/lib/widgets/member_details_modal.dart`
- Modify: `terminal-frontend/lib/widgets/status_info_modal.dart`
- Modify: `terminal-frontend/lib/services/cart_service.dart`
- Modify: `terminal-frontend/lib/services/dispenser_recovery_service.dart`
- Modify: `terminal-frontend/lib/services/transaction_history_service.dart`
- Modify: `terminal-frontend/lib/repository/*.dart` (4 files)
- Modify: `terminal-frontend/lib/l10n/app_en.arb`
- Modify: `terminal-frontend/lib/l10n/app_de.arb`
- Modify: All test files referencing RuderbarDatabase
- Modify: `terminal-frontend/README.md`
- Modify: `terminal-frontend/INSTALL.md`
- Modify: `terminal-frontend/Makefile`
- Modify: `terminal-frontend/scripts/reset-db.sh`
- Modify: `terminal-frontend/scripts/README.md`
- Modify: `terminal-frontend/scripts/screen-idle.py`
- Modify: macOS Xcode project files (bundle identifiers)

**Step 1: Update pubspec.yaml**

- `name: ruderbar_terminal` → `name: clubbar_terminal`
- `description: "Native Flutter POS terminal for Ruderbar club bar system."` → `description: "Native Flutter POS terminal for Club Bar system."`

**Step 2: Rename RuderbarDatabase → ClubBarDatabase**

In `lib/database/database.dart`:
- `class RuderbarDatabase extends _$RuderbarDatabase` → `class ClubBarDatabase extends _$ClubBarDatabase`
- `RuderbarDatabase()` → `ClubBarDatabase()`
- `RuderbarDatabase.forTesting` → `ClubBarDatabase.forTesting`
- `ruderbar_terminal.db` → `clubbar_terminal.db`

**Step 3: Rename RuderbarTerminalApp → ClubBarTerminalApp**

In `lib/main.dart`:
- `_seedMockData(RuderbarDatabase` → `_seedMockData(ClubBarDatabase`
- `final database = RuderbarDatabase()` → `final database = ClubBarDatabase()`
- `runApp(RuderbarTerminalApp(` → `runApp(ClubBarTerminalApp(`
- `class RuderbarTerminalApp` → `class ClubBarTerminalApp`
- `Provider<RuderbarDatabase>` → `Provider<ClubBarDatabase>`
- `"Ruderbar-Kühlschrank"` → `"Club-Bar-Kühlschrank"`

**Step 4: Rename ruderbar_header.dart → clubbar_header.dart**

```bash
cd terminal-frontend
git mv lib/widgets/ruderbar_header.dart lib/widgets/clubbar_header.dart
```

In the renamed file:
- `class RuderbarHeader` → `class ClubBarHeader`
- `_RuderbarHeaderState` → `_ClubBarHeaderState`
- `'Ruderbar',` → `'Club Bar',`

**Step 5: Update all imports and type references across lib/**

Every file that imports `ruderbar_terminal` or references `RuderbarDatabase`:
- `import 'package:ruderbar_terminal/` → `import 'package:clubbar_terminal/`
- `RuderbarDatabase` → `ClubBarDatabase`
- `RuderbarHeader` → `ClubBarHeader`
- `RuderbarTerminalApp` → `ClubBarTerminalApp`
- `ruderbar_header.dart` → `clubbar_header.dart`

**Step 6: Update app_config.dart**

- `static const String appName = 'Ruderbar Terminal';` → `static const String appName = 'Club Bar Terminal';`

**Step 7: Update i18n files**

In `app_en.arb`:
- `"Connect this terminal to the Ruderbar backend."` → `"Connect this terminal to the Club Bar backend."`

In `app_de.arb`:
- `"Verbinde dieses Terminal mit dem Ruderbar-Backend."` → `"Verbinde dieses Terminal mit dem Club Bar-Backend."`

**Step 8: Update test files**

Replace all `RuderbarDatabase` → `ClubBarDatabase`, `MockRuderbarDatabase` → `MockClubBarDatabase`, `RuderbarTerminalApp` → `ClubBarTerminalApp`, and `ruderbar_terminal` → `clubbar_terminal` in:
- `test/database_test.dart`
- `test/repository_test.dart`
- `test/widget_test.dart`
- `test/services/cart_service_test.dart`
- `test/services/dispenser_recovery_service_test.dart`
- `test/widgets/ruderbar_header_test.dart` → **rename to** `test/widgets/clubbar_header_test.dart`

**Step 9: Update documentation**

Replace in `README.md`, `INSTALL.md`, `Makefile`, `scripts/reset-db.sh`, `scripts/README.md`, `scripts/screen-idle.py`:
- `Ruderbar` → `Club Bar`
- `ruderbar_terminal` → `clubbar_terminal`
- `ruderbarTerminal` → `clubbarTerminal`
- `de.clubbar.ruderbarTerminal` → `de.clubbar.clubbarTerminal`

**Step 10: Update macOS Xcode project files**

In `macos/Runner.xcodeproj/project.pbxproj`:
- `ruderbar_terminal.app` → `clubbar_terminal.app`
- `de.clubbar.ruderbarTerminal` → `de.clubbar.clubbarTerminal`

In `macos/Runner.xcodeproj/xcshareddata/xcschemes/Runner.xcscheme`:
- `ruderbar_terminal.app` → `clubbar_terminal.app`

**Step 11: Regenerate generated code**

```bash
cd terminal-frontend
dart run build_runner build --delete-conflicting-outputs
```

This regenerates `database.g.dart` with the new `ClubBarDatabase` class name.

**Step 12: Run Flutter tests to verify nothing broke**

```bash
cd terminal-frontend && flutter test
```

**Step 13: Commit**

```bash
git add terminal-frontend/
git commit -m "rebrand: rename Ruderbar to Club Bar across entire Flutter terminal"
```

---

## Task 9: Rebrand E2E Tests

**Files:**
- Modify: `e2etests/package.json`
- Modify: `e2etests/playwright.config.ts`
- Modify: `e2etests/pages/LoginPage.ts`
- Modify: `e2etests/patterns/README.md`
- Modify: `e2etests/README-AUTH-FIXTURE.md`

**Step 1: Update package.json**

- `"name": "ruderbar-e2etests"` → `"name": "clubbar-e2etests"`
- `"description": "E2E and API tests for Ruderbar POS system"` → `"description": "E2E and API tests for Club Bar POS system"`

**Step 2: Update playwright.config.ts**

- `Ruderbar E2E Test Configuration` → `Club Bar E2E Test Configuration`

**Step 3: Update LoginPage.ts**

- `'h1:has-text("Ruderbar")'` → `'h1:has-text("Club Bar")'`

**Step 4: Update patterns README and auth fixture doc**

Replace `Ruderbar` → `Club Bar` and `ruderbar` → `clubbar`.

**Step 5: Regenerate package-lock.json**

```bash
cd e2etests && npm install && cd ..
```

**Step 6: Run E2E tests to verify login still works**

```bash
cd e2etests && npm test -- --grep "login" --workers=1
```

**Step 7: Commit**

```bash
git add e2etests/
git commit -m "rebrand: update E2E tests from Ruderbar to Club Bar"
```

---

## Task 10: Rebrand Use Cases and Data Model Docs

**Files:**
- Modify: `use-cases/sepa/README.md`
- Modify: `use-cases/sepa/uc-sepa-09-csv-export.md`
- Modify: `use-cases/dsgvo/README.md`
- Modify: `docs/datamodel.md`
- Modify: `docs/erm-master.md`
- Modify: `docs/icon-registry.md`

**Step 1: Replace in all files**

- `the Ruderbar system` → `the Club Bar system`
- `for the Ruderbar` → `for the Club Bar`
- `for Ruderbar` → `for Club Bar`
- `Vereinsbar Abrechnung` → `Club Bar Abrechnung`

**Step 2: Commit**

```bash
git add use-cases/ docs/
git commit -m "rebrand: update use cases and data model docs from Ruderbar to Club Bar"
```

---

## Task 11: Rebrand Build Scripts & CI/CD

**Files:**
- Modify: `scripts/build-package.sh`
- Modify: `.github/workflows/build.yaml`
- Modify: `package/README.txt`
- Modify: `package/config.sample.php`
- Modify: `package/install.php`

**Step 1: Update build-package.sh**

- `Ruderbar` → `Club Bar`
- `ruderbar-VERSION.zip` → `clubbar-VERSION.zip`
- `ruderbar` (in DB commands) → `clubbar`

**Step 2: Update GitHub Actions workflow**

- `ruderbar-package.zip` → `clubbar-package.zip`
- `ruderbar-package` (artifact name) → `clubbar-package`

**Step 3: Update package/ files**

In `package/README.txt`:
- `Ruderbar - Member-Managed Bar/Club POS System` → `Club Bar - Member-Managed Bar/Club POS System`

In `package/config.sample.php`:
- `Ruderbar Configuration` → `Club Bar Configuration`
- `'name' => 'ruderbar'` → `'name' => 'clubbar'`

In `package/install.php`:
- All `Ruderbar` → `Club Bar`
- `ruderbar` → `clubbar` (in titles)

**Step 4: Commit**

```bash
git add scripts/ .github/ package/
git commit -m "rebrand: update build scripts and CI/CD from Ruderbar to Club Bar"
```

---

## Task 12: Rebrand Implementation Plans

**Files:**
- Modify: `plans/INDEX.md`
- Modify: All plan files in `plans/` that reference Ruderbar

**Step 1: Bulk replace across all plan files**

- `Ruderbar` → `Club Bar`
- `ruderbar` → `clubbar`
- `RuderbarDatabase` → `ClubBarDatabase`
- `RuderbarTerminalApp` → `ClubBarTerminalApp`
- `ruderbar_terminal` → `clubbar_terminal`
- `de.clubbar.ruderbarTerminal` → `de.clubbar.clubbarTerminal`
- `package:ruderbar_terminal` → `package:clubbar_terminal`

Note: Plans are historical documents. Some code snippets in plans will no longer match the codebase exactly, but the plans should still reflect current naming for readability.

**Step 2: Also update `docs/plans/` subdirectory**

Same replacements for all files in `docs/plans/` and `terminal-frontend/docs/plans/`.

**Step 3: Commit**

```bash
git add plans/ docs/plans/ terminal-frontend/docs/plans/
git commit -m "rebrand: update all implementation plans from Ruderbar to Club Bar"
```

---

## Task 13: Rebrand Remaining Files

**Files:**
- Modify: `backend/db/migrations/001_initial_schema.sql`
- Modify: `.claude/skills/` — any skill files referencing Ruderbar
- Modify: `terminal-frontend/lib/database/database.dart.bak` (if keeping)
- Delete or update: `terminal-frontend/lib/l10n/app_localizations*.dart` (auto-generated — will be regenerated)

**Step 1: Update migration SQL**

- `Ruderbar v1.0` → `Club Bar v1.0`
- `Ruderbar (Vereinsbar)` → `Club Bar`

**Step 2: Update any .claude skills that reference Ruderbar**

Check and replace brand references.

**Step 3: Clean up .bak file**

Either delete `database.dart.bak` or update it to match.

**Step 4: Regenerate Flutter localizations**

```bash
cd terminal-frontend && flutter gen-l10n
```

**Step 5: Commit**

```bash
git add .
git commit -m "rebrand: final cleanup — migrations, skills, generated files"
```

---

## Task 14: Update Auto-Memory

**Files:**
- Modify: `/Users/dg/.claude/projects/-Users-dg-dev-frgs-vereinsbar/memory/MEMORY.md`

**Step 1: Update project references in MEMORY.md**

Replace `Ruderbar` → `Club Bar` and `ruderbar` → `clubbar` where it appears in memory context (e.g., database commands, docker references).

**Step 2: No commit needed** (memory is outside repo)

---

## Task 15: Full Verification

**Step 1: Search for any remaining "Ruderbar" or "ruderbar" references**

```bash
grep -ri "ruderbar" --include="*.md" --include="*.ts" --include="*.tsx" --include="*.php" --include="*.dart" --include="*.yaml" --include="*.yml" --include="*.json" --include="*.sql" --include="*.sh" --include="*.py" --include="*.html" --include="*.txt" . | grep -v node_modules | grep -v vendor | grep -v .dart_tool | grep -v .worktrees | grep -v pubspec.lock | grep -v package-lock.json
```

**Step 2: Fix any remaining references found**

**Step 3: Rebuild and verify Docker environment**

```bash
docker compose down -v
docker compose up -d
sleep 5
curl http://localhost:8080/api/health
```

**Step 4: Run E2E tests**

```bash
cd e2etests && npm test -- --workers=4
```

**Step 5: Run Flutter tests**

```bash
cd terminal-frontend && flutter test
```

**Step 6: Final commit if anything was missed**

```bash
git add .
git commit -m "rebrand: fix remaining Ruderbar references found in final sweep"
```

---

## Out of Scope (noted for later)

- **Git repository name** (`frgs-vereinsbar`) — this is a GitHub/hosting concern, not a code change
- **`.idea/` project files** — IDE-specific, will auto-update
- **`pubspec.lock`** — auto-generated, updates when running `flutter pub get`
- **`package-lock.json`** — auto-generated, updates when running `npm install`
- **`vendor/`** — auto-generated by Composer
- **`.dart_tool/`** — auto-generated by Dart
- **Worktree copies in `.worktrees/`** — ephemeral, will be recreated
