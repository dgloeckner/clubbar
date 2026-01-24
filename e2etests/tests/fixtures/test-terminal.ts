/**
 * Test Terminal Token Fixture
 *
 * Provides Bearer token for authenticating E2E tests against Terminal API.
 * Token must be set up before tests run (via seeder or environment variable).
 */

// This token is set by test setup (see playwright.config.ts)
// It should match the token from the test terminal seeded in the database
export const TEST_TERMINAL_TOKEN = process.env.TEST_TERMINAL_TOKEN || '';

/**
 * Get Authorization headers with Bearer token
 *
 * Usage:
 *   const response = await request.get('/api/sync/members', {
 *     headers: authHeaders()
 *   });
 */
export function authHeaders() {
  if (!TEST_TERMINAL_TOKEN) {
    throw new Error(
      'TEST_TERMINAL_TOKEN not set. Run: docker compose exec backend php artisan db:seed --class=TerminalSeeder'
    );
  }

  return {
    'Authorization': `Bearer ${TEST_TERMINAL_TOKEN}`,
  };
}
