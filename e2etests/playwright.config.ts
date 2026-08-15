import { defineConfig, devices } from '@playwright/test';

/**
 * Club Bar E2E Test Configuration
 *
 * Test projects per ADR-0022:
 * - api-tests: Backend API testing (no browser)
 * - admin-chromium: Admin Panel UI tests
 * - terminal-touch: Terminal App touch tests
 *
 * Note: Tests use hardcoded credentials from config/test-credentials.ts
 * No environment variables needed - credentials are deterministic and reproducible
 *
 * @see https://playwright.dev/docs/test-configuration
 */
export default defineConfig({
  testDir: './tests',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 4 : undefined,
  reporter: process.env.CI
    ? [['github'], ['list'], ['html'], ['json', { outputFile: 'results/latest.json' }]]
    : [['html'], ['./reporters/subtitle-reporter.ts'], ['json', { outputFile: 'results/latest.json' }]],

  use: {
    baseURL: process.env.API_URL || 'http://localhost:8080',
    trace: 'on-first-retry',
    actionTimeout: 10000,
    navigationTimeout: 10000,
  },

  projects: [
    // Setup project: Authenticate before running tests
    // This runs once and saves storage state for test projects
    {
      name: 'setup auth',
      testMatch: 'auth.setup.ts',
      use: {
        ...devices['Desktop Chrome'],
        baseURL: process.env.ADMIN_URL || 'http://localhost:5173',
      },
    },


    // API Tests - No browser, pure HTTP testing
    {
      name: 'api-tests',
      testDir: './tests/api',
      // Exclude the rate-limit, attempt-cap and key-rotation tests — they are
      // ordered, not parallel, and live in the `rate-limit` and `api-ordered`
      // projects below. Leaving `key-rotation` in would be the worst of them:
      // it changes the installation's ACTIVE encryption key, so it would move
      // the ground under every other spec's member writes mid-run.
      testIgnore: /(terminal-rate-limit|auth-rate-limit|auth-mfa-lockout|key-rotation)\.spec\.ts/,
      // The authenticatedRequest fixture (fixtures/auth.fixture.ts) reuses the
      // session "setup auth" writes to playwright/.auth/admin.json rather than
      // logging in itself (#338 — avoids racing TOTP replay protection against
      // the shared seeded admin's secret), so that file must exist first.
      dependencies: ['setup auth'],
      use: {
        // No browser needed for API tests
      },
    },

    // Admin Panel - Chromium desktop
    {
      name: 'admin-chromium',
      testDir: './tests/admin',
      dependencies: ['setup auth'],
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1920, height: 1080 },
        baseURL: process.env.ADMIN_URL || 'http://localhost:5173',
        storageState: 'playwright/.auth/admin.json',
      },
    },

    // Admin Panel - Mobile (iPhone 14)
    {
      name: 'admin-mobile',
      testDir: './tests/admin-mobile',
      dependencies: ['setup auth'],
      use: {
        ...devices['iPhone 14'],
        baseURL: process.env.ADMIN_URL || 'http://localhost:5173',
        storageState: 'playwright/.auth/admin.json',
      },
    },

    // Terminal - Touch device simulation
    {
      name: 'terminal-touch',
      testDir: './tests/terminal',
      use: {
        ...devices['iPad Pro 11'],
        baseURL: process.env.TERMINAL_URL || 'http://localhost:5174',
        hasTouch: true,
      },
    },

    // Ordered rate-limit tests — excluded from api-tests above, and not part of
    // the `npm test` project list, because they deliberately exhaust an attempt
    // window and would then 429 every other test's login. Run them on their
    // own, one at a time:
    //   npm run test:rate-limit
    //
    // `--workers=1` is the point of this project, not an optimisation: each
    // spec owns a counter that is global to the backend — the login attempt
    // window, the terminal token window — so two of them running at once
    // measure each other rather than the limiter.
    //
    // They also need an empty attempts table and the matching
    // DISABLE_*_RATE_LIMITING flag removed from docker-compose.yml, which the
    // dev stack sets to "true". They therefore cannot pass against a default
    // dev stack, which is why they stay out of the default run.
    {
      name: 'rate-limit',
      testDir: './tests/api',
      testMatch: /(terminal-rate-limit|auth-rate-limit)\.spec\.ts/,
      fullyParallel: false,
      dependencies: ['setup auth'],
    },

    // Ordered, but part of the default run.
    //
    // The MFA attempt cap counts inside a single pending session, so unlike the
    // two specs above it needs no flag change and passes against a plain dev
    // stack. What it cannot survive is company: its logins consume the shared
    // admin's TOTP time-step, which `totp_last_timestep` (#338) hands out once
    // per 30 seconds, so run 4-wide it races every other spec that logs in as
    // that admin — and loses for a reason that has nothing to do with the cap.
    //
    // `dependencies` is what orders it: the parallel API suite must finish
    // before this project starts, and with `fullyParallel: false` the specs
    // here run one after another in a single worker. Ordered rather than
    // skipped — an attempt cap nobody exercises is one nobody knows is still
    // there.
    //
    {
      name: 'api-ordered',
      testDir: './tests/api',
      testMatch: /auth-mfa-lockout\.spec\.ts/,
      fullyParallel: false,
      dependencies: ['api-tests'],
    },

    // Last of all, and for a stronger reason than the project above.
    //
    // Rotating the encryption key changes what the *whole installation* seals
    // under (#394). Between activating the successor and draining the last
    // batch, the active key and the key the stored rows are sealed under are
    // deliberately different — and any SEPA export landing in that window
    // brings the new private key to rows still holding the old ciphertext.
    // That is correct behaviour and a broken test, so it waits for every
    // project that exports one: `tests/api` and `tests/admin`. Writing a
    // member during a rotation is harmless by contrast — a write always seals
    // under whatever key is active — which is why `admin-mobile` is not in the
    // list, and why this does not drag WebKit into an otherwise Chromium run.
    //
    // The cost is that `--project=api-rotation` on its own pulls those suites
    // in as dependencies. That is the right way round: this spec is meaningful
    // as the tail of a full run, not on its own.
    {
      name: 'api-rotation',
      testDir: './tests/api',
      testMatch: /key-rotation\.spec\.ts/,
      fullyParallel: false,
      dependencies: ['api-tests', 'api-ordered', 'admin-chromium'],
    },

    // The mail chain (#409): finalize → backend/bin/cron.php → Mailpit.
    //
    // Ordered rather than parallel, and for two reasons that are both about
    // state nobody owns:
    //
    //   1. **A drain claims the whole queue.** These specs assert on what a run
    //      delivered — and one of them asserts that a cancelled settlement
    //      delivers *nothing at all*, which a foreign drain firing in the
    //      window between the finalize and the cancel would break. Running
    //      after the suites that create settlements keeps every drain in the
    //      run inside this project, where the tests know about them.
    //   2. **`mail_config` and `sepa_config` are singletons.** This project
    //      sets the sender and the Kassenwart reply-to it asserts on, and reads
    //      the creditor block it expects the announcement to name. Both rows are
    //      written by specs in the two projects below — `mail-config.spec.ts`
    //      and `settlements.spec.ts` in api-tests, `mail-settings.spec.ts` and
    //      `settings-sepa-config.spec.ts` in admin-chromium. One side has to go
    //      second, and it is this one.
    //
    // The cost of the ordering: Playwright **skips a project whose dependency
    // failed**, so a genuine failure anywhere in those two suites leaves these
    // specs unrun and reported as skipped. In a red run that hides nothing —
    // the run is red — but it is worth knowing before reading "10 skipped" as
    // "10 fine". CI retries twice, so a flake does not cost the chain.
    //
    // `fullyParallel: false` then serialises the file itself, so the drains
    // inside it happen one at a time.
    //
    // Needs the compose stack (the drain runs through `docker compose exec`)
    // and Mailpit on :8025. `--no-deps` runs it alone against a stack that is
    // already seeded, which is what to use while iterating on a single spec.
    {
      name: 'mail-chain',
      testDir: './tests/mail',
      fullyParallel: false,
      dependencies: ['api-tests', 'admin-chromium'],
    },

    // Package smoke tests - only run when PACKAGE_TEST=1
    {
      name: 'package-tests',
      testDir: './tests/package',
      use: {
        baseURL: process.env.PACKAGE_URL || 'http://localhost:8080',
      },
    },

    // Walkthrough data seeding - runs before walkthrough recording
    {
      name: 'setup walkthrough-data',
      testMatch: /seed-data\.setup\.ts/,
      testDir: './tests/walkthrough',
      use: {
        baseURL: 'http://localhost:8080',
      },
      dependencies: ['setup auth'],
    },

    // Walkthrough recording - browser demo with video capture
    {
      name: 'walkthrough',
      testDir: './tests/walkthrough',
      testIgnore: /\.setup\.ts/,
      use: {
        ...devices['Desktop Chrome'],
        baseURL: 'http://localhost:5173',
        storageState: 'playwright/.auth/admin.json',
        video: {
          mode: 'on',
          size: { width: 1280, height: 720 },
        },
        viewport: { width: 1280, height: 720 },
        // No slowMo — POM methods (setSepaFilter, etc.) have waitForResponse
        // after click, which races with slowMo. Pacing via pause fixtures instead.
      },
      dependencies: ['setup auth', 'setup walkthrough-data'],
    },

    // Walkthrough screenshots - captures PNG at key UI states for slideshow video
    {
      name: 'walkthrough-screenshots',
      testDir: './tests/walkthrough',
      testIgnore: /\.setup\.ts/,
      use: {
        ...devices['Desktop Chrome'],
        baseURL: 'http://localhost:5173',
        storageState: 'playwright/.auth/admin.json',
        video: { mode: 'off' },
        viewport: { width: 1920, height: 1038 },
      },
      dependencies: ['setup auth', 'setup walkthrough-data'],
    },
  ],

  // Web server configuration for local development
  // Uncomment when running tests locally without Docker
  // webServer: {
  //   command: 'cd ../backend && php artisan serve --port=8080',
  //   url: 'http://localhost:8080/api/health',
  //   reuseExistingServer: !process.env.CI,
  // },
});
