/**
 * Walkthrough Demo Fixtures
 *
 * Extends Page Object Fixtures with:
 * - Timing helpers (pause, narrationPause, quickPause) for demo pacing
 * - Click cursor indicator (yellow dot visible in video recordings)
 * - Overridden POM fixtures that wait for data loading to complete
 *   (so the video never shows "Laden..." loading states)
 */

import { test as pomTest } from './pageObjects'
import { MembersPage, ProductsPage, CategoriesPage, JournalPage, SettingsPage, AuditLogPage, DashboardPage } from '../pages'
import { ProfilePage } from '../pages/ProfilePage'

export { expect } from '@playwright/test'


export const test = pomTest.extend<{
  showClicks: void
  showCursor: (target: import('@playwright/test').Locator) => Promise<void>
  pause: (ms?: number) => Promise<void>
  narrationPause: () => Promise<void>
  quickPause: () => Promise<void>
}>({
  /**
   * Fixture: showClicks (auto)
   *
   * Shows a persistent yellow cursor dot that follows mouse movement and
   * pulses on click. Uses context.addInitScript() so the cursor script
   * is registered BEFORE any page navigations (including POM fixtures).
   */
  showClicks: [async ({ context }, use) => {
    await context.addInitScript(() => {
      const setup = () => {
        if (document.getElementById('walkthrough-cursor')) return;

        const style = document.createElement('style');
        style.textContent = [
          // Hide loading indicators so video never shows "Laden..."
          '[data-testid$="-loading"], [data-testid$="-loading-indicator"] {',
          '  display: none !important;',
          '}',
          '#walkthrough-cursor {',
          '  position: fixed;',
          '  pointer-events: none;',
          '  z-index: 2147483647;',
          '  width: 40px;',
          '  height: 40px;',
          '  margin-left: -20px;',
          '  margin-top: -20px;',
          '  border-radius: 50%;',
          '  background: rgba(250, 204, 21, 0.45);',
          '  border: 3px solid rgba(250, 204, 21, 0.9);',
          '  box-shadow: 0 0 16px 4px rgba(250, 204, 21, 0.5);',
          '  transition: transform 100ms ease-out, background 100ms ease-out;',
          '  display: none;',
          '}',
          '#walkthrough-cursor.clicking {',
          '  transform: scale(2);',
          '  background: rgba(250, 204, 21, 0.7);',
          '  border-color: rgba(234, 179, 8, 1);',
          '  box-shadow: 0 0 30px 8px rgba(250, 204, 21, 0.6);',
          '}',
        ].join('\n');
        document.head.appendChild(style);

        const cursor = document.createElement('div');
        cursor.id = 'walkthrough-cursor';
        document.body.appendChild(cursor);

        document.addEventListener('mousemove', (e: MouseEvent) => {
          cursor.style.left = e.clientX + 'px';
          cursor.style.top = e.clientY + 'px';
          cursor.style.display = 'block';
        }, true);

        document.addEventListener('mousedown', () => {
          cursor.classList.add('clicking');
        }, true);

        document.addEventListener('mouseup', () => {
          setTimeout(() => cursor.classList.remove('clicking'), 300);
        }, true);
      };

      if (document.body) {
        setup();
      } else {
        document.addEventListener('DOMContentLoaded', setup);
      }
    });
    await use();
  }, { auto: true }],

  /**
   * Fixture: showCursor
   *
   * Moves the mouse cursor to a target element and pauses briefly so the
   * yellow dot is visible in the video BEFORE the actual click happens.
   * Call this before .click() on important interactive elements.
   */
  showCursor: async ({ page }, use) => {
    await use(async (target) => {
      await target.scrollIntoViewIfNeeded();
      const box = await target.boundingBox();
      if (box) {
        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.waitForTimeout(300);
      }
    });
  },

  // ─── Overridden POM fixtures: networkidle ensures all API data is loaded ───
  // Regular POM fixtures use 'domcontentloaded' (fast but shows loading states).
  // Walkthrough overrides use 'networkidle' so video never shows "Laden...".

  authenticatedDashboardPage: async ({ page }, use) => {
    await page.goto('/dashboard', { waitUntil: 'networkidle' })
    await use(new DashboardPage(page))
  },

  authenticatedMembersPage: async ({ page }, use) => {
    await page.goto('/members', { waitUntil: 'networkidle' })
    await use(new MembersPage(page))
  },

  authenticatedProductsPage: async ({ page }, use) => {
    await page.goto('/products', { waitUntil: 'networkidle' })
    await use(new ProductsPage(page))
  },

  authenticatedCategoriesPage: async ({ page }, use) => {
    await page.goto('/categories', { waitUntil: 'networkidle' })
    await use(new CategoriesPage(page))
  },

  authenticatedJournalPage: async ({ page }, use) => {
    await page.goto('/journal', { waitUntil: 'networkidle' })
    await use(new JournalPage(page))
  },

  authenticatedSettingsPage: async ({ page }, use) => {
    await page.goto('/settings', { waitUntil: 'networkidle' })
    await use(new SettingsPage(page))
  },

  authenticatedAuditLogPage: async ({ page }, use) => {
    await page.goto('/audit-log', { waitUntil: 'networkidle' })
    await use(new AuditLogPage(page))
  },

  authenticatedProfilePage: async ({ page }, use) => {
    await page.goto('/profile', { waitUntil: 'networkidle' })
    await use(new ProfilePage(page))
  },

  // ─── Timing fixtures ───
  // With slowMo: 300ms on every Playwright action, we need much less
  // manual pausing. These are just "dwell time" for the viewer to absorb.

  pause: async ({}, use) => {
    await use(async (ms = 600) => {
      await new Promise(resolve => setTimeout(resolve, ms))
    })
  },

  narrationPause: async ({ pause }, use) => {
    await use(async () => {
      await pause(1200)
    })
  },

  quickPause: async ({ pause }, use) => {
    await use(async () => {
      await pause(300)
    })
  },
})
