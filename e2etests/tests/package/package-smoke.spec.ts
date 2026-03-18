import { test, expect } from '@playwright/test';

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

test.describe('Package: Install Wizard', () => {
  test.skip(!process.env.PACKAGE_TEST, 'Skipped unless PACKAGE_TEST=1');

  test('install.php requires install key when not authenticated', async ({ request }) => {
    const response = await request.get(`${PACKAGE_URL}/install.php`);
    expect(response.ok()).toBeTruthy();
    const html = await response.text();
    expect(html).toContain('Install Key Required');
    expect(html).toContain('.install-key');
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
    expect(body.message).toBe('Login successful');
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
