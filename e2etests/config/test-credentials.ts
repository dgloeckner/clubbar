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

  // Terminal whose token lifetime already ran out (#106). Active terminal,
  // well-formed token, `token_expires_at` in the past — a state the API cannot
  // produce, so it is seeded. Must match backend/db/seed.sql.
  expiredTerminal: {
    token: 'expired-terminal-token-do-not-use-in-production-9z8y7x6w5v4u',
    deviceId: 'test-device-expired-001',
    name: 'Expired Test Terminal',
    // Read-only in tests. Its whole value is being the one terminal in an
    // expired state, so anything that rotates or revokes it takes that state
    // away from every other spec (E2E Pattern 001).
    id: '44e4567-e89b-12d3-a456-426614174001',
  },

  // TOTP secrets for two-factor authentication tests
  totp: {
    // Pre-enrolled test admin TOTP secret (base32). Matches the AES-encrypted value
    // in reset_test_data.sql, which uses TOTP_ENCRYPTION_KEY=0000...0001.
    adminSecret: 'JBSWY3DPEHPK3PXP',
  },
} as const;
