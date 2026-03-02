# Mobile Responsive Design — Admin Frontend

**Date:** 2026-03-02

**Goal:** Make the admin frontend fully usable on 375px mobile screens (iPhone SE/mini).

**Approach:** Four coordinated changes gated behind `useBreakpoint()` — no desktop regressions.

---

## 1. Bottom Tab Bar Navigation

On mobile (`<768px`), the top horizontal nav is removed and replaced with a fixed bottom tab bar.

**5 bottom tabs:**

| Position | Label | Icon | Route |
|----------|-------|------|-------|
| 1 | Members | UsersIcon | /members |
| 2 | Products | PackageIcon | /products |
| 3 | Journal | BookIcon | /journal |
| 4 | Settlements | ReceiptIcon | /settlements |
| 5 | More | GridIcon (dots) | popup menu |

**"More" popup** appears above the tab bar showing:
- Categories
- Statistics
- Settings
- Audit Log

**Header on mobile:** Shrinks to logo (36px) + logout icon button. No nav row.

**Tab bar specs:**
- Height: 56px + safe-area-inset-bottom
- Fixed to bottom of viewport
- Main content gets `paddingBottom: 56px`
- Active tab: blue highlight, same as current nav style
- "More" popup: absolute positioned div toggling on tap, dismiss on outside click

**Implementation:** New `BottomTabBar` component in `components/layout/`. Rendered by `MainLayout` when `isMobile`. Existing `<nav>` hidden with `display: none` on mobile.

**Desktop (>=768px):** No change. Current top nav stays as-is.

---

## 2. Card View for Mobile Data

On mobile, every `<table>` is hidden and a card-based list is shown instead. Two sibling elements gated by breakpoint — no complex responsive table hacks.

```tsx
{isMobile ? <MobileCardList data={items} /> : <DesktopTable data={items} />}
```

### Card layouts per page

**Members:**
```
+-------------------------------+
| [toggle]  Max Mustermann  [>] |
|   SEPA: Gültig   Card: 00123 |
|   Seit: 01.03.2026           |
|                  [edit] [del] |
+-------------------------------+
```

**Products:**
```
+-------------------------------+
| [toggle]  Weizenbier    3,50€ |
|   Kategorie: Getränke         |
|                  [edit] [del] |
+-------------------------------+
```

**Settlements:**
```
+-------------------------------+
| 01.03.2026          Aktiv     |
|  Admin User                   |
|  2 Mitglieder · 4 Buchungen  |
|  55,00 €                      |
| [SEPA] [CSV] [TXN] [Undo]    |
+-------------------------------+
```

**Journal:**
```
+-------------------------------+
| 01.03.2026  22:57   Purchase  |
|  Max Mustermann               |
|  Weizenbier · stat-test       |
|                      3,50 €   |
+-------------------------------+
```

**Audit Log:**
```
+-------------------------------+
| 01.03.2026, 22:57    create   |
|  Admin User · product         |
|  550b0f9c-ef5f...        [>]  |
+-------------------------------+
```

**Categories:**
```
+-------------------------------+
| [toggle]  Getränke            |
|   3 Produkte     [edit] [del] |
+-------------------------------+
```

**Settings (Admin Users):**
```
+-------------------------------+
| [toggle]  Admin User          |
|   admin@example.com           |
|   Letzter Login: Heute        |
|                  [edit] [del] |
+-------------------------------+
```

### Card styling

- Background: `rgba(255,255,255,0.03)`
- Border: `1px solid rgba(255,255,255,0.06)`
- Border-radius: `10px`
- Padding: `14px 16px`
- Gap between cards: `8px`
- Matches existing filter toolbar aesthetic

### Statistics page

Top Products and Top Members tables stack vertically instead of side-by-side. They keep a simplified 3-column table (#, Name, Revenue) since small tables work fine on mobile.

### No generic component

Each page renders its own cards inline. No `<DataCard>` abstraction — YAGNI.

---

## 3. Mobile Toolbar: Search + Sort + Collapsible Filters

On mobile, the filter toolbar is replaced with a compact bar. Filters are collapsed by default.

### Collapsed state (default)

```
+----------------------------------+
| [Q Search...]  [Sort v] [Flt 2] |
+----------------------------------+
```

- Search: `flex: 1`, always visible
- Sort button: shows current sort field, tap opens dropdown with page-specific sort options
- Filter button: "Flt" + badge count of active (non-default) filters. Hidden when page has no filters.

### Expanded state (tap Filter)

```
+----------------------------------+
| [Q Search...]  [Sort v] [Flt 2] |
+----------------------------------+
| Status  [Alle] [Aktiv] [Inaktiv]|
| Karte   [Alle] [Mit] [Ohne]    |
| SEPA    [Alle] [Gültig] [Fehlt] |
+----------------------------------+
```

Each filter group on its own row. Label left, pill buttons right. Same styling as current.

### Sort dropdown replaces column header clicks

Since cards have no column headers, sorting is controlled via the Sort button:

| Page | Sort Options |
|------|-------------|
| Members | Name, Card-UID, Member since |
| Products | Name, Price, Category |
| Journal | Date, Member, Amount |
| Settlements | Date |
| Categories | Name |
| Audit Log | Timestamp |

### Per-page filter inventory

| Page | Search | Sort | Filters |
|------|--------|------|---------|
| Members | Yes | Yes | Status, Card, SEPA |
| Products | Yes | Yes | Active/Inactive, Category dropdown |
| Journal | Yes | Yes | Time range, Settlement status |
| Settlements | No | Yes | Time range, Cancel status |
| Categories | No | Yes | Active/Inactive |
| Audit Log | Yes | Yes | Admin, Action, Entity type, Date range |
| Settings | No | No | — |
| Statistics | No | No | — |

### Badge count logic

Count filters not set to default ("all"). Members has 3 filterable dimensions, so badge can show 0–3.

### Desktop (>=768px)

No change. Existing inline filter toolbar stays as-is.

---

## 4. Single-Column Forms on Mobile

All multi-column form grids collapse to `gridTemplateColumns: '1fr'` on mobile.

Affected forms:
- Member create/edit (currently 2-column)
- Product create/edit (if multi-column)
- Any other forms using grid layouts

No other form changes — modals already use `width: 90%` on mobile.

---

## Implementation Scope

| Component | Type | Description |
|-----------|------|-------------|
| `BottomTabBar` | New | Bottom tab bar + "More" popup |
| `MainLayout` | Modify | Hide top nav on mobile, render BottomTabBar, simplify header |
| `MembersPage` | Modify | Add card view, sort dropdown, collapsible filters |
| `ProductsPage` | Modify | Add card view, sort dropdown, collapsible filters |
| `JournalPage` | Modify | Add card view, sort dropdown, collapsible filters |
| `SettlementsPage` | Modify | Add card view, sort dropdown, collapsible filters |
| `CategoriesPage` | Modify | Add card view, sort dropdown, collapsible filters |
| `AuditLogPage` | Modify | Add card view, sort dropdown, collapsible filters |
| `SettingsPage` | Modify | Add card view for admin users table |
| `StatisticsPage` | Modify | Stack top-10 tables vertically |

**No new libraries.** No design-system changes. No backend changes.

**Desktop is untouched.** All changes gated behind `isMobile` from `useBreakpoint()`.
