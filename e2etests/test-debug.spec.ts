import { test, expect } from '@playwright/test'

test('debug: check login page loads with test IDs', async ({ page }) => {
  await page.goto('http://localhost:5173/login', { waitUntil: 'networkidle' })
  
  // Wait a bit for form to render
  await page.waitForTimeout(1000)
  
  // Check if test ID exists
  const emailInput = page.getByTestId('login-email-input')
  const count = await emailInput.count()
  console.log('Email input found:', count > 0)
  
  if (count === 0) {
    // Try to find what inputs exist
    const allInputs = await page.locator('input[type="email"]').count()
    console.log('Email inputs found by type:', allInputs)
    
    // Try to take screenshot
    await page.screenshot({ path: 'debug-login.png' })
  }
  
  expect(count).toBe(1)
})
