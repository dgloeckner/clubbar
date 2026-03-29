# IONOS Integration Deployment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Automate deployment of the Club Bar package to IONOS shared hosting via SFTP, triggered by the existing CI pipeline on every push to `main` after tests pass.

**Architecture:** A self-destructing `deploy.php` script is uploaded with each deployment alongside a `.deploy-secret` file; after the SFTP sync completes the workflow calls `GET /deploy.php?key=<secret>`, which runs `MigrationRunner`, returns JSON, and deletes both files. The existing `build.yaml` gains a `deploy-integration` job that depends on the `test-package` job.

**Tech Stack:** PHP 8.3, `MigrationRunner` (existing), lftp (SFTP), GitHub Actions, Playwright (package smoke tests)

---

## File Map

| File | Change |
|------|--------|
| `package/deploy.php` | **Create** — self-destructing migration runner |
| `package/.htaccess` | **Modify** — add `.deploy-secret` HTTP block |
| `e2etests/tests/package/package-smoke.spec.ts` | **Modify** — add `Package: Deploy Runner` test suite |
| `.github/workflows/build.yaml` | **Modify** — add job output to `test-package`; add `deploy-integration` job; add write-deploy-secret step |

---

## Task 1: Write Failing Smoke Test for `deploy.php`

Add the test before the file exists so it fails red first (TDD).

**Files:**
- Modify: `e2etests/tests/package/package-smoke.spec.ts`
- Modify: `.github/workflows/build.yaml` (write-deploy-secret step in `test-package`)

- [ ] **Step 1.1: Add the failing test to `package-smoke.spec.ts`**

Append this block at the end of `e2etests/tests/package/package-smoke.spec.ts`, after the existing `Package: SPA serving` describe block:

```typescript
test.describe.serial('Package: Deploy Runner', () => {
  test.skip(!process.env.PACKAGE_TEST, 'Skipped unless PACKAGE_TEST=1');

  const CI_DEPLOY_SECRET = 'ci-deploy-secret-0000';

  test('deploy.php returns 403 with wrong key', async ({ request }) => {
    const response = await request.get(
      `${PACKAGE_URL}/deploy.php?key=wrong-key`
    );
    expect(response.status()).toBe(403);
    const body = await response.json();
    expect(body.ok).toBe(false);
  });

  test('deploy.php runs migrations and self-destructs', async ({ request }) => {
    const response = await request.get(
      `${PACKAGE_URL}/deploy.php?key=${CI_DEPLOY_SECRET}`
    );
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.ok).toBe(true);
    expect(Array.isArray(body.results)).toBe(true);
    const statuses = (body.results as Array<{ status: string }>).map(r => r.status);
    expect(statuses).not.toContain('FAIL');
    expect(statuses).toContain('DONE');

    // Script self-destructs — second call must return 404
    const second = await request.get(
      `${PACKAGE_URL}/deploy.php?key=${CI_DEPLOY_SECRET}`
    );
    expect(second.status()).toBe(404);
  });

  test('.deploy-secret is not accessible via HTTP', async ({ request }) => {
    const response = await request.get(`${PACKAGE_URL}/.deploy-secret`);
    expect(response.status()).toBe(403);
  });
});
```

- [ ] **Step 1.2: Add write-deploy-secret step to `test-package` job in `build.yaml`**

Find this existing step in the `test-package` job:

```yaml
      - name: Write installer data for package tests
        run: echo -n '{"key":"ci-package-install-key-0000","completed_step":0}' > dist/package/.installer-data
```

Add the following step immediately after it:

```yaml
      - name: Write deploy secret for package tests
        run: echo -n 'ci-deploy-secret-0000' > dist/package/.deploy-secret
```

- [ ] **Step 1.3: Verify the test fails as expected**

```bash
cd e2etests
PACKAGE_TEST=1 npx playwright test tests/package/package-smoke.spec.ts --project=package-tests --workers=1
```

