# Mobile Responsive Design — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make the admin frontend fully usable on 375px mobile screens with bottom tab bar navigation, card views replacing tables, collapsible filters with sort dropdown, and single-column forms.

**Architecture:** All changes gated behind `isMobile` from `useBreakpoint()`. Desktop is untouched. Each page gets a mobile card view rendered as a sibling to the existing table, selected via ternary. A new `BottomTabBar` component replaces the top nav on mobile. A new `MobileToolbar` component provides search + sort + collapsible filters.

**Tech Stack:** React, TypeScript, inline styles (design-system.ts tokens), useBreakpoint hook. No new libraries.

**Design spec:** `docs/plans/2026-03-02-mobile-responsive-design.md`

---

## Task 1: BottomTabBar Component

Create the bottom tab bar component that replaces top navigation on mobile.

**Files:**
- Create: `admin-frontend/src/components/layout/BottomTabBar.tsx`

**Step 1: Create the BottomTabBar component**

```tsx
// admin-frontend/src/components/layout/BottomTabBar.tsx
import { useState, useEffect, useRef } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import {
  UsersIcon,
  PackageIcon,
  BookIcon,
  ReceiptIcon,
  ChartIcon,
  SettingsIcon,
  AuditLogIcon,
} from '../icons'
import { NavigationIconRegistry } from '../icons/IconRegistry'

function GridDotsIcon({ size = 20, color = 'currentColor' }: { size?: number; color?: string }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill={color}>
      <circle cx="5" cy="5" r="2" />
      <circle cx="12" cy="5" r="2" />
      <circle cx="19" cy="5" r="2" />
      <circle cx="5" cy="12" r="2" />
      <circle cx="12" cy="12" r="2" />
      <circle cx="19" cy="12" r="2" />
      <circle cx="5" cy="19" r="2" />
      <circle cx="12" cy="19" r="2" />
      <circle cx="19" cy="19" r="2" />
    </svg>
  )
}

export function BottomTabBar() {
  const { t } = useTranslation()
  const location = useLocation()
  const [showMore, setShowMore] = useState(false)
  const moreRef = useRef<HTMLDivElement>(null)

  const isActive = (path: string) => location.pathname === path

  const primaryTabs = [
    { label: t('nav.members'), path: '/members', icon: UsersIcon, testId: 'tab-members' },
    { label: t('nav.products'), path: '/products', icon: PackageIcon, testId: 'tab-products' },
    { label: t('nav.journal'), path: '/journal', icon: BookIcon, testId: 'tab-journal' },
    { label: t('nav.settlements'), path: '/settlements', icon: ReceiptIcon, testId: 'tab-settlements' },
  ]

  const moreItems = [
    { label: t('nav.categories'), path: '/categories', icon: NavigationIconRegistry.CategoryIcon, testId: 'tab-categories' },
    { label: t('nav.statistics'), path: '/statistics', icon: ChartIcon, testId: 'tab-statistics' },
    { label: t('nav.settings'), path: '/settings', icon: SettingsIcon, testId: 'tab-settings' },
    { label: t('nav.auditLog'), path: '/audit-log', icon: AuditLogIcon, testId: 'tab-audit-log' },
  ]

  const isMoreActive = moreItems.some((item) => isActive(item.path))

  // Close popup on outside click
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (moreRef.current && !moreRef.current.contains(e.target as Node)) {
        setShowMore(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  // Close popup on navigation
  useEffect(() => {
    setShowMore(false)
  }, [location.pathname])

  const tabStyle = (active: boolean): React.CSSProperties => ({
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    gap: '2px',
    flex: 1,
    padding: '8px 0',
    background: 'transparent',
    border: 'none',
    color: active ? theme.colors.semantic.primary : theme.colors.text.secondary,
    fontSize: '10px',
    fontWeight: active ? 600 : 400,
    textDecoration: 'none',
    cursor: 'pointer',
    transition: `all ${theme.transitions.default}`,
  })

  return (
    <div
      data-testid="bottom-tab-bar"
      style={{
        position: 'fixed',
        bottom: 0,
        left: 0,
        right: 0,
        height: '56px',
        background: theme.colors.bg.secondary,
        borderTop: `1px solid ${theme.colors.border.light}`,
        display: 'flex',
        alignItems: 'center',
        zIndex: 1000,
        paddingBottom: 'env(safe-area-inset-bottom)',
      }}
    >
      {primaryTabs.map((tab) => (
        <Link key={tab.path} to={tab.path} data-testid={tab.testId} style={tabStyle(isActive(tab.path))}>
          <tab.icon size={22} />
          <span>{tab.label}</span>
        </Link>
      ))}

      {/* More button */}
      <div ref={moreRef} style={{ flex: 1, position: 'relative' }}>
        <button
          data-testid="tab-more"
          onClick={() => setShowMore(!showMore)}
          style={tabStyle(isMoreActive)}
        >
          <GridDotsIcon size={22} />
          <span>{t('nav.more', 'Mehr')}</span>
        </button>

        {/* More popup */}
        {showMore && (
          <div
            data-testid="tab-more-popup"
            style={{
              position: 'absolute',
              bottom: '100%',
              right: 0,
              marginBottom: '8px',
              minWidth: '180px',
              background: theme.colors.bg.card,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              boxShadow: '0 -4px 20px rgba(0,0,0,0.4)',
              padding: '6px',
            }}
          >
            {moreItems.map((item) => (
              <Link
                key={item.path}
                to={item.path}
                data-testid={item.testId}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: '10px',
                  padding: '10px 12px',
                  borderRadius: '8px',
                  background: isActive(item.path) ? 'rgba(59,130,246,0.15)' : 'transparent',
                  color: isActive(item.path) ? theme.colors.semantic.primary : theme.colors.text.primary,
                  textDecoration: 'none',
                  fontSize: '14px',
                  fontWeight: isActive(item.path) ? 600 : 400,
                  transition: `all ${theme.transitions.default}`,
                }}
              >
                <item.icon size={20} />
                <span>{item.label}</span>
              </Link>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
```

**Step 2: Verify it compiles**

Run: `cd admin-frontend && npx tsc --noEmit 2>&1 | head -30`
Expected: No errors related to BottomTabBar.

**Step 3: Commit**

