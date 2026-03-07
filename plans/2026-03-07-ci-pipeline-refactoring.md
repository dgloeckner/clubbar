# CI Pipeline Refactoring Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Restructure the GitHub Actions CI pipeline to eliminate duplication, add proper job dependencies, fix silent failures, add caching, and gate release uploads behind passing tests.

**Architecture:** Refactor the single `build.yaml` workflow into a dependency graph of jobs that share artifacts. Introduce composite actions for repeated setup sequences. Add a fast lint gate. Fix health check bugs. Cache Composer and Flutter SDK.

**Tech Stack:** GitHub Actions, Composer, Node.js/npm, Playwright, Flutter, Docker Compose

---

## Current State

Single workflow file `.github/workflows/build.yaml` with 4 independent parallel jobs:
- `test-backend` — PHPUnit Unit tests only
- `test-e2e` — Full stack E2E (Playwright against Docker services + Vite preview)
- `test-package` — Build package ZIP, upload, then smoke test
- `build-terminal` — Flutter Linux ARM64 build + tests

**Problems:** No job dependencies, duplicated setup (~10 steps shared between `test-e2e` and `test-package`), no Composer cache, Flutter cloned fresh each run, admin frontend built twice, health check missing `exit 1`, release artifacts uploaded before smoke tests pass.

---

## Task 1: Fix health check exit guard in `test-e2e`

**Files:**
- Modify: `.github/workflows/build.yaml:79-84`

**Step 1: Add `exit 1` to the backend health loop**

The `test-package` job (line 207) already has the correct pattern. Apply the same to `test-e2e`.

Change lines 79-84 from:
```yaml
      - name: Wait for backend health
        run: |
          for i in $(seq 1 30); do
            curl -sf http://localhost:8080/api/health && break
            sleep 2
          done
```

To:
```yaml
      - name: Wait for backend health
        run: |
          for i in $(seq 1 30); do
            curl -sf http://localhost:8080/api/health && break
            if [ $i -eq 30 ]; then echo "Backend not ready after 60s" && exit 1; fi
            sleep 2
          done
```

**Step 2: Commit**

```bash
git add .github/workflows/build.yaml
git commit -m "fix(ci): add exit guard to test-e2e health check loop"
```

---

## Task 2: Add Composer cache to all PHP jobs

**Files:**
- Modify: `.github/workflows/build.yaml` (jobs: `test-backend`, `test-e2e`, `test-package`)

**Step 1: Add cache step after PHP setup in `test-backend`**

Insert after line 26 (after `Setup PHP` step), before `Install dependencies`:

```yaml
      - name: Cache Composer packages
        uses: actions/cache@v4
        with:
          path: ~/.composer/cache
          key: composer-${{ hashFiles('backend/composer.lock') }}
          restore-keys: composer-
```

**Step 2: Add same cache step to `test-e2e`**

Insert after line 47 (after `Setup PHP`), before `Setup Node.js`:

```yaml
      - name: Cache Composer packages
        uses: actions/cache@v4
        with:
          path: ~/.composer/cache
          key: composer-${{ hashFiles('backend/composer.lock') }}
          restore-keys: composer-
```

**Step 3: Add same cache step to `test-package`**

Insert after line 153 (after `Setup PHP`), before `Setup Node.js`:

```yaml
      - name: Cache Composer packages
        uses: actions/cache@v4
        with:
          path: ~/.composer/cache
          key: composer-no-dev-${{ hashFiles('backend/composer.lock') }}
          restore-keys: composer-no-dev-
```

Note: `test-package` uses `--no-dev`, so it gets a separate cache key prefix (`composer-no-dev-`).

**Step 4: Commit**

```bash
git add .github/workflows/build.yaml
git commit -m "perf(ci): add Composer dependency caching to all PHP jobs"
```

---

## Task 3: Use `subosito/flutter-action` for Flutter SDK caching

**Files:**
- Modify: `.github/workflows/build.yaml` (job: `build-terminal`, lines 262-285)

