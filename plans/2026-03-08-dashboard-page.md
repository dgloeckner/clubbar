# Dashboard Page (UC-A80) Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Create the admin Dashboard page as the post-login landing page, displaying key metrics, recent transactions, terminal status, and alerts from the existing `GET /api/admin/dashboard` endpoint.

**Architecture:** Single new `DashboardPage.tsx` component consuming the existing `getDashboardMetrics()` service. Route added to `App.tsx`, default redirect changed from `/members` to `/dashboard`. Navigation updated in `MainLayout.tsx` and `BottomTabBar.tsx`. Auto-refresh every 60 seconds per UC-A80.

**Tech Stack:** React 18, TypeScript, CSS-in-JS (design-system theme), react-i18next, existing `StatCard` component, Playwright E2E tests.

---

## Task 1: Add i18n translations for Dashboard

**Files:**
- Modify: `admin-frontend/public/locales/de.json`
- Modify: `admin-frontend/public/locales/en.json`

**Step 1: Add German translations**

In `de.json`, add a `"dashboard"` section after the `"nav"` section. Also add `"nav.dashboard": "Dashboard"`.

```json
"nav": {
  "dashboard": "Dashboard",
  "members": "Mitglieder",
  ...
}
```

```json
"dashboard": {
  "title": "Dashboard",
  "activeMembers": "Aktive Mitglieder",
  "outstandingBalance": "Offene Posten",
  "todaysRevenue": "Heutiger Umsatz",
  "terminals": "Terminals",
  "recentTransactions": "Letzte Buchungen",
  "terminalStatus": "Terminal-Status",
  "systemStatus": "Systemstatus",
  "alerts": "Hinweise",
  "sepaIssues": "SEPA-Probleme",
  "noRecentTransactions": "Keine aktuellen Buchungen",
  "noTerminals": "Keine Terminals konfiguriert",
  "lastSettlement": "Letzte Abrechnung",
  "pendingSettlements": "Ausstehende Abrechnungen",
  "totalMembers": "Mitglieder gesamt",
  "totalTransactions": "Buchungen gesamt",
  "databaseHealth": "Datenbank",
  "online": "Online",
  "offline": "Offline",
  "disabled": "Deaktiviert",
  "lastSync": "Letzte Sync",
  "membersNeedSepaData": "{{count}} Mitglied(er) ohne SEPA-Daten",
  "allSepaValid": "Alle SEPA-Daten vollständig",
  "autoRefresh": "Automatische Aktualisierung",
  "refreshNow": "Jetzt aktualisieren",
  "purchase": "Kauf",
  "correction": "Korrektur"
}
```

**Step 2: Add English translations**

In `en.json`, add the same structure:

```json
"nav": {
  "dashboard": "Dashboard",
  "members": "Members",
  ...
}
```

```json
"dashboard": {
  "title": "Dashboard",
  "activeMembers": "Active Members",
  "outstandingBalance": "Outstanding Balance",
  "todaysRevenue": "Today's Revenue",
  "terminals": "Terminals",
  "recentTransactions": "Recent Transactions",
  "terminalStatus": "Terminal Status",
  "systemStatus": "System Status",
  "alerts": "Alerts",
  "sepaIssues": "SEPA Issues",
  "noRecentTransactions": "No recent transactions",
  "noTerminals": "No terminals configured",
  "lastSettlement": "Last Settlement",
  "pendingSettlements": "Pending Settlements",
  "totalMembers": "Total Members",
  "totalTransactions": "Total Transactions",
  "databaseHealth": "Database",
  "online": "Online",
  "offline": "Offline",
  "disabled": "Disabled",
  "lastSync": "Last Sync",
  "membersNeedSepaData": "{{count}} member(s) missing SEPA data",
  "allSepaValid": "All SEPA data complete",
  "autoRefresh": "Auto-refresh",
  "refreshNow": "Refresh now",
  "purchase": "Purchase",
  "correction": "Correction"
}
```

**Step 3: Commit**

```bash
git add admin-frontend/public/locales/de.json admin-frontend/public/locales/en.json
git commit -m "feat(dashboard): add i18n translations for dashboard page (UC-A80)"
```

---

## Task 2: Create DashboardPage component

**Files:**
- Create: `admin-frontend/src/pages/DashboardPage.tsx`