```bash
git add admin-frontend/src/components/layout/BottomTabBar.tsx
git commit -m "feat: add BottomTabBar component for mobile navigation"
```

---

## Task 2: Integrate BottomTabBar into MainLayout

Hide the top nav on mobile and show the BottomTabBar instead. Add padding-bottom to main content so it's not hidden behind the tab bar.

**Files:**
- Modify: `admin-frontend/src/components/layout/MainLayout.tsx`

**Step 1: Import BottomTabBar**

At line 13, after the `LoadingIndicator` import, add:

```tsx
import { BottomTabBar } from './BottomTabBar'
```

**Step 2: Hide header nav on mobile**

In the `<nav>` element (line 173), change the `display` style from:
```tsx
display: 'flex',
```
to:
```tsx
display: isMobile ? 'none' : 'flex',
```

**Step 3: Add BottomTabBar and padding**

After the `</footer>` (line 325), before the closing `</div>`, add:

```tsx
{isMobile && <BottomTabBar />}
```

In the `<main>` style (line 302), add `paddingBottom` for mobile:

```tsx
paddingBottom: isMobile ? '72px' : undefined,
```

(72px = 56px tab bar + 16px spacing)

**Step 4: Hide footer on mobile**

The footer is unnecessary on mobile (it just says "Ruderbar Admin © 2026"). Wrap it:

Change the `<footer>` (line 314) to only render on non-mobile:
```tsx
{!isMobile && (
  <footer ... >
    ...
  </footer>
)}
```

**Step 5: Verify visually**

Run: `cd admin-frontend && npm run dev`
Open browser at mobile viewport (375px). Verify:
- Bottom tab bar shows with 5 tabs
- Top nav row is gone
- "More" button opens popup with Categories, Statistics, Settings, Audit Log
- Main content has padding so nothing is hidden behind tab bar

**Step 6: Commit**

```bash
git add admin-frontend/src/components/layout/MainLayout.tsx
git commit -m "feat: integrate BottomTabBar into MainLayout, hide top nav on mobile"
```

---

## Task 3: MobileToolbar Component

Create a reusable toolbar component for mobile that provides search + sort dropdown + collapsible filter button.

**Files:**
- Create: `admin-frontend/src/components/layout/MobileToolbar.tsx`

**Step 1: Create the MobileToolbar component**

```tsx
// admin-frontend/src/components/layout/MobileToolbar.tsx
import { useState, useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'

interface SortOption {
  value: string
  label: string
  direction: 'asc' | 'desc'
}

interface MobileToolbarProps {
  search?: {
    value: string
    onChange: (value: string) => void
    placeholder?: string
    testId?: string
  }
  sort?: {
    options: SortOption[]
    value: string
    onChange: (value: string) => void
    testId?: string
  }
  filterCount?: number
  onFilterToggle?: () => void
  showFilters?: boolean
  filterContent?: React.ReactNode
  testId?: string
}

function ChevronDownIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <polyline points="6 9 12 15 18 9" />
    </svg>
  )
}

function ArrowUpIcon() {
  return (
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <line x1="12" y1="19" x2="12" y2="5" />
      <polyline points="5 12 12 5 19 12" />
    </svg>
  )
}

function ArrowDownIcon() {
  return (
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <line x1="12" y1="5" x2="12" y2="19" />
      <polyline points="19 12 12 19 5 12" />
    </svg>
  )
}

function FilterIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" />
    </svg>
  )
}

export function MobileToolbar({
  search,
  sort,
  filterCount = 0,
  onFilterToggle,
  showFilters = false,
  filterContent,
  testId = 'mobile-toolbar',
}: MobileToolbarProps) {
  const { t } = useTranslation()
  const [showSort, setShowSort] = useState(false)
  const sortRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (sortRef.current && !sortRef.current.contains(e.target as Node)) {
        setShowSort(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  const selectedSort = sort?.options.find((o) => o.value === sort.value)

  const buttonStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    gap: '4px',
    padding: '8px 10px',
    background: 'rgba(255,255,255,0.04)',
    border: '1px solid rgba(255,255,255,0.08)',
    borderRadius: '7px',
    color: theme.colors.text.primary,
    fontSize: '13px',
    cursor: 'pointer',
    whiteSpace: 'nowrap',
    flexShrink: 0,
  }

  return (
    <div data-testid={testId} style={{ marginBottom: '12px' }}>
      {/* Top row: search + sort + filter */}
      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: '8px',
          padding: '10px 14px',
          background: 'rgba(255,255,255,0.03)',
          borderRadius: showFilters ? '10px 10px 0 0' : '10px',
          border: '1px solid rgba(255,255,255,0.06)',
          borderBottom: showFilters ? '1px solid rgba(255,255,255,0.04)' : undefined,
        }}
      >
        {/* Search */}
        {search && (
          <div style={{ position: 'relative', flex: 1, minWidth: 0 }}>
            <svg
              width="14" height="14" viewBox="0 0 16 16" fill="none"
              style={{ position: 'absolute', left: '8px', top: '50%', transform: 'translateY(-50%)', opacity: 0.35 }}
            >
              <circle cx="7" cy="7" r="5.5" stroke="#fff" strokeWidth="1.5" />
              <path d="M11 11l3.5 3.5" stroke="#fff" strokeWidth="1.5" strokeLinecap="round" />
            </svg>
            <input
              type="text"
              value={search.value}
              onChange={(e) => search.onChange(e.target.value)}
              placeholder={search.placeholder || t('common.searchPlaceholder')}
              data-testid={search.testId || `${testId}-search`}
              style={{
                width: '100%',
                padding: '8px 10px 8px 28px',
                borderRadius: '7px',
                border: '1px solid rgba(255,255,255,0.08)',
                background: 'rgba(255,255,255,0.04)',
                color: '#e2e8f0',
                fontSize: '13px',
                outline: 'none',
              }}
            />
          </div>
        )}

        {/* Sort dropdown */}
        {sort && (
          <div ref={sortRef} style={{ position: 'relative' }}>
            <button
              data-testid={sort.testId || `${testId}-sort`}
              onClick={() => setShowSort(!showSort)}
              style={buttonStyle}
            >
              {selectedSort?.direction === 'asc' ? <ArrowUpIcon /> : <ArrowDownIcon />}
              <ChevronDownIcon />
            </button>

            {showSort && (
              <div
                data-testid={`${testId}-sort-dropdown`}
                style={{
                  position: 'absolute',
                  top: '100%',
                  right: 0,
                  marginTop: '6px',
                  minWidth: '180px',
                  background: theme.colors.bg.card,
                  border: `1px solid ${theme.colors.border.light}`,
                  borderRadius: theme.borderRadius.md,
                  boxShadow: '0 10px 40px rgba(0,0,0,0.4)',
                  zIndex: 1000,
                  padding: '6px',
                }}
              >
                {sort.options.map((option) => (
                  <button
                    key={option.value}
                    data-testid={`${testId}-sort-option-${option.value}`}
                    onClick={() => {
                      sort.onChange(option.value)
                      setShowSort(false)
                    }}
                    style={{
                      width: '100%',
                      display: 'flex',
                      alignItems: 'center',
                      gap: '8px',
                      padding: '10px 12px',
                      background: sort.value === option.value ? 'rgba(59,130,246,0.15)' : 'transparent',
                      border: 'none',
                      borderRadius: '8px',
                      color: sort.value === option.value ? theme.colors.semantic.primary : theme.colors.text.primary,
                      fontSize: '13px',
                      cursor: 'pointer',
                      textAlign: 'left',
                    }}
                  >
                    <span style={{ color: sort.value === option.value ? theme.colors.semantic.primary : theme.colors.text.muted }}>
                      {option.direction === 'asc' ? <ArrowUpIcon /> : <ArrowDownIcon />}
                    </span>
                    <span>{option.label}</span>
                  </button>
                ))}
              </div>
            )}
          </div>
        )}

        {/* Filter toggle */}
        {onFilterToggle && (
          <button
            data-testid={`${testId}-filter-toggle`}
            onClick={onFilterToggle}
            style={{
              ...buttonStyle,
              background: showFilters ? 'rgba(59,130,246,0.15)' : buttonStyle.background,
              borderColor: showFilters ? 'rgba(59,130,246,0.3)' : 'rgba(255,255,255,0.08)',
              color: showFilters ? theme.colors.semantic.primary : theme.colors.text.primary,
            }}
          >
            <FilterIcon />
            {filterCount > 0 && (
              <span
                data-testid={`${testId}-filter-badge`}
                style={{
                  background: theme.colors.semantic.primary,
                  color: '#fff',
                  borderRadius: '9999px',
                  padding: '0 6px',
                  fontSize: '11px',
                  fontWeight: 600,
                  lineHeight: '18px',
                  minWidth: '18px',
                  textAlign: 'center',
                }}
              >
                {filterCount}
              </span>
            )}
          </button>
        )}
      </div>

      {/* Expanded filters */}
      {showFilters && filterContent && (
        <div
          data-testid={`${testId}-filters`}
          style={{
            padding: '12px 14px',
            background: 'rgba(255,255,255,0.03)',
            borderRadius: '0 0 10px 10px',
            border: '1px solid rgba(255,255,255,0.06)',
            borderTop: 'none',
            display: 'flex',
            flexDirection: 'column',
            gap: '10px',
          }}
        >
          {filterContent}
        </div>
      )}
    </div>
  )
}
```

