# Club Bar Admin Frontend

React-based admin panel for the Club Bar POS system. Provides member management, product catalog, transaction journal, SEPA settlement billing, reports, GDPR compliance, and a dashboard with live terminal status.

## Technology Stack

- **React 18** with TypeScript 5
- **Vite** — build tool and dev server
- **React Router v6** — client-side routing
- **Axios** — HTTP client with CSRF and bearer token interceptors
- **Recharts** — charts for reports and dashboard
- **i18next** — internationalization (German and English)
- **CSS-in-JS** — custom design system with inline styles (no CSS files)
- **Vitest** — unit testing
- **Playwright** — E2E testing

## Getting Started

### Prerequisites

- Node.js 18+
- Backend running at `http://localhost:8080` (see root README)

### Installation & Development

```bash
cd admin-frontend
npm install
npm run dev
```

The app runs at `http://localhost:5173`. API requests are proxied to the backend at `http://localhost:8080`.

### Production Build

```bash
npm run build     # outputs to dist/
npm run preview   # preview production build locally
```

## Pages

| Route | Page | Description |
|-------|------|-------------|
| `/login` | Login | Email/password authentication |
| `/dashboard` | Dashboard | KPI cards, recent transactions, terminal status, system alerts |
| `/members` | Members | Member CRUD, RFID card assignment, GDPR export & anonymize |
| `/products` | Products | Product catalog with multilingual names, icons, category assignment |
| `/categories` | Categories | Category management with icons and display order |
| `/journal` | Journal | Transaction ledger with search, date/type filters, CSV export, manual corrections |
| `/settlements` | Settlements | Settlement wizard (filter, preview, finalize), SEPA XML/CSV export, cancel |
| `/reports` | Reports | Revenue by period, member ranking, terminal activity — with charts and CSV export |
| `/settings` | Settings | Three tabs: Admin Users, SEPA Config, Terminals |
| `/audit-log` | Audit Log | Chronological log of all admin actions |
| `/profile` | Profile | Change display name, email, and password |

## Project Structure

```
src/
├── components/
│   ├── common/        # Button, Input, Card, Alert, Avatar, Badge, Tooltip,
│   │                  # ActionMenu, StatCard, Toggle, LoadingIndicator
│   ├── forms/         # LoginForm, IconSelect, CategorySelect, LanguageSelector,
│   │                  # LanguageTabsInput, DateRangePicker, PeriodPicker,
│   │                  # StatusFilter, StatusFilterPills, TypeFilter, etc.
│   ├── icons/         # 30+ custom SVG product icons, category icons, admin icons,
│   │                  # navigation icons, IconRegistry
│   ├── layout/        # MainLayout, MobileToolbar, BottomTabBar
│   ├── modals/        # ConfirmDialog, CreateAdminModal, EditAdminModal,
│   │                  # CreateTerminalModal, EditTerminalModal, TokenDisplayModal,
│   │                  # PasswordDisplayModal, SettlementConfirmModal, TransactionModal
│   └── tables/        # TableContainer, SortableTableHeader, SortDropdown,
│                      # TableSearchToolbar, SearchAndSortToolbar, StatusToggleCell,
│                      # BadgeCell, IconCell, PriceCell, ActionButtons
├── context/
│   ├── AuthContext.tsx    # Auth state, login/logout, session management
│   └── LoadingContext.tsx # Global loading state (driven by Axios request counter)
├── hooks/
│   ├── useFormatters.ts   # Locale-aware price, date, relative date formatting
│   └── useBreakpoint.ts   # Responsive breakpoint detection
├── i18n/
│   └── config.ts          # i18next setup (de/en), persists language in localStorage
├── pages/                 # One file per page (see table above)
├── services/
│   ├── api.ts             # Axios client, bearer token + CSRF interceptors
│   ├── auth.ts            # Login, logout, profile, password change
│   ├── members.ts         # Member CRUD + GDPR export/anonymize
│   ├── admin-users.ts     # Admin user management
│   ├── transactions.ts    # Transaction/journal listing + corrections
│   ├── settlements.ts     # Settlement creation, cancellation, SEPA export
│   ├── sepa-config.ts     # SEPA creditor configuration
│   ├── terminals.ts       # Terminal management + token rotation
│   ├── audit-log.ts       # Audit log listing
│   ├── reports.ts         # Revenue, ranking, terminal activity reports
│   └── dashboard.ts       # Dashboard metrics + monthly statistics
├── styles/
│   ├── design-system.ts   # Theme tokens, colors, typography, formatting utilities
│   └── tableTokens.ts     # Table-specific design tokens
├── types/
│   └── index.ts           # All TypeScript interfaces
├── utils/
│   ├── i18n-helpers.ts    # Locale code mapping (de → de-DE)
│   └── productIcons.tsx   # Product icon name → component resolver
├── App.tsx                # Router setup with protected routes
└── main.tsx               # Entry point
```

## Authentication

- **Session-based** with bearer token stored in `localStorage`
- CSRF token sent as `X-CSRF-Token` header on state-changing requests
- 401 responses automatically clear session and redirect to `/login`
- Login redirects to `/dashboard` on success

## Design System

Dark theme defined in `src/styles/design-system.ts`:

- **Dark backgrounds**: `#0a1628` (primary), `#1a2744` (cards), `#0d1829` (inputs)
- **Semantic colors**: Blue (actions), Green (success), Orange (warning), Red (danger)
- **Typography**: System font stack; monospace for IBAN, RFID UIDs
- **Responsive**: Breakpoints at 480px (small mobile), 768px (mobile), 1500px (tablet)
- **i18n-aware formatting**: Dates, prices, and relative times adapt to selected language

## Testing

```bash
# Unit tests
npm run test
npm run test -- --watch

# E2E tests (requires backend running)
npm run test:e2e
npm run test:e2e:ui
```

E2E tests live in `/e2etests/` (project root) and follow patterns documented in `e2etests/patterns/`.

## Code Quality

```bash
npm run type-check   # TypeScript strict mode
npm run lint         # ESLint
npm run lint -- --fix
npm run format       # Prettier
```

## API

All API requests are proxied through Vite to the backend. See `api/admin.yaml` for the full OpenAPI spec.

**Base URL**: `/api/admin` (proxied to `http://localhost:8080/api/admin`)

## License

Apache-2.0 (see root LICENSE file)
