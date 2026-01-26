/**
 * Authentication Setup
 *
 * This setup runs once before tests to authenticate and save storage state.
 * Implements Playwright's recommended multi-role authentication pattern:
 * https://playwright.dev/docs/auth#multiple-signed-in-roles
 *
 * Setup Project Configuration:
 * - Runs before all test projects
 * - Logs in test users and saves auth state to files
 * - Tests then use saved state instead of logging in repeatedly
 *
 * Benefits:
 * - Tests run faster (no repeated login)
 * - More reliable (auth happens once in controlled setup)
 * - Cleaner test code (no auth logic in fixtures)
 * - Easy to add more user roles (admin, member, etc.)
 *
 * Usage in tests:
 *   test.use({ storageState: 'playwright/.auth/admin.json' });
 *   test('my test', async ({ page }) => {
 *     // Already authenticated as admin
 *     await page.goto('/members');
 *   });
 */

import { test as setup } from '@playwright/test'
import { LoginPage } from '../pages'
import path from 'path'

// Define auth state paths
const authDir = 'playwright/.auth'
const adminAuthFile = path.join(authDir, 'admin.json')

/**
 * Setup: Admin User Authentication
 *
 * Logs in as admin and saves storage state for reuse in tests.
 * This runs once before tests using the admin auth state.
 */
setup('authenticate as admin', async ({ page, context }) => {
  // Navigate to login
  const loginPage = new LoginPage(page)
  await loginPage.navigate()

  // Perform login
  await loginPage.login('admin@example.com', 'password123', true)

  console.log('✅ Admin authentication successful')
  console.log(`   Saving auth state to: ${adminAuthFile}`)

  // Save storage state (localStorage + cookies)
  await context.storageState({ path: adminAuthFile })
})