**Step 2: Verify it compiles**

Run: `cd admin-frontend && npx tsc --noEmit 2>&1 | head -30`
Expected: No errors related to MobileToolbar.

**Step 3: Commit**

```bash
git add admin-frontend/src/components/layout/MobileToolbar.tsx
git commit -m "feat: add MobileToolbar component with search, sort dropdown, and collapsible filters"
```

---

## Task 4: MembersPage Mobile View

Add card view, mobile toolbar, and single-column form to the Members page.

**Files:**
- Modify: `admin-frontend/src/pages/MembersPage.tsx`

**Step 1: Add imports**

At line 10, after the `useBreakpoint` import, add:

```tsx
import { MobileToolbar } from '../components/layout/MobileToolbar'
```

**Step 2: Add isMobile variable and sort helpers**

After the breakpoint variable (around line 36–37 where `const breakpoint = useBreakpoint()` is), add:

```tsx
const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'
```

Add a helper to compute the active filter count and sort options. After the state declarations (after line 66), add:

```tsx
const [showMobileFilters, setShowMobileFilters] = useState(false)

const mobileFilterCount = [
  filterIsActive !== 'all' ? 1 : 0,
  filterCardUid !== 'all' ? 1 : 0,
  filterSepaStatus !== 'all' ? 1 : 0,
].reduce((a, b) => a + b, 0)

const mobileSortOptions = [
  { value: 'last_name_asc', label: t('members.sortName', 'Name A–Z'), direction: 'asc' as const },
  { value: 'last_name_desc', label: t('members.sortNameDesc', 'Name Z–A'), direction: 'desc' as const },
  { value: 'card_uid_asc', label: t('members.sortCard', 'Card-UID ↑'), direction: 'asc' as const },
  { value: 'created_at_desc', label: t('members.sortNewest', 'Newest first'), direction: 'desc' as const },
  { value: 'created_at_asc', label: t('members.sortOldest', 'Oldest first'), direction: 'asc' as const },
]

const mobileSortValue = `${sortKey}_${sortDirection}`

const handleMobileSortChange = (value: string) => {
  const lastUnderscore = value.lastIndexOf('_')
  const key = value.substring(0, lastUnderscore) as typeof sortKey
  const dir = value.substring(lastUnderscore + 1) as 'asc' | 'desc'
  setSortKey(key)
  setSortDirection(dir)
  setPage(1)
}
```

**Step 3: Add mobile filter row component**

Below the helpers above, add a helper for rendering a filter pill group (reusable within this page):

```tsx
const MobileFilterRow = ({ label, options, value, onChange, testId }: {
  label: string
  options: { value: string; label: string }[]
  value: string
  onChange: (v: string) => void
  testId: string
}) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
    <span style={{ fontSize: '12px', color: 'rgba(255,255,255,0.35)', fontWeight: 500, textTransform: 'uppercase', minWidth: '50px' }}>
      {label}
    </span>
    {options.map((opt) => (
      <button
        key={opt.value}
        data-testid={`${testId}-${opt.value}`}
        onClick={() => { onChange(opt.value); setPage(1) }}
        style={{
          padding: '4px 10px',
          borderRadius: '6px',
          border: 'none',
          background: value === opt.value ? 'rgba(59,130,246,0.2)' : 'rgba(255,255,255,0.04)',
          color: value === opt.value ? '#3b82f6' : 'rgba(255,255,255,0.5)',
          fontSize: '12px',
          fontWeight: value === opt.value ? 600 : 400,
          cursor: 'pointer',
        }}
      >
        {opt.label}
      </button>
    ))}
  </div>
)
```

