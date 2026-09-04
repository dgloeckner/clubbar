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
/**
 * CI lanes (#e2e-sharding).
 *
 * `--shard` splits *tests*, but it cannot split a **dependency-connected
 * component**: every project in one has to land in the same shard. Almost
 * everything in this file is in one such component, because `api-ordered`,
 * `api-rotation` and the whole mail chain declare `api-tests` and
 * `admin-chromium` as dependencies. Measured on the two-way shard CI used to
 * run: shard 1 got **28** tests and shard 2 got **995**, so the second shard
 * was the entire suite and the first was a 52-second no-op that still paid two
 * minutes of stack setup.
 *
 * Those dependencies are about **one shared database**, not about data. They
 * order the specs that cannot run beside company: the MFA lockout that eats
 * the shared admin's TOTP time-step, the key rotation that changes what the
 * whole installation seals under, the mail chains whose drains claim the whole
 * queue and whose `mail_config` is a singleton other specs write.
 *
 * CI gives each job its own MariaDB, so running those projects in a **job of
 * their own** satisfies every one of those requirements by construction —
 * nothing else is running against that database at all. `E2E_LANE=chain` is
 * how the run says so, and it is the only thing that relaxes the edges:
 * a local `npx playwright test` sets nothing, keeps every dependency, and
 * behaves exactly as it always has.
 *
 * Ordering *within* the chain lane is preserved, because that part is real:
 * the rotation still runs after the lockout, and the four mail chains still
 * run in their fixed order.
 */
const CHAIN_LANE = process.env.E2E_LANE === 'chain'

/**
 * The dependencies a chain-lane project keeps. `whenShared` is what it needs
 * when the parallel suites run against the same database; `whenAlone` is what
 * is still true when they do not.
 */
