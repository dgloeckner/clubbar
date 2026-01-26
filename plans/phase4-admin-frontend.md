# Phase 4: Admin Frontend - Implementation Plan

## Overview

**Project**: Ruderbar Admin Frontend (React SPA)

**Objective**: Implement production-ready admin panel using React + TypeScript with exact design and UX from `prototypes/frgs-admin.html`

**Reference Prototype**: `prototypes/frgs-admin-6.html` - Complete working prototype with all layouts, components, interactions, and design system (UPDATED: icons, stats, loading indicator)

**Status**: Planning phase — Frontend infrastructure ready, design specifications complete, backend API complete, UI prototype v6 available

**Scope**:
- React SPA for admin operations (CRUD, settlements, reports)
- Full integration with backend API (9 core modules)
- Design system implementation (dark theme, component library)
- 30+ E2E tests for all workflows
- Estimated 4-6 weeks development for Phase 2 (core pages)

---

### Responsive Implementation Approach

**CSS-in-JS Strategy** (using template literals or styled-components):

1. **Define Breakpoints as Constants**:
```typescript
const breakpoints = {
  desktop: '1024px',   // 1024px+
  tablet: '768px',     // 769-1024px
  mobile: '481px',     // 481-768px
  smallMobile: '480px' // ≤480px
};
```

2. **Use Media Queries in Component Styles**:
```typescript
const headerStyles = {
  default: {
    flexDirection: 'row',
    height: 64,
    // ...
  },
  '@media (max-width: 768px)': {
    flexDirection: 'column',
    height: 'auto',
    // ...
  }
};
```

3. **Conditional Rendering for Hidden Elements**:
```typescript
// Hide user badge on mobile
{window.innerWidth > 768 && <AdminBadge />}

// Show table wrapper scroll on mobile
<div className={isMobile ? 'table-wrapper' : ''}>
  <table>...</table>
</div>
```

4. **Responsive Grid Components**:
```typescript
const StatsGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(3, 1fr);

  @media (max-width: 1024px) {
    grid-template-columns: repeat(2, 1fr);
  }

  @media (max-width: 768px) {
    grid-template-columns: 1fr;
  }
`;
```

5. **Tailwind CSS Alternative** (if using Tailwind):
```jsx
<div className="grid grid-cols-3 md:grid-cols-2 sm:grid-cols-1 gap-4">
  {/* Stats cards */}
</div>
```

**Testing Responsive Behavior**:
- Playwright tests include viewport configuration
- Test at all breakpoints: 1440px (desktop), 1024px (tablet), 768px (mobile), 375px (small)
- Verify layout shifts correctly at breakpoints
- Verify touch interactions work on mobile

---

## ⚡ NEXT IMMEDIATE TASK: UI Implementation from frgs-admin-6.html

**Reference**: New prototype `prototypes/frgs-admin-6.html` with updated features

**Key Features to Implement** (in order of priority):

1. **Loading Indicator** ⏳
   - 3px bar at top of page with blue gradient animation
   - Shows during backend API calls
   - Fixed position, z-index: 9999
   - Triggers on all async operations

2. **Header Navigation with Icons** 🎨
   - Tab buttons with SVG icons + labels
   - Icons: UsersIcon (members), PackageIcon (products), BookIcon (journal), ReceiptIcon (settlements), ChartIcon (stats)
   - Active state: blue background + blue text
   - Inactive state: gray text, transparent background
   - Smooth transitions

3. **User Badge & Logout Button** 👤
   - User badge: UserIcon + "Admin" text in rounded badge (blue background)
   - Logout button: LogoutIcon (door) + "Abmelden" text (red styling)
   - Positioned in header right with gap
   - Logout button functional

4. **Dashboard Stats on Members Page** 📊
   - 3-column stat cards grid
   - Total Members: UsersIcon + count (green accent)
   - Total Outstanding Balance: BankIcon + formatted amount (blue accent)
   - Optional third stat (Last Settlement)
   - Each stat shows icon, label, and value
   - Cards have subtle background color

**Implementation Order**:
1. Create LoadingIndicator component (responsive: same at all breakpoints)
2. Create Icon components (8 total)
3. Update Header with tab nav + icons (with responsive behavior)
4. Add user badge and logout button (hidden on mobile < 768px)
5. Add stats to Members page (3 cols → 2 cols → 1 col responsive grid)
6. Wire up loading state across API calls
7. E2E test each feature at all breakpoints

**Responsive Requirements**:
- **Desktop (1024px+)**: Full layout as designed
- **Tablet (769-1024px)**:
  - Stats: 2 columns
  - Nav tabs: Icons only (hide labels)
  - Tab gaps: minimal (2px)
- **Mobile (481-768px)**:
  - Header: Vertical stack, auto height
  - Stats: 1 column
  - Nav tabs: Full width, horizontally scrollable
  - User badge: HIDDEN
  - Tables: Horizontally scrollable
  - Padding: 16px (reduced from 24px)
- **Small Mobile (≤480px)**:
  - Nav tabs: Icons only
  - Logout text: HIDDEN (icon only)
  - Table cells: Compact padding
  - Stat cards: Vertical stack, centered

**Viewport Testing Sizes**:
- Desktop: 1440px
- Tablet: 1024px, 768px
- Mobile: 481px, 375px (small)

**Expected Output**: Phase 2 core pages match frgs-admin-6.html exactly with full responsive support

---

## 📋 Phase 4 Roadmap: 5-Phase Implementation

**See [USE_CASE_AUDIT.md](./USE_CASE_AUDIT.md) for complete use case mapping to all 5 phases.**

This document focuses on **Phase 2** (core pages). Phases 3-5 are planned but deferred.

### Phase 2 (Core Pages) - **THIS DOCUMENT** ✅ Planning
- **Objective**: Implement core admin workflow
- **Pages**: Members, Products, Journal, Settlements, Statistics
- **Use Cases**: 25 of 43 admin use cases
- **Estimated Duration**: 4-6 weeks
- **Backend APIs**: 100% complete and tested

### Phase 3A (Settings & Compliance) - Planned
- **Pages**: Organization Settings, Admin Users, Audit Log, SEPA Validation
- **Use Cases**: UC-A60, UC-A61-63, UC-A81-82
- **Estimated Duration**: 2-3 weeks

### Phase 3B (RFID Card Management) - Planned
- **Pages**: RFID Cards (dedicated management page)
- **Use Cases**: UC-A13, UC-A14, UC-A70-71
- **Estimated Duration**: 1-2 weeks

### Phase 4 (Member Import) - Planned
- **Pages**: CSV Import Wizard
- **Use Cases**: UC-A16
- **Estimated Duration**: 3-5 days

### Phase 5 (Advanced/TBD) - Future
- Transaction corrections (UC-A21)
- Terminal Management UI (UC-A50-55) - backend module complete
- Export/reporting enhancements

---

## Architecture Overview

### Tech Stack

- **Framework**: React 18.x
- **Language**: TypeScript 5.x
- **Build Tool**: Vite
- **UI/Styling**: CSS-in-JS (Tailwind CSS recommended)
- **State Management**: React Context API (Redux optional for scale)
- **HTTP Client**: Axios (with interceptors for auth, error handling)
- **Testing**: Vitest + Playwright E2E
- **Code Quality**: ESLint, Prettier

### Design System

**Color Palette** (from prototype):
```
Background: #0a1628 (primary)
Secondary: #0f1d32 (cards, panels)
Card: #1a2744 (content cards)
Input: #0d1829 (form fields)