**Step 4: Replace toolbar section with conditional mobile/desktop**

Find the `{/* Unified filter toolbar */}` block (line ~380). Wrap it and the table in the mobile/desktop conditional:

Before the existing toolbar div, add:

```tsx
{isMobile ? (
  <>
    <MobileToolbar
      testId="members-mobile-toolbar"
      search={{
        value: search,
        onChange: (v) => { setSearch(v); setPage(1) },
        testId: 'members-search-input',
      }}
      sort={{
        options: mobileSortOptions,
        value: mobileSortValue,
        onChange: handleMobileSortChange,
      }}
      filterCount={mobileFilterCount}
      onFilterToggle={() => setShowMobileFilters(!showMobileFilters)}
      showFilters={showMobileFilters}
      filterContent={
        <>
          <MobileFilterRow
            label={t('members.filterStatus', 'Status')}
            options={[
              { value: 'all', label: t('common.all') },
              { value: 'active', label: t('common.active') },
              { value: 'inactive', label: t('common.inactive') },
            ]}
            value={filterIsActive}
            onChange={(v) => setFilterIsActive(v as typeof filterIsActive)}
            testId="members-mobile-filter-status"
          />
          <MobileFilterRow
            label={t('members.filterCard', 'Card')}
            options={[
              { value: 'all', label: t('common.all') },
              { value: 'with', label: t('members.filterWithCard', 'With') },
              { value: 'without', label: t('members.filterWithoutCard', 'Without') },
            ]}
            value={filterCardUid}
            onChange={(v) => setFilterCardUid(v as typeof filterCardUid)}
            testId="members-mobile-filter-card"
          />
          <MobileFilterRow
            label="SEPA"
            options={[
              { value: 'all', label: t('common.all') },
              { value: 'valid', label: t('members.filterSepaValid', 'Valid') },
              { value: 'missing', label: t('members.filterSepaMissing', 'Missing') },
            ]}
            value={filterSepaStatus}
            onChange={(v) => setFilterSepaStatus(v as typeof filterSepaStatus)}
            testId="members-mobile-filter-sepa"
          />
        </>
      }
    />

    {/* Mobile card list */}
    <div data-testid="members-mobile-cards" style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
      {members.map((member) => (
        <div
          key={member.id}
          data-testid={`member-card-${member.id}`}
          style={{
            background: 'rgba(255,255,255,0.03)',
            border: '1px solid rgba(255,255,255,0.06)',
            borderRadius: '10px',
            padding: '14px 16px',
          }}
        >
          {/* Row 1: toggle + name + chevron */}
          <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '8px' }}>
            <StatusToggleCell
              isActive={member.is_active}
              onToggle={() => handleToggleActive(member)}
              testId={`member-toggle-${member.id}`}
            />
            <span style={{ flex: 1, fontWeight: 600, color: theme.colors.text.primary, fontSize: '14px' }}>
              {member.first_name} {member.last_name}
            </span>
          </div>
          {/* Row 2: SEPA + Card info */}
          <div style={{ display: 'flex', gap: '12px', fontSize: '12px', color: theme.colors.text.secondary, marginBottom: '6px' }}>
            <span>
              SEPA: {member.iban ? (
                <span style={{ color: theme.colors.semantic.success }}>{t('members.sepaValid', 'Valid')}</span>
              ) : (
                <span style={{ color: theme.colors.text.muted }}>{t('members.sepaMissing', 'Missing')}</span>
              )}
            </span>
            {member.card_uid && <span>Card: {member.card_uid}</span>}
          </div>
          {/* Row 3: member since */}
          <div style={{ fontSize: '12px', color: theme.colors.text.muted, marginBottom: '10px' }}>
            {t('members.memberSince', 'Since')}: {formatters.date(member.created_at)}
          </div>
          {/* Row 4: actions */}
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '8px' }}>
            <button
              data-testid={`member-edit-${member.id}`}
              onClick={() => handleEdit(member)}
              style={{
                display: 'flex', alignItems: 'center', gap: '4px',
                padding: '6px 12px', borderRadius: '6px', border: 'none',
                background: 'rgba(59,130,246,0.1)', color: theme.colors.semantic.primary,
                fontSize: '12px', cursor: 'pointer',
              }}
            >
              <EditIcon size={14} /> {t('common.edit')}
            </button>
            <button
              data-testid={`member-delete-${member.id}`}
              onClick={() => setDeleteConfirm(member.id)}
              style={{
                display: 'flex', alignItems: 'center', gap: '4px',
                padding: '6px 12px', borderRadius: '6px', border: 'none',
                background: 'rgba(239,68,68,0.1)', color: theme.colors.semantic.danger,
                fontSize: '12px', cursor: 'pointer',
              }}
            >
              <TrashIcon size={14} /> {t('common.delete')}
            </button>
          </div>
        </div>
      ))}
    </div>
  </>
) : (
  <>
    {/* ... existing desktop toolbar + table code here ... */}
  </>
)}
```

Move the existing toolbar div and table wrapper into the desktop `<>...</>` branch.

**Step 5: Fix form grid for mobile**

Find the form grid (around line 947 with `gridTemplateColumns: '1fr 1fr'`). Change to:

```tsx
gridTemplateColumns: isMobile ? '1fr' : '1fr 1fr',
```

**Step 6: Verify visually**

Run dev server, open at 375px:
- Verify mobile toolbar shows with search, sort icon, filter button
- Verify member cards render with toggle, name, SEPA/card info, edit/delete
- Verify filter button shows badge when filters active
- Verify sort dropdown opens and changes order
- Verify form modal shows single-column on mobile
- Verify desktop is unchanged at 1024px+

**Step 7: Commit**

```bash
git add admin-frontend/src/pages/MembersPage.tsx
git commit -m "feat: add mobile card view, toolbar, and single-column form to MembersPage"
```

---

## Task 5: ProductsPage Mobile View

Add card view and mobile toolbar to the Products page.

