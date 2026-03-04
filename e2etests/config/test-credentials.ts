/**
 * Test Credentials - Fixed test credentials for E2E tests
 *
 * These are known, hardcoded test credentials used during development and testing.
 * They must match the values used in backend seed script (backend/db/seed.sql).
 *
 * Usage:
 * - E2E test fixtures use these to authenticate
 * - Tests are reproducible and don't depend on env vars
 * - Seeders populate the database with these credentials
 *
 * Security: These are test-only values. Real production uses strong random values.
 */

export const TEST_CREDENTIALS = {
  // Admin user credentials (created by AdminUsersSeeder)
  admin: {
    email: 'admin@example.com',
    password: 'password123',
  },

  // Terminal API token (created by TerminalSeeder)
  // Must match token hash in backend/db/seed.sql
  terminal: {
    token: 'test-terminal-token-do-not-use-in-production-0a1b2c3d4e5f6g7h',
    deviceId: 'test-device-001',
    name: 'Test Terminal',
  },
} as const;