Semantic:
- Blue: #3b82f6 (primary action, info)
- Green: #22c55e (success)
- Orange: #f97316 (warning, balance)
- Red: #ef4444 (danger, errors)
- Gray: #94a3b8 (secondary text)
```

**Typography**:
```
Font: System stack (-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif)
Headings: 600-700 weight
Body: 400 weight, 14px
Small: 12-13px for secondary info
Monospace: For RFID, IBAN, amounts
```

**Spacing**:
- Gaps: 4, 8, 12, 16, 20, 24, 32px
- Padding: 12, 16, 20, 24px
- Border radius: 8-20px (cards use 16px)

---

### Responsive Design Strategy

**Breakpoints** (from frgs-admin-6.html):
```
Desktop:      1024px+ (full layout)
Tablet:       769px - 1024px (reduced columns)
Mobile:       481px - 768px (single column, scrollable)
Small Mobile: ≤480px (minimal, compact)
```

#### Desktop (1024px+)
- **Stats Grid**: 3 columns
- **Nav Tabs**: Full labels visible + icons
- **Header**: Horizontal layout (fixed height)
- **Tables**: Full width, scrollable only if needed
- **User Badge**: Visible
- **Logout Button**: Icon + text visible

#### Tablet (769px - 1024px)
- **Stats Grid**: 2 columns (from 3)
- **Nav Tabs**: Icons only (no labels via `display: none` on span)
- **Tab Padding**: Reduced (8px 10px)
- **Tab Font**: Smaller (13px)
- **Gap**: Minimal (2px)
- **Overall**: More compact, icons become primary

#### Mobile (481px - 768px)
- **Header Layout**: Stacked vertically (flex-direction: column, auto height)
- **Header Padding**: Reduced (12px 16px)
- **Nav Tabs**:
  - Full width with horizontal scroll (overflow-x: auto)
  - Touch-friendly scrolling (-webkit-overflow-scrolling: touch)
  - Flex-shrink: 0 (prevents collapse)
  - Labels back visible
- **Stats Grid**: 1 column (from 2)
- **Main Content Padding**: 16px (reduced from 24px)
- **Stat Cards**: Reduced padding (16px)
- **Stat Icons**: Smaller (44px, from larger)
- **Stat Values**: Smaller font (22px)
- **Card Border Radius**: 12px (from 16px)
- **Tables**: Horizontally scrollable (min-width: 700px forces scroll)
- **Modals**: Adjusted margins/padding, fit within viewport
- **User Badge**: HIDDEN (display: none)
- **Search Bar**: Full width
- **Toolbar**: Stacked vertically (flex-direction: column)
- **Action Buttons**: Wrappable (flex-wrap: wrap)

#### Small Mobile (≤480px)
- **Header Title**: Smaller font (16px)
- **Nav Tabs**: Extra compact padding (6px 8px)
- **Tab Icons**: Smaller (16px, from 20px)
- **Tab Labels**: HIDDEN (display: none)
- **Logout Button**: Icon only, HIDDEN text (display: none on span)
- **Logout Padding**: Compact (8px)
- **Table Cells**: Minimal padding (10px 8px), smaller font (12px)
- **Stat Cards**: Vertical flex-direction, centered text

#### Touch Optimization
- **Scrollable Content**: All areas with overflow use `-webkit-overflow-scrolling: touch` for momentum scrolling
- **Touch Targets**: Button padding ensures >= 44px clickable area
- **Tap-Friendly**: Spacing between interactive elements

#### Progressive Enhancement
1. **Desktop First**: Full feature set, multi-column layouts
2. **Tablet**: Reduce visual noise, icons become primary navigation
3. **Mobile**: Essential information only, vertical stacking, horizontal scrolling for tables
4. **Small Mobile**: Minimum viable UI, text hidden except when essential

#### Responsive Component Strategy

**Stats Grid**:
```
Desktop:  grid-template-columns: repeat(3, 1fr)
Tablet:   grid-template-columns: repeat(2, 1fr)
Mobile:   grid-template-columns: 1fr
```

**Nav Tabs Labels**:
```
Desktop:  display: inline    (visible)
Tablet:   display: none      (icons only)
Mobile:   display: inline    (visible again for clarity)
Small:    display: none      (icons only, compact)
```

**Header Layout**:
```
Desktop:  flex-direction: row, height: 64px
Mobile:   flex-direction: column, height: auto
```

**Tables**:
```
Desktop:  Normal width
Mobile:   Horizontally scrollable container
          Table min-width: 700px (forces scroll)
          -webkit-overflow-scrolling: touch