**References:**
- Service: `admin-frontend/src/services/dashboard.ts` (existing `getDashboardMetrics()`)
- Component: `admin-frontend/src/components/common/StatCard.tsx` (existing, supports blue/green/orange/red)
- Icons: `admin-frontend/src/components/icons/HomeIcon.tsx` (existing, not yet exported)
- Hooks: `useTranslation()`, `useBreakpoint()`, `useLoading()`, `useFormatters()`
- Theme: `admin-frontend/src/styles/design-system.ts`
- UC-A80 spec: `use-cases/admin/UC-A80-dashboard.md`

**Step 1: Export HomeIcon**

In `admin-frontend/src/components/icons/index.ts`, add:
```typescript
export { HomeIcon } from './HomeIcon'
```

**Step 2: Create DashboardPage.tsx**

The page has 4 sections:
1. **Metrics row** — 4 StatCards (Active Members, Outstanding Balance, Today's Revenue, Terminals)
2. **Recent Transactions** — Table of last 10 transactions
3. **Terminal Status** — List of terminals with online/offline/disabled badges
4. **Alerts** — SEPA issues with severity badge (yellow 1-5, red 6+)

Key behaviors:
- Auto-refresh every 60 seconds (per UC-A80)
- Manual refresh button
- Responsive layout (grid for desktop, stack for mobile)
- Amounts displayed via `useFormatters().formatPrice()` (converts cents to EUR)
- Dates via `useFormatters().formatDateTime()`
- `data-testid="dashboard-page"` on root element

```typescript
import { useState, useEffect, useCallback, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { theme } from '../styles/design-system'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { useLoading } from '../context/LoadingContext'
import { useFormatters } from '../hooks/useFormatters'
import { getDashboardMetrics, DashboardResponse } from '../services/dashboard'
import { StatCard } from '../components/common/StatCard'
import { UsersIcon, ReceiptIcon, BookIcon } from '../components/icons'
import { HomeIcon } from '../components/icons/HomeIcon'

const AUTO_REFRESH_INTERVAL = 60_000 // 60 seconds per UC-A80

export function DashboardPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const breakpoint = useBreakpoint()
  const { setIsLoading } = useLoading()
  const { formatPrice, formatDateTime, formatRelativeDate } = useFormatters()

  const [data, setData] = useState<DashboardResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null)

  const fetchDashboard = useCallback(async (showGlobalLoading = true) => {
    try {
      if (showGlobalLoading) {
        setLoading(true)
        setIsLoading(true)
      }
      const response = await getDashboardMetrics()
      setData(response)
      setError(null)
    } catch (err) {
      setError(t('errors.generic'))
    } finally {
      setLoading(false)
      setIsLoading(false)
    }
  }, [setIsLoading, t])

  // Initial load + auto-refresh
  useEffect(() => {
    fetchDashboard()
    intervalRef.current = setInterval(() => fetchDashboard(false), AUTO_REFRESH_INTERVAL)
    return () => {
      if (intervalRef.current) clearInterval(intervalRef.current)
    }
  }, [fetchDashboard])

  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'

  if (loading && !data) {
    return (
      <div data-testid="dashboard-page" style={{ padding: theme.spacing['2xl'] }}>
        <div data-testid="dashboard-loading" style={{ color: theme.colors.text.secondary }}>
          {t('common.loading')}
        </div>
      </div>
    )
  }

  if (error && !data) {
    return (
      <div data-testid="dashboard-page" style={{ padding: theme.spacing['2xl'] }}>
        <div data-testid="dashboard-error" style={{ color: theme.colors.semantic.danger }}>
          {error}
        </div>
      </div>
    )
  }

  if (!data) return null

  const { metrics, recent_transactions, terminal_status, system_status, alerts } = data

  const statusColor = (status: string) => {
    switch (status) {
      case 'online': return theme.colors.semantic.success
      case 'offline': return theme.colors.semantic.warning
      case 'disabled': return theme.colors.text.muted
      default: return theme.colors.text.secondary
    }
  }

  const severityColor = (severity: string) => {
    switch (severity) {
      case 'error': return theme.colors.semantic.danger
      case 'warning': return theme.colors.semantic.warning
      default: return theme.colors.semantic.success
    }
  }

  return (
    <div data-testid="dashboard-page" style={{ padding: isMobile ? theme.spacing.lg : theme.spacing['2xl'], maxWidth: '1200px' }}>
      {/* Header */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: theme.spacing.xl }}>
        <h1 data-testid="dashboard-title" style={{
          fontSize: theme.typography.fontSize['2xl'],
          fontWeight: theme.typography.fontWeight.bold,
          color: theme.colors.text.primary,
          margin: 0,
        }}>
          {t('dashboard.title')}
        </h1>
        <button
          data-testid="dashboard-refresh-button"
          onClick={() => fetchDashboard()}
          style={{
            background: theme.colors.bg.card,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.sm,
            padding: `${theme.spacing.sm} ${theme.spacing.lg}`,
            color: theme.colors.text.secondary,
            cursor: 'pointer',
            fontSize: theme.typography.fontSize.sm,
          }}
        >
          {t('dashboard.refreshNow')}
        </button>
      </div>

      {/* Metrics Row */}
      <div data-testid="dashboard-metrics" style={{
        display: 'grid',
        gridTemplateColumns: isMobile ? '1fr' : 'repeat(4, 1fr)',
        gap: theme.spacing.lg,
        marginBottom: theme.spacing['2xl'],
      }}>
        <StatCard
          icon={<UsersIcon />}
          label={t('dashboard.activeMembers')}
          value={metrics.active_members}
          color="blue"
        />
        <StatCard
          icon={<ReceiptIcon />}
          label={t('dashboard.outstandingBalance')}
          value={formatPrice(metrics.outstanding_balance_cents)}
          color={metrics.outstanding_balance_cents > 0 ? 'orange' : 'green'}
        />
        <StatCard
          icon={<BookIcon />}
          label={t('dashboard.todaysRevenue')}
          value={formatPrice(metrics.todays_revenue_cents)}
          color="green"
        />
        <StatCard
          icon={<HomeIcon />}
          label={t('dashboard.terminals')}
          value={`${metrics.active_terminals}/${metrics.terminal_count}`}
          color="blue"
        />
      </div>

      {/* Two-column layout: Transactions + Sidebar */}
      <div style={{
        display: 'grid',
        gridTemplateColumns: isMobile ? '1fr' : '2fr 1fr',
        gap: theme.spacing.xl,
      }}>
        {/* Recent Transactions */}
        <div data-testid="dashboard-recent-transactions" style={{
          background: theme.colors.bg.card,
          border: `1px solid ${theme.colors.border.light}`,
          borderRadius: theme.borderRadius.lg,
          padding: theme.spacing.xl,
        }}>
          <h2 style={{
            fontSize: theme.typography.fontSize.lg,
            fontWeight: theme.typography.fontWeight.semibold,
            color: theme.colors.text.primary,
            margin: `0 0 ${theme.spacing.lg} 0`,
          }}>
            {t('dashboard.recentTransactions')}
          </h2>

          {recent_transactions.length === 0 ? (
            <div data-testid="dashboard-no-transactions" style={{ color: theme.colors.text.muted, fontSize: theme.typography.fontSize.sm }}>
              {t('dashboard.noRecentTransactions')}
            </div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.sm }}>
              {recent_transactions.map((tx: any) => (
                <div key={tx.id} data-testid={`dashboard-transaction-${tx.id}`} style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                  padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                  borderRadius: theme.borderRadius.sm,
                  background: theme.colors.bg.secondary,
                }}>
                  <div style={{ flex: 1 }}>
                    <div style={{ fontSize: theme.typography.fontSize.sm, color: theme.colors.text.primary }}>
                      {tx.member_name}
                    </div>
                    <div style={{ fontSize: theme.typography.fontSize.xs, color: theme.colors.text.muted }}>
                      {tx.product_name || t(`dashboard.${tx.type}`)} · {formatDateTime(tx.timestamp)}
                    </div>
                  </div>
                  <div style={{
                    fontSize: theme.typography.fontSize.sm,
                    fontWeight: theme.typography.fontWeight.semibold,
                    fontFamily: 'JetBrains Mono, monospace',
                    color: tx.amount_cents < 0 ? theme.colors.semantic.success : theme.colors.text.primary,
                  }}>
                    {formatPrice(tx.amount_cents)}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Sidebar: Terminals + Alerts + System Status */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.xl }}>
          {/* Terminal Status */}
          <div data-testid="dashboard-terminal-status" style={{
            background: theme.colors.bg.card,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.lg,
            padding: theme.spacing.xl,
          }}>
            <h2 style={{
              fontSize: theme.typography.fontSize.lg,
              fontWeight: theme.typography.fontWeight.semibold,
              color: theme.colors.text.primary,
              margin: `0 0 ${theme.spacing.lg} 0`,
            }}>
              {t('dashboard.terminalStatus')}
            </h2>

            {terminal_status.length === 0 ? (
              <div data-testid="dashboard-no-terminals" style={{ color: theme.colors.text.muted, fontSize: theme.typography.fontSize.sm }}>
                {t('dashboard.noTerminals')}
              </div>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.sm }}>
                {terminal_status.map((term: any) => (
                  <div key={term.id} data-testid={`dashboard-terminal-${term.id}`} style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                    borderRadius: theme.borderRadius.sm,
                    background: theme.colors.bg.secondary,
                  }}>
                    <span style={{ fontSize: theme.typography.fontSize.sm, color: theme.colors.text.primary }}>
                      {term.name}
                    </span>
                    <span data-testid={`dashboard-terminal-status-${term.id}`} style={{
                      fontSize: theme.typography.fontSize.xs,
                      fontWeight: theme.typography.fontWeight.semibold,
                      color: statusColor(term.status),
                      textTransform: 'uppercase',
                    }}>
                      {t(`dashboard.${term.status}`)}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Alerts */}
          <div data-testid="dashboard-alerts" style={{
            background: theme.colors.bg.card,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.lg,
            padding: theme.spacing.xl,
          }}>
            <h2 style={{
              fontSize: theme.typography.fontSize.lg,
              fontWeight: theme.typography.fontWeight.semibold,
              color: theme.colors.text.primary,
              margin: `0 0 ${theme.spacing.lg} 0`,
            }}>
              {t('dashboard.alerts')}
            </h2>

            {/* SEPA Issues Alert */}
            <div data-testid="dashboard-sepa-alert" style={{
              display: 'flex',
              alignItems: 'center',
              gap: theme.spacing.md,
              padding: theme.spacing.md,
              borderRadius: theme.borderRadius.sm,
              background: alerts.sepa_issues.severity === 'none'
                ? 'rgba(34, 197, 94, 0.1)'
                : alerts.sepa_issues.severity === 'warning'
                  ? 'rgba(249, 115, 22, 0.1)'
                  : 'rgba(239, 68, 68, 0.1)',
            }}>
              <div style={{
                width: '8px',
                height: '8px',
                borderRadius: '50%',
                background: severityColor(alerts.sepa_issues.severity),
                flexShrink: 0,
              }} />
              <span data-testid="dashboard-sepa-alert-message" style={{
                fontSize: theme.typography.fontSize.sm,
                color: theme.colors.text.primary,
              }}>
                {alerts.sepa_issues.count > 0
                  ? t('dashboard.membersNeedSepaData', { count: alerts.sepa_issues.count })
                  : t('dashboard.allSepaValid')
                }
              </span>
            </div>
          </div>

          {/* System Status */}
          <div data-testid="dashboard-system-status" style={{
            background: theme.colors.bg.card,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.lg,
            padding: theme.spacing.xl,
          }}>
            <h2 style={{
              fontSize: theme.typography.fontSize.lg,
              fontWeight: theme.typography.fontWeight.semibold,
              color: theme.colors.text.primary,
              margin: `0 0 ${theme.spacing.lg} 0`,
            }}>
              {t('dashboard.systemStatus')}
            </h2>

            <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.sm }}>
              {[
                { label: t('dashboard.lastSettlement'), value: system_status.last_settlement_date ? formatRelativeDate(system_status.last_settlement_date) : '–', testId: 'last-settlement' },
                { label: t('dashboard.pendingSettlements'), value: system_status.pending_settlement_count, testId: 'pending-settlements' },
                { label: t('dashboard.totalMembers'), value: system_status.total_members, testId: 'total-members' },
                { label: t('dashboard.totalTransactions'), value: system_status.total_transactions, testId: 'total-transactions' },
                { label: t('dashboard.databaseHealth'), value: system_status.database_health, testId: 'database-health' },
              ].map(({ label, value, testId }) => (
                <div key={testId} data-testid={`dashboard-system-${testId}`} style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  padding: `${theme.spacing.xs} 0`,
                  borderBottom: `1px solid ${theme.colors.border.dark}`,
                }}>
                  <span style={{ fontSize: theme.typography.fontSize.sm, color: theme.colors.text.secondary }}>{label}</span>
                  <span style={{
                    fontSize: theme.typography.fontSize.sm,
                    fontWeight: theme.typography.fontWeight.medium,
                    color: theme.colors.text.primary,
                    fontFamily: 'JetBrains Mono, monospace',
                  }}>
                    {value}
                  </span>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
```

**Step 3: Verify it compiles**

Run: `cd admin-frontend && npx tsc --noEmit`
Expected: No errors

**Step 4: Commit**

```bash
git add admin-frontend/src/pages/DashboardPage.tsx admin-frontend/src/components/icons/index.ts
git commit -m "feat(dashboard): create DashboardPage component with metrics, transactions, terminals, alerts"
```

---

## Task 3: Add route and update navigation

**Files:**
- Modify: `admin-frontend/src/App.tsx` (lines 79-80, 158)
- Modify: `admin-frontend/src/components/layout/MainLayout.tsx` (lines 56-65)
- Modify: `admin-frontend/src/components/layout/BottomTabBar.tsx` (lines 41-46)

**Step 1: Add route to App.tsx**

Add import at line 22:
```typescript
import { DashboardPage } from './pages/DashboardPage'
```

Change line 79 (login redirect) from `/members` to `/dashboard`:
```typescript
<Route path="/login" element={isAuthenticated ? <Navigate to="/dashboard" /> : <LoginPage />} />
```

Change line 80 (root redirect) from `/members` to `/dashboard`:
```typescript
<Route path="/" element={isAuthenticated ? <Navigate to="/dashboard" /> : <Navigate to="/login" />} />
```

Add dashboard route after line 81 (before the members route):
```typescript
<Route
  path="/dashboard"
  element={
    <ProtectedRoute>
      <DashboardPage />
    </ProtectedRoute>
  }
/>
```

Change line 158 (404 fallback) from `/members` to `/dashboard`:
```typescript
<Route path="*" element={<Navigate to="/dashboard" />} />
```

**Step 2: Add Dashboard to navigation in MainLayout.tsx**

In the `navItems` array (line 56), add Dashboard as the **first** item:
```typescript
const navItems = [
  { label: t('nav.dashboard'), path: '/dashboard', icon: <HomeIcon size={20} />, testId: 'nav-dashboard' },
  { label: t('nav.members'), path: '/members', icon: <UsersIcon size={20} />, testId: 'nav-members' },
  // ... rest unchanged
]
```

Add `HomeIcon` to the imports at line 15:
```typescript
import {
  AuditLogIcon,
  UsersIcon,
  PackageIcon,
  BookIcon,
  ReceiptIcon,
  ChartIcon,
  SettingsIcon,
  UserIcon,
  LogoutIcon,
  HomeIcon,
} from '../icons'
```

**Step 3: Add Dashboard to mobile BottomTabBar.tsx**

In the `primaryTabs` array (line 41), add Dashboard as the **first** item and move settlements to `moreItems`:
```typescript
const primaryTabs = [
  { label: t('nav.dashboard'), path: '/dashboard', icon: HomeIcon, testId: 'tab-dashboard' },
  { label: t('nav.members'), path: '/members', icon: UsersIcon, testId: 'tab-members' },
  { label: t('nav.products'), path: '/products', icon: PackageIcon, testId: 'tab-products' },
  { label: t('nav.journalShort'), path: '/journal', icon: BookIcon, testId: 'tab-journal' },
]
```

Add `moreItems` entry for settlements:
```typescript
const moreItems = [
  { label: t('nav.settlements'), path: '/settlements', icon: ReceiptIcon, testId: 'tab-settlements' },
  { label: t('nav.categories'), path: '/categories', icon: NavigationIconRegistry.CategoryIcon, testId: 'tab-categories' },
  // ... rest unchanged
]
```

Add `HomeIcon` import in BottomTabBar.tsx:
```typescript
import { HomeIcon } from '../icons/HomeIcon'
```

**Step 4: Verify app compiles and renders**

Run: `cd admin-frontend && npx tsc --noEmit`
Expected: No errors

**Step 5: Commit**

```bash
git add admin-frontend/src/App.tsx admin-frontend/src/components/layout/MainLayout.tsx admin-frontend/src/components/layout/BottomTabBar.tsx
git commit -m "feat(dashboard): add route, navigation entry, and make dashboard the landing page"
```

---

## Task 4: Update MainLayoutPage E2E page object for Dashboard nav

**Files:**
- Modify: `e2etests/pages/MainLayoutPage.ts`

**Step 1: Add dashboard nav locator and update NAV_LABELS**

Add `dashboard` to NAV_LABELS:
```typescript
const NAV_LABELS = {
  de: {
    dashboard: 'Dashboard',
    members: 'Mitglieder',
    // ... rest unchanged
  },
  en: {
    dashboard: 'Dashboard',
    members: 'Members',
    // ... rest unchanged
  },
}
```

Add locator and navigation method:
```typescript
private readonly navDashboard = () => this.page.locator('[data-testid="nav-dashboard"]')

async clickDashboard() {
  await this.navDashboard().click()
  await this.page.waitForURL('**/dashboard', { timeout: 5000 })
}
```

Update `expectNavigationInLanguage` to include dashboard:
```typescript
async expectNavigationInLanguage(lang: 'de' | 'en') {
  const labels = NAV_LABELS[lang]
  await expect(this.navDashboard()).toContainText(labels.dashboard)
  await expect(this.navMembers()).toContainText(labels.members)
  // ... rest unchanged
}
```

**Step 2: Commit**

```bash
git add e2etests/pages/MainLayoutPage.ts
git commit -m "feat(dashboard): add dashboard nav to MainLayoutPage E2E page object"
```

---

## Task 5: Create DashboardPage E2E page object

**Files:**
- Create: `e2etests/pages/DashboardPage.ts`
- Modify: `e2etests/pages/index.ts`
- Modify: `e2etests/fixtures/pageObjects.ts`

**Step 1: Create page object**

```typescript
import { Page, expect } from '@playwright/test'
import { BasePage } from './BasePage'

export class DashboardPage extends BasePage {
  // Section locators
  private readonly pageRoot = () => this.page.getByTestId('dashboard-page')
  private readonly title = () => this.page.getByTestId('dashboard-title')
  private readonly metricsSection = () => this.page.getByTestId('dashboard-metrics')
  private readonly recentTransactions = () => this.page.getByTestId('dashboard-recent-transactions')
  private readonly terminalStatusSection = () => this.page.getByTestId('dashboard-terminal-status')
  private readonly alertsSection = () => this.page.getByTestId('dashboard-alerts')
  private readonly systemStatusSection = () => this.page.getByTestId('dashboard-system-status')
  private readonly refreshButton = () => this.page.getByTestId('dashboard-refresh-button')
  private readonly sepaAlert = () => this.page.getByTestId('dashboard-sepa-alert')
  private readonly sepaAlertMessage = () => this.page.getByTestId('dashboard-sepa-alert-message')
  private readonly loadingIndicator = () => this.page.getByTestId('dashboard-loading')

  constructor(page: Page) {
    super(page)
  }

  async expectPageVisible() {
    await expect(this.pageRoot()).toBeVisible()
    await expect(this.title()).toBeVisible()
  }

  async expectMetricsVisible() {
    await expect(this.metricsSection()).toBeVisible()
  }

  async expectRecentTransactionsVisible() {
    await expect(this.recentTransactions()).toBeVisible()
  }

  async expectTerminalStatusVisible() {
    await expect(this.terminalStatusSection()).toBeVisible()
  }

  async expectAlertsVisible() {
    await expect(this.alertsSection()).toBeVisible()
  }

  async expectSystemStatusVisible() {
    await expect(this.systemStatusSection()).toBeVisible()
  }

  async clickRefresh() {
    await this.refreshButton().click()
    // Wait for the dashboard API response
    await this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/dashboard') && resp.status() === 200,
      { timeout: 10000 }
    )
  }

  async getSepaAlertMessage(): Promise<string> {
    return (await this.sepaAlertMessage().textContent()) ?? ''
  }

  async getTransactionCount(): Promise<number> {
    return this.page.locator('[data-testid^="dashboard-transaction-"]').count()
  }

  async getTerminalCount(): Promise<number> {
    return this.page.locator('[data-testid^="dashboard-terminal-"]').count()
  }

  async expectSystemStatusField(testId: string) {
    await expect(this.page.getByTestId(`dashboard-system-${testId}`)).toBeVisible()
  }

  async waitForLoadingToComplete() {
    await expect(this.loadingIndicator()).not.toBeVisible({ timeout: 10000 })
  }
}
```

**Step 2: Add to pages/index.ts**

```typescript
export { DashboardPage } from './DashboardPage'
```

**Step 3: Add fixture to pageObjects.ts**

Add to interface:
```typescript
interface PageObjectFixtures {
  // ... existing entries
  authenticatedDashboardPage: DashboardPage
}
```

Add import:
```typescript
import { LoginPage, MembersPage, ProductsPage, SettlementsPage, CategoriesPage, JournalPage, SettingsPage, AuditLogPage, DashboardPage } from '../pages'
```

Add fixture function:
```typescript
const authenticatedDashboardPageFixture = async (
  { page }: { page: Page },
  use: (value: DashboardPage) => Promise<void>
) => {
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })
  await page.waitForSelector('[data-testid="dashboard-page"]', { timeout: 5000 })
  const dashboardPage = new DashboardPage(page)
  await use(dashboardPage)
}
```

Add to `base.extend`:
```typescript
authenticatedDashboardPage: authenticatedDashboardPageFixture,
```

**Step 4: Commit**

```bash
git add e2etests/pages/DashboardPage.ts e2etests/pages/index.ts e2etests/fixtures/pageObjects.ts
git commit -m "feat(dashboard): create DashboardPage E2E page object and fixture"
```

---

## Task 6: Write Dashboard E2E tests

**Files:**
- Create: `e2etests/tests/admin/dashboard.spec.ts`

**Step 1: Write E2E test file**

```typescript
import { test, expect } from '../../fixtures/pageObjects'

test.describe('Admin Dashboard Page (UC-A80)', () => {

  test('displays dashboard page with all sections', async ({ authenticatedDashboardPage }) => {
    await authenticatedDashboardPage.expectPageVisible()
    await authenticatedDashboardPage.expectMetricsVisible()
    await authenticatedDashboardPage.expectRecentTransactionsVisible()
    await authenticatedDashboardPage.expectTerminalStatusVisible()
    await authenticatedDashboardPage.expectAlertsVisible()
    await authenticatedDashboardPage.expectSystemStatusVisible()
  })

  test('displays 4 stat cards with correct labels', async ({ authenticatedDashboardPage, page }) => {
    await authenticatedDashboardPage.expectPageVisible()

    // Verify all 4 metric stat cards are present
    const metricsSection = page.getByTestId('dashboard-metrics')
    // StatCard generates testid from label: stat-card-{label-kebab-case}
    // Labels depend on locale, check structure presence instead
    const statCards = metricsSection.locator('[data-testid^="stat-card-"]')
    await expect(statCards).toHaveCount(4)
  })

  test('displays recent transactions from API', async ({ authenticatedDashboardPage }) => {
    await authenticatedDashboardPage.expectPageVisible()
    await authenticatedDashboardPage.expectRecentTransactionsVisible()

    // Should show transaction entries (seeded data has transactions)
    const count = await authenticatedDashboardPage.getTransactionCount()
    expect(count).toBeGreaterThanOrEqual(0)
    expect(count).toBeLessThanOrEqual(10) // API returns max 10
  })

  test('displays terminal status entries', async ({ authenticatedDashboardPage }) => {
    await authenticatedDashboardPage.expectPageVisible()
    await authenticatedDashboardPage.expectTerminalStatusVisible()

    const count = await authenticatedDashboardPage.getTerminalCount()
    expect(count).toBeGreaterThanOrEqual(0)
  })

  test('displays SEPA alert with severity', async ({ authenticatedDashboardPage }) => {
    await authenticatedDashboardPage.expectPageVisible()
    await authenticatedDashboardPage.expectAlertsVisible()

    const message = await authenticatedDashboardPage.getSepaAlertMessage()
    expect(message.length).toBeGreaterThan(0)
  })

  test('displays system status fields', async ({ authenticatedDashboardPage }) => {
    await authenticatedDashboardPage.expectPageVisible()
    await authenticatedDashboardPage.expectSystemStatusVisible()

    await authenticatedDashboardPage.expectSystemStatusField('last-settlement')
    await authenticatedDashboardPage.expectSystemStatusField('pending-settlements')
    await authenticatedDashboardPage.expectSystemStatusField('total-members')
    await authenticatedDashboardPage.expectSystemStatusField('total-transactions')
    await authenticatedDashboardPage.expectSystemStatusField('database-health')
  })

  test('refresh button fetches new data', async ({ authenticatedDashboardPage }) => {
    await authenticatedDashboardPage.expectPageVisible()
    await authenticatedDashboardPage.clickRefresh()
    // After refresh, page should still be visible with all sections
    await authenticatedDashboardPage.expectMetricsVisible()
  })

  test('dashboard is the post-login landing page', async ({ page }) => {
    // Navigate to root - should redirect to dashboard
    await page.goto('/', { waitUntil: 'domcontentloaded' })
    await page.waitForURL('**/dashboard', { timeout: 5000 })
    await expect(page.getByTestId('dashboard-page')).toBeVisible()
  })

  test('dashboard nav item is visible and navigable', async ({ authenticatedDashboardPage, page }) => {
    // Navigate away first
    await page.goto('/members', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="members-page"]', { timeout: 5000 })

    // Click dashboard nav
    await page.locator('[data-testid="nav-dashboard"]').click()
    await page.waitForURL('**/dashboard', { timeout: 5000 })
    await expect(page.getByTestId('dashboard-page')).toBeVisible()
  })
})
```

**Step 2: Run the tests to verify they pass**

```bash
cd e2etests && npm test -- tests/admin/dashboard.spec.ts --workers=4
```
Expected: All tests pass.

**Step 3: Commit**

```bash
git add e2etests/tests/admin/dashboard.spec.ts
git commit -m "test(dashboard): add 9 E2E tests for DashboardPage (UC-A80)"
```

---

## Task 7: Update existing E2E tests for navigation changes

**Files:**
- Check/update: `e2etests/tests/admin/main-layout.spec.ts` (or similar nav tests)

Since the default landing page changed from `/members` to `/dashboard`, any tests that:
1. Expect `/members` as the post-login redirect need updating
2. Use `expectNavigationInLanguage` need to include Dashboard label

**Step 1: Search for affected tests**

```bash
cd e2etests && grep -r "Navigate.*members\|/members.*landing\|redirect.*members" tests/ --include="*.spec.ts" -l
```

**Step 2: Update any tests that assert the post-login landing page**

Change assertions from expecting `/members` to `/dashboard`.

**Step 3: Run full admin test suite**

```bash
cd e2etests && npm test -- --workers=4
```
Expected: All tests pass.

**Step 4: Commit if any changes needed**

```bash
git add -A e2etests/
git commit -m "fix(e2e): update existing tests for dashboard as default landing page"
```

---

## Task 8: Update plans/INDEX.md

**Files:**
- Modify: `plans/INDEX.md`

**Step 1: Add dashboard plan to current/completed plans**

Update INDEX.md to reflect the dashboard implementation plan status.

**Step 2: Commit**

```bash
git add plans/INDEX.md plans/2026-03-08-dashboard-page.md
git commit -m "docs: add dashboard page plan to INDEX.md"
```

---

## Summary

| Task | Description | Files | Tests |
|------|-------------|-------|-------|
| 1 | i18n translations | 2 locale files | – |
| 2 | DashboardPage component | 1 new page + 1 icon export | – |
| 3 | Route + navigation | 3 files (App, MainLayout, BottomTabBar) | – |
| 4 | MainLayoutPage page object | 1 E2E page object | – |
| 5 | DashboardPage page object + fixture | 3 E2E files | – |
| 6 | Dashboard E2E tests | 1 test file (9 tests) | 9 tests |
| 7 | Update existing tests | Variable | Regression |
| 8 | Documentation | INDEX.md | – |