Expected: `Package: Deploy Runner` tests fail with `404 Not Found` (deploy.php doesn't exist yet). Other tests pass unchanged.

- [ ] **Step 1.4: Commit**

```bash
git add e2etests/tests/package/package-smoke.spec.ts .github/workflows/build.yaml
git commit -m "test(package): add deploy runner smoke tests (red)"
```

---

## Task 2: Implement `package/deploy.php`

**Files:**
- Create: `package/deploy.php`

- [ ] **Step 2.1: Create `package/deploy.php`**

```php
<?php

declare(strict_types=1);

/**
 * Club Bar Deploy Runner
 *
 * Uploaded by CI on each deployment alongside a .deploy-secret file.
 * Runs pending database migrations, returns JSON, then self-destructs.
 *
 * Usage: GET /deploy.php?key=<IONOS_DEPLOY_SECRET>
 * Returns: {"ok": true, "results": [...]} or {"ok": false, "error": "..."}
 *
 * Security:
 * - hash_equals() prevents timing attacks on key comparison
 * - Returns 403 immediately if .deploy-secret is missing or key is wrong
 * - Registers shutdown cleanup ONLY after successful key validation
 * - Self-destructs unconditionally after validation succeeds
 */

header('Content-Type: application/json');

$secretFile = __DIR__ . '/.deploy-secret';
$configFile = __DIR__ . '/config.php';
$scriptPath = __FILE__;

// Validate key before doing anything else
$providedKey = (string) ($_GET['key'] ?? '');

if (!file_exists($secretFile)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No deploy secret on server.']);
    exit;
}

$storedKey = trim((string) file_get_contents($secretFile));

if ($storedKey === '' || !hash_equals($storedKey, $providedKey)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid deploy key.']);
    exit;
}

// Key is valid — schedule cleanup of both files regardless of what happens next
register_shutdown_function(function () use ($secretFile, $scriptPath): void {
    @unlink($secretFile);
    @unlink($scriptPath);
});

// Load config
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'config.php not found. Run the installer first.']);
    exit;
}

$config = require $configFile;

// Load autoloader
$autoload = __DIR__ . '/backend/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'backend/vendor/autoload.php not found.']);
    exit;
}

require $autoload;

// Run migrations
try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['db']['host'],
            (int) ($config['db']['port'] ?? 3306),
            $config['db']['name']
        ),
        $config['db']['user'],
        $config['db']['pass'],
        [
            PDO::ATTR_ERRMODE        => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $runner  = new \App\Db\MigrationRunner($pdo);
    $results = $runner->migrate(__DIR__ . '/backend/db/migrations', 'deploy');

    $failed = array_filter($results, fn($r) => ($r['status'] ?? '') === 'FAIL');

    if ($failed) {
        http_response_code(500);
        echo json_encode([
            'ok'      => false,
            'error'   => 'Migration failed.',
            'results' => array_values($results),
        ]);
        exit;
    }

    echo json_encode(['ok' => true, 'results' => array_values($results)]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
```

- [ ] **Step 2.2: Run the package smoke tests locally to verify they pass**

First, build the package and start it locally:

```bash
./scripts/build-package.sh test
# Write test secrets
echo -n '{"key":"ci-package-install-key-0000","completed_step":0}' > dist/package/.installer-data
echo -n 'ci-deploy-secret-0000' > dist/package/.deploy-secret
# Start the package environment
docker compose -f docker-compose.yml -f docker-compose.ci.yml -f docker-compose.package.yml up -d database backend
# Wait for it
for i in $(seq 1 20); do curl -sf http://localhost:8080/install.php > /dev/null 2>&1 && break; sleep 2; done
```

Then run the tests:

```bash
cd e2etests
PACKAGE_TEST=1 npx playwright test tests/package/package-smoke.spec.ts --project=package-tests --workers=1
```

Expected output: All tests pass including the new `Package: Deploy Runner` suite.

Clean up:

```bash
docker compose -f docker-compose.yml -f docker-compose.ci.yml -f docker-compose.package.yml down
```

- [ ] **Step 2.3: Commit**

```bash
git add package/deploy.php
git commit -m "feat(deploy): add self-destructing deploy.php migration runner"
```

---

## Task 3: Update `package/.htaccess`

Block HTTP access to `.deploy-secret`.

**Files:**
- Modify: `package/.htaccess`

- [ ] **Step 3.1: Add the `.deploy-secret` block to `.htaccess`**

Find this existing block in `package/.htaccess`:

```apache
# Block direct HTTP access to the install key file
<Files ".installer-data">
    Require all denied
</Files>
```

Add immediately after it:

```apache
<Files ".deploy-secret">
    Require all denied
</Files>
```

- [ ] **Step 3.2: Rebuild the package and verify the block works**

```bash
./scripts/build-package.sh test
echo -n '{"key":"ci-package-install-key-0000","completed_step":0}' > dist/package/.installer-data
echo -n 'ci-deploy-secret-0000' > dist/package/.deploy-secret
docker compose -f docker-compose.yml -f docker-compose.ci.yml -f docker-compose.package.yml up -d database backend
for i in $(seq 1 20); do curl -sf http://localhost:8080/install.php > /dev/null 2>&1 && break; sleep 2; done
```

Verify the block:

```bash
curl -v http://localhost:8080/.deploy-secret
```

Expected: `403 Forbidden`

Run the smoke tests to confirm nothing broke:

```bash
cd e2etests
PACKAGE_TEST=1 npx playwright test tests/package/package-smoke.spec.ts --project=package-tests --workers=1
```

Expected: all tests pass (`.deploy-secret HTTP block` test also passes now).

Clean up:

```bash
docker compose -f docker-compose.yml -f docker-compose.ci.yml -f docker-compose.package.yml down
```

- [ ] **Step 3.3: Commit**

```bash
git add package/.htaccess
git commit -m "security(package): block HTTP access to .deploy-secret"
```

---

## Task 4: Update `build.yaml` — `test-package` Job Output + `deploy-integration` Job

**Files:**
- Modify: `.github/workflows/build.yaml`

- [ ] **Step 4.1: Add `outputs:` to the `test-package` job**

Find the `test-package` job definition:

```yaml
  test-package:
    name: Build & Test Package
    needs: [test-e2e]
    runs-on: ubuntu-24.04
    timeout-minutes: 20
```

Replace with:

```yaml
  test-package:
    name: Build & Test Package
    needs: [test-e2e]
    runs-on: ubuntu-24.04
    timeout-minutes: 20
    outputs:
      artifact: ${{ steps.package.outputs.artifact }}
```

- [ ] **Step 4.2: Add the `deploy-integration` job at the end of `build.yaml`**

Append after the `build-terminal` job (at the very end of the file):

```yaml
  deploy-integration:
    name: Deploy to Integration
    needs: [test-package]
    if: github.ref == 'refs/heads/main'
    runs-on: ubuntu-24.04

    steps:
      - name: Download package artifact
        uses: actions/download-artifact@v4
        with:
          name: ${{ needs.test-package.outputs.artifact }}

      - name: Unzip package
        run: unzip "${{ needs.test-package.outputs.artifact }}" -d dist/package/

      - name: Write deploy secret
        run: echo -n "${{ secrets.IONOS_DEPLOY_SECRET }}" > dist/package/.deploy-secret

      - name: Install lftp
        run: sudo apt-get install -y lftp

      - name: Upload via SFTP
        run: |
          lftp -u "acc1206117549,${{ secrets.IONOS_PASSWORD }}" \
            sftp://home277820902.1and1-data.host:22 << 'LFTP'
          set sftp:auto-confirm yes
          mirror --reverse --delete --verbose \
            --exclude '^config\.php$' \
            --exclude '^\.installer-data$' \
            --exclude '^backend/storage' \
            --exclude '^backend/logs' \
            --exclude '^python_libs' \
            dist/package/ /
          bye
          LFTP

      - name: Run migrations
        run: |
          RESPONSE=$(curl -sf "${{ secrets.IONOS_SITE_URL }}/deploy.php?key=${{ secrets.IONOS_DEPLOY_SECRET }}")
          echo "$RESPONSE" | jq .
          [ "$(echo "$RESPONSE" | jq -r '.ok')" = "true" ]
```

- [ ] **Step 4.3: Validate the YAML syntax**

```bash
python3 -c "import yaml, sys; yaml.safe_load(open('.github/workflows/build.yaml'))" && echo "YAML OK"
```

Expected: `YAML OK`

- [ ] **Step 4.4: Verify the job only triggers on `main`**

Check the `if:` condition is present and correct:

```bash
grep -A3 'deploy-integration:' .github/workflows/build.yaml
```

Expected output includes `if: github.ref == 'refs/heads/main'`.

- [ ] **Step 4.5: Commit**

```bash
git add .github/workflows/build.yaml
git commit -m "ci: add deploy-integration job — SFTP upload + migration trigger on main"
```

---

## Task 5: Update `plans/INDEX.md`

- [ ] **Step 5.1: Add this plan to INDEX.md**

Add to the "Current Plan" table in `plans/INDEX.md`:

```markdown
| [IONOS Integration Deployment](../docs/superpowers/plans/2026-03-29-ionos-deployment.md) | Completed | Self-destructing deploy.php migration runner + deploy-integration CI job |
```

- [ ] **Step 5.2: Commit**

```bash
git add plans/INDEX.md
git commit -m "docs: mark IONOS deployment plan as completed in INDEX.md"
```

---

## Verification Checklist

After all tasks are complete, verify end-to-end:

- [ ] Push to a feature branch — confirm `deploy-integration` job does **not** appear in the Actions run (the `if: github.ref == 'refs/heads/main'` guard works)
- [ ] Merge to `main` — confirm `deploy-integration` job runs after `test-package` succeeds
- [ ] Check Actions log for `Upload via SFTP` step — confirm files listed, `config.php` not in the list
- [ ] Check Actions log for `Run migrations` step — confirm `{"ok":true,...}` response
- [ ] Open `$IONOS_SITE_URL` in browser — confirm site loads and API health check passes:
  ```bash
  curl https://<site>/api/health | jq .
  ```
  Expected: `{"status":"ok"}`

---

## Notes

- **`python_libs`**: A directory present on the server but not in the package. Excluded from `--delete` to prevent accidental removal. Its purpose is unknown — investigate separately if needed.
- **No rollback**: If the migration step fails, the new files are already on the server. Manual intervention required — restore from a database backup taken before the deploy.
- **No atomic swap**: SFTP uploads files one by one. A brief inconsistency window exists during upload. Acceptable for an integration environment; revisit if this becomes a production deployment target.
- **DB secrets in GitHub**: `IONOS_DB_*` secrets are configured but unused in this workflow — `deploy.php` reads `config.php` directly from the server.