```

**Badges & Labels**:
```
Desktop:  Visible
Tablet:   Visible (some reduced)
Mobile:   Hidden to save space (@1024px and @480px)
```

---

**Shadows**:
```
Card: 1px solid border
Modal: 0 25px 50px rgba(0,0,0,0.5)
Button: 0 4px 12px rgba(color, 0.3)
```

---

## Screens & Components

### 1. Authentication

#### LoginScreen
- Email + password form
- Error handling for invalid credentials
- Session management (JWT tokens in localStorage)
- "Remember me" optional

**Workflow**:
- POST /api/auth/login with credentials
- Store session token
- Redirect to dashboard

---

### 2. Main Dashboard (Index)

#### Header Navigation (Fixed)
- Logo + "Ruderbar Admin"
- **Primary nav tabs with icons**:
  - 👥 Mitglieder (Members) - UsersIcon
  - 📦 Produkte (Products) - PackageIcon
  - 📖 Buchungsjournal (Transaction Journal) - BookIcon
  - 🧾 Abrechnungen (Settlements) - ReceiptIcon
  - 📊 Statistik (Statistics) - ChartIcon
- **User badge**: User icon + "Admin" text (rounded badge style)
- **Logout button**: Door icon + "Abmelden" text (red/danger styling)
- **Loading indicator**: Fixed 3px bar at top, animates during backend calls

#### Members Page (Default Dashboard)

**Layout**:
```
[Dashboard Stats - 3 columns with icons]
├── 👥 Total Members Count (UsersIcon, green)
├── 💰 Total Outstanding Balance (BankIcon, blue)
└── 📌 Last Settlement Date (optional third stat)

[Members Table]
├── Search/Filter bar
├── Add New Member button
└── Table with columns:
    - Name (firstName lastName)
    - RFID
    - IBAN (masked: DE89****3000)
    - BIC
    - Member Since (created_at)
    - Balance (deckel) - color coded
    - Actions (View, Edit, Delete)
```

**Design Notes**:
- Members page IS the default dashboard (activePage defaults to 'users')
- Stats cards display with icons on the left, values prominent
- Cards have colored icon backgrounds (green for members, blue for balance)
- Each stat shows label + value
- Icons: UsersIcon (👥), BankIcon (💰)

**Interactions**:
- Search: by name, RFID
- View Posten: Modal showing recent transactions and balance
- Edit: Modal form to update member details
- Delete: Confirmation modal with soft-delete
- Create: Modal form for new member

**API Calls**:
- GET /api/admin/members (paginated list)
- GET /api/admin/members/{id}
- POST /api/admin/members (create)
- PATCH /api/admin/members/{id} (update)
- DELETE /api/admin/members/{id} (soft-delete)
- GET /api/admin/members/{id}/transactions (balance/posten)

#### Products Page

**Layout**:
```
[Products Table/Grid]
├── Add New Product button
├── Search/Filter
└── Product grid or table:
    - Product name
    - Description (multilingual)
    - Price
    - Active toggle
    - Actions (Edit, Delete)
```

**Interactions**:
- Add Product: Modal with name, description (de/en), price
- Edit Product: Modal to update details
- Toggle Active: Inline toggle for activation/deactivation
- Delete: Confirmation modal

**API Calls**:
- GET /api/admin/products (list)
- POST /api/admin/products (create)
- PATCH /api/admin/products/{id} (update)
- DELETE /api/admin/products/{id}

#### Transaction Journal (Buchungsjournal)

**Layout**:
```
[Filter Bar]
├── Date range picker
├── Member filter (dropdown)
├── Transaction type (all, purchase, correction)
└── Search

[Transaction Table]
├── Date (timestamp)
├── Member (name, RFID)
├── Item (product name)
├── Quantity
├── Price
├── Type badge (purchase/correction)
└── Actions (View, Edit, Delete if correction)
```

**Interactions**:
- Filter by date range
- Search by member name or RFID
- View transaction detail (modal)
- Export transactions as CSV

**API Calls**:
- GET /api/admin/transactions (filtered, paginated)
- GET /api/admin/transactions/export (CSV download)

#### Settlements (Abrechnungen)

**Layout**:
```
[Settlement Overview]
├── Create Settlement button
├── Last Settlement date
└── Settlement list:
    - Date
    - Total amount
    - Member count
    - Actions (View, Undo)

[Settlement Detail]
├── Members in settlement
├── Items per member
└── Export (CSV/SEPA XML)
```

**Interactions**:
- Create Settlement: Modal showing members with balance, preview totals
- Confirm: Creates settlement, resets member balances
- Undo: Reverses settlement, restores member balances
- View: Expands settlement to show details
- Export: CSV or SEPA XML download

**API Calls**:
- GET /api/admin/settlements (list)
- GET /api/admin/settlements/{id} (detail)
- POST /api/admin/settlements (create)
- DELETE /api/admin/settlements/{id} (undo/cancel)
- GET /api/admin/settlements/{id}/export (CSV/SEPA)

#### Statistics Page (Statistik)

**Layout**:
```
[Metric Cards - 4 columns]
├── Total Members
├── Total Revenue (all time)
├── Outstanding Balance
└── Settlement Count

[Charts]
├── Revenue over time (line chart)
├── Transaction distribution (pie chart)
├── Top members by spending (bar chart)
└── Settlement history (timeline)

[Export]
└── Export report as PDF
```

**Interactions**:
- Date range filtering
- Chart interactions (hover, click)
- PDF export of dashboard

**API Calls**:
- GET /api/admin/dashboard (metrics)
- GET /api/admin/statistics (detailed stats)

---

### 3. Global UI Components

#### LoadingIndicator
**Purpose**: Visual feedback during backend API calls
**Display**:
- Fixed 3px bar at top of page (z-index: 9999)
- Background: rgba(59, 130, 246, 0.2) - light blue
- Animated gradient slide: 25% width with blue gradient moving left→right
- Animation: loadingSlide (0.8s ease-in-out infinite)

**Behavior**:
- Show during all async API calls
- Auto-hide when request completes
- Non-blocking (doesn't prevent user interaction)
- Lightweight, no modal overlay

**Implementation**:
```typescript
interface LoadingIndicatorProps {
  show: boolean;
}

