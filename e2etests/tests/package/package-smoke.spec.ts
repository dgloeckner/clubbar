import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Package smoke tests -- verify the shared hosting package works end-to-end.
 *
 * These tests run against the assembled package served via docker-compose.package.yml.
 * The install wizard must be completed before API/SPA tests run.
 *
 * Run: PACKAGE_TEST=1 npm test -- tests/package/package-smoke.spec.ts --workers=1
 *
 * CI writes a known key to dist/package/.install-key before these tests run.
 * Each test that drives the wizard must enter the key first to obtain a session cookie.
 */

const PACKAGE_URL = process.env.PACKAGE_URL || 'http://localhost:8080';
const CI_INSTALL_KEY = 'ci-package-install-key-0000';
const CI_DEPLOY_SECRET = 'ci-deploy-secret-0000';

test.describe('Package: Install Wizard', () => {
  test.skip(!process.env.PACKAGE_TEST, 'Skipped unless PACKAGE_TEST=1');

  test('install.php requires install key when not authenticated', async ({ request }) => {
    const response = await request.get(`${PACKAGE_URL}/install.php`);
    expect(response.ok()).toBeTruthy();
    const html = await response.text();
    expect(html).toContain('Install Key Required');
    expect(html).toContain('.installer-data');
  });

  test('install wizard completes via POST steps', async ({ request }) => {
    // Enter install key — sets session cookie on this request context
    const keyResponse = await request.post(`${PACKAGE_URL}/install.php`, {
      form: { install_key: CI_INSTALL_KEY },
    });
    expect(keyResponse.ok()).toBeTruthy();
    const html = await keyResponse.text();
    expect(html).toContain('Prerequisites');

    // Step 2: DB credentials
    const step2 = await request.post(`${PACKAGE_URL}/install.php?step=2`, {
      form: {
        step: '2',
        db_host: 'database',
        db_port: '3306',
        db_name: 'clubbar',
        db_user: 'clubbar',
        db_pass: 'clubbar',
      },
      maxRedirects: 0,
    });
    expect(step2.status()).toBe(302);
    expect(step2.headers()['location']).toContain('step=3');

    // Step 3: Migrations
    const step3 = await request.post(`${PACKAGE_URL}/install.php?step=3`, {
      form: { step: '3' },
      maxRedirects: 0,
    });
    expect(step3.status()).toBe(302);
    expect(step3.headers()['location']).toContain('step=4');

    // Step 4: Admin user
    const step4 = await request.post(`${PACKAGE_URL}/install.php?step=4`, {
      form: {
        step: '4',
        admin_email: 'admin@example.com',
        admin_password: 'password123',
        admin_password_confirm: 'password123',
      },
      maxRedirects: 0,
    });
    expect(step4.status()).toBe(302);
    expect(step4.headers()['location']).toContain('step=5');
  });
});

test.describe('Package: API through front controller', () => {
  test.skip(!process.env.PACKAGE_TEST, 'Skipped unless PACKAGE_TEST=1');

  test('GET /api/health returns ok', async ({ request }) => {
    const response = await request.get(`${PACKAGE_URL}/api/health`);
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.status).toBe('ok');
  });

  test('POST /api/auth/login works through front controller', async ({ request }) => {
    const response = await request.post(`${PACKAGE_URL}/api/auth/login`, {
      data: {
        email: 'admin@example.com',
        password: 'password123',
      },
    });
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    // Fresh install: admin has no TOTP enrolled yet, so login triggers setup flow
    expect(body.requiresTotpSetup).toBe(true);
  });
});

test.describe('Package: SPA serving', () => {
  test.skip(!process.env.PACKAGE_TEST, 'Skipped unless PACKAGE_TEST=1');

  test('root URL serves SPA index.html', async ({ request }) => {
    const response = await request.get(`${PACKAGE_URL}/`);
    expect(response.ok()).toBeTruthy();
    const html = await response.text();
    expect(html).toContain('<div id="root">');
  });

  test('unknown route serves SPA (client-side routing)', async ({ request }) => {
    const response = await request.get(`${PACKAGE_URL}/members`);
    expect(response.ok()).toBeTruthy();
    const html = await response.text();
    expect(html).toContain('<div id="root">');
  });
});