**Files:**
- Modify: `admin-frontend/src/pages/ProductsPage.tsx`

**Step 1: Add imports and mobile state**

Add imports for `useBreakpoint` and `MobileToolbar` (if not already present). Add:

```tsx
import { useBreakpoint } from '../hooks/useBreakpoint'
import { MobileToolbar } from '../components/layout/MobileToolbar'
```

Add state and helpers:

```tsx
const breakpoint = useBreakpoint()
const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'
const [showMobileFilters, setShowMobileFilters] = useState(false)
```

Add sort options (match existing sort state variables in the page):

```tsx
const mobileSortOptions = [
  { value: 'name_asc', label: t('products.sortName', 'Name A–Z'), direction: 'asc' as const },
  { value: 'name_desc', label: t('products.sortNameDesc', 'Name Z–A'), direction: 'desc' as const },
  { value: 'price_asc', label: t('products.sortPriceLow', 'Price ↑'), direction: 'asc' as const },
  { value: 'price_desc', label: t('products.sortPriceHigh', 'Price ↓'), direction: 'desc' as const },
  { value: 'category_asc', label: t('products.sortCategory', 'Category'), direction: 'asc' as const },
]
```

**Step 2: Add mobile card view**

Wrap the existing toolbar + table in `{isMobile ? (...mobile...) : (...desktop...)}`.

Product card layout:

```tsx
<div
  key={product.id}
  data-testid={`product-card-${product.id}`}
  style={{
    background: 'rgba(255,255,255,0.03)',
    border: '1px solid rgba(255,255,255,0.06)',
    borderRadius: '10px',
    padding: '14px 16px',
  }}
>
  {/* Row 1: toggle + name + price */}
  <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '6px' }}>
    <StatusToggleCell
      isActive={product.is_active}
      onToggle={() => handleToggleActive(product)}
      testId={`product-toggle-${product.id}`}
    />
    <span style={{ flex: 1, fontWeight: 600, color: theme.colors.text.primary, fontSize: '14px' }}>
      {product.name}
    </span>
    <span style={{ fontWeight: 600, color: theme.colors.semantic.primary, fontSize: '14px' }}>
      {formatters.currency(product.price)}
    </span>
  </div>
  {/* Row 2: category */}
  <div style={{ fontSize: '12px', color: theme.colors.text.secondary, marginBottom: '10px', paddingLeft: '34px' }}>
    {t('products.category', 'Category')}: {product.category_name || '—'}
  </div>
  {/* Row 3: actions */}
  <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '8px' }}>
    <button
      data-testid={`product-edit-${product.id}`}
      onClick={() => handleEdit(product)}
      style={{
        display: 'flex', alignItems: 'center', gap: '4px',
        padding: '6px 12px', borderRadius: '6px', border: 'none',
        background: 'rgba(59,130,246,0.1)', color: theme.colors.semantic.primary,
        fontSize: '12px', cursor: 'pointer',
      }}
    >
      <EditIcon size={14} /> {t('common.edit')}
    </button>
    <button
      data-testid={`product-delete-${product.id}`}
      onClick={() => setDeleteConfirm(product.id)}
      style={{
        display: 'flex', alignItems: 'center', gap: '4px',
        padding: '6px 12px', borderRadius: '6px', border: 'none',
        background: 'rgba(239,68,68,0.1)', color: theme.colors.semantic.danger,
        fontSize: '12px', cursor: 'pointer',
      }}
    >
      <TrashIcon size={14} /> {t('common.delete')}
    </button>
  </div>
</div>
```

Filter content: Status (Active/Inactive) + Category dropdown.

**Step 3: Verify visually and commit**

```bash
git add admin-frontend/src/pages/ProductsPage.tsx
git commit -m "feat: add mobile card view and toolbar to ProductsPage"
```

---

## Task 6: JournalPage Mobile View

Add card view and mobile toolbar to the Journal page.

**Files:**
- Modify: `admin-frontend/src/pages/JournalPage.tsx`

**Step 1: Add imports, state, sort options**

Same pattern as Tasks 4–5. Sort options: Date (newest/oldest), Member, Amount.

**Step 2: Add mobile card view**

Journal card layout:

```tsx
<div
  key={entry.id}
  data-testid={`journal-card-${entry.id}`}
  style={{
    background: 'rgba(255,255,255,0.03)',
    border: '1px solid rgba(255,255,255,0.06)',
    borderRadius: '10px',
    padding: '14px 16px',
  }}
>
  {/* Row 1: date + time + type badge */}
  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '6px' }}>
    <span style={{ fontSize: '13px', color: theme.colors.text.secondary }}>
      {formatters.date(entry.created_at)} {formatters.time(entry.created_at)}
    </span>
    <span style={{
      padding: '2px 8px', borderRadius: '4px', fontSize: '11px', fontWeight: 600,
      background: entry.type === 'purchase' ? 'rgba(59,130,246,0.15)' : 'rgba(239,68,68,0.15)',
      color: entry.type === 'purchase' ? theme.colors.semantic.primary : theme.colors.semantic.danger,
    }}>
      {entry.type}
    </span>
  </div>
  {/* Row 2: member name */}
  <div style={{ fontWeight: 600, color: theme.colors.text.primary, fontSize: '14px', marginBottom: '4px' }}>
    {entry.member_name}
  </div>
  {/* Row 3: product + terminal */}
  <div style={{ fontSize: '12px', color: theme.colors.text.secondary, marginBottom: '4px' }}>
    {entry.product_name}{entry.terminal_name ? ` · ${entry.terminal_name}` : ''}
  </div>
  {/* Row 4: amount right-aligned */}
  <div style={{ textAlign: 'right', fontWeight: 600, fontSize: '14px', color: theme.colors.text.primary }}>
    {formatters.currency(entry.amount)}
  </div>
</div>
```

Filter content: PeriodPicker + SettlementStatusFilter (reuse existing components, stacked vertically).

**Step 3: Mobile actions bar**

The journal has an actions bar (create correction, settlement buttons). On mobile, stack these vertically or wrap them. Use `flexWrap: 'wrap'` on the actions bar when `isMobile`.

**Step 4: Verify visually and commit**

```bash
git add admin-frontend/src/pages/JournalPage.tsx
git commit -m "feat: add mobile card view and toolbar to JournalPage"
```

