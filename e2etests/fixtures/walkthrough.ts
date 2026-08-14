/**
 * Walkthrough Demo Fixtures
 *
 * Extends Page Object Fixtures with:
 * - Timing helpers (pause, narrationPause, quickPause) for demo pacing
 * - Click cursor indicator (yellow dot visible in video recordings)
 * - Screenshot capture (capture) for slideshow-based walkthroughs
 * - Overridden POM fixtures that wait for data loading to complete
 *   (so the video/screenshots never show "Laden..." loading states)
 */

import { test as pomTest } from './pageObjects'
import { MembersPage, ProductsPage, CategoriesPage, JournalPage, SettlementsPage, SettingsPage, AuditLogPage, DashboardPage } from '../pages'
import { ProfilePage } from '../pages/ProfilePage'
import * as fs from 'fs'
import * as path from 'path'

export { expect } from '@playwright/test'

/** Manifest entry for one screenshot */
export interface ScreenshotEntry {
  index: number
  file: string
  subtitle: string
}

/** Full manifest written to disk */
export interface ScreenshotManifest {
  screenshots: ScreenshotEntry[]
}

const SCREENSHOTS_DIR = path.join(process.cwd(), 'walkthrough-screenshots')

export const test = pomTest.extend<{
  walkthroughLang: string
  showClicks: void
  showCursor: (target: import('@playwright/test').Locator) => Promise<void>
  capture: (subtitle: string) => Promise<void>
  pause: (ms?: number) => Promise<void>
  narrationPause: () => Promise<void>
  quickPause: () => Promise<void>
}>({
  /**
   * Fixture: walkthroughLang
   *
   * Controls the admin UI language for this test. Defaults to 'en'.
   * Override in individual tests with test.use({ walkthroughLang: 'de' }).
   */
  walkthroughLang: ['en', { option: true }],

  /**
   * Fixture: showClicks (auto)
   *
   * Shows a persistent yellow cursor dot that follows mouse movement and
   * pulses on click. Also sets the admin locale in localStorage before
   * any page navigations so the UI renders in the correct language.
   */
  showClicks: [async ({ context, walkthroughLang }, use) => {
    // Set admin locale in localStorage before any page loads
    await context.addInitScript((lang) => {
      localStorage.setItem('adminLocale', lang);
    }, walkthroughLang);

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

  /**
   * Fixture: capture
   *
   * Takes a numbered PNG screenshot and records subtitle text.
   * Screenshots are saved to walkthrough-screenshots/ directory.
   * A manifest JSON is written at the end with all entries.
   */
  capture: [async ({ page }, use) => {
    const entries: ScreenshotEntry[] = []
    let counter = 0

    // Load existing manifest to continue numbering across tests
    const manifestPath = path.join(SCREENSHOTS_DIR, 'manifest.json')
    if (fs.existsSync(manifestPath)) {
      try {
        const existing: ScreenshotManifest = JSON.parse(fs.readFileSync(manifestPath, 'utf-8'))
        counter = existing.screenshots.length
        entries.push(...existing.screenshots)
      } catch { /* start fresh */ }
    }

    fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true })

    await use(async (subtitle: string) => {
      counter++
      const filename = `screenshot-${String(counter).padStart(3, '0')}.png`
      const filepath = path.join(SCREENSHOTS_DIR, filename)

      // Hide the walkthrough cursor dot before capturing
      await page.evaluate(() => {
        const cursor = document.getElementById('walkthrough-cursor')
        if (cursor) cursor.style.display = 'none'
      })

      await page.screenshot({ path: filepath, fullPage: false })

      entries.push({ index: counter, file: filename, subtitle })

      // Write manifest after each capture (crash-safe)
      const manifest: ScreenshotManifest = { screenshots: entries }
      fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + '\n')
    })
  }, { scope: 'test' }],

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
    await page.goto('/products/categories', { waitUntil: 'networkidle' })
    await use(new CategoriesPage(page))
  },

  authenticatedJournalPage: async ({ page }, use) => {
    await page.goto('/journal', { waitUntil: 'networkidle' })
    await use(new JournalPage(page))
  },

  authenticatedSettlementsPage: async ({ page }, use) => {
    await page.goto('/settlements', { waitUntil: 'networkidle' })
    await use(new SettlementsPage(page))
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