test.describe.serial('Package: Upgrade Runner', () => {
  test.skip(!process.env.PACKAGE_TEST, 'Skipped unless PACKAGE_TEST=1');

  test('upgrade.php returns 403 with wrong key', async ({ request }) => {
    const response = await request.get(
      `${PACKAGE_URL}/upgrade.php?key=wrong-key`
    );
    expect(response.status()).toBe(403);
    const body = await response.json();
    expect(body.ok).toBe(false);
  });

  test('upgrade.php runs migrations successfully', async ({ request }) => {
    const response = await request.get(
      `${PACKAGE_URL}/upgrade.php?key=${CI_DEPLOY_SECRET}`
    );
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.ok).toBe(true);
    expect(Array.isArray(body.results)).toBe(true);
    const statuses = (body.results as Array<{ status: string }>).map(r => r.status);
    expect(statuses).not.toContain('FAIL');
    // At least one migration must have run or been skipped on a fresh CI DB
    expect(statuses).toContain('DONE');
  });

  test('upgrade.php self-destructs after use', async ({ request }) => {
    // upgrade.php was called in the previous test — must be gone now.
    // .htaccess catch-all serves the SPA (200 HTML) for missing files, so we
    // cannot assert a specific status code. Instead verify the response is not
    // a JSON upgrade-script response, which proves upgrade.php no longer runs.
    const response = await request.get(
      `${PACKAGE_URL}/upgrade.php?key=${CI_DEPLOY_SECRET}`
    );
    const contentType = response.headers()['content-type'] ?? '';
    expect(contentType).not.toContain('application/json');
  });

  test('.upgrade-secret is not accessible via HTTP', async ({ request }) => {
    const response = await request.get(`${PACKAGE_URL}/.upgrade-secret`);
    expect(response.status()).toBe(403);
  });
});

/**
 * ADR-0031 decision 1: the PHP runtime settings the deployment depends on are
 * applied by the application, so they hold on a host whose php.ini we never
 * see. This block measures the shipped package rather than the source tree —
 * "did we regress the package?" is the question the smoke suite exists to
 * answer, and a build that dropped `backend/src/Shared/Security` would still
 * pass every unit test.
 *
 * Runs last on purpose: it drives PHP inside the container as the web user, so
 * anything it creates on disk (the session directory) is created the way a
 * request would have created it, after the tests that use sessions have run.
 */