**Step 1: Replace manual Flutter clone with `subosito/flutter-action`**

Replace the Flutter setup steps (lines 262-285):

```yaml
      - name: Install Flutter dependencies
        run: |
          sudo apt-get update
          sudo apt-get install -y \
            clang cmake ninja-build pkg-config \
            libgtk-3-dev liblzma-dev libstdc++-12-dev \
            libgstreamer1.0-dev libgstreamer-plugins-base1.0-dev \
            xvfb

      - name: Setup Flutter
        run: |
          git clone https://github.com/flutter/flutter.git --depth 1 -b stable ~/flutter
          echo "$HOME/flutter/bin" >> $GITHUB_PATH

      - name: Verify Flutter
        run: flutter doctor -v

      - name: Enable Linux desktop
        run: flutter config --enable-linux-desktop
        working-directory: terminal-frontend

      - name: Get dependencies
        run: flutter pub get
        working-directory: terminal-frontend
```

With:

```yaml
      - name: Install Linux build dependencies
        run: |
          sudo apt-get update
          sudo apt-get install -y \
            clang cmake ninja-build pkg-config \
            libgtk-3-dev liblzma-dev libstdc++-12-dev \
            libgstreamer1.0-dev libgstreamer-plugins-base1.0-dev \
            xvfb

      - name: Setup Flutter
        uses: subosito/flutter-action@v2
        with:
          channel: stable
          cache: true

      - name: Enable Linux desktop
        run: flutter config --enable-linux-desktop
        working-directory: terminal-frontend

      - name: Cache pub dependencies
        uses: actions/cache@v4
        with:
          path: ~/.pub-cache
          key: pub-${{ hashFiles('terminal-frontend/pubspec.lock') }}
          restore-keys: pub-

      - name: Get dependencies
        run: flutter pub get
        working-directory: terminal-frontend
```

**Step 2: Commit**

```bash
git add .github/workflows/build.yaml
git commit -m "perf(ci): cache Flutter SDK and pub dependencies in build-terminal"
```

---

## Task 4: Add lint/type-check job as fast gate

**Files:**
- Modify: `.github/workflows/build.yaml` (add new job `lint`)

**Step 1: Add `lint` job at the top of `jobs:`**

Insert after `jobs:` (line 15), before `test-backend`:

```yaml
  lint:
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'
          cache-dependency-path: admin-frontend/package-lock.json

      - name: Install dependencies
        run: npm ci
        working-directory: admin-frontend

      - name: Type check
        run: npm run type-check
        working-directory: admin-frontend

      - name: Lint
        run: npm run lint
        working-directory: admin-frontend
```

**Step 2: Commit**

```bash
git add .github/workflows/build.yaml
git commit -m "feat(ci): add lint and type-check job for admin frontend"
```

---

## Task 5: Add job dependency graph with `needs:`

**Files:**
- Modify: `.github/workflows/build.yaml` (all job definitions)

This is the most impactful structural change. The target dependency graph:

```
lint ──────────────┐
test-backend ──────┼──→ test-e2e ──→ test-package
                   │
                   └──→ build-terminal
```

- `test-e2e` requires `lint` and `test-backend` to pass first
- `test-package` requires `test-e2e` to pass (no point smoke-testing a package if E2E fails)
- `build-terminal` requires `lint` and `test-backend` (independent of E2E/package but gated by basic checks)

**Step 1: Add `needs:` to `test-e2e`**

After the `runs-on:` line in `test-e2e`, add:

```yaml
  test-e2e:
    needs: [lint, test-backend]
    runs-on: ubuntu-24.04
    timeout-minutes: 45
```

**Step 2: Add `needs:` to `test-package`**

```yaml
  test-package:
    name: Build & Test Package
    needs: [test-e2e]
    runs-on: ubuntu-24.04
    timeout-minutes: 20
```

**Step 3: Add `needs:` to `build-terminal`**

```yaml
  build-terminal:
    needs: [lint, test-backend]
    runs-on: ubuntu-24.04-arm
```

**Step 4: Commit**