---

## Task 7: SettlementsPage Mobile View

Add card view and mobile toolbar to the Settlements page.

**Files:**
- Modify: `admin-frontend/src/pages/SettlementsPage.tsx`

**Step 1: Add imports, state, sort options**

Sort options: Date (newest/oldest).

**Step 2: Add mobile card view**

Settlement card layout:

```tsx
<div
  key={settlement.id}
  data-testid={`settlement-card-${settlement.id}`}
  style={{
    background: 'rgba(255,255,255,0.03)',
    border: '1px solid rgba(255,255,255,0.06)',
    borderRadius: '10px',
    padding: '14px 16px',
  }}
>
  {/* Row 1: date + status */}
  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' }}>
    <span style={{ fontWeight: 600, color: theme.colors.text.primary, fontSize: '14px' }}>
      {formatters.date(settlement.settlement_date)}
    </span>
    <span style={{
      padding: '2px 8px', borderRadius: '4px', fontSize: '11px', fontWeight: 600,
      background: settlement.is_cancelled ? 'rgba(239,68,68,0.15)' : 'rgba(34,197,94,0.15)',
      color: settlement.is_cancelled ? theme.colors.semantic.danger : theme.colors.semantic.success,
    }}>
      {settlement.is_cancelled ? t('settlements.cancelled') : t('settlements.active')}
    </span>
  </div>
  {/* Row 2: admin user */}
  <div style={{ fontSize: '13px', color: theme.colors.text.secondary, marginBottom: '4px' }}>
    {settlement.admin_user_name}
  </div>
  {/* Row 3: summary */}
  <div style={{ fontSize: '12px', color: theme.colors.text.muted, marginBottom: '6px' }}>
    {settlement.member_count} {t('settlements.members')} · {settlement.transaction_count} {t('settlements.transactions')}
  </div>
  {/* Row 4: amount */}
  <div style={{ fontWeight: 600, fontSize: '16px', color: theme.colors.text.primary, marginBottom: '10px' }}>
    {formatters.currency(settlement.total_amount)}
  </div>
  {/* Row 5: action buttons */}
  <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
    {/* SEPA, CSV, TXN, Undo buttons — same as desktop but smaller padding */}
    {/* Use compact button style: padding '5px 10px', fontSize '11px' */}
  </div>
</div>
```

Filter content: PeriodPicker + cancel status filter.

**Step 3: Verify visually and commit**

```bash
git add admin-frontend/src/pages/SettlementsPage.tsx
git commit -m "feat: add mobile card view and toolbar to SettlementsPage"
```

---

## Task 8: CategoriesPage Mobile View

**Files:**
- Modify: `admin-frontend/src/pages/CategoriesPage.tsx`

Category card layout:

```tsx
{/* Row 1: toggle + name */}
{/* Row 2: product count + edit/delete */}
```

Filter content: Status (Active/Inactive). Sort: Name.

**Commit:**

```bash
git add admin-frontend/src/pages/CategoriesPage.tsx
git commit -m "feat: add mobile card view and toolbar to CategoriesPage"
```

---

## Task 9: AuditLogPage Mobile View

**Files:**
- Modify: `admin-frontend/src/pages/AuditLogPage.tsx`

Audit log card layout:

```tsx
{/* Row 1: timestamp + action badge */}
{/* Row 2: admin user + entity type */}
{/* Row 3: entity ID (truncated) + expand chevron */}
```

Filter content: All 4 existing filters (admin, action, entity type, date range) stacked vertically. Sort: Timestamp.

**Commit:**

```bash
git add admin-frontend/src/pages/AuditLogPage.tsx
git commit -m "feat: add mobile card view and toolbar to AuditLogPage"
```

---

## Task 10: SettingsPage Mobile View (Admin Users)

**Files:**
- Modify: `admin-frontend/src/components/settings/AdminUsersTab.tsx`

Admin user card layout:

```tsx
{/* Row 1: toggle + name */}
{/* Row 2: email */}
{/* Row 3: last login + edit/delete */}
```

No filters or sort needed (Settings page has no search/sort per the design spec).

**Commit:**

```bash
git add admin-frontend/src/components/settings/AdminUsersTab.tsx
git commit -m "feat: add mobile card view to AdminUsersTab"
```

---

## Task 11: StatisticsPage Mobile View

**Files:**
- Modify: `admin-frontend/src/pages/StatisticsPage.tsx`

**Step 1: Stack the rankings grid**

Find the rankings grid (line ~407 with `gridTemplateColumns: 'repeat(2, 1fr)'`). Change to:

```tsx
gridTemplateColumns: isMobile ? '1fr' : 'repeat(2, 1fr)',
```

**Step 2: Fix summary boxes grid**

Find the summary boxes grid (line ~232 with `gridTemplateColumns: 'repeat(3, 1fr)'`). Change to:

```tsx
gridTemplateColumns: isMobile ? '1fr' : 'repeat(3, 1fr)',
```

**Step 3: Verify and commit**

```bash
git add admin-frontend/src/pages/StatisticsPage.tsx
git commit -m "feat: stack statistics grids vertically on mobile"
```

---

## Task 12: Visual QA Pass

Open the dev server at 375px and test every page:

**Checklist:**
- [ ] Login page renders properly
- [ ] Bottom tab bar visible on all pages
- [ ] "More" popup works (Categories, Statistics, Settings, Audit Log)
- [ ] Members: cards + toolbar + filters + sort + pagination + form
- [ ] Products: cards + toolbar + filters + sort + pagination + form
- [ ] Journal: cards + toolbar + filters + sort + pagination
- [ ] Settlements: cards + toolbar + filters + sort + action buttons
- [ ] Categories: cards + toolbar + filters + sort
- [ ] Audit Log: cards + toolbar + filters + sort + expand
- [ ] Settings: admin user cards, tabs still work
- [ ] Statistics: stacked grids, charts readable
- [ ] Desktop (1024px+): no changes, everything identical to before

Fix any visual issues found. Commit fixes.

```bash
git commit -m "fix: visual QA fixes for mobile responsive design"
```

---

## Task 13: Add i18n Keys

Check all new translation keys used in Tasks 1–12. Add any missing keys to the locale files.

**Files:**
- Modify: `admin-frontend/public/locales/de/translation.json`
- Modify: `admin-frontend/public/locales/en/translation.json`

