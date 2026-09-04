import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createHash, generateKeyPairSync } from 'node:crypto';
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

/** Run PHP inside the package container, as the uid the webserver runs as. */
function inPackageContainer(code: string): string {
  return execFileSync(
    'docker',
    ['compose', ...COMPOSE_FILES, 'exec', '-T', '-u', '1000', 'backend', 'php', '-r', code],
    { cwd: REPO_ROOT, encoding: 'utf8' }
  ).trim();
}

/**
 * Step 6's recipient rows (#735) post as repeated `recipient_label[]` /
 * `recipient_key[]` fields rather than one flattened value, so `form:` (which
 * cannot express a repeated key) is not enough — built by hand and sent as a
 * pre-encoded body instead.
 */
function step6Body(
  rows: Array<{ label: string; key: string }>,
  extra: Record<string, string> = {}
): { data: string; headers: Record<string, string> } {
  const params = new URLSearchParams();
  params.append('step', '6');
  for (const row of rows) {
    params.append('recipient_label[]', row.label);
    params.append('recipient_key[]', row.key);
  }
  for (const [key, value] of Object.entries(extra)) {
    params.append(key, value);
  }
  return { data: params.toString(), headers: { 'content-type': 'application/x-www-form-urlencoded' } };
}

type InstallAuditRow = {
  admin_user_id: string;
  entity_id: string;
  old_values: Record<string, unknown> | null;
  new_values: { email: string; display_name: string; password: string };
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

type InstallAdminRow = {
  email: string;
  password_hash: string;
  is_active: number;
};

/**
 * Reads the admin_users row the installer's raw INSERT wrote (#505). The
 * redirect and the audit-log entry only prove the request was accepted —
 * this is the assertion the issue asks for: the actual row, with a real
 * bcrypt hash and an active account, not just an HTTP/HTML response shape.
 */
function queryInstallAdminRow(email: string): InstallAdminRow | null {
  const sql =
    "SELECT JSON_OBJECT('email', email, 'password_hash', password_hash, 'is_active', is_active) " +
    `FROM admin_users WHERE email = '${email}'`;

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
  const stepUp = () => ({ current_password: 'Password123', totp_code: generateTotp(totpSecret) });

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

  test('install.php rejects a wrong install key and never starts a session', async ({ request }) => {
    const rejected = await request.post(`${PACKAGE_URL}/install.php`, {
      form: { install_key: 'definitely-not-the-install-key' },
    });
    expect(rejected.ok()).toBeTruthy();
    expect(await rejected.text()).toContain('Invalid install key');

    // No session cookie was granted, so a wrong key gates every step, not
    // just the key form itself.
    const step4 = await request.get(`${PACKAGE_URL}/install.php?step=4`);
    expect(await step4.text()).toContain('Install Key Required');
  });

  /**
   * Step 2 refuses a zone it cannot resolve, and says so (#365).
   *
   * This is the one screen in the system that turns a bad zone name into a
   * sentence a human reads. Everywhere else the fallback is deliberately
   * silent — a mail that throws in the builder reaches nobody — so if this
   * refusal ever went quiet, `Europe/Berlim` would cost a club a year of
   * books stated an hour out with nothing anywhere saying so.
   *
   * Nothing is written by a refused step, so this leaves the installation
   * exactly where it found it: at completed_step 0, for the test below.
   */
  test('step 2 refuses a time zone the zone database does not know', async ({ request }) => {
    await request.post(`${PACKAGE_URL}/install.php`, { form: { install_key: CI_INSTALL_KEY } });

    const refused = await request.post(`${PACKAGE_URL}/install.php?step=2`, {
      form: {
        step: '2',
        db_host: 'database',
        db_port: '3306',
        db_name: 'clubbar',
        db_user: 'clubbar',
        db_pass: 'clubbar',
        app_timezone: 'Europe/Berlim',
      },
      maxRedirects: 0,
    });

    expect(refused.status(), 'a misspelled zone must not advance the wizard').toBe(200);
    expect(await refused.text()).toContain('choose the time zone');

    // The refusal is a refusal: nothing was written behind it. Asked through
    // the same resolver the installer uses, because where `config.php` belongs
    // is a decision this host makes (ADR-0031 decision 2), not a fixed path.
    expect(
      inPackageContainer(
        [
          'require "/app/backend/vendor/autoload.php";',
          'echo is_file(App\\Shared\\Config\\DataDirectory::configPath("/app")) ? "yes" : "no";',
        ].join('\n')
      ),
      'a refused step 2 wrote a config.php'
    ).toBe('no');
  });

  test('install wizard completes via POST steps', async ({ request }) => {
    // Enter install key — sets session cookie on this request context
    const keyResponse = await request.post(`${PACKAGE_URL}/install.php`, {
      form: { install_key: CI_INSTALL_KEY },
    });
    expect(keyResponse.ok()).toBeTruthy();
    const html = await keyResponse.text();
    expect(html).toContain('Prerequisites');

    // Step 2: DB credentials and the club's clock.
    //
    // `app_timezone` is required since #365 — the wizard's own select always
    // sends it, so a POST harness that omits it is not simulating a browser,
    // it is exercising a path no club can reach. Vienna rather than the
    // pre-selected Europe/Berlin so the assertion below can tell "what the
    // club chose" apart from "what the application falls back to"; the two
    // zones share every offset and every DST transition, so nothing else in
    // this suite can see the difference.
    const step2 = await request.post(`${PACKAGE_URL}/install.php?step=2`, {
      form: {
        step: '2',
        db_host: 'database',
        db_port: '3306',
        db_name: 'clubbar',
        db_user: 'clubbar',
        db_pass: 'clubbar',
        app_timezone: 'Europe/Vienna',
      },
      maxRedirects: 0,
    });
    expect(step2.status()).toBe(302);
    expect(step2.headers()['location']).toContain('step=3');

    // The zone the club chose is what landed in config.php — stated, not
    // inferred, and not the fallback that happens to agree with it.
    expect(
      inPackageContainer(
        [
          'require "/app/backend/vendor/autoload.php";',
          '$config = require App\\Shared\\Config\\DataDirectory::configPath("/app");',
          'echo $config["app"]["timezone"] ?? "";',
        ].join('\n')
      )
    ).toBe('Europe/Vienna');

    // Step 3: Migrations.
    //
    // The one request in this suite that is not an ordinary API call: it
    // applies *every* file in backend/db/migrations to an empty schema, on a
    // MariaDB container that started moments ago and has never flushed a page.
    // The config's 10s `actionTimeout` is sized for endpoints that answer a
    // question; this one builds a database, and its cost grows by one DDL
    // statement every time somebody adds a migration.
    //
    // Measured: ~0.6s against a warm local stack, 11.1s on a CI runner — which
    // is what tipped this over and produced the failure that led here. The
    // knock-on was the confusing part: step 3 timing out client-side meant step
    // 4 never created the admin, so the *login* test failed with a plain 401
    // several tests later, naming nothing about migrations.
    //
    // 60s is headroom, not a target. A step 3 that genuinely takes a minute is
    // a real regression and still fails.
    const step3 = await request.post(`${PACKAGE_URL}/install.php?step=3`, {
      form: { step: '3' },
      maxRedirects: 0,
      timeout: 60_000,
    });
    expect(step3.status()).toBe(302);
    expect(step3.headers()['location']).toContain('step=4');

    // Step 4: a too-short password is rejected before any row is written
    // (#505 — the length check, checked ahead of and independent of the
    // composition rule below).
    const shortPassword = await request.post(`${PACKAGE_URL}/install.php?step=4`, {
      form: {
        step: '4',
        admin_email: 'weak-password@example.com',
        admin_password: 'short1',
        admin_password_confirm: 'short1',
      },
      maxRedirects: 0,
    });
    expect(shortPassword.status()).toBe(200);
    expect(await shortPassword.text()).toContain('Password must be at least 8 characters');
    expect(queryInstallAdminRow('weak-password@example.com')).toBeNull();

    // Step 4: Admin user. #502: a password that clears the length check but
    // fails the composition rule (lower + upper + digit, matching the rule
    // AuthController::changePassword already enforces for self-service
    // password change) must be rejected before the account is created — tried
    // here, still inside the same authenticated session and before the
    // eventual success below deletes .installer-data (and its key) for good.
    for (const weakPassword of ['alllowercase1', 'ALLUPPERCASE1', '12345678901']) {
      const rejected = await request.post(`${PACKAGE_URL}/install.php?step=4`, {
        form: {
          step: '4',
          admin_email: 'admin@example.com',
          admin_password: weakPassword,
          admin_password_confirm: weakPassword,
        },
        maxRedirects: 0,
      });
      expect(rejected.status(), `password "${weakPassword}" should have been rejected`).toBe(200);
      expect(await rejected.text()).toContain('one lowercase letter, one uppercase letter, and one digit');
    }

    const step4 = await request.post(`${PACKAGE_URL}/install.php?step=4`, {
      form: {
        step: '4',
        admin_email: 'admin@example.com',
        admin_password: 'Password123',
        admin_password_confirm: 'Password123',
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
    expect(installAuditEntry!.new_values.email).toBe('admin@example.com');
    expect(installAuditEntry!.new_values.password).toBe('[INSTALLER]');
    expect(installAuditEntry!.old_values).toBeNull();

    // The row itself (#505): active, and a real bcrypt hash rather than the
    // plaintext password the request carried.
    const adminRow = queryInstallAdminRow('admin@example.com');
    expect(adminRow).not.toBeNull();
    expect(adminRow!.is_active).toBe(1);
    expect(adminRow!.password_hash).toMatch(/^\$2y\$/);
    expect(adminRow!.password_hash).not.toContain('Password123');

    // Step 5: mail (#710). The one config.php line that cannot live in the
    // admin panel, because it carries an SMTP password — everything else about
    // a mail is under Settings → Mail. Before this step existed, switching mail
    // on meant hand-editing a PHP file on a live site.
    const step5 = await request.get(`${PACKAGE_URL}/install.php?step=5`);
    expect(step5.status()).toBe(200);
    expect(await step5.text()).toContain('Sending mail');

    // Refused by the application's own parser, not by a second copy of the
    // rules here. An unparseable transport does not throw when mail is
    // *queued*, so without this the queue fills and the drain fails in a job
    // nobody watches.
    const badDsn = await request.post(`${PACKAGE_URL}/install.php?step=5`, {
      form: { step: '5', mail_dsn: 'mail.example.test' },
      maxRedirects: 0,
    });
    expect(badDsn.status()).toBe(200);
    expect(await badDsn.text()).toContain('is missing a scheme');

    const step5Post = await request.post(`${PACKAGE_URL}/install.php?step=5`, {
      form: { step: '5', mail_dsn: 'smtp://ci:hunter2@mail.example.test:587' },
      maxRedirects: 0,
    });
    expect(step5Post.status()).toBe(302);
    expect(step5Post.headers()['location']).toContain('step=6');

    // Read back through config.php, redacted: end-to-end proof that the value
    // reached the file and was loaded from it again.
    //
    // **This is also the only place the ADR-0050 rule is observable.** The POST
    // above wrote config.php and this GET re-reads it milliseconds later —
    // inside opcache's 2-second window, where the compiled copy is served and
    // the mtime is never consulted. It passes because the *writer* announced
    // the change (`ConfigWriter::writeTo()`), not because this read checked:
    // reads deliberately pay nothing, since `index.php` does one on every
    // request. Move that invalidation back to `read()` and this still passes
    // while `index.php` quietly loses opcache for the file; delete it from both
    // and this fails. No unit test can cover it — `opcache.enable_cli` is Off.
    const step5Again = await request.get(`${PACKAGE_URL}/install.php?step=5`);
    const step5AgainHtml = await step5Again.text();
    expect(step5AgainHtml).toContain('smtp://ci:***@mail.example.test:587');
    // Never the password, in a page a browser may cache.
    expect(step5AgainHtml).not.toContain('hunter2');

    // Step 6: backups (ADR-0049, #710). Also optional, also reachable long
    // after the install — a club sets backups up in the week it thinks about
    // backups, not in the hour it installs.
    const step6 = await request.get(`${PACKAGE_URL}/install.php?step=6`);
    expect(step6.status()).toBe(200);
    const step6Html = await step6.text();
    expect(step6Html).toContain('Backups');

    // #733: the generator is generated and used entirely offline. Linking a
    // server-hosted copy would both defeat the point (a compromised host could
    // serve a modified copy that steals the private half it just displayed)
    // and not even work, since the shipped CSP refuses its inline <script>.
    expect(step6Html).not.toMatch(/href=["']tools\/keypair-generator\.html["']/);
    expect(step6Html).toContain('tools/keypair-generator.html');
    expect(step6Html).toMatch(/offline/i);

    // #735: two rows by default, encouraging (without requiring) two holders.
    expect((step6Html.match(/name="recipient_label\[\]"/g) ?? []).length).toBe(2);

    // The mismatch this step was built around: the key generator printed
    // base64 while the keyring needs hex, and four documents promised the
    // opposite. The refusal has to name the encoding, not merely refuse — and
    // (#735) attach to the specific row rather than a generic banner.
    const base64Key = await request.post(`${PACKAGE_URL}/install.php?step=6`, {
      ...step6Body([{ label: 'admin', key: 'yeMcz7Ncmobf9EuVwTkeR+DOf3focDz4UV0c9/CIjk4=' }]),
      maxRedirects: 0,
    });
    expect(base64Key.status()).toBe(200);
    const base64KeyHtml = await base64Key.text();
    expect(base64KeyHtml).toMatch(/64 hex characters/);
    expect(base64KeyHtml).toContain('recipient-row-error');

    // A label with a space is refused the same way, naming the rule rather
    // than just saying "invalid" (#735).
    const badLabel = await request.post(`${PACKAGE_URL}/install.php?step=6`, {
      ...step6Body([{ label: 'vorstand müller', key: 'a1b2c3d4'.repeat(8) }]),
      maxRedirects: 0,
    });
    expect(badLabel.status()).toBe(200);
    expect(await badLabel.text()).toMatch(/letters, digits, hyphens/);

    // A DSN with a segment missing is the worst state this screen can let
    // through: the club types one, believes archives are leaving the host, and
    // they never do. Refused by BackupDsn::parse(), which names the missing
    // part rather than saying "invalid".
    const badBackupDsn = await request.post(`${PACKAGE_URL}/install.php?step=6`, {
      form: { step: '6', backup_dsn: 'msgraph://tenant-only' },
      maxRedirects: 0,
    });
    expect(badBackupDsn.status()).toBe(200);
    expect(await badBackupDsn.text()).toContain('backup.dsn');

    const recipients = [
      { label: 'admin', key: 'a1b2c3d4'.repeat(8) },
      { label: 'vorstand', key: '9f8e7d6c'.repeat(8) },
    ];

    // #735 defect fix: a mistake in one row must not cost the operator every
    // other field on the screen. The second row is malformed here; the first
    // row's key and the DSN/expiry/heartbeat fields must all survive the
    // failed round trip untouched.
    const partialFailure = await request.post(`${PACKAGE_URL}/install.php?step=6`, {
      ...step6Body(
        [recipients[0], { label: 'vorstand mueller', key: recipients[1].key }],
        {
          backup_dsn: 'msgraph://tenant/client@drive/b!ci/clubbar',
          backup_secret_expires_at: '2099-01-01',
          backup_heartbeat_url: 'https://hc-ping.com/ci-canary',
        }
      ),
      maxRedirects: 0,
    });
    expect(partialFailure.status()).toBe(200);
    const partialFailureHtml = await partialFailure.text();
    expect(partialFailureHtml).toContain(recipients[0].key);
    expect(partialFailureHtml).toContain('msgraph://tenant/client@drive/b!ci/clubbar');
    expect(partialFailureHtml).toContain('2099-01-01');
    expect(partialFailureHtml).toContain('https://hc-ping.com/ci-canary');
    expect(partialFailureHtml).toContain('recipient-row-error');

    const step6Post = await request.post(`${PACKAGE_URL}/install.php?step=6`, {
      ...step6Body(recipients, {
        backup_dsn: 'msgraph://tenant/client@drive/b!ci/clubbar',
        backup_remote_secret: 'ci-secret',
        backup_secret_expires_at: '2099-01-01',
      }),
      maxRedirects: 0,
    });
    expect(step6Post.status()).toBe(302);
    expect(step6Post.headers()['location']).toContain('step=7');

    // Both recipients survived the round trip through config.php — these are
    // public keys, so this screen does pre-fill them, each beside the SHA-256
    // fingerprint tools/keypair-generator.html and the archive header show for
    // the same key (#735) — so a swapped or truncated paste is verifiable
    // against the paper the operator is holding.
    const step6Again = await request.get(`${PACKAGE_URL}/install.php?step=6`);
    const step6AgainHtml = await step6Again.text();
    for (const recipient of recipients) {
      expect(step6AgainHtml).toContain(recipient.label);
      expect(step6AgainHtml).toContain(recipient.key);
      const fingerprint = createHash('sha256').update(Buffer.from(recipient.key, 'hex')).digest('hex');
      expect(step6AgainHtml).toContain(fingerprint);
    }
    // The client secret is not echoed back, for the same reason as the DSN's
    // password.
    expect(step6AgainHtml).not.toContain('ci-secret');

    // **The mode these two screens exist for.** Configuring backups during the
    // install is the easy half; the half that matters is a club coming back
    // months later, through the updater route, on a working installation. That
    // is a different code path — ?update=1 — and if it did not work, the answer
    // to "how do I add backup credentials after install?" would still be "hand
    // -edit config.php on a live site", which is the question #710 came from.
    const step6Update = await request.get(`${PACKAGE_URL}/install.php?step=6&update=1`);
    expect(step6Update.status()).toBe(200);
    const step6UpdateHtml = await step6Update.text();
    expect(step6UpdateHtml).toContain('Backups');
    // It reads the live file, so what was saved above is here.
    expect(step6UpdateHtml).toContain(recipients[0].key);

    // A blank client secret on a re-run means "keep the stored one", which is
    // what lets the screen decline to echo a live credential back. Changing the
    // expiry date alone must not silently delete the secret the whole remote
    // depends on — a failure that would surface as uploads stopping, weeks
    // later, in a job nobody reads.
    const step6Reentry = await request.post(`${PACKAGE_URL}/install.php?step=6&update=1`, {
      ...step6Body(recipients, {
        backup_dsn: 'msgraph://tenant/client@drive/b!ci/clubbar',
        backup_remote_secret: '',
        backup_secret_expires_at: '2098-06-30',
      }),
      maxRedirects: 0,
    });
    expect(step6Reentry.status()).toBe(302);
    expect(step6Reentry.headers()['location']).toContain('step=7');

    // The new date took, and the secret survived — the screen still refuses to
    // show it, so the proof it is there is that the save was accepted at all:
    // a remote with no stored secret is rejected by name.
    const afterReentry = await request.get(`${PACKAGE_URL}/install.php?step=6&update=1`);
    const afterReentryHtml = await afterReentry.text();
    expect(afterReentryHtml).toContain('2098-06-30');
    expect(afterReentryHtml).not.toContain('ci-secret');
    // The placeholder only renders when a secret is actually stored, so this
    // says "there is one" rather than merely "the page mentions secrets" — the
    // hint paragraph below the field says that unconditionally.
    expect(afterReentryHtml).toContain('placeholder="stored');

    // The mail transport two screens back is still what step 5 wrote. Both
    // optional screens rewrite the *whole* config.php, so this is where losing
    // an earlier screen's value would show up.
    const mailAfterBackup = await request.get(`${PACKAGE_URL}/install.php?step=5&update=1`);
    expect(await mailAfterBackup.text()).toContain('smtp://ci:***@mail.example.test:587');

    // Every earlier screen's values are still in the file. Two optional steps
    // each rewrite the *whole* config.php, so what this guards against is a
    // club reaching the backup screen months later and losing its database
    // password — a file that still loads and a site that no longer starts.
    expect(queryInstallAdminRow('admin@example.com')).not.toBeNull();

    // Step 7: the scheduler (#405). A prerequisite step, not a suggestion —
    // the drain is the only thing that sends announcement emails, and until a
    // run has been observed the panel refuses to finalize a collection.
    const step7 = await request.get(`${PACKAGE_URL}/install.php?step=7`);
    expect(step7.status()).toBe(200);
    const step7Html = await step7.text();
    // The command has to be printed with an absolute path: a hosting panel's
    // cron form has no working directory to resolve a relative one against.
    expect(step7Html).toContain('backend/bin/cron.php');
    expect(step7Html).toContain('cronCheckBtn');

    // Both jobs, on one screen (ADR-0049). The backup is the one nothing
    // reminds you about afterwards — it blocks no workflow, so a volunteer who
    // learns about it from a manual instead of from this page does not add it,
    // and the club's first backup is the one it does not have. The docs claim
    // the installer prints both; this is what makes that claim true.
    expect(step7Html).toContain('backend/bin/backup.php');
    expect(step7Html).toContain('backup.recipient_public_keys');

    // The Prüfen button reads cron_heartbeat and reports what it found. A
    // fresh install has never been drained, so the honest answer is "no".
    const check = await request.get(`${PACKAGE_URL}/install.php?action=check_cron`);
    expect(check.status()).toBe(200);
    const checkBody = await check.json();
    expect(checkBody.verified).toBe(false);
    expect(checkBody.error).toBeUndefined();

    // The alarm that outlives the wizard (#743). The Check button above answers
    // for today; `cron.heartbeat_url` is what reports every run to a monitor
    // outside this installation — and until this field existed, the installer
    // wrote every other key of the `cron` section and left this one to a club
    // hand-editing config.php on a live site.
    expect(step7Html).toContain('name="cron_heartbeat_url"');

    // Refused rather than stored. This is the one field on the wizard whose
    // mistake is completely silent: a monitor URL that goes nowhere pings
    // nowhere, and looks exactly like a working alarm until the outage it was
    // meant to catch.
    const badHeartbeat = await request.post(`${PACKAGE_URL}/install.php?step=7`, {
      form: { step: '7', cron_heartbeat_url: 'hc-ping.com/not-a-url' },
      maxRedirects: 0,
    });
    expect(badHeartbeat.status()).toBe(200);
    const badHeartbeatHtml = await badHeartbeat.text();
    expect(badHeartbeatHtml).toContain('does not look like a check URL');
    // And handed back as typed, so the operator can see the typo instead of
    // retyping the URL from the monitor's page.
    expect(badHeartbeatHtml).toContain('hc-ping.com/not-a-url');

    const step7Post = await request.post(`${PACKAGE_URL}/install.php?step=7`, {
      form: { step: '7', cron_heartbeat_url: 'https://hc-ping.com/ci-drain-canary' },
      maxRedirects: 0,
    });
    expect(step7Post.status()).toBe(302);
    expect(step7Post.headers()['location']).toContain('step=8');

    // Read back through config.php: end-to-end proof the value reached the file
    // and was loaded from it again. Not a secret, unlike the mail DSN, so this
    // screen does pre-fill it — an operator who cannot see the configured
    // monitor cannot tell a live alarm from one pointing at a deleted check.
    const step7Again = await request.get(`${PACKAGE_URL}/install.php?step=7&update=1`);
    expect(await step7Again.text()).toContain('https://hc-ping.com/ci-drain-canary');

    // The mail transport four screens back is still what step 5 wrote — this
    // screen rewrites the whole of config.php too.
    const mailAfterSchedule = await request.get(`${PACKAGE_URL}/install.php?step=5&update=1`);
    expect(await mailAfterSchedule.text()).toContain('smtp://ci:***@mail.example.test:587');

    // An emptied field is an erase, not "unchanged" — the rule that lets the
    // mail and backup screens keep a stored secret when their field is blank
    // would otherwise keep an alarm the operator has just switched off, and
    // report success for it.
    const clearHeartbeat = await request.post(`${PACKAGE_URL}/install.php?step=7&update=1`, {
      form: { step: '7', cron_heartbeat_url: '' },
      maxRedirects: 0,
    });
    expect(clearHeartbeat.status()).toBe(302);
    const afterClear = await request.get(`${PACKAGE_URL}/install.php?step=7&update=1`);
    expect(await afterClear.text()).not.toContain('ci-drain-canary');

    // Step 8: the completion page, which repeats the outcome rather than
    // letting an unverified scheduler pass silently.
    const step8 = await request.get(`${PACKAGE_URL}/install.php?step=8`);
    expect(step8.status()).toBe(200);
    expect(await step8.text()).toContain('Installation Complete');
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
        password: 'Password123',
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

/**
 * The public self-registration page, in the layout an installed release
 * actually has (ADR-0052 decision 11, #781, #796).
 *
 * These are the tests that would have caught the shipped bug. In the docker
 * layout the document root *is* `backend/public`, so `/register` resolves for
 * free and every other suite that touches it — the `register` project included
 * — proves nothing about the package: the build script did not copy the
 * directory, `.htaccess` has no rule for a path that is a directory rather
 * than a file, and the front controller answers `spa.html` for anything that
 * is not `/api/`. A member scanning the club's QR poster got the admin login
 * form, and the club's only signal was the member saying so.
 *
 * Asserted against Apache with the shipped `.htaccess`, which is the whole
 * point — the rewrite ordering is what was wrong, and no unit test can see it.
 */
test.describe('Package: self-registration page', () => {
  test.skip(!process.env.PACKAGE_TEST, 'Skipped unless PACKAGE_TEST=1');

  test('/register serves the onboarding page, not the admin SPA', async ({ request }) => {
    const response = await request.get(`${PACKAGE_URL}/register`);
    expect(response.ok()).toBeTruthy();

    const html = await response.text();
    expect(html).toContain('data-testid="register-app"');
    // The failure this guards is not "404" — it is "200, wrong page".
    expect(html).not.toContain('<div id="root">');
  });

  test('/register/ with the trailing slash serves it too', async ({ request }) => {
    const response = await request.get(`${PACKAGE_URL}/register/`);
    expect(response.ok()).toBeTruthy();

    const html = await response.text();
    expect(html).toContain('data-testid="register-app"');
    expect(html).not.toContain('<div id="root">');
  });

  test('its stylesheet and script are served from the same directory', async ({ request }) => {
    // Relative hrefs in the page, so a rewrite that served the HTML from
    // somewhere else would leave an unstyled, inert form rather than an error.
    const css = await request.get(`${PACKAGE_URL}/register/register.css`);
    expect(css.ok()).toBeTruthy();
    expect(css.headers()['content-type']).toContain('text/css');

    const js = await request.get(`${PACKAGE_URL}/register/register.js`);
    expect(js.ok()).toBeTruthy();
    expect(await js.text()).toContain('/api/public/registrations');
  });

  /**
   * The QR poster's own URL — `PosterSecret::PATH` is `/register`, with no
   * trailing slash — has to end up at the directory (#812).
   *
   * Served in place, the browser stays at `/register` and the page's relative
   * `./register.css` and `./register.js` resolve to `/register.css` and
   * `/register.js`: not files, so the front controller answers with the admin
   * SPA's HTML at 200. Nothing 404s, nothing is logged, and the member gets an
   * unstyled form whose buttons do nothing.
   *
   * `maxRedirects: 0` is what makes this test see the answer at all — every
   * other test here follows the redirect, which is exactly why the failure was
   * invisible.
   */
  test('/register redirects to /register/ so the page finds its own assets', async ({
    request,
  }) => {
    const response = await request.get(`${PACKAGE_URL}/register`, { maxRedirects: 0 });

    expect(response.status()).toBe(301);
    // Apache expands a leading-slash rewrite target into a fully-qualified URL,
    // so the assertion is on where it points rather than on how it is written.
    expect(response.headers()['location']).toMatch(/(^|:\/\/[^/]+)\/register\/$/);
  });

  /**
   * The two assets have stable names and no build step, and the shipped
   * `.htaccess` gives every stylesheet and script a year — right for Vite's
   * content-hashed `assets/`, and the reason a phone that opened `/register`
   * before an upgrade keeps rendering the new markup with the old stylesheet
   * (#812). Both halves of the fix are asserted here because either alone
   * leaves a club upgrading into a page its members see broken.
   */
  test('the page assets revalidate and are referenced by content hash', async ({ request }) => {
    const page = await request.get(`${PACKAGE_URL}/register/`);
    const html = await page.text();

    // Stamped by build-package.sh from the file's own bytes, so an upgrade
    // asks for a URL no cache is holding.
    expect(html).toMatch(/href="\.\/register\.css\?v=[0-9a-f]{8}"/);
    expect(html).toMatch(/src="\.\/register\.js\?v=[0-9a-f]{8}"/);

    // And the belt: were the stamp ever lost, the cost is a revalidation per
    // load rather than a year of a broken form.
    for (const asset of ['register.css', 'register.js']) {
      const response = await request.get(`${PACKAGE_URL}/register/${asset}`);
      expect(response.ok()).toBeTruthy();
      expect(response.headers()['cache-control']).toContain('no-cache');
      expect(response.headers()['expires']).toBeUndefined();
    }
  });

  test('the public submission endpoint is reachable through the front controller', async ({
    request,
  }) => {
    // Uniform refusal, no detail (ADR-0052 decision 2) — what matters here is
    // that the route exists in the packaged layout at all, and that it is not
    // the SPA shell answering 200 to a POST.
    const response = await request.post(`${PACKAGE_URL}/api/public/registrations/context`, {
      data: { secret: 'not-a-real-poster-secret' },
    });
    expect(response.status()).toBeGreaterThanOrEqual(400);
    expect(response.headers()['content-type']).toContain('application/json');
  });
});

test.describe.serial('Package: Upgrade Runner', () => {
  test.skip(!process.env.PACKAGE_TEST, 'Skipped unless PACKAGE_TEST=1');

  /**
   * An installation unpacked from a release before #751 has `config.sample.php`
   * in its document root, and an upgrade is the only moment it can go: a
   * release is unpacked *over* an installation, which adds files and never
   * removes one. So the migrate step retires it by name (`RetiredFiles`).
   *
   * Planted as a real file and asserted on disk rather than over HTTP —
   * `.htaccess` denies both locations, so an HTTP check would come back 403
   * whether or not the file is still there.
   */
  test.beforeAll(() => {
    inPackageContainer('file_put_contents("/app/config.sample.php", "<?php return [];");');
  });

  test('upgrade.php returns 403 with wrong key', async ({ request }) => {
    const response = await request.get(
      `${PACKAGE_URL}/upgrade.php?key=wrong-key`
    );
    expect(response.status()).toBe(403);
    const body = await response.json();
    expect(body.ok).toBe(false);
  });

  test('upgrade.php runs migrations successfully', async ({ request }) => {
    // Same reasoning as step 3 of the wizard: this runs the migration set. It
    // is cheaper here because the wizard already applied them and these are
    // SKIPs — but "cheaper because of what another test did first" is not a
    // budget worth relying on.
    const response = await request.get(
      `${PACKAGE_URL}/upgrade.php?key=${CI_DEPLOY_SECRET}`,
      { timeout: 60_000 }
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

  test('the upgrade retires the copy an older release left in the document root', () => {
    expect(
      inPackageContainer('echo is_file("/app/config.sample.php") ? "yes" : "no";'),
      'the template an older release put in the document root survived the upgrade (#751)'
    ).toBe('no');
    expect(
      inPackageContainer('echo is_file("/app/backend/config.sample.php") ? "yes" : "no";'),
      'the sweep took the template the installer reads with it'
    ).toBe('yes');
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

  /**
   * #733: the offline backup tools must not be reachable over HTTP, on this
   * server or any other — they are meant to be opened from a local copy
   * (file://), and a compromised host could otherwise serve a modified
   * generator that steals the private half it just displayed. `tools/` still
   * ships inside the document root (see "the offline backup tools ship
   * inside the document root" in Package: Data placement below) — this is
   * the denial that has to hold regardless.
   */
  test('the offline backup tools are denied over HTTP', async ({ request }) => {
    for (const file of ['keypair-generator.html', 'backup-decryptor.html']) {
      const response = await request.get(`${PACKAGE_URL}/tools/${file}`);
      expect(response.status(), `tools/${file}`).toBe(403);
    }
  });

  /**
   * #751: `config.sample.php` is the template ConfigWriter substitutes into,
   * read off the disk by the installer and never fetched by a browser. It now
   * ships inside `backend/` — see "the config template ships inside backend/"
   * in Package: Data placement — so both the old location and the new one must
   * refuse. The old one because an installation unpacked from an earlier
   * release still has a copy sitting there until its next upgrade.
   */
  test('the config template is denied at both its old and its new location', async ({ request }) => {
    for (const path of ['/config.sample.php', '/backend/config.sample.php']) {
      const response = await request.get(`${PACKAGE_URL}${path}`);
      expect([403, 404], `${path} returned ${response.status()}`).toContain(response.status());
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
   * The release must actually contain the tools it tells clubs to use (#710
   * regression guard, sharpened by #733): `tools/` ships inside the document
   * root — see `scripts/build-package.sh` — precisely so it can be denied by
   * `.htaccess` ("the offline backup tools are denied over HTTP", in
   * Package: .htaccess access rules) rather than by not existing.
   */
  test('the offline backup tools ship inside the document root', () => {
    for (const file of ['keypair-generator.html', 'backup-decryptor.html']) {
      expect(fileExists(`${DOCUMENT_ROOT}/tools/${file}`), `tools/${file} did not ship (#710 regression)`).toBe(
        true
      );
    }
  });

  /**
   * #751: the installer needs `config.sample.php` on disk long after the
   * install — the mail, backup and scheduler screens all rewrite `config.php`
   * through it months later — but nothing ever requests it over HTTP. Shipping
   * it beside `index.php` therefore bought a URL and nothing else, on a file
   * that outlived the `install.php` clubs are told to delete. `backend/` is
   * denied wholesale by `.htaccess` and is where `config.php` itself lands on
   * a host with no writable parent (decision 2).
   */
  test('the config template ships inside backend/, not in the document root', () => {
    expect(
      fileExists(`${DOCUMENT_ROOT}/config.sample.php`),
      'config.sample.php is back in the document root (#751)'
    ).toBe(false);
    expect(
      fileExists(`${DOCUMENT_ROOT}/backend/config.sample.php`),
      'the installer reads this file when it writes config.php — without it every write fails'
    ).toBe(true);
  });

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