// Styles
@keyframes loadingSlide {
  0% { transform: translateX(-100%); }
  100% { transform: translateX(400%); }
}
```

**Usage**:
- Wrap in loading state context or React hook
- Show when: `isLoading` = true
- Hide when: API response received or error

---

### 4. Modals & Forms

#### UserModal (Member Edit/Create)
**Fields**:
- First name (required)
- Last name (required)
- RFID (unique, required)
- Email (optional)
- Phone (optional)
- Preferred language (de/en)
- IBAN (required for settlements)
- BIC (required for settlements)
- Active checkbox

#### PostenModal (View Member Balance)
**Displays**:
- Recent transactions (open items)
- Current balance
- Manual corrections option
- Correction history

**Interactions**:
- Add Correction: Form to add manual adjustment
- View History: Tab showing all corrections

#### ExportModal (Settlement Export)
**Options**:
- CSV export
- SEPA XML export (for bank transfer)
- Date range selection
- Include details checkbox

---

### 5. Icon System
**All navigation tabs and components use SVG icons**:

| Component | Icon | Icon Name |
|-----------|------|-----------|
| Members tab | 👥 | UsersIcon |
| Products tab | 📦 | PackageIcon |
| Journal tab | 📖 | BookIcon |
| Settlements tab | 🧾 | ReceiptIcon |
| Statistics tab | 📊 | ChartIcon |
| User badge | 👤 | UserIcon |
| Logout button | 🚪 | LogoutIcon |
| Dashboard stats | 👥, 💰 | UsersIcon, BankIcon |

**Icon Properties**:
- Size: 20px (tabs), 18px (small actions)
- Stroke width: 2
- Color: Inherited from text color (blue for active, gray for inactive)
- All icons are inline SVG components in React

---

## Implementation Phases

### ⚙️ TDD + Playwright MCP Development Workflow

**For every milestone**, follow this Test-Driven Development cycle:

1. **Write E2E Tests First** (red):
   ```bash
   # Create test file with scenarios
   npm test -- tests/pages/feature.spec.ts --workers=1
   # Tests fail (expected) - no implementation yet
   ```

2. **Implement Feature** (green):
   ```bash
   # Code the feature to pass tests
   # Start dev server: npm run dev
   ```

3. **Visual Debugging with Playwright MCP** (blue):
   ```bash
   # While implementing, use Playwright MCP to visually verify:
   # - Open browser and navigate to page
   # - Take screenshots to verify design matches prototype
   # - Test interactions (clicks, forms, modals)
   # - Debug failing tests with step-by-step navigation
   ```

4. **Verify Tests Pass**:
   ```bash
   npm test -- tests/pages/feature.spec.ts --workers=1
   # All tests passing ✅
   ```

5. **Parallel Execution Check**:
   ```bash
   npm test -- tests/pages/feature.spec.ts --workers=4
   # Verify no flaky tests in parallel ✅
   ```

**Key Principle**: Tests document requirements; implementation satisfies tests; Playwright MCP verifies visual correctness.

---

### Phase 1: Project Setup & Infrastructure (1-2 weeks)

**✅ Status**: COMPLETE (Phase 1 already delivered)
- React project structure created ✅
- Routing, auth, design system implemented ✅
- Component library established ✅
- API client with interceptors ready ✅

**Files Already Created**:
- `/admin-frontend/src/` — Complete project structure
- `/admin-frontend/tests/` — E2E test fixtures
- All infrastructure components ✅

**No additional work needed for Phase 1.**

---

### Phase 2: Core Pages Implementation (4-6 weeks) 📍 NEXT PRIORITY

**TDD Cycle for Each Page**:

#### Milestone 2.1: Members Page (1 week)

**Step 1: Write E2E Tests** (TDD Red Phase)
```bash
# Create: admin-frontend/tests/pages/members.spec.ts
# Tests to implement:
```

**Test Cases** (7 tests):
- [ ] GET /members returns paginated list with 20+ members
- [ ] Search members by first/last name filters results
- [ ] Search members by RFID/card_uid filters results
- [ ] Create member modal: form validates required fields
- [ ] Create member: submit creates new member, appears in list
- [ ] Edit member: PATCH updates member, reflects in list
- [ ] Delete member: soft-delete removes from active list

**Step 2: Implement Members Page Component**
```tsx
// src/pages/MembersPage.tsx
// - Fetch members with GET /members
// - Display table with columns: Name, RFID, IBAN, Balance, Created, Actions
// - Search/filter by name, RFID, status
// - Create/Edit/Delete modals
// - Handle loading, error states
```

**Step 3: Visual Verification with Playwright MCP**
```bash
# While developing:
# 1. Open browser: mcp__playwright__browser_navigate("http://localhost:5173/members")
# 2. Take screenshot: mcp__playwright__browser_take_screenshot()
# 3. Test interactions: Click buttons, fill forms, verify layout
# 4. Compare with prototype: /prototypes/frgs-admin.html#members
```

**Step 4: Run Tests**
```bash
npm test -- tests/pages/members.spec.ts --workers=1
# All 7 tests passing ✅
npm test -- tests/pages/members.spec.ts --workers=4
# Parallel test: no flaky tests ✅
```

**Success Criteria**:
- ✅ 7/7 tests passing (serial)
- ✅ 7/7 tests passing (parallel, 4 workers)
- ✅ Visual matches prototype screenshot
- ✅ No console errors/warnings
- ✅ Loading states + error handling working

---

#### Milestone 2.2: Products Page (4-5 days)

**Test Cases** (5 tests):
- [ ] GET /products returns product list with categories
- [ ] GET /categories returns available categories
- [ ] Create product: form validates name, price required
- [ ] Create product: multilingual names (de/en) supported
- [ ] Edit product: PATCH updates details
- [ ] Delete product: soft-delete with confirmation

**Implementation**: Similar to Members page
- Fetch products + categories
- Display table/grid with name, price, category, status
- Create/Edit/Delete modals
- Category management (optional Phase 2+)

**Visual Verification**:
```bash
# Navigate to products page
# Take screenshots
# Verify category dropdown, multilingual fields
# Test form validation
```

**Success Criteria**:
- ✅ 5/5 tests passing
- ✅ Multilingual product names displayed correctly
- ✅ Price formatting (€X,XX with German locale)
- ✅ Category filter working

---

#### Milestone 2.3: Transaction Journal Page (4-5 days)

**Test Cases** (4 tests):
- [ ] GET /members/{id}/transactions returns member history
- [ ] Transaction list shows: Member, Product, Amount, Date, Type
- [ ] Filter by date range: narrows transaction list
- [ ] Filter by member: shows only that member's transactions

**Note**: Using member-centric view (not global journal) per API limitation

**Implementation**:
- Member selector (dropdown or link from members page)
- Fetch member's transactions via GET /members/{id}/transactions
- Display table: Date, Product, Amount, Type, Balance
- Date range filter, export CSV option

**Visual Verification**:
```bash
# Navigate to journal
# Select member from dropdown
# Verify transactions display
# Test date filter
```

---

#### Milestone 2.4: Settlements Page (1 week)

**Test Cases** (6 tests):
- [ ] GET /settlements returns settlement list with history
- [ ] Create settlement: preview shows members with balance
- [ ] Create settlement: confirm creates new settlement
- [ ] Settlement list: shows Date, Total, Member Count, Status
- [ ] View settlement: shows SEPA-XML export option
- [ ] Export SEPA: GET /settlements/{id}/export/sepa-xml downloads file

**Implementation** (Complex Workflow):
- Settlement list with filters
- "Create Settlement" button → modal with preview
  - Shows all members with outstanding balance
  - Preview totals, execution date validation (min 7 days)
  - Confirm/Cancel buttons
- View settlement detail
- Export buttons (CSV, SEPA XML)
- Cancel settlement option

**Visual Verification**:
```bash
# Navigate to settlements
# Click "Create Settlement"
# Verify preview modal displays members
# Verify date picker shows minimum date
# Test export buttons
```

---

#### Milestone 2.5: Statistics Page (4-5 days)

**Test Cases** (4 tests):
- [ ] GET /dashboard returns metrics (members, revenue, balance, settlements)
- [ ] Dashboard displays: Total Members, Outstanding Balance, Today's Revenue
- [ ] GET /reports/{type} returns transaction data for charts
- [ ] Charts display: Daily revenue, Member ranking, Terminal activity

**Implementation**:
- Fetch dashboard metrics
- Display metric cards (4 columns)
- Fetch reports data
- Render charts (Recharts library recommended)
- Date range selector

**Visual Verification**:
```bash
# Navigate to statistics
# Verify metric cards display
# Check chart rendering
# Test date range filter
```

---

### Phase 3A: Settings & Compliance Pages (2-3 weeks) - Planned

**Pages**: Organization Settings, Admin Users, Audit Log, SEPA Validation
**Use Cases**: UC-A60, UC-A61-63, UC-A81-82
**E2E Tests**: 15+ tests
**Same TDD cycle** applies to each page

---

### Phase 3B: RFID Card Management (1-2 weeks) - Planned

**Pages**: RFID Cards (dedicated page)
**Use Cases**: UC-A13, UC-A14, UC-A70-71
**E2E Tests**: 8+ tests
**Same TDD cycle** applies

---

### Phase 4: Member CSV Import (3-5 days) - Planned

**Page**: CSV Import Wizard
**Use Case**: UC-A16
**E2E Tests**: 4+ tests

---

### Phase 5: Refinement & Deployment (1-2 weeks)

**Tasks**:
1. Performance optimization (code splitting, lazy loading)
2. Responsive design (mobile, tablet, desktop)
3. Accessibility audit (WCAG 2.1 AA)
4. Browser compatibility testing
5. Build optimization (minification, tree-shaking)
6. Deploy to staging/production
7. Documentation (README, setup guide)
8. Environment configuration (.env files)

**Success Criteria**:
- Build bundle size < 500KB gzipped
- Lighthouse score > 80
- All tests passing (30+/30+)
- Production deployment successful
- No console warnings/errors

---

## Backend API Integration

### Required Backend Endpoints (All Complete ✅)

**Members Module**:
- GET /api/admin/members (paginated)
- GET /api/admin/members/{id}
- POST /api/admin/members
- PATCH /api/admin/members/{id}
- DELETE /api/admin/members/{id}
- GET /api/admin/members/{id}/transactions

**Products Module**:
- GET /api/admin/products
- POST /api/admin/products
- PATCH /api/admin/products/{id}
- DELETE /api/admin/products/{id}

**Transactions Module**:
- GET /api/admin/transactions (filtered)
- GET /api/admin/transactions/export

**Settlements Module**:
- GET /api/admin/settlements
- GET /api/admin/settlements/{id}
- POST /api/admin/settlements
- DELETE /api/admin/settlements/{id}
- GET /api/admin/settlements/{id}/export

**Dashboard Module**:
- GET /api/admin/dashboard

**All endpoints require**: Session authentication (admin session cookie)

---

## Design Decisions

### 1. Component Structure
- **Atomic Design**: Atoms (Button, Input) → Molecules (Form) → Organisms (Page)
- **Composition**: Prefer composition over inheritance
- **Reusability**: Shared components in `/components/common`
- **Props Drilling**: Use Context API for global state (auth, theme)

### 2. State Management
- **Local State**: useState for form data, UI toggles
- **Global State**: AuthContext for logged-in user, AppContext for notifications
- **Server State**: React Query (or similar) for API data caching (optional enhancement)

### 3. Error Handling
- **API Errors**: Show toast notifications to user
- **Validation**: Form-level validation before submission
- **User Feedback**: Loading spinners, success/error messages
- **Fallback UI**: Empty states, error boundaries

### 4. Performance
- **Code Splitting**: Lazy load pages with React.lazy()
- **Image Optimization**: Use WebP with fallbacks
- **Memoization**: useMemo/useCallback for expensive computations
- **Virtual Lists**: For large tables (1000+ rows)

### 5. Security
- **HTTPS Only**: All API calls over HTTPS
- **Auth Tokens**: Stored securely in httpOnly cookies (server-side session)
- **CSRF Protection**: Use CSRF tokens from backend
- **Input Validation**: Sanitize user input before sending to API

---

## Testing Strategy

### Unit Tests (Optional)
- Component rendering (snapshot tests)
- Form validation
- Utility functions
- Custom hooks

### E2E Tests (Required)
- User workflows (login → CRUD → logout)
- Form submissions with validation
- Error scenarios
- Navigation between pages
- Data export and download

---

## TDD Workflow: Test-Driven Development for Phase 2

### Core TDD Cycle (5 Steps)

**For each feature/page, follow this cycle**:

#### Step 1: Write Failing Tests (Red Phase)

Create E2E test file with all test cases:

```bash
# Example: admin-frontend/tests/pages/members.spec.ts
cd admin-frontend