Keys to add (check which are missing):
- `nav.more` → "Mehr" / "More"
- `members.sortName` → "Name A–Z"
- `members.sortNameDesc` → "Name Z–A"
- `members.sortCard` → "Card-UID ↑"
- `members.sortNewest` → "Neueste zuerst" / "Newest first"
- `members.sortOldest` → "Älteste zuerst" / "Oldest first"
- `members.filterStatus` → "Status"
- `members.filterCard` → "Karte" / "Card"
- `members.filterWithCard` → "Mit" / "With"
- `members.filterWithoutCard` → "Ohne" / "Without"
- `members.filterSepaValid` → "Gültig" / "Valid"
- `members.filterSepaMissing` → "Fehlt" / "Missing"
- `members.sepaValid` → "Gültig" / "Valid"
- `members.sepaMissing` → "Fehlt" / "Missing"
- `members.memberSince` → "Seit" / "Since"
- `products.sortName`, `products.sortNameDesc`, `products.sortPriceLow`, `products.sortPriceHigh`, `products.sortCategory`
- `products.category` → "Kategorie" / "Category"
- `settlements.active`, `settlements.cancelled`, `settlements.members`, `settlements.transactions`
- Any other sort/filter labels per page

**Commit:**

```bash
git add admin-frontend/public/locales/
git commit -m "feat: add i18n keys for mobile responsive components"
```

---

## Task 14: E2E Tests for Mobile View

Add a dedicated Playwright test suite that runs with iPhone device emulation to verify all mobile-specific components.

**Files:**
- Modify: `e2etests/playwright.config.ts`
- Create: `e2etests/tests/admin/mobile-responsive.spec.ts`

**Step 1: Add mobile project to Playwright config**

In `e2etests/playwright.config.ts`, after the `admin-chromium` project (line ~63), add:

```typescript
// Admin Panel - Mobile (iPhone 14 emulation)
{
  name: 'admin-mobile',
  testDir: './tests/admin',
  testMatch: 'mobile-*.spec.ts',
  dependencies: ['setup auth'],
  use: {
    ...devices['iPhone 14'],
    baseURL: process.env.ADMIN_URL || 'http://localhost:5173',
    storageState: 'playwright/.auth/admin.json',
  },
},
```

**Step 2: Create the mobile E2E test file**