test.describe('Package: Runtime hardening', () => {
  test.skip(!process.env.PACKAGE_TEST, 'Skipped unless PACKAGE_TEST=1');

  const REPO_ROOT = path.resolve(__dirname, '../../..');
  const COMPOSE_FILES = ['-f', 'docker-compose.yml', '-f', 'docker-compose.package.yml'];

  /**
   * The only hardening measure a caller can observe from outside, and the one
   * that has no other layer behind it: `expose_php` is PHP_INI_SYSTEM, so no
   * file the package ships can switch it off on shared hosting.
   */
  test('no response advertises the PHP version', async ({ request }) => {
    for (const url of [`${PACKAGE_URL}/api/health`, `${PACKAGE_URL}/`]) {
      const response = await request.get(url);
      expect(response.headers()['x-powered-by'], `X-Powered-By on ${url}`).toBeUndefined();
    }
  });

  // No docker guard: PACKAGE_TEST=1 already means the package is being served
  // by the compose stack this reaches into. If docker were missing, nothing in
  // this file could have run.
  test('the packaged application applies its runtime directives', async () => {
    const effective = measurePackagedRuntime();

    // Read back with ini_get() — the effective value, not the intent.
    expect(effective['display_errors']).toBe('0');
    expect(effective['log_errors']).toBe('1');
    expect(effective['zend.exception_ignore_args']).toBe('1');
    expect(effective['session.use_strict_mode']).toBe('1');
    expect(effective['session.use_only_cookies']).toBe('1');
    expect(effective['session.use_trans_sid']).toBe('0');
    // Session files belong to the installation, not to whatever directory the
    // host shares between its accounts.
    expect(effective['session.save_path']).toBe('/app/backend/storage/sessions');
    expect(effective['warnings']).toEqual([]);
  });

  /**
   * Applies the hardening inside the running package container and reports what
   * PHP ended up with. Run as uid 1000, the uid the web server runs the
   * application as, so the session directory it may create is owned the way a
   * real request would own it.
   */
  function measurePackagedRuntime(): Record<string, string | string[]> {
    const script = [
      'require "/app/backend/vendor/autoload.php";',
      '$warnings = App\\Shared\\Security\\RuntimeHardening::apply(new App\\Shared\\Config\\AppConfig(), false);',
      '$keys = ["display_errors", "log_errors", "zend.exception_ignore_args", "session.use_strict_mode",',
      '  "session.use_only_cookies", "session.use_trans_sid", "session.save_path"];',
      '$out = ["warnings" => $warnings];',
      'foreach ($keys as $key) { $out[$key] = ini_get($key); }',
      'echo json_encode($out);',
    ].join('\n');

    const output = execFileSync(
      'docker',
      ['compose', ...COMPOSE_FILES, 'exec', '-T', '-u', '1000', 'backend', 'php', '-r', script],
      { cwd: REPO_ROOT, encoding: 'utf8' }
    );

    return JSON.parse(output.trim());
  }
});

/**
 * ADR-0031 decision 2: a scanned SEPA mandate — a name, an IBAN and a
 * handwritten signature, named after the member UUID the admin API already
 * hands to the browser — must not be retrievable over HTTP.
 *
 * Both layouts are exercised because both ship. In the document root the only
 * thing standing between that PDF and a URL is an `.htaccess` rule, so the test
 * asks the webserver. Relocated, the stronger claim holds and is the one worth
 * asserting: the file is not under the document root at all, so a host that
 * stopped honouring `.htaccess` tomorrow would have nothing to serve.
 *
 * Runs last: it moves the installation's data around and puts it back.
 */
