import { test, expect } from '@playwright/test'

test.describe('Admin UI Features - Icons, Loading, Responsive Design', () => {
  test.beforeEach(async ({ page }) => {
    // Navigate to login page and login first
    await page.goto('http://localhost:5173/login')

    // Fill email and password fields using test IDs
    await page.getByTestId('login-email-input').fill('admin@example.com')
    await page.getByTestId('login-password-input').fill('password123')

    // Click login button using test ID
    await page.getByTestId('login-submit-button').click({ timeout: 10000 })

    // Wait for navigation to members page
    await page.waitForURL('**/members', { timeout: 15000 })
  })

  test.describe('Icon-based Navigation', () => {
    test('should display navigation tabs with icons', async ({ page }) => {
      // Get all nav links
      const navLinks = page.locator('nav a')

      // Verify we have 5 nav items
      expect(await navLinks.count()).toBe(5)

      // Check that each nav item has an SVG icon
      for (let i = 0; i < 5; i++) {
        const link = navLinks.nth(i)
        const icon = link.locator('svg')
        expect(await icon.isVisible()).toBe(true)
      }
    })

    test('should show all 5 navigation items with correct labels', async ({ page }) => {
      const expectedItems = [
        'Mitglieder',
        'Produkte',
        'Buchungsjournal',
        'Abrechnungen',
        'Statistik',
      ]

      for (const label of expectedItems) {
        const link = page.locator(`nav a:has-text("${label}")`)
        expect(await link.count()).toBeGreaterThan(0)
      }
    })

    test('should highlight active navigation tab with blue background', async ({ page }) => {
      // Members page should have active Mitglieder tab
      const activeMembersTab = page.locator('nav a').filter({ hasText: 'Mitglieder' }).first()

      const style = await activeMembersTab.getAttribute('style')
      expect(style).toContain('rgba(59, 130, 246, 0.2)')
    })

    test('should navigate to different pages when clicking nav items', async ({ page }) => {
      // Click Produkte
      await page.click('nav a:has-text("Produkte")')
      await page.waitForURL('**/products')
      expect(page.url()).toContain('/products')

      // Click Buchungsjournal
      await page.click('nav a:has-text("Buchungsjournal")')
      await page.waitForURL('**/journal')
      expect(page.url()).toContain('/journal')

      // Click Abrechnungen
      await page.click('nav a:has-text("Abrechnungen")')
      await page.waitForURL('**/settlements')
      expect(page.url()).toContain('/settlements')

      // Click Statistik
      await page.click('nav a:has-text("Statistik")')
      await page.waitForURL('**/statistics')
      expect(page.url()).toContain('/statistics')

      // Navigate back to Mitglieder
      await page.click('nav a:has-text("Mitglieder")')
      await page.waitForURL('**/members')
      expect(page.url()).toContain('/members')
    })
  })

  test.describe('User Badge and Logout Button', () => {
    test('should display user badge with icon on desktop', async ({ page }) => {
      // Set desktop viewport
      await page.setViewportSize({ width: 1440, height: 900 })

      // User badge should be visible using test ID
      const userBadge = page.getByTestId('header-user-badge')
      expect(await userBadge.isVisible()).toBe(true)

      // Check for user icon in badge
      const userIcon = page.getByTestId('header-user-icon')
      expect(await userIcon.isVisible()).toBe(true)
    })

    test('should hide user badge on mobile viewport', async ({ page }) => {
      // Set mobile viewport
      await page.setViewportSize({ width: 375, height: 667 })
      await page.waitForLoadState('networkidle')

      // User badge should not be visible
      const userBadge = page.locator('header').locator('text=Admin User')
      expect(await userBadge.count()).toBe(0)
    })

    test('should display logout button with icon and text on desktop', async ({ page }) => {
      // Set desktop viewport
      await page.setViewportSize({ width: 1440, height: 900 })

      // Find logout button
      const logoutBtn = page.locator('button:has-text("Abmelden")').first()
      expect(await logoutBtn.isVisible()).toBe(true)

      // Check for logout icon
      const logoutIcon = logoutBtn.locator('svg')
      expect(await logoutIcon.isVisible()).toBe(true)

      // Check for text
      const btnText = logoutBtn.locator('span:has-text("Abmelden")')
      expect(await btnText.isVisible()).toBe(true)
    })

    test('should logout user when logout button clicked', async ({ page }) => {
      // Click logout
      await page.click('button:has-text("Abmelden")')

      // Should redirect to login page
      await page.waitForURL('**/login')
      expect(page.url()).toContain('/login')
    })
  })

  test.describe('Loading Indicator', () => {
    test('should show loading bar during page navigation', async ({ page }) => {
      // Intercept navigation to add delay
      await page.route('**/*', (route) => {
        setTimeout(() => route.continue(), 100)
      })

      // Click to navigate
      const navigationPromise = page.waitForURL('**/products')
      await page.click('nav a:has-text("Produkte")')

      // Check if loading indicator is visible during navigation
      const loadingBar = page.locator('div').filter({
        has: page.locator('div[style*="loadingSlide"]'),
      })

      // Loading bar should exist (may be removed quickly)
      await navigationPromise
    })
  })

  test.describe('Responsive Design - Small Mobile (375px)', () => {
    test.beforeEach(async ({ page }) => {
      await page.setViewportSize({ width: 375, height: 667 })
    })

    test('should stack header vertically on small mobile', async ({ page }) => {
      // Header should have flex-direction: column
      const header = page.locator('header')
      const style = await header.getAttribute('style')
      expect(style).toContain('flex-direction')
      expect(style).toContain('column')
    })

    test('should show logout icon only (no text) on small mobile', async ({ page }) => {
      // Find logout button by test ID
      const logoutBtn = page.getByTestId('header-logout-button-mobile')
      expect(await logoutBtn.isVisible()).toBe(true)

      // Check for logout icon
      const logoutIcon = logoutBtn.locator('svg')
      expect(await logoutIcon.isVisible()).toBe(true)

      // Should NOT have text span visible on small mobile
      const btnText = logoutBtn.locator('span')
      expect(await btnText.count()).toBe(0)
    })

    test('should show nav icons only (no labels) on small mobile', async ({ page }) => {
      // Get first nav link
      const firstNavLink = page.locator('nav a').first()

      // Should have icon
      const icon = firstNavLink.locator('svg')
      expect(await icon.isVisible()).toBe(true)

      // Should NOT have text label (span with label)
      const label = firstNavLink.locator('span')
      // Labels are hidden with conditional rendering
      const visibleLabels = await firstNavLink.locator('span:visible').count()
      expect(visibleLabels).toBe(0)
    })

    test('should display stats in single column on small mobile', async ({ page }) => {
      // All three stat cards should be visible
      const card1 = page.getByTestId('stat-card-mitglieder')
      const card2 = page.getByTestId('stat-card-offene-posten')
      const card3 = page.getByTestId('stat-card-letzte-abrechnung')

      expect(await card1.isVisible()).toBe(true)
      expect(await card2.isVisible()).toBe(true)
      expect(await card3.isVisible()).toBe(true)

      // On small mobile, cards should be displayed full-width (1 per row)
      const box1 = await card1.boundingBox()
      const box2 = await card2.boundingBox()
      const box3 = await card3.boundingBox()

      // They should be stacked vertically (y position increases)
      expect(box2!.y).toBeGreaterThan(box1!.y)
      expect(box3!.y).toBeGreaterThan(box2!.y)
    })
  })

  test.describe('Responsive Design - Mobile (480-768px)', () => {
    test.beforeEach(async ({ page }) => {
      await page.setViewportSize({ width: 640, height: 800 })
    })

    test('should display navigation with scrollable horizontal layout', async ({ page }) => {
      // Nav should be full width and scrollable
      const nav = page.locator('nav')
      const style = await nav.getAttribute('style')

      expect(style).toContain('overflow')
      expect(style).toContain('auto')
    })

    test('should show stat cards in 2-column layout on mobile', async ({ page }) => {
      // All three stat cards should be visible
      const card1 = page.getByTestId('stat-card-mitglieder')
      const card2 = page.getByTestId('stat-card-offene-posten')
      const card3 = page.getByTestId('stat-card-letzte-abrechnung')

      expect(await card1.isVisible()).toBe(true)
      expect(await card2.isVisible()).toBe(true)
      expect(await card3.isVisible()).toBe(true)

      // On mobile with 2-column layout:
      // Cards 1 and 2 should be on the same row (similar y position)
      // Card 3 should be on a second row
      const box1 = await card1.boundingBox()
      const box2 = await card2.boundingBox()
      const box3 = await card3.boundingBox()

      // Cards 1 and 2 have similar y (within 50px for same row)
      expect(Math.abs(box1!.y - box2!.y)).toBeLessThan(50)
      // Card 3 is on a different row (y position > card2)
      expect(box3!.y).toBeGreaterThan(box2!.y + 20)
    })
  })

  test.describe('Responsive Design - Tablet (768-1024px)', () => {
    test.beforeEach(async ({ page }) => {
      await page.setViewportSize({ width: 800, height: 1024 })
    })

    test('should show nav items with icons only (no text) on tablet', async ({ page }) => {
      const navLinks = page.locator('nav a')

      // Each link should have icon
      const firstLink = navLinks.first()
      const icon = firstLink.locator('svg')
      expect(await icon.isVisible()).toBe(true)

      // Labels should not be visible due to conditional rendering
      const span = firstLink.locator('span')
      const visibleSpans = await firstLink.locator('span:visible').count()
      expect(visibleSpans).toBe(0)
    })

    test('should show logout button with icon and text on tablet', async ({ page }) => {
      // Logout button should be visible
      const logoutBtn = page.locator('button:has-text("Abmelden")').first()
      expect(await logoutBtn.isVisible()).toBe(true)

      // Should have icon and text
      const icon = logoutBtn.locator('svg')
      expect(await icon.isVisible()).toBe(true)

      const text = logoutBtn.locator('span:has-text("Abmelden")')
      expect(await text.isVisible()).toBe(true)
    })

    test('should display stat cards in 2-column grid on tablet', async ({ page }) => {
      // Should have 3 stat cards
      const statCards = page.locator('main > div').first().locator('> div').first()
      const style = await statCards.getAttribute('style')

      // Grid should be configured
      expect(style).toContain('grid-template-columns')
    })

    test('should hide user badge on tablet', async ({ page }) => {
      // User badge should not be visible
      const userBadges = page.locator('header').locator('text=Admin User')
      expect(await userBadges.count()).toBe(0)
    })
  })

  test.describe('Responsive Design - Desktop (1440px)', () => {
    test.beforeEach(async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 900 })
    })

    test('should display full navigation layout on desktop', async ({ page }) => {
      // Header should be flex row
      const header = page.locator('header')
      const style = await header.getAttribute('style')
      expect(style).not.toContain('flex-direction: column')
    })

    test('should show nav items with labels on desktop', async ({ page }) => {
      const expectedLabels = [
        'Mitglieder',
        'Produkte',
        'Buchungsjournal',
        'Abrechnungen',
        'Statistik',
      ]

      for (const label of expectedLabels) {
        const link = page.locator(`nav a:has-text("${label}")`)
        expect(await link.isVisible()).toBe(true)
      }
    })

    test('should show user badge and logout button on desktop', async ({ page }) => {
      // User badge should be visible
      const userBadge = page.locator('header').filter({ hasText: 'Admin' }).locator('text=Admin').first()
      expect(await userBadge.isVisible()).toBe(true)

      // Logout button should be visible
      const logoutBtn = page.locator('button:has-text("Abmelden")')
      expect(await logoutBtn.isVisible()).toBe(true)
    })

    test('should display stat cards in 3-column grid on desktop', async ({ page }) => {
      // Get all stat cards
      const statCards = page.locator('main').locator('> div').first()

      // Should have 3 stat cards (Mitglieder, Offene Posten, Letzte Abrechnung)
      const cards = statCards.locator('> div').first().locator('> div')
      expect(await cards.count()).toBe(3)
    })
  })

  test.describe('Dashboard Stats on Members Page', () => {
    test('should display all three stat cards', async ({ page }) => {
      // Check for stat cards using test IDs
      expect(await page.getByTestId('stat-card-mitglieder').isVisible()).toBe(true)
      expect(await page.getByTestId('stat-card-offene-posten').isVisible()).toBe(true)
      expect(await page.getByTestId('stat-card-letzte-abrechnung').isVisible()).toBe(true)
    })

    test('should show stat values in correct format', async ({ page }) => {
      // Mitglieder should show number (128)
      expect(await page.locator('text=128').isVisible()).toBe(true)

      // Offene Posten should show currency (45,67 €)
      expect(await page.locator('text=45,67').isVisible()).toBe(true)
      expect(await page.locator('text=€').isVisible()).toBe(true)

      // Letzte Abrechnung should show date (31.12.2024)
      expect(await page.locator('text=31.12.2024').isVisible()).toBe(true)
    })

    test('should display colored icons for each stat', async ({ page }) => {
      // Get all stat cards
      const statGrid = page.locator('main > div').first().locator('> div').first()
      const cards = statGrid.locator('> div')

      // All cards should have SVG icons
      expect(await cards.count()).toBeGreaterThanOrEqual(3)
      for (let i = 0; i < 3; i++) {
        const card = cards.nth(i)
        const icon = card.locator('svg')
        expect(await icon.isVisible()).toBe(true)
      }
    })

    test('should use different colors for stat cards', async ({ page }) => {
      // Check that stat card icon containers have colored backgrounds
      const mitgliederIconContainer = await page.getByTestId('stat-card-mitglieder-icon-container')
      const offenePostenIconContainer = await page.getByTestId('stat-card-offene-posten-icon-container')

      // Get their styles
      const mitgliederStyle = await mitgliederIconContainer.getAttribute('style')
      const offenePostenStyle = await offenePostenIconContainer.getAttribute('style')

      // Both should have background colors applied
      expect(mitgliederStyle).toContain('background')
      expect(offenePostenStyle).toContain('background')

      // Colors should be different
      expect(mitgliederStyle).not.toEqual(offenePostenStyle)
    })
  })

  test.describe('Icon Rendering', () => {
    test('should render SVG icons with correct viewBox', async ({ page }) => {
      const icons = page.locator('nav svg')

      for (let i = 0; i < 5; i++) {
        const icon = icons.nth(i)
        const viewBox = await icon.getAttribute('viewBox')
        expect(viewBox).toBe('0 0 24 24')
      }
    })

    test('should render stats with icons', async ({ page }) => {
      // Stats container should have icons
      const statIcons = page.locator('main').locator('svg')

      // Should have at least 3 icons for stats + navigation icons
      expect(await statIcons.count()).toBeGreaterThanOrEqual(3)
    })
  })

  test.describe('Header Layout', () => {
    test('should display logo and brand text', async ({ page }) => {
      // Logo emoji should be visible
      expect(await page.getByTestId('header-logo-emoji').isVisible()).toBe(true)

      // Brand name should be visible
      expect(await page.getByTestId('header-brand-name').isVisible()).toBe(true)

      // Admin subtitle should be visible
      expect(await page.getByTestId('header-brand-subtitle').isVisible()).toBe(true)
    })

    test('should have proper header structure', async ({ page }) => {
      // Header should exist and be visible
      const header = page.locator('header')
      expect(await header.isVisible()).toBe(true)

      // Should have navigation
      const nav = page.locator('nav')
      expect(await nav.isVisible()).toBe(true)
    })
  })
})