```bash
git add .github/workflows/build.yaml
git commit -m "feat(ci): add job dependency graph to gate releases behind tests"
```

---

## Task 6: Move release upload after smoke tests in `test-package`

**Files:**
- Modify: `.github/workflows/build.yaml` (job: `test-package`, lines 225-254)

**Step 1: Reorder steps — smoke tests before release upload**

Move the "Run package smoke tests" step (currently lines 241-246) to come before "Create package ZIP" (line 225). The new order should be:

1. Run package smoke tests (must pass first)
2. Create package ZIP
3. Upload package artifact
4. Upload to release

Reordered section:

```yaml
      - name: Run package smoke tests
        run: npx playwright test --project=package-tests --workers=1
        working-directory: e2etests
        env:
          CI: true
          PACKAGE_TEST: '1'

      - name: Create package ZIP
        run: cd dist && zip -r clubbar-package.zip package/ -q

      - name: Upload package artifact
        uses: actions/upload-artifact@v4
        with:
          name: clubbar-package
          path: dist/clubbar-package.zip
          retention-days: 30

      - name: Upload to release
        if: github.event_name == 'release'
        uses: softprops/action-gh-release@v2
        with:
          files: dist/clubbar-package.zip

      - name: Upload test results
        if: always()
        uses: actions/upload-artifact@v4
        with:
          name: package-test-results
          path: e2etests/test-results/
          retention-days: 14
```

**Step 2: Commit**

```bash
git add .github/workflows/build.yaml
git commit -m "fix(ci): run smoke tests before release upload in test-package"
```

---

## Task 7: Fix Playwright cache key collision

**Files:**
- Modify: `.github/workflows/build.yaml` (cache keys in `test-e2e` and `test-package`)

**Step 1: Use distinct cache keys**

In `test-e2e` (line 111), change:
```yaml
          key: playwright-chromium-${{ hashFiles('e2etests/package-lock.json') }}
```
To:
```yaml
          key: playwright-chromium-webkit-${{ hashFiles('e2etests/package-lock.json') }}
```

Leave `test-package` (line 219) unchanged — it already says `playwright-chromium-*` and installs only chromium.

**Step 2: Commit**

```bash
git add .github/workflows/build.yaml
git commit -m "fix(ci): use distinct Playwright cache keys for chromium vs chromium+webkit"
```

---

## Task 8: Exclude non-applicable Playwright projects in `test-e2e`

**Files:**
- Modify: `.github/workflows/build.yaml` (job: `test-e2e`, line 118)

Currently `npx playwright test` runs ALL projects including `terminal-touch` (no server at port 5174) and `package-tests` (skips via `test.skip` but still starts the project). Explicitly list the projects to run.

**Step 1: Add `--project` filters to the Playwright command**

Change line 118 from:
```yaml
        run: npx playwright test
```
To:
```yaml
        run: npx playwright test --project=api-tests --project=admin-chromium --project=admin-mobile
```

**Step 2: Commit**

```bash
git add .github/workflows/build.yaml
git commit -m "fix(ci): explicitly select Playwright projects in test-e2e, skip terminal/package"
```

---

## Task 9: Build admin frontend once and share as artifact

**Files:**
- Modify: `.github/workflows/build.yaml` (add `build-frontend` job, modify `test-e2e` and `test-package`)

**Step 1: Create `build-frontend` job**

Add after the `lint` job:

```yaml
  build-frontend:
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'
          cache-dependency-path: admin-frontend/package-lock.json

      - name: Install and build
        run: npm ci && npm run build
        working-directory: admin-frontend

      - name: Upload frontend build
        uses: actions/upload-artifact@v4
        with:
          name: admin-frontend-dist
          path: admin-frontend/dist/
          retention-days: 1
```

**Step 2: Update `test-e2e` — download artifact instead of building**

Remove the "Build admin frontend" step (line 65-67):
```yaml
      - name: Build admin frontend
        run: npm ci && npm run build
        working-directory: admin-frontend
```