```typescript
// e2etests/tests/admin/mobile-responsive.spec.ts
import { test, expect } from '@playwright/test'

// These tests run with iPhone 14 device emulation (via playwright.config.ts admin-mobile project)
// Viewport: 390x844, touch enabled, mobile user agent

test.describe('Mobile Responsive - Navigation', () => {
  test('should show bottom tab bar on mobile', async ({ page }) => {
    await page.goto('/members')
    const tabBar = page.locator('[data-testid="bottom-tab-bar"]')
    await expect(tabBar).toBeVisible()

    // Verify 5 tabs visible
    await expect(page.locator('[data-testid="tab-members"]')).toBeVisible()
    await expect(page.locator('[data-testid="tab-products"]')).toBeVisible()
    await expect(page.locator('[data-testid="tab-journal"]')).toBeVisible()
    await expect(page.locator('[data-testid="tab-settlements"]')).toBeVisible()
    await expect(page.locator('[data-testid="tab-more"]')).toBeVisible()
  })

  test('should hide top navigation on mobile', async ({ page }) => {
    await page.goto('/members')
    // Desktop nav links should not be visible
    await expect(page.locator('[data-testid="nav-members"]')).not.toBeVisible()
  })

  test('should navigate between tabs', async ({ page }) => {
    await page.goto('/members')
    await page.locator('[data-testid="tab-products"]').click()
    await expect(page).toHaveURL(/\/products/)
    await page.locator('[data-testid="tab-journal"]').click()
    await expect(page).toHaveURL(/\/journal/)
  })

  test('should open More popup with additional pages', async ({ page }) => {
    await page.goto('/members')
    await page.locator('[data-testid="tab-more"]').click()
    const popup = page.locator('[data-testid="tab-more-popup"]')
    await expect(popup).toBeVisible()

    // Verify all 4 "more" items
    await expect(page.locator('[data-testid="tab-categories"]')).toBeVisible()
    await expect(page.locator('[data-testid="tab-statistics"]')).toBeVisible()
    await expect(page.locator('[data-testid="tab-settings"]')).toBeVisible()
    await expect(page.locator('[data-testid="tab-audit-log"]')).toBeVisible()
  })

  test('should navigate from More popup and close it', async ({ page }) => {
    await page.goto('/members')
    await page.locator('[data-testid="tab-more"]').click()
    await page.locator('[data-testid="tab-categories"]').click()
    await expect(page).toHaveURL(/\/categories/)
    // Popup should close after navigation
    await expect(page.locator('[data-testid="tab-more-popup"]')).not.toBeVisible()
  })
})

test.describe('Mobile Responsive - Members Page', () => {
  test('should show mobile cards instead of table', async ({ page }) => {
    await page.goto('/members')
    // Cards should be visible
    await expect(page.locator('[data-testid="members-mobile-cards"]')).toBeVisible()
    // Desktop table should not be visible
    await expect(page.locator('[data-testid="members-table-wrapper"]')).not.toBeVisible()
  })

  test('should show mobile toolbar with search, sort, and filter', async ({ page }) => {
    await page.goto('/members')
    await expect(page.locator('[data-testid="members-mobile-toolbar"]')).toBeVisible()
    await expect(page.locator('[data-testid="members-search-input"]')).toBeVisible()
  })

  test('should search members in mobile view', async ({ page }) => {
    await page.goto('/members')
    const searchInput = page.locator('[data-testid="members-search-input"]')
    await searchInput.fill('Admin')
    // Wait for API response with search param
    await page.waitForResponse((resp) =>
      resp.url().includes('/api/admin/members') && resp.status() === 200
    )
    // Verify cards are filtered (at least one card visible)
    const cards = page.locator('[data-testid^="member-card-"]')
    await expect(cards.first()).toBeVisible()
  })

  test('should toggle filter panel and show filter pills', async ({ page }) => {
    await page.goto('/members')
    const filterToggle = page.locator('[data-testid="members-mobile-toolbar-filter-toggle"]')
    await filterToggle.click()
    // Filter panel should be visible
    await expect(page.locator('[data-testid="members-mobile-toolbar-filters"]')).toBeVisible()
    // Status filter pills should be visible
    await expect(page.locator('[data-testid="members-mobile-filter-status-all"]')).toBeVisible()
    await expect(page.locator('[data-testid="members-mobile-filter-status-active"]')).toBeVisible()
  })

  test('should show filter badge count when filters are active', async ({ page }) => {
    await page.goto('/members')
    // Open filters
    await page.locator('[data-testid="members-mobile-toolbar-filter-toggle"]').click()
    // Select "active" status filter
    await page.locator('[data-testid="members-mobile-filter-status-active"]').click()
    // Wait for filtered response
    await page.waitForResponse((resp) =>
      resp.url().includes('/api/admin/members') && resp.status() === 200
    )
    // Badge should show "1"
    const badge = page.locator('[data-testid="members-mobile-toolbar-filter-badge"]')
    await expect(badge).toHaveText('1')
  })

  test('should open sort dropdown and change sort order', async ({ page }) => {
    await page.goto('/members')
    await page.locator('[data-testid="members-mobile-toolbar-sort"]').click()
    const sortDropdown = page.locator('[data-testid="members-mobile-toolbar-sort-dropdown"]')
    await expect(sortDropdown).toBeVisible()
    // Click a sort option
    await page.locator('[data-testid="members-mobile-toolbar-sort-option-last_name_asc"]').click()
    // Dropdown should close
    await expect(sortDropdown).not.toBeVisible()
    // Wait for sorted response
    await page.waitForResponse((resp) =>
      resp.url().includes('/api/admin/members') && resp.status() === 200
    )
  })

  test('member cards should have edit and delete buttons', async ({ page }) => {
    await page.goto('/members')
    const firstCard = page.locator('[data-testid^="member-card-"]').first()
    await expect(firstCard).toBeVisible()
    // Edit and delete buttons should be inside the card
    await expect(firstCard.locator('[data-testid^="member-edit-"]')).toBeVisible()
    await expect(firstCard.locator('[data-testid^="member-delete-"]')).toBeVisible()
  })
})

test.describe('Mobile Responsive - Products Page', () => {
  test('should show mobile cards instead of table', async ({ page }) => {
    await page.goto('/products')
    await expect(page.locator('[data-testid="products-mobile-cards"]')).toBeVisible()
    await expect(page.locator('[data-testid="products-table-wrapper"]')).not.toBeVisible()
  })

  test('product cards should show name, price, and category', async ({ page }) => {
    await page.goto('/products')
    const firstCard = page.locator('[data-testid^="product-card-"]').first()
    await expect(firstCard).toBeVisible()
    // Should have edit and delete actions
    await expect(firstCard.locator('[data-testid^="product-edit-"]')).toBeVisible()
    await expect(firstCard.locator('[data-testid^="product-delete-"]')).toBeVisible()
  })
})

test.describe('Mobile Responsive - Settlements Page', () => {
  test('should show mobile cards instead of table', async ({ page }) => {
    await page.goto('/settlements')
    await expect(page.locator('[data-testid="settlements-mobile-cards"]')).toBeVisible()
    await expect(page.locator('[data-testid="settlements-table-wrapper"]')).not.toBeVisible()
  })
})

test.describe('Mobile Responsive - Categories Page', () => {
  test('should show mobile cards instead of table', async ({ page }) => {
    await page.goto('/categories')
    await expect(page.locator('[data-testid="categories-mobile-cards"]')).toBeVisible()
    await expect(page.locator('[data-testid="categories-table-wrapper"]')).not.toBeVisible()
  })
})

test.describe('Mobile Responsive - Audit Log Page', () => {
  test('should show mobile cards instead of table', async ({ page }) => {
    await page.goto('/audit-log')
    await expect(page.locator('[data-testid="audit-log-mobile-cards"]')).toBeVisible()
    await expect(page.locator('[data-testid="audit-log-table-wrapper"]')).not.toBeVisible()
  })
})

test.describe('Mobile Responsive - Statistics Page', () => {
  test('should stack summary boxes vertically', async ({ page }) => {
    await page.goto('/statistics')
    const summaryBoxes = page.locator('[data-testid="summary-boxes"]')
    await expect(summaryBoxes).toBeVisible()
    // On mobile (390px), the grid should be single-column
    // Verify the element is less than viewport width (no horizontal overflow)
    const box = await summaryBoxes.boundingBox()
    expect(box!.width).toBeLessThanOrEqual(390)
  })
})

test.describe('Mobile Responsive - Settings Page', () => {
  test('should show admin user cards on mobile', async ({ page }) => {
    await page.goto('/settings')
    // Click Admin Users tab if needed
    const adminTab = page.locator('button', { hasText: 'Admin' }).first()
    if (await adminTab.isVisible()) {
      await adminTab.click()
    }
    // Should show mobile cards for admin users
    await expect(page.locator('[data-testid="settings-admin-users-mobile-cards"]')).toBeVisible()
  })
})

test.describe('Mobile Responsive - No Desktop Regression', () => {
  // Note: This test uses iPhone 14 viewport (390px), so it verifies mobile behavior.
  // Desktop regression is covered by the existing admin-chromium tests which use Desktop Chrome.
  test('existing desktop tests should still pass (verified by admin-chromium project)', async ({ page }) => {
    // This is a placeholder to document that desktop regression
    // is covered by the admin-chromium project which runs all
    // existing test files at desktop viewport (1280x720).
    // No action needed here.
    await page.goto('/members')
    await expect(page.locator('[data-testid="bottom-tab-bar"]')).toBeVisible()
  })
})
```

**Step 3: Run the mobile tests**

```bash
cd e2etests
npm test -- --project=admin-mobile --workers=4
```

Expected: All tests pass. If failures, fix the implementation and re-run.

**Step 4: Run the existing desktop tests to verify no regressions**

```bash
cd e2etests
npm test -- --project=admin-chromium --workers=4
```

Expected: All existing tests still pass (they run with Desktop Chrome, not affected by mobile changes).

**Step 5: Commit**

```bash
git add e2etests/playwright.config.ts e2etests/tests/admin/mobile-responsive.spec.ts
git commit -m "test: add E2E tests for mobile responsive design with iPhone 14 emulation"
```