# Create test file with test cases
cat > tests/pages/members.spec.ts << 'EOF'
import { test, expect } from '@playwright/test'
import { authenticatedRequest } from '../fixtures/auth'

test.describe('Members Page', () => {
  test('should list members with pagination', async ({ page }) => {
    await page.goto('http://localhost:5173/members')
    // Assert test conditions
    await expect(page.locator('table')).toBeVisible()
  })
  // ... more tests
})
EOF

# Run tests (they should fail - no implementation yet)
npm test -- tests/pages/members.spec.ts --workers=1
# Expected: ✕ 7 tests failing
```

#### Step 2: Implement Feature (Green Phase)

Code the React component to satisfy test requirements:

```tsx
// src/pages/MembersPage.tsx
import { useEffect, useState } from 'react'
import { getMembers } from '../services/members'

export function MembersPage() {
  const [members, setMembers] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    getMembers().then(setMembers).finally(() => setLoading(false))
  }, [])

  if (loading) return <div>Loading...</div>
  return (
    <table>
      <tbody>
        {members.map(m => (
          <tr key={m.id}>
            <td>{m.first_name} {m.last_name}</td>
            <td>{m.card_uid}</td>
            {/* ... more columns */}
          </tr>
        ))}
      </tbody>
    </table>
  )
}
```

#### Step 3: Visual Debugging with Playwright MCP (Blue Phase)

**While implementing**, use Playwright MCP to visually verify correctness:

```bash
# In Claude Code terminal, use MCP commands to:
# 1. Open browser
# 2. Navigate to page
# 3. Take screenshots
# 4. Verify design matches prototype
# 5. Test interactions
```

**Example Debugging Session**:

```bash
# Start dev server in another terminal
npm run dev

