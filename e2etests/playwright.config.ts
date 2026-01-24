import { defineConfig, devices } from '@playwright/test';

/**
 * Ruderbar E2E Test Configuration
 *
 * Test projects per ADR-0022:
 * - api-tests: Backend API testing (no browser)
 * - admin-chromium: Admin Panel UI tests
 * - terminal-touch: Terminal App touch tests
 *
 * @see https://playwright.dev/docs/test-configuration
 */
export default defineConfig({
  testDir: './tests',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: 'html',

  use: {
    baseURL: process.env.API_URL || 'http://localhost:8080',
    trace: 'on-first-retry',
  },

  projects: [
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
      use: {
        ...devices['Desktop Chrome'],
        baseURL: process.env.ADMIN_URL || 'http://localhost:5173',
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
  ],

  // Web server configuration for local development
  // Uncomment when running tests locally without Docker
  // webServer: {
  //   command: 'cd ../backend && php artisan serve --port=8080',
  //   url: 'http://localhost:8080/api/health',
  //   reuseExistingServer: !process.env.CI,
  // },
});