Replace with:
```yaml
      - name: Download admin frontend build
        uses: actions/download-artifact@v4
        with:
          name: admin-frontend-dist
          path: admin-frontend/dist/
```

Also remove the `Setup Node.js` step from `test-e2e` (lines 49-56) since Node.js is only needed for `e2etests` now, not for building the frontend. Actually — Node.js is still needed for `npm ci` in `e2etests/` and for `npx vite preview` and `npx playwright`. Keep `Setup Node.js` but simplify the cache path to only `e2etests/package-lock.json`:

```yaml
      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'
          cache-dependency-path: e2etests/package-lock.json
```

Update `needs:` for `test-e2e`:
```yaml
    needs: [lint, test-backend, build-frontend]
```

**Step 3: Update `test-package` — download artifact instead of building**

Remove the "Build admin frontend" step (lines 168-169) and the admin-frontend cache-dependency-path from `Setup Node.js`.

Replace with download:
```yaml
      - name: Download admin frontend build
        uses: actions/download-artifact@v4
        with:
          name: admin-frontend-dist
          path: admin-frontend/dist/
```

Update `needs:` for `test-package` (already depends on `test-e2e` which depends on `build-frontend`, so transitive — but adding explicitly is clearer):
```yaml
    needs: [test-e2e]
```
(This already transitively requires `build-frontend`.)

**Step 4: Install `vite` for preview in `test-e2e`**

Since we no longer run `npm ci` in `admin-frontend/`, the `npx vite preview` step will fail (no `node_modules/`). Install vite globally or install just admin-frontend deps before preview:

```yaml
      - name: Install admin frontend deps (for vite preview)
        run: npm ci
        working-directory: admin-frontend
```

Add this before the "Serve admin frontend" step. This is still faster than building (skips `tsc && vite build`).

**Step 5: Commit**

```bash
git add .github/workflows/build.yaml
git commit -m "perf(ci): build admin frontend once, share via artifact"
```

---

## Task 10: Final validation — review complete workflow

**Step 1: Read the final `build.yaml` and verify:**

- [ ] Job dependency graph: `lint` → `test-e2e`, `test-backend` → `test-e2e`, `build-frontend` → `test-e2e` → `test-package`
- [ ] `build-terminal` depends on `lint` and `test-backend`
- [ ] All health check loops have `exit 1` guards
- [ ] Composer cache in all PHP jobs
- [ ] Flutter SDK cached via `subosito/flutter-action`
- [ ] Pub cache cached
- [ ] Admin frontend built once in `build-frontend`, downloaded in `test-e2e` and `test-package`
- [ ] Playwright cache keys are distinct
- [ ] `test-e2e` explicitly selects projects (no `terminal-touch`, no `package-tests`)
- [ ] `test-package` runs smoke tests before release upload
- [ ] Lint + type-check runs as fast gate

**Step 2: Commit final state if any adjustments needed**

```bash
git add .github/workflows/build.yaml
git commit -m "chore(ci): finalize CI pipeline refactoring"
```

---

## Final Job Dependency Graph

```
  lint ────────────────────┐
  test-backend ────────────┼──→ test-e2e ──→ test-package
  build-frontend ──────────┘
                           │
  lint ────────────────────┼──→ build-terminal
  test-backend ────────────┘
```

**Estimated CI time (sequential path):**
- `lint` (~1 min) → `test-e2e` (~5 min) → `test-package` (~5 min) = ~11 min total
- `build-terminal` runs in parallel with `test-e2e`: ~8 min
- **Before:** All 4 jobs ran in parallel (~10 min) but with no safety guarantees
- **After:** Slightly longer wall time but release artifacts are only published after all tests pass

## Out of Scope (Future Work)

These were identified but intentionally excluded from this plan:
- **PHPUnit Feature suite in CI** — requires database container in `test-backend`, larger scope change
- **Composite actions** — further DRY refactoring of shared setup steps; diminishing returns after artifact sharing
- **Security scanning / dependency audit** — separate concern, separate plan
- **Vite preview process management** — low impact since the `curl` wait loop catches failures
