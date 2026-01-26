# Phase 4: Admin Frontend - Implementation Plan

## Overview

**Project**: Ruderbar Admin Frontend (React SPA)

**Objective**: Implement production-ready admin panel using React + TypeScript with exact design and UX from `prototypes/frgs-admin.html`

**Reference Prototype**: `prototypes/frgs-admin.html` - Complete working prototype with all layouts, components, interactions, and design system

**Status**: Planning phase — Frontend infrastructure ready, design specifications complete, backend API complete

**Scope**:
- React SPA for admin operations (CRUD, settlements, reports)
- Full integration with backend API (9 core modules)
- Design system implementation (dark theme, component library)
- 30+ E2E tests for all workflows
- Estimated 4-6 weeks development

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
- Primary nav tabs:
  - Mitglieder (Members)
  - Produkte (Products)
  - Buchungsjournal (Transaction Journal)
  - Abrechnungen (Settlements)
  - Statistik (Statistics)
- User badge (admin indicator)
- Logout button

#### Members Page (Default)

**Layout**:
```
[Summary Cards - 3 columns]
├── Active Members Count
├── Total Outstanding Balance
└── Last Settlement Date

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

### 3. Modals & Forms

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

## Implementation Phases

### Phase 1: Project Setup & Infrastructure (1-2 weeks)

**Tasks**:
1. Create React project structure (Vite + TypeScript)
2. Set up routing (React Router v6)
3. Implement design system (colors, spacing, components)
4. Create reusable component library
5. Set up API client with axios + interceptors
6. Implement authentication (login, session storage)
7. Create layout components (header, nav, main)

**Files to Create**:
```
admin-frontend/
├── src/
│   ├── components/
│   │   ├── common/ (Button, Input, Card, Modal, Table)
│   │   ├── layout/ (Header, Navigation, MainLayout)
│   │   ├── forms/ (LoginForm, UserForm, ProductForm)
│   │   └── modals/ (UserModal, ExportModal, etc.)
│   ├── pages/
│   │   ├── LoginPage
│   │   ├── DashboardPage
│   │   ├── MembersPage
│   │   ├── ProductsPage
│   │   ├── JournalPage
│   │   ├── SettlementsPage
│   │   └── StatisticsPage
│   ├── services/
│   │   ├── api.ts (HTTP client)
│   │   ├── members.ts (API methods)
│   │   ├── products.ts
│   │   ├── transactions.ts
│   │   └── settlements.ts
│   ├── hooks/
│   │   ├── useAuth.ts
│   │   ├── useFetch.ts
│   │   └── useForm.ts
│   ├── context/
│   │   ├── AuthContext.tsx
│   │   └── AppContext.tsx
│   ├── types/
│   │   └── index.ts (TypeScript interfaces)
│   ├── styles/
│   │   └── design-system.ts (theme, colors, styles)
│   └── App.tsx
├── tests/
│   └── (E2E test structure)
└── package.json
```

**Success Criteria**:
- React app compiles without errors
- Login page renders
- Protected routes redirect to login
- API client successfully makes requests
- Design system colors/styles applied

---

### Phase 2: Core Pages (2-3 weeks)

**Tasks**:
1. Members page (list, search, CRUD)
2. Products page (list, CRUD)
3. Transaction journal (list, filter, export)
4. Settlements page (list, create, undo)
5. Statistics page (metrics, charts)

**API Integration**:
- All CRUD operations integrated
- Error handling with user feedback
- Loading states and spinners
- Success notifications

**Success Criteria**:
- All pages load and display data
- CRUD operations work end-to-end
- Search/filter functionality working
- Forms validate properly
- API errors handled gracefully

---

### Phase 3: Advanced Features (1-2 weeks)

**Tasks**:
1. Modal interactions (open, close, form submission)
2. Confirmation modals for destructive actions
3. Inline editing (optional)
4. Batch operations (select multiple members)
5. Export functionality (CSV, SEPA XML)
6. Real-time data refresh (polling or WebSockets)
7. User preferences (pagination size, theme)

**Success Criteria**:
- All modals open/close correctly
- Form validation prevents invalid submissions
- Export downloads work properly
- Batch operations complete successfully

---

### Phase 4: E2E Testing (1 week)

**Test Suite** (30+ tests):

**A. Authentication (3 tests)**:
- Login with valid credentials
- Login with invalid credentials
- Logout and redirect to login

**B. Members (8 tests)**:
- List members with pagination
- Search members by name/RFID
- Create new member
- Edit member details
- Delete member with confirmation
- View member balance and transactions
- Add manual correction
- Export member list

**C. Products (5 tests)**:
- List products
- Create product (multilingual)
- Edit product
- Delete product
- Toggle product active/inactive

**D. Transactions (5 tests)**:
- Filter transactions by date range
- Filter by member
- Filter by type
- View transaction detail
- Export transactions as CSV

**E. Settlements (5 tests)**:
- Create settlement (preview + confirm)
- View settlement details
- Undo settlement
- Export settlement (CSV and SEPA)
- View settlement history

**F. Statistics (2 tests)**:
- Load dashboard with metrics
- Generate and export report

**Success Criteria**:
- 30/30 tests passing with 1 worker
- 30/30 tests passing with 4 workers (parallel)
- No flaky tests
- < 500ms average test duration

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
- All tests passing
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
