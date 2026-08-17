import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { generateKeyPairSync } from 'node:crypto';
import path from 'node:path';
import { generateTotp } from '../../utils/totp';

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

const REPO_ROOT = path.resolve(__dirname, '../../..');
const COMPOSE_FILES = ['-f', 'docker-compose.yml', '-f', 'docker-compose.package.yml'];

type InstallAuditRow = {
  admin_user_id: string;
  entity_id: string;
  old_values: string | null;
  new_values: string;
};

/**
 * Reads the audit_log row the installer wrote for the first admin (#501)
 * directly from the package database, bypassing the admin session/API —
 * this admin's account must stay TOTP-unenrolled for the login test below.
 */
function queryInstallAdminAuditEntry(): InstallAuditRow | null {
  const sql =
    "SELECT JSON_OBJECT('admin_user_id', al.admin_user_id, 'entity_id', al.entity_id, " +
    "'old_values', al.old_values, 'new_values', al.new_values) " +
    'FROM audit_log al JOIN admin_users au ON al.entity_id = au.id ' +
    "WHERE al.entity_type = 'admin_user' AND al.action = 'create' AND au.email = 'admin@example.com' " +
    'ORDER BY al.id DESC LIMIT 1';

  const output = execFileSync(
    'docker',
    ['compose', ...COMPOSE_FILES, 'exec', '-T', 'database', 'mysql', '-uclubbar', '-pclubbar', 'clubbar', '-N', '-B', '-e', sql],
    { cwd: REPO_ROOT, encoding: 'utf8' }
  ).trim();

  return output ? JSON.parse(output) : null;
}

/**
 * Register and activate an IBAN encryption keypair, the way a club does after
 * installing (ADR-0036).
 *
 * A fresh install has no key, and that is the design rather than an oversight:
 * the server only ever learns the public half, so nothing it could generate
 * on its own would be safe to seal with. Until an admin registers one, storing
 * an IBAN is refused — which is why this has to happen before the first member
 * with bank details can be created.
 *
 * The dev keypair published in this repository is deliberately unusable here:
 * the package runs with APP_ENV=production and `IbanSealedBox` refuses it, so
 * the test generates a real X25519 pair. Only the public half is sent; the
 * private half is discarded, as the club's would go into a safe.
 */
