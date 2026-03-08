/**
 * Walkthrough Demo Fixtures
 *
 * Extends Page Object Fixtures with timing helpers for demo video narration.
 * Implements pacing pauses for natural-looking UI walkthroughs.
 *
 * Usage in tests:
 *   import { test, expect } from '../fixtures/walkthrough'
 *
 *   test('demo walkthrough', async ({ authenticatedMembersPage, narrationPause }) => {
 *     // Perform action
 *     await authenticatedMembersPage.clickCreateButton()
 *     // Pause for narration
 *     await narrationPause()
 *   })
 */

import { test as pomTest } from './pageObjects'

export { expect } from '@playwright/test'

export const test = pomTest.extend<{
  pause: (ms?: number) => Promise<void>
  narrationPause: () => Promise<void>
  quickPause: () => Promise<void>
}>({
  /**
   * Fixture: pause
   *
   * Generic pause helper with configurable duration.
   * Default: 1000ms
   *
   * Usage:
   *   await pause()        // 1 second
   *   await pause(2000)    // 2 seconds
   */
  pause: async ({}, use) => {
    await use(async (ms = 1000) => {
      await new Promise(resolve => setTimeout(resolve, ms))
    })
  },

  /**
   * Fixture: narrationPause
   *
   * Pause for spoken narration during demo.
   * Duration: 2000ms
   *
   * Usage:
   *   await authenticatedMembersPage.clickCreateButton()
   *   await narrationPause()  // Pause so narrator can speak
   */
  narrationPause: async ({ pause }, use) => {
    await use(async () => {
      await pause(2000)
    })
  },

  /**
   * Fixture: quickPause
   *
   * Short pause between UI interactions for visual breathing room.
   * Duration: 600ms
   *
   * Usage:
   *   await authenticatedMembersPage.fillName('John Doe')
   *   await quickPause()  // Brief pause before next action
   */
  quickPause: async ({ pause }, use) => {
    await use(async () => {
      await pause(600)
    })
  },
})
