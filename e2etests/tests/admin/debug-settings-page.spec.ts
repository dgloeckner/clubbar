import { test } from '@playwright/test'

test('debug settings page load with console capture', async ({ page }) => {
  const consoleErrors: Array<{ type: string; text: string }> = []
  const pageErrors: string[] = []

  // Capture console messages
  page.on('console', (msg) => {
    consoleErrors.push({ type: msg.type(), text: msg.text() })
  })

  // Capture page errors
  page.on('pageerror', (err) => {
    pageErrors.push(err.message)
  })

  console.log('\n=== Settings Page Debug Test ===')
  console.log('Navigating to /settings...')

  await page.goto('http://localhost:5173/settings', { waitUntil: 'domcontentloaded' })

  // Wait for app to potentially load
  await page.waitForTimeout(2000)

  const title = await page.title()
  console.log('Page title:', title)

  // Check root element content
  const rootHTML = await page.locator('#root').innerHTML()
  console.log('Root element HTML length:', rootHTML.length)

  if (rootHTML.trim() === '') {
    console.log('⚠️  Root element is EMPTY - React app failed to mount')
  } else {
    console.log('Root HTML preview:', rootHTML.substring(0, 200))
  }

  // Check for React dev tools
  const hasReact = await page.evaluate(() => {
    return !!(window as any).__REACT_DEVTOOLS_GLOBAL_HOOK__
  })
  console.log('React DevTools detected:', hasReact)

  // Log all errors
  console.log('\nConsole errors:', consoleErrors.length > 0 ? consoleErrors : 'None')
  console.log('Page errors:', pageErrors.length > 0 ? pageErrors : 'None')

  // Try to check for SettingsPage
  const hasSettingsPageId = await page.locator('[data-testid="settings-page"]').isVisible({ timeout: 1000 }).catch(() => false)
  console.log('Has settings-page ID:', hasSettingsPageId)

  console.log('=== Test Complete ===\n')
})