async function registerAndActivateEncryptionKey(
  request: import('@playwright/test').APIRequestContext,
  csrfToken: string,
  totpSecret: string,
): Promise<void> {
  const { publicKey } = generateKeyPairSync('x25519');
  // X25519 SPKI DER is 12 bytes of header followed by the raw 32-byte key.
  const spki = publicKey.export({ type: 'spki', format: 'der' });
  const rawPublicKey = spki.subarray(spki.length - 32);

  const headers = { 'X-CSRF-Token': csrfToken };
  const stepUp = () => ({ current_password: 'password123', totp_code: generateTotp(totpSecret) });

  const registered = await request.post(`${PACKAGE_URL}/api/admin/encryption-keys`, {
    data: {
      public_key: rawPublicKey.toString('base64'),
      key_identifier: `package-smoke-${Date.now()}`,
      ...stepUp(),
    },
    headers,
  });
  expect(registered.status(), await registered.text()).toBe(201);

  const keyId = (await registered.json()).key.id;
  const activated = await request.post(`${PACKAGE_URL}/api/admin/encryption-keys/${keyId}/activate`, {
    data: stepUp(),
    headers,
  });
  expect(activated.status(), await activated.text()).toBe(200);
}

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

    // The first admin is created by a raw INSERT the installer runs before
    // Composer's autoloader (and therefore AdminUsersService/AuditService)
    // is reachable — every admin the panel creates afterwards is audit-logged,
    // and this one must not be the exception (#501). Read the row straight
    // from the database rather than through the login + audit-log API: the
    // later "requiresTotpSetup" test in this file depends on this admin still
    // having no second factor enrolled, which completing a real TOTP setup
    // here would break.
    const installAuditEntry = queryInstallAdminAuditEntry();
    expect(installAuditEntry).not.toBeNull();
    expect(installAuditEntry!.admin_user_id).toBe(installAuditEntry!.entity_id);
    const newValues = JSON.parse(installAuditEntry!.new_values);
    expect(newValues.email).toBe('admin@example.com');
    expect(newValues.password).toBe('[INSTALLER]');
    expect(installAuditEntry!.old_values).toBeNull();

    // Step 5: the scheduler (#405). A prerequisite step, not a suggestion —
    // the drain is the only thing that sends announcement emails, and until a
    // run has been observed the panel refuses to finalize a collection.
    const step5 = await request.get(`${PACKAGE_URL}/install.php?step=5`);
    expect(step5.status()).toBe(200);
    const step5Html = await step5.text();
    // The command has to be printed with an absolute path: a hosting panel's
    // cron form has no working directory to resolve a relative one against.
    expect(step5Html).toContain('backend/bin/cron.php');
    expect(step5Html).toContain('cronCheckBtn');

    // The Prüfen button reads cron_heartbeat and reports what it found. A
    // fresh install has never been drained, so the honest answer is "no".
    const check = await request.get(`${PACKAGE_URL}/install.php?action=check_cron`);
    expect(check.status()).toBe(200);
    const checkBody = await check.json();
    expect(checkBody.verified).toBe(false);
    expect(checkBody.error).toBeUndefined();

    // Step 6: the completion page, which repeats the outcome rather than
    // letting an unverified scheduler pass silently.
    const step6 = await request.get(`${PACKAGE_URL}/install.php?step=6`);
    expect(step6.status()).toBe(200);
    expect(await step6.text()).toContain('Installation Complete');
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
 * ADR-0031 layers L1/L2 (#249): .htaccess denies config.php, *.log, *.sql,
 * package.json and dotfiles beyond .installer-data/.upgrade-secret, and adds
 * Permissions-Policy — the two things RuntimeHardening.php cannot cover
 * because expose_php is not the only concern: this is the belt for a request
 * that never reaches PHP at all (a host that stops executing .php files).
 */
test.describe('Package: .htaccess access rules', () => {
  test.skip(!process.env.PACKAGE_TEST, 'Skipped unless PACKAGE_TEST=1');

  test('config.php, a stray log file and a dotfile are all denied', async ({ request }) => {
    for (const path of ['/config.php', '/test.log', '/.env']) {
      const response = await request.get(`${PACKAGE_URL}${path}`);
      expect([403, 404], `${path} returned ${response.status()}`).toContain(response.status());
    }
  });

  test('Permissions-Policy denies camera, microphone, geolocation and payment', async ({ request }) => {
    const response = await request.get(`${PACKAGE_URL}/api/health`);
    const header = response.headers()['permissions-policy'] ?? '';
    for (const directive of ['camera=()', 'microphone=()', 'geolocation=()', 'payment=()']) {
      expect(header, `Permissions-Policy: ${header}`).toContain(directive);
    }
  });
});

/**
 * ADR-0031 decision 3, third surface: "did we regress the package?"
 *
 * The installer asks this of a host once and the admin panel asks it of a
 * running installation; here CI asks it of the artifact it is about to publish.
 * Same engine, so a check that stopped measuring — a directive dropped from
 * `RuntimeHardening`, an `.htaccess` rule that no longer denies `backend/` —
 * turns a green row red and fails the build instead of shipping.
 *
 * This runs the check *inside* the package container, where the document root
 * really is the package and the webserver really is the one being asked. The
 * exposure rows are the reason: they are answered by writing a canary where a
 * scanned mandate lives and fetching it back over HTTP, which no unit test can
 * do.
 */
test.describe('Package: Security self-check', () => {
  test.skip(!process.env.PACKAGE_TEST, 'Skipped unless PACKAGE_TEST=1');

  const REPO_ROOT = path.resolve(__dirname, '../../..');
  const COMPOSE_FILES = ['-f', 'docker-compose.yml', '-f', 'docker-compose.package.yml'];

  type Finding = {
    id: string;
    category: string;
    status: 'pass' | 'warn' | 'fail' | 'unknown';
    observed: string;
    remedy: string | null;
  };

  /**
   * Rows the package must always deliver, whatever the host underneath.
   *
   * Everything here is either set by application code (ADR-0031 decision 1) or
   * enforced by a file the package ships and the container honours. The rows
   * left out are the ones that legitimately depend on the deployment — HTTPS,
   * HSTS (a browser-side no-op without it), and the data directory's
   * placement, which needs a writable parent the container does not have.
   */
  const MUST_PASS = [
    'display_errors',
    'log_errors',
    'exception_args',
    'session_strict_mode',
    'session_cookie_only',
    'session_cookie_httponly',
    'session_cookie_samesite',
    'session_save_path',
    // The two that matter most, and the only ones measured by asking the
    // webserver: a scanned SEPA mandate and the application log must be refused.
    'mandate_not_served',
    'logs_not_served',
    // Set by RuntimeHardening::applySecurityHeaders() (#383, ADR-0031 — CSP and
    // HSTS moved to L0), measured regardless of transport, so unlike HSTS this
    // belongs here rather than in the excluded set above.
    'csp_header',
    // Written by the installer with 0600 — the database password and the key
    // that encrypts every admin's second factor are in it.
    'config_file_mode',
    // Shipped 0700 by the build, and 0777 until #248: the mandate store and the
    // request logs must not be readable by another account on the machine.
    'storage_directory_mode',
    'log_directory_mode',
    // Served, so it stays readable — but nothing else may write a .php file
    // into a directory the webserver executes from.
    'document_root_mode',
  ];

  test('every protection the package promises is in effect', async () => {
    const findings = measurePackagedSecurity();
    const byId = new Map(findings.map((finding) => [finding.id, finding]));

    for (const id of MUST_PASS) {
      const finding = byId.get(id);
      expect(finding, `${id} is missing from the report`).toBeDefined();
      expect(
        finding?.status,
        `${id}: ${finding?.observed} — ${finding?.remedy ?? 'no remedy given'}`
      ).toBe('pass');
    }
  });

  test('no row of the packaged installation reports an exposure', async () => {
    const failing = measurePackagedSecurity().filter((finding) => finding.status === 'fail');

    expect(failing.map((finding) => `${finding.id}: ${finding.observed}`)).toEqual([]);
  });

  /**
   * ADR-0031 decision 4, asserted against the artifact rather than the intent.
   *
   * `MUST_PASS` above would stay green on `0640` or `0750` — anything without
   * world bits passes. These are the modes the release is supposed to carry, so
   * a build that stopped calling `package-permissions.sh harden`, or an
   * installer whose `chmod` silently did nothing, is caught here instead of
   * shipping. The document root is the exception: it is served, so `0755` is
   * the target and only the write bits are the finding.
   */
  test('the writable directories and the config carry the modes the release targets', async () => {
    const byId = new Map(measurePackagedSecurity().map((finding) => [finding.id, finding]));

    expect(byId.get('storage_directory_mode')?.observed).toBe('0700');
    expect(byId.get('log_directory_mode')?.observed).toBe('0700');
    expect(byId.get('config_file_mode')?.observed).toBe('0600');
    expect(byId.get('document_root_mode')?.observed).toBe('0755');
  });

  /**
   * The rule the report rests on, asserted where it is easiest to break: a
   * refusal that was never observed must not be rendered as one. If the canary
   * fetch stopped happening, these rows would go `unknown` — and this test
   * fails rather than the suite quietly proving nothing.
   */
  test('the exposure rows come from a real refusal, not from an assumption', async () => {
    const findings = measurePackagedSecurity();

    for (const id of ['mandate_not_served', 'logs_not_served']) {
      const observed = findings.find((finding) => finding.id === id)?.observed ?? '';
      expect(observed, `${id} was not measured over HTTP`).toMatch(/refused \(HTTP (403|404)\)/);
    }
  });

  /**
   * Runs the check as the package's own front controller would see the world:
   * document root `/app`, data directory wherever the pointer says, and the
   * webserver of this container as the thing to ask.
   */
  function measurePackagedSecurity(): Finding[] {
    const script = [
      'require "/app/backend/vendor/autoload.php";',
      '$_ENV["DATA_DIR"] = App\\Shared\\Config\\DataDirectory::resolve("/app");',
      '$config = require App\\Shared\\Config\\DataDirectory::configPath("/app");',
      '$_ENV["APP_DEBUG"] = ($config["app"]["debug"] ?? false) ? "true" : "false";',
      'App\\Shared\\Config\\Env::reset();',
      '$appConfig = new App\\Shared\\Config\\AppConfig();',
      'App\\Shared\\Security\\RuntimeHardening::apply($appConfig, false);',
      '$findings = App\\Shared\\Security\\SecuritySelfCheck::run(new App\\Shared\\Security\\SecurityCheckContext(',
      '  documentRoot: "/app",',
      '  dataDirectory: $appConfig->dataDir,',
      '  configFile: App\\Shared\\Config\\DataDirectory::configPath("/app"),',
      '  expectedSessionSavePath: $appConfig->sessionSavePath,',
      '  https: false,',
      '  debug: $appConfig->debug,',
      '  baseUrlCandidates: ["http://127.0.0.1"],',
      '  controlPaths: ["/README.txt"],',
      '));',
      'echo json_encode(array_map(fn($f) => $f->toArray(), $findings));',
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
 * #250, ADR-0031 layer L2: the admin SPA renders member PII and IBANs behind
 * a session cookie, and now behind a Content-Security-Policy that has to
 * survive real use — not just a header edit. This suite caught `install.js`'s
 * Run Migrations button silently stopping submission once its own click
 * handler disabled it (disabling a submit button inside its own click
 * handler can cancel the browser's default submission), invisible to a
 * header diff.
 */
test.describe.serial('Package: Admin SPA under the enforcing CSP', () => {
  test.skip(!process.env.PACKAGE_TEST, 'Skipped unless PACKAGE_TEST=1');

  test('the enforcing policy is actually sent, with no unsafe-eval', async ({ request }) => {
    const response = await request.get(`${PACKAGE_URL}/`);
    const header = response.headers()['content-security-policy'] ?? '';

    expect(header).toContain("default-src 'self'");
    expect(header).toContain("script-src 'self'");
    expect(header).not.toContain('unsafe-eval');
    expect(header).toContain("worker-src 'self' blob:");
    expect(header).toContain("frame-ancestors 'none'");
    expect(header).toContain("object-src 'none'");
  });
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
    // Written from inside the container, as the user the application runs as.
    // Since #248 the package is owned by that user rather than chmod'ed 0777,
    // so a host-side write here would be exactly the permission denial the
    // installer itself would hit.
    inContainer([
      `file_put_contents("${DOCUMENT_ROOT}/.installer-data",`,
      `  json_encode(["key" => "${key}", "completed_step" => 0]));`,
    ]);

    await request.post(`${PACKAGE_URL}/install.php`, { form: { install_key: key } });
    const page = await request.get(`${PACKAGE_URL}/install.php?step=1`);
    const html = await page.text();

    expect(html).toContain('A scanned mandate cannot be fetched over the web');
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