const orderedAfter = (whenShared: string[], whenAlone: string[] = ['setup auth']): string[] =>
  CHAIN_LANE ? whenAlone : whenShared

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

    // The offline tools, as files - no server, no stack, no fixtures.
    //
    // tools/backup-decryptor.html is opened from `file://` because that is how
    // a key holder actually uses it: on a machine they trust, with the network
    // off. Its crypto half is covered headlessly by
    // scripts/backup-decryptor-interop.test.mjs; this project covers the page
    // around it, which is where a bug can decrypt correctly and still show the
    // holder nothing.
    {
      name: 'tools',
      testDir: './tests/tools',
      use: {
        ...devices['Desktop Chrome'],
      },
    },

    /*
     * The public onboarding page (#781).
     *
     * Its own project because it is its own application: served as static files
     * from the backend's document root, at `http://localhost:8080/register/`,
     * with no build step and no relationship to the admin SPA on :5173. Putting
     * it in `admin-chromium` would point it at the wrong origin.
     *
     * **iPhone 14 metrics on Chromium**, and the mix is deliberate. The
     * viewport, the touch flag and the mobile user agent are what these specs
     * assert against — 375px layout, 44px targets, a numeric keypad — while the
     * page itself is plain DOM with no framework, so there is no rendering
     * engine's opinion in play. Pinning it to Chromium keeps it runnable in a
     * sandbox that has only Chromium installed, which is where most of this
     * work happens; `admin-mobile` remains the suite's WebKit lane.
     */
    {
      name: 'register',
      testDir: './tests/register',
      use: {
        ...devices['iPhone 14'],
        browserName: 'chromium',
        baseURL: process.env.API_URL || 'http://localhost:8080',
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
      dependencies: orderedAfter(['api-tests']),
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
      dependencies: orderedAfter(['api-tests', 'api-ordered', 'admin-chromium'], ['api-ordered']),
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
    // Everything that queues has run; nothing that delivers has started. That
    // is the only moment the backlog can be discarded, and it is why this is a
    // project with dependencies rather than a `beforeAll` in one spec.
    {
      name: 'quiet mail backlog',
      testDir: './tests',
      testMatch: /mail-backlog\.setup\.ts/,
      // The same edge `mail-chain` used to carry, and for the same reason: it
      // waits for the suites that fill the queue, unless the chain lane has the
      // database to itself, in which case there is nothing to wait for.
      dependencies: orderedAfter(['api-tests', 'admin-chromium']),
    },

    // The backup alarm (#693): an empty backup directory → bin/cron.php →
    // Mailpit.
    //
    // **First among the chains, and that ordering is load-bearing.** The scan
    // reads one directory shared by the whole stack and every `bin/cron.php`
    // run scans before it drains — so on a stack whose backup has never run,
    // every later drain would queue a warning to every admin, into the very
    // mailboxes the projects below count exactly.
    //
    // So this project owns the broken state: it creates it, asserts on it, and
    // its last test triggers a real backup so everything after it scans a
    // directory with an archive in it and queues nothing. The same containment
    // `mail-digest` uses for its cadence, for the same reason.
    {
      name: 'mail-backup',
      testDir: './tests/mail-backup',
      fullyParallel: false,
      dependencies: ['quiet mail backlog'],
    },

    {
      name: 'mail-chain',
      testDir: './tests/mail',
      fullyParallel: false,
      dependencies: ['mail-backup'],
    },

    // The Deckelauszug chain (#463): cadence on → bin/cron.php → Mailpit.
    //
    // Its own project rather than a second file in `mail-chain`, and the reason
    // is the one thing `fullyParallel: false` does **not** buy: it serialises
    // the tests inside a file, but Playwright still runs two files of the same
    // project on two workers. That is fine for announcements, which only ever
    // reach the member a spec finalized against. It is not fine here.
    //
    // A Deckelauszug goes to **every member in scope**, which is every member
    // in the database with an address — including the ones
    // `prenotification-chain.spec.ts` has just created and is waiting on. While
    // `statement_cadence` is `monthly`, any drain enqueues a statement for
    // those members too, and that spec's `waitForMessages(address, 1)` would
    // find two messages and fail, for a reason invisible in its own file.
    //
    // `dependencies: ['mail-chain']` removes the overlap by construction: the
    // cadence is only ever switched on after the announcement chain has
    // finished asserting. It is switched back to `off` in this project's
    // `afterAll`, so the window is this project and nothing else.
    {
      name: 'mail-statement',
      testDir: './tests/mail-statement',
      fullyParallel: false,
      dependencies: ['mail-chain'],
    },

    // The credential expiry chain (#438): a token near expiry → bin/cron.php →
    // an admin's mailbox.
    //
    // Last of the three, and its own project for the same reason `mail-statement`
    // is: the messages it asserts on are addressed to **every active admin**, so
    // while its fixtures exist any drain in any other file queues warnings for
    // them too. Running after the two member-facing chains keeps that window
    // inside this project — and its own `afterAll` deactivates the terminal, so
    // the window closes rather than merely narrowing.
    //
    // It is also the only project that writes SQL (`utils/sql.ts`): a terminal
    // token's lifetime is not settable through the API, so there is no other way
    // to stand a credential seven days from expiry.
    {
      name: 'mail-credentials',
      testDir: './tests/mail-credentials',
      fullyParallel: false,
      dependencies: ['mail-statement'],
    },

    // The issuance chain (ADR-0043): minting a terminal credential → bin/cron.php
    // → every active admin's mailbox.
    //
    // Its own project rather than a second file under `mail-credentials`, for
    // the reason that split those two: `fullyParallel: false` serialises the
    // tests inside a file, but Playwright still runs two files of one project on
    // two workers. Both files address **every active admin**, so while this
    // file's admin exists, an enrolment here would land in the expiry file's
    // mailbox and vice versa — each failing on a count the other caused.
    //
    // Last, because it is the newest of the four and its drains claim the whole
    // queue like the rest. `dependencies` makes that ordering structural rather
    // than alphabetical luck.
    {
      name: 'mail-issuance',
      testDir: './tests/mail-issuance',
      fullyParallel: false,
      dependencies: ['mail-credentials'],
    },

    // The admin lifecycle chain (ADR-0044 rule 3): creating an account or
    // moving its roles → bin/cron.php → the club's mailbox.
    //
    // Its own project for the reason each of the four before it is: these
    // notices go to **every active admin**, so while this file's accounts exist
    // its messages would land in the other files' mailboxes and theirs in this
    // one, each failing on a count the other caused. Last, because its drains
    // claim the whole queue like the rest, and `dependencies` makes that
    // ordering structural rather than alphabetical luck.
    //
    // It is also the only chain that asserts for an address belonging to **no
    // account at all** — the club-level address, which is the entire point of
    // the feature.
    {
      name: 'mail-lifecycle',
      testDir: './tests/mail-lifecycle',
      fullyParallel: false,
      dependencies: ['mail-issuance'],
    },

    // The Jugendschutz chain (#622): an underage sale at sync → bin/cron.php →
    // an admin's mailbox.
    //
    // Its own project for the reason each of the five before it is: the notice
    // goes to **every active admin**, so while this file's admin exists its
    // messages would land in the other files' mailboxes and theirs in this one,
    // each failing on a count the other caused. Last, because its drain claims
    // the whole queue like the rest, and `dependencies` makes that ordering
    // structural rather than alphabetical luck.
    {
      name: 'mail-jugendschutz',
      testDir: './tests/mail-jugendschutz',
      fullyParallel: false,
      dependencies: ['mail-lifecycle'],
    },

    // The role chain (#633): a notice queued for one office → bin/cron.php →
    // that office's mailbox, and nothing in the others'.
    //
    // Its own project for the reason each of the six before it is: it asserts
    // that two mailboxes stay **empty**, which is the assertion most easily
    // broken by a file it never heard of. Its accounts hold the lesser offices,
    // so nothing else in the suite writes to them by design — but a second file
    // of this project running beside it on another worker would drain the queue
    // between this one's finalize and its assertion, which is exactly what the
    // fixed order buys everywhere else in the chain.
    //
    // Last, because its drains claim the whole queue like the rest, and
    // `dependencies` makes that ordering structural rather than alphabetical
    // luck.
    {
      name: 'mail-roles',
      testDir: './tests/mail-roles',
      fullyParallel: false,
      dependencies: ['mail-jugendschutz'],
    },

    // The near-limit digest chain (ADR-0047): a member near their ceiling →
    // bin/cron.php → the Kassenwart's mailbox.
    //
    // Its own project for the reason each of the seven before it is, and more
    // sharply: this digest reports on **every** member near their ceiling, so
    // while its cadence is on, any drain anywhere in the suite queues one — and
    // its content depends on fixtures other files created. Running it alone,
    // last, with the cadence switched on in `beforeAll` and off in `afterAll`,
    // is what keeps that window inside this project.
    //
    // `dependencies` makes the ordering structural rather than alphabetical
    // luck, as it does for the rest of the chain.
    {
      name: 'mail-digest',
      testDir: './tests/mail-digest',
      fullyParallel: false,
      dependencies: ['mail-roles'],
    },

    // The member lifecycle chain (ADR-0051): a card assigned or an address
    // changed → bin/cron.php → the *member's* mailbox.
    //
    // The one chain that writes to no admin mailbox at all — every message it
    // asserts on is addressed to a member it created, at an address it
    // generated — so unlike the eight before it, it cannot collide with their
    // counts and they cannot collide with its.
    //
    // It is in the chain anyway, and last, for the reason that has nothing to
    // do with mailboxes: its drains claim the **whole queue**, so running it
    // beside another sending project would sweep messages that project is
    // asserting are still pending. Half its claims are negative ("a member with
    // no card hears nothing"), and a concurrent drain is exactly what makes a
    // negative pass for the wrong reason.
    //
    // `dependencies` makes that ordering structural rather than alphabetical
    // luck, as it does for the rest of the chain.
    {
      name: 'mail-member',
      testDir: './tests/mail-member',
      fullyParallel: false,
      dependencies: ['mail-digest'],
    },

    // The Anmeldelink chain (#821, ADR-0053): an admin types an address →
    // bin/cron.php → a stranger's mailbox → back through the registration form
    // → the review inbox.
    //
    // Last, and its own project, for the two reasons every chain here is:
    // its drains claim the whole queue, and it writes `self_registration_config`
    // — the singleton row four other spec files contend over. It takes the same
    // cross-file lock they do (#784), but a project of its own is what keeps its
    // drains from sweeping a sending project's pending messages.
    //
    // Unlike the chains above it, this one also drives a **browser**: the claim
    // that the mailed link is genuinely the poster's is only checkable by
    // opening what was delivered, in a context holding no session, and
    // completing a submission with it.
    {
      name: 'mail-anmeldelink',
      testDir: './tests/mail-anmeldelink',
      fullyParallel: false,
      dependencies: ['mail-member'],
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