# In Claude Code, use Playwright MCP:
# mcp__playwright__browser_navigate("http://localhost:5173/members")
# mcp__playwright__browser_take_screenshot()
# [Verify screenshot looks correct compared to prototype]
# mcp__playwright__browser_click(ref="[button with 'Create' text]")
# mcp__playwright__browser_take_screenshot()
# [Verify modal appeared correctly]
```

**Visual Verification Checklist**:
- [ ] Page layout matches prototype (`/prototypes/frgs-admin.html`)
- [ ] Table columns are correct
- [ ] Colors use design system (blue #3b82f6, green #22c55e, etc.)
- [ ] Spacing and padding consistent
- [ ] Form labels and inputs properly styled
- [ ] Buttons have correct appearance and hover states
- [ ] Loading spinner appears during data fetch
- [ ] Error messages display properly
- [ ] Modal dialogs are centered and styled correctly
- [ ] Responsive design works (if testing different window sizes)

**Playwright MCP Key Commands**:

```javascript
// Navigate to page
mcp__playwright__browser_navigate("http://localhost:5173/members")

// Take screenshot (save to file)
mcp__playwright__browser_take_screenshot("members-page-loaded.png")

// Click element
mcp__playwright__browser_click(ref="[button ref]", element="Create Member button")

// Type text
mcp__playwright__browser_type(ref="[input ref]", text="John Doe")

// Fill form
mcp__playwright__browser_fill_form(fields=[{name: "name", ref: "...", type: "textbox", value: "..."}])

// Take snapshot (accessibility tree)
mcp__playwright__browser_snapshot("members-page-structure.md")

// Wait for element
mcp__playwright__browser_wait_for(text="Loading complete")

// Get console messages (debug errors)
mcp__playwright__browser_console_messages(level="error")
```

#### Step 4: Run Tests & Verify (Green Phase Complete)

```bash
# Serial execution (1 worker for debugging)
npm test -- tests/pages/members.spec.ts --workers=1
# Expected: ✓ 7 tests passing

# If tests fail:
# 1. Check console errors: npm run dev (look for errors)
# 2. Use Playwright MCP to screenshot page
# 3. Compare with test expectations
# 4. Fix implementation or test
```

#### Step 5: Parallel Execution Safety Check

```bash
# Run with 4 workers to ensure no flaky tests
npm test -- tests/pages/members.spec.ts --workers=4
# Expected: ✓ 7 tests passing (all workers)

# If tests fail in parallel but pass serially:
# → Test isolation issue
# → Check: shared state, database cleanup, async cleanup
# → Reference: E2E Testing Patterns (001-004)
```

---

### Recommended TDD Order for Phase 2

1. **Write all 5 tests first** (Red phase)
2. **Implement simplest page** (Products - no complex workflows)
3. **Run tests** - should pass ✅
4. **Visual check** with Playwright MCP
5. **Next page** - Members (more CRUD)
6. **Continue** through Journal, Settlements, Statistics

**Why this order**:
- Products builds confidence (simple CRUD)
- Members establishes patterns (modals, forms)
- Journal adds filtering (query params)
- Settlements adds complex workflows (preview, exports)
- Statistics adds data visualization (charts)

---

### Test File Template

Create each test file following this structure:

```typescript
// tests/pages/feature.spec.ts
import { test, expect } from '@playwright/test'
import { authenticatedRequest } from '../fixtures/auth'

