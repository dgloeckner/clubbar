import { test } from '@playwright/test'

test('test form field rendering', async ({ page }) => {
  console.log('\n=== Form Field Render Test ===')

  // Navigate directly to settings
  await page.goto('http://localhost:5173/settings', { waitUntil: 'networkidle' })

  // Wait longer for React to boot
  await page.waitForTimeout(3000)

  // Check console for specific errors
  const bodyHTML = await page.locator('body').innerHTML()

  console.log('Body length:', bodyHTML.length)
  console.log('Contains settings-page:', bodyHTML.includes('data-testid="settings-page"'))
  console.log('Contains input fields:', bodyHTML.includes('<input'))
  console.log('Body preview (first 2000 chars):')
  console.log(bodyHTML.substring(0, 2000))

  console.log('\n=== Test Complete ===\n')
})