test.describe.serial('Package: Data placement', () => {
  test.skip(!process.env.PACKAGE_TEST, 'Skipped unless PACKAGE_TEST=1');

  const REPO_ROOT = path.resolve(__dirname, '../../..');
  const COMPOSE_FILES = ['-f', 'docker-compose.yml', '-f', 'docker-compose.package.yml'];
  const DOCUMENT_ROOT = '/app';
  const RELOCATED = '/tmp/clubbar-data';
  // Shaped like the real thing: the filename is the member UUID, which is
  // exactly why guessing it is not a defence.
  const MEMBER_ID = '11111111-2222-3333-4444-555555555555';
  const MANDATE_URL = `${PACKAGE_URL}/backend/storage/mandates/${MEMBER_ID}.pdf`;

  /**
   * The installer does not take the `.htaccess` denial on trust either: it
   * writes a file into the storage directory, fetches it over HTTP and reports
   * what came back. This asserts that verification passes against the package
   * as built — if a future change broke the rewrite rule, the wizard would say
   * so on the prerequisites screen and so would this.
   */
  test('the installer verifies over HTTP that storage is not served', async ({ request }) => {
    const key = 'exposure-check-key-0000';
    fs.writeFileSync(
      path.join(REPO_ROOT, 'dist/package/.installer-data'),
      JSON.stringify({ key, completed_step: 0 })
    );

    await request.post(`${PACKAGE_URL}/install.php`, { form: { install_key: key } });
    const page = await request.get(`${PACKAGE_URL}/install.php?step=1`);
    const html = await page.text();

    expect(html).toContain('Not served: backend/storage/');
    expect(html, 'the installer could not confirm the denial holds').toContain('refused (HTTP 403)');
  });

  test('a mandate inside the document root is refused by the webserver', async ({ request }) => {
    inContainer([
      `@mkdir("${DOCUMENT_ROOT}/backend/storage/mandates", 0700, true);`,
      `file_put_contents("${DOCUMENT_ROOT}/backend/storage/mandates/${MEMBER_ID}.pdf", "%PDF-1.4 signature");`,
    ]);

    const response = await request.get(MANDATE_URL);

    expect(response.status()).toBe(403);
    expect(await response.text()).not.toContain('%PDF');
  });

  test('the logs are refused too, and config.php never returns its contents', async ({ request }) => {
    const logs = await request.get(`${PACKAGE_URL}/backend/logs/`);
    expect(logs.status()).toBe(403);

    // config.php sits next to index.php in this layout, so what protects it is
    // that PHP files are executed rather than served. It returns an array and
    // prints nothing — but that protection is exactly the one the relocated
    // layout below stops depending on.
    const config = await request.get(`${PACKAGE_URL}/config.php`);
    // Strings that exist only in the config file's source, so this cannot pass
    // by accident on a page that merely mentions passwords.
    expect(await config.text()).not.toContain('totp_encryption_key');
  });

  test('relocating takes the mandate out of the document root entirely', async ({ request }) => {
    const moved = inContainer([
      'require "/app/backend/vendor/autoload.php";',
      `$r = App\\Shared\\Config\\DataDirectory::relocate("${DOCUMENT_ROOT}", "${RELOCATED}");`,
      'echo json_encode($r);',
    ]);
    expect(JSON.parse(moved).ok, `relocate failed: ${moved}`).toBe(true);

    // The application still boots, which is what proves the front controller
    // followed the pointer rather than the old path.
    const health = await request.get(`${PACKAGE_URL}/api/health`);
    expect(health.ok()).toBeTruthy();

    // The claim decision 2 is actually about: nothing left under a URL.
    expect(fileExists(`${DOCUMENT_ROOT}/backend/storage/mandates/${MEMBER_ID}.pdf`)).toBe(false);
    expect(fileExists(`${DOCUMENT_ROOT}/config.php`)).toBe(false);
    expect(fileExists(`${RELOCATED}/storage/mandates/${MEMBER_ID}.pdf`)).toBe(true);
    expect(fileExists(`${RELOCATED}/config.php`)).toBe(true);

    const response = await request.get(MANDATE_URL);
    expect(response.status()).toBe(403);

    // The pointer naming the new location is denied as well, and would print
    // nothing even if it were not.
    const pointer = await request.get(`${PACKAGE_URL}/data-path.php`);
    expect(pointer.status()).toBe(403);
  });

  test('moving back restores the in-document-root layout', async ({ request }) => {
    const moved = inContainer([
      'require "/app/backend/vendor/autoload.php";',
      `$r = App\\Shared\\Config\\DataDirectory::relocate("${DOCUMENT_ROOT}", "${DOCUMENT_ROOT}/backend");`,
      `App\\Shared\\Config\\DataDirectory::removePointer("${DOCUMENT_ROOT}");`,
      'echo json_encode($r);',
    ]);
    expect(JSON.parse(moved).ok, `revert failed: ${moved}`).toBe(true);

    const health = await request.get(`${PACKAGE_URL}/api/health`);
    expect(health.ok()).toBeTruthy();
    expect(fileExists(`${DOCUMENT_ROOT}/config.php`)).toBe(true);
  });

  /** Run PHP inside the package container as the uid the webserver uses. */
  function inContainer(lines: string[]): string {
    return execFileSync(
      'docker',
      ['compose', ...COMPOSE_FILES, 'exec', '-T', '-u', '1000', 'backend', 'php', '-r', lines.join('\n')],
      { cwd: REPO_ROOT, encoding: 'utf8' }
    ).trim();
  }

  function fileExists(path: string): boolean {
    return inContainer([`echo is_file("${path}") ? "yes" : "no";`]) === 'yes';
  }
});