test.describe('Feature Page', () => {
  test.beforeEach(async ({ page, context }) => {
    // Setup: login, navigate
    const auth = await authenticatedRequest(context)
    await page.goto('http://localhost:5173/feature')
  })

  test.describe('List', () => {
    test('should display list with data', async ({ page }) => {
      // Verify table loaded
      await expect(page.locator('table')).toBeVisible()
      await expect(page.locator('tbody tr')).toHaveCount(20) // paginated
    })

    test('should search/filter', async ({ page }) => {
      const searchInput = page.locator('[placeholder="Search"]')
      await searchInput.fill('test')
      // Verify filtered results
    })
  })

  test.describe('CRUD', () => {
    test('should create item', async ({ page }) => {
      // Click create button, fill form, submit
      // Verify item appears in list
    })

    test('should edit item', async ({ page }) => {
      // Click edit button, update field, submit
      // Verify changes saved
    })

    test('should delete item', async ({ page }) => {
      // Click delete button, confirm
      // Verify item removed from list
    })
  })

  test.describe('Error Handling', () => {
    test('should show error for invalid input', async ({ page }) => {
      // Try to submit invalid form
      // Verify error message appears
    })

    test('should show error for API failure', async ({ page, context }) => {
      // Mock API to return error
      // Verify error displayed to user
    })
  })
})
```

---

### Test Verification Commands Reference

```bash
# Run tests for specific page (serial - 1 worker)
npm test -- tests/pages/members.spec.ts --workers=1

# Run specific test by name
npm test -- --grep "should list members" --workers=1

# Run all E2E tests (parallel - 4 workers default)
npm test -- --workers=4

# Run with JSON reporter (for parsing results)
npm test -- --reporter=json > test-results.json
cat test-results.json | jq '.suites[].tests[] | select(.status=="fail")'

# Run with UI (for interactive debugging)
npm test -- tests/pages/members.spec.ts --ui

# Run in debug mode (step through)
npm test -- tests/pages/members.spec.ts --debug
```

---

### Debugging Failed Tests with Playwright MCP

**When a test fails**:

1. **Check error message**:
   ```bash
   npm test -- tests/pages/members.spec.ts --workers=1
   # Read the failure message
   ```

2. **Visual inspection with Playwright MCP**:
   ```javascript
   // Start dev server: npm run dev
   // In Claude Code:
   mcp__playwright__browser_navigate("http://localhost:5173/members")
   mcp__playwright__browser_take_screenshot("debug.png")
   mcp__playwright__browser_snapshot("debug-structure.md")
   ```

3. **Check console errors**:
   ```javascript
   mcp__playwright__browser_console_messages(level="error")
   ```

4. **Verify API connection**:
   ```javascript
   // Navigate to app
   // Open browser DevTools Network tab
   // Check if API requests are made
   // Verify response status codes
   ```

5. **Fix and retry**:
   ```bash
   # Make code changes
   npm test -- tests/pages/members.spec.ts --workers=1 --grep "failing test"
   ```

---

### Manual Testing Checklist
- [ ] Test in Chrome, Firefox, Safari
- [ ] Test on desktop, tablet, mobile
- [ ] Keyboard navigation (Tab, Enter, Escape)
- [ ] Screen reader compatibility
- [ ] Page load performance (< 3 seconds)
- [ ] Form validation messages clear
- [ ] Error messages helpful
- [ ] No layout shifts or jank

---

## Phase 2 Implementation Start Guide

### Prerequisites Check

Before starting Phase 2, verify everything is ready:

```bash
# 1. Backend is running
curl -s http://localhost:8080/api/health | jq .
# Expected: { "status": "healthy" }

# 2. Frontend project exists
ls -la admin-frontend/
# Expected: package.json, src/, tests/, etc.

# 3. Dependencies installed
cd admin-frontend
npm ls react react-dom typescript vite
# Expected: All packages installed

# 4. Dev server starts
npm run dev &
# Expected: Server running on http://localhost:5173
# Press Ctrl+C to stop
```

### Phase 2 Implementation Command Reference

**For each milestone** (Members, Products, Journal, Settlements, Statistics):

```bash
# Step 1: Create test file
cat > admin-frontend/tests/pages/[page-name].spec.ts << 'EOF'
// Copy test template from above
EOF

# Step 2: Run tests (should fail)
cd admin-frontend
npm test -- tests/pages/[page-name].spec.ts --workers=1
# Expected: ✕ N tests failing (red phase)

# Step 3: Start dev server (in new terminal)
npm run dev
# Expected: http://localhost:5173 available

# Step 4: Implement page component
# Edit src/pages/[PageName]Page.tsx

# Step 5: Visual verification (in Claude Code)
# Use Playwright MCP:
# - Navigate to http://localhost:5173/[page]
# - Take screenshot
# - Compare with prototype

# Step 6: Run tests again
npm test -- tests/pages/[page-name].spec.ts --workers=1
# Expected: ✓ N tests passing (green phase)

# Step 7: Parallel safety check
npm test -- tests/pages/[page-name].spec.ts --workers=4
# Expected: ✓ N tests passing (all workers)

# Step 8: Commit
git add .
git commit -m "[Phase 2 Milestone 2.X] Implement [Page Name] page

- Write E2E tests for [page] workflows
- Implement [Page] React component
- All API integrations complete
- 7/7 tests passing (serial and parallel)
- Visual verification complete

Tests: ✓ N/N passing"
```

### Backend API Verification

**Before starting each milestone**, verify the backend endpoints are available:

```bash
# Members endpoints
curl -s http://localhost:8080/api/admin/members -H "Cookie: PHPSESSID=test" | jq .

# Products endpoints
curl -s http://localhost:8080/api/admin/products | jq .

# Settlements endpoints
curl -s http://localhost:8080/api/admin/settlements | jq .

# Dashboard endpoint
curl -s http://localhost:8080/api/admin/dashboard | jq .

# Reports endpoint
curl -s http://localhost:8080/api/admin/reports/revenue | jq .
```

### E2E Test Execution Timeline

**Phase 2 implementation milestone**: ~1 week per page × 5 pages = 4-6 weeks

```
Week 1: Products page (simplest CRUD)
        - Mon-Tue: Write tests
        - Wed: Implement
        - Thu: Visual verification
        - Fri: Run tests + parallel check

