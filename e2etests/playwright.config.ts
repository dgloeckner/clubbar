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
    ? [['github'], ['list'], ['html']]
    : [['html'], ['./reporters/subtitle-reporter.ts']],

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
  ],

  // Web server configuration for local development
  // Uncomment when running tests locally without Docker
  // webServer: {
  //   command: 'cd ../backend && php artisan serve --port=8080',
  //   url: 'http://localhost:8080/api/health',
  //   reuseExistingServer: !process.env.CI,
  // },
});