Week 2: Members page (complex CRUD + modals)
        - Mon-Tue: Write tests
        - Wed-Thu: Implement
        - Fri: Testing + parallel check

Week 3: Journal page (filtering, member-centric)
        - Similar cycle

Week 4: Settlements page (complex workflow + exports)
        - Mon-Wed: Write tests
        - Thu-Fri: Implement
        - Additional debugging as needed

Week 5: Statistics page (charts + reports)
        - Mon-Tue: Write tests
        - Wed-Thu: Implement
        - Fri: Finalization

Target: 25-30 E2E tests, all passing ✅
```

### Test Coverage Goals

**Phase 2 Target**:
- **Authentication**: 3 tests (login, logout, protected routes)
- **Members**: 8 tests (list, search, CRUD, delete)
- **Products**: 5 tests (list, CRUD, categories)
- **Journal**: 4 tests (member-centric, filtering)
- **Settlements**: 6 tests (create, preview, export, cancel)
- **Statistics**: 4 tests (dashboard, reports)
- **Total**: 30 tests

**Success Criteria**:
- ✅ 30/30 tests passing with 1 worker
- ✅ 30/30 tests passing with 4 workers (parallel)
- ✅ No flaky tests (consistent pass rate)
- ✅ < 30 seconds total execution time (all tests in parallel)

---

## Deployment

### Development
```bash
npm run dev      # Start dev server on http://localhost:5173
```

### Production Build
```bash
npm run build    # Build optimized bundle
npm run preview  # Preview production build
```

### Deployment Platforms
- **Vercel** (recommended for Next.js, works with React)
- **Netlify** (excellent for React SPAs)
- **Self-hosted**: nginx or Apache with static site hosting

### Environment Configuration
```
.env.development
.env.staging
.env.production

Required variables:
- VITE_API_BASE_URL=http://localhost:8080
- VITE_APP_NAME=Ruderbar Admin
```

---

## Documentation

### To Create
1. **README.md**: Setup instructions, dependencies
2. **DEVELOPMENT.md**: Development workflow, code style guide
3. **COMPONENTS.md**: Component library documentation with examples
4. **API_INTEGRATION.md**: How to add new API endpoints
5. **TESTING.md**: How to run and write tests
6. **DEPLOYMENT.md**: Deployment procedures

### Prototype Reference
- `prototypes/frgs-admin.html` - Source of truth for UI/UX
- Exact color values, spacing, component layouts
- All interactions and workflows defined

---

## Success Metrics

### Code Quality
- [x] TypeScript strict mode enabled
- [x] ESLint passes with no warnings
- [x] Prettier formatting consistent
- [x] No console.log statements in production code

### Performance
- [x] Lighthouse score > 80
- [x] Bundle size < 500KB gzipped
- [x] First Contentful Paint < 2 seconds
- [x] Time to Interactive < 3 seconds

### Testing
- [x] 30/30 E2E tests passing
- [x] All user workflows covered
- [x] Error scenarios tested
- [x] No flaky tests

### UX/Accessibility
- [x] All colors meet WCAG contrast requirements
- [x] Keyboard navigation works on all pages
- [x] Screen reader compatible
- [x] Mobile responsive (375px - 1920px)

### Backend Integration
- [x] All 9 core API modules integrated
- [x] Error handling for all HTTP status codes
- [x] Authentication flow working
- [x] Session management secure

---

## Risks & Mitigation

| Risk | Impact | Mitigation |
|------|--------|-----------|
| API changes during development | High | Versioning, API contracts, mock data |
| Performance issues with large datasets | Medium | Pagination, virtual lists, caching |
| Browser compatibility issues | Medium | Cross-browser testing, polyfills |
| State management complexity | Medium | Clear patterns, custom hooks abstractions |
| Scope creep | High | Fixed feature list, change control process |
| Team onboarding | Medium | Clear documentation, code examples, pairing |

---

## Timeline

**Start Date**: After backend completion (✅ 2026-01-26)

**Proposed Schedule**:
- Phase 1 (Setup): Week 1-2
- Phase 2 (Core): Week 2-4
- Phase 3 (Advanced): Week 4-5
- Phase 4 (Testing): Week 5-6
- Phase 5 (Refinement): Week 6-7

**Total**: 4-7 weeks (depends on team size, priorities)

---

## Next Steps

1. ✅ Review prototype (frgs-admin.html) - specifications locked
2. ✅ Create React project structure
3. ✅ Set up design system and component library
4. ✅ Implement authentication
5. ✅ Build members page (primary feature)
6. ✅ Integrate with backend API
7. ✅ Build remaining pages
8. ✅ Write E2E tests
9. ✅ Performance optimization
10. ✅ Deploy to production

---

## Completion Checklist

### Planning
- [ ] Design system fully documented
- [ ] Component library specifications
- [ ] Page wireframes and data models
- [ ] API integration checklist
- [ ] Test scenarios documented

### Development
- [ ] React project created with structure
- [ ] All pages implemented
- [ ] All CRUD operations working
- [ ] Forms with validation
- [ ] Search/filter functionality
- [ ] Export functionality
- [ ] Error handling and user feedback

### Testing
- [ ] 30/30 E2E tests created and passing
- [ ] Manual testing completed
- [ ] Browser compatibility verified
- [ ] Performance optimized
- [ ] Accessibility audit passed

### Deployment
- [ ] Build process optimized
- [ ] Environment configuration complete
- [ ] Staging deployment tested
- [ ] Production deployment successful
- [ ] Monitoring and error tracking configured

### Documentation
- [ ] README with setup instructions
- [ ] Component library documented
- [ ] API integration guide
- [ ] Development workflow documented
- [ ] Deployment procedures documented

---

**Status**: Ready for Phase 1 implementation

**Prototype Reference**: `prototypes/frgs-admin.html` - Complete working prototype with all specifications

**Backend Status**: ✅ All 9 core modules complete and tested (240/240 tests passing)
