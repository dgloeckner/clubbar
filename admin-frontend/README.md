# Club Bar Admin Frontend

React-based admin panel for Club Bar POS system. Provides member management, product catalog, transaction journal, settlement billing, and analytics dashboard.

## Technology Stack

- **React 18.x** - UI framework
- **TypeScript 5.x** - Type safety
- **Vite** - Lightning-fast build tool and dev server
- **React Router v6** - Client-side routing
- **Axios** - HTTP client for API requests
- **Tailwind CSS** (optional) - Utility-first CSS framework
- **Playwright** - E2E testing
- **Vitest** - Unit testing

## Project Setup

### Prerequisites

- Node.js 18+ installed locally
- npm or yarn package manager

### Installation

```bash
cd admin-frontend
npm install
```

### Development Server

```bash
npm run dev
```

The app runs at `http://localhost:5173` with hot module reloading enabled.

API requests are proxied to `http://localhost:8080/api` (backend).

### Build for Production

```bash
npm run build
```

Creates optimized production build in `dist/` directory.

Preview production build:

```bash
npm run preview
```

## Project Structure

```
src/
├── components/
│   ├── common/           # Reusable UI components (Button, Input, Card)
│   ├── forms/            # Form components (LoginForm)
│   ├── layout/           # Layout wrapper (MainLayout)
│   └── modals/           # Modal dialogs (created in Phase 2)
├── pages/
│   ├── LoginPage         # Authentication page
│   ├── MembersPage       # Member management
│   ├── ProductsPage      # Product catalog
│   ├── JournalPage       # Transaction journal
│   ├── SettlementsPage   # Settlement management
│   └── StatisticsPage    # Analytics dashboard
├── services/
│   ├── api.ts            # Axios HTTP client with interceptors
│   ├── auth.ts           # Authentication service
│   ├── members.ts        # Members API methods
│   ├── products.ts       # Products API methods
│   ├── transactions.ts   # Transactions API methods
│   └── settlements.ts    # Settlements API methods
├── context/
│   ├── AuthContext.tsx   # Global auth state
│   └── AppContext.tsx    # Global app state
├── hooks/
│   ├── useAuth.ts        # Auth hook
│   ├── useFetch.ts       # Data fetching hook
│   └── useForm.ts        # Form handling hook
├── styles/
│   └── design-system.ts  # Theme, colors, utilities, formatters
├── types/
│   └── index.ts          # TypeScript interfaces
├── App.tsx               # Main app component with routing
└── main.tsx              # React entry point

public/
├── vite.svg              # Vite logo
└── (other static assets)
```

## Authentication

- Session-based authentication via Laravel backend
- Credentials stored in localStorage
- Auto-redirect to login on 401 Unauthorized
- Logout clears session and redirects to login

### Login

1. Navigate to `/login`
2. Enter admin email and password
3. Session stored in localStorage
4. Auto-redirect to `/members` on success

## Design System

Theme and colors defined in `src/styles/design-system.ts`:

- **Primary colors**: Blue #3b82f6 (actions), Green #22c55e (success), Orange #f97316 (warning), Red #ef4444 (danger)
- **Backgrounds**: #0a1628 (primary), #1a2744 (cards), #0d1829 (inputs)
- **Typography**: System font stack, Monospace for technical data (RFID, IBAN)
- **Localization**: German UI, DD.MM.YYYY dates, Euro currency with comma separator

### Formatting Utilities

```typescript
import { formatPrice, formatIban, formatDate, formatDateTime } from './styles/design-system'

// Format price in EUR with German locale
formatPrice(10050) // "100,50 €"

// Format IBAN (mask except last 4 digits)
formatIban('DE89370400440532013000') // "DE89****3000"

// Format dates
formatDate('2026-01-26') // "26.01.2026"
formatDateTime('2026-01-26T14:30:00Z') // "26.01.2026 14:30"
```

## API Integration

All API requests use `src/services/api.ts`:

```typescript
import { get, post, patch, del } from './services/api'

// Fetch paginated members
const response = await get<Member[]>('/admin/members', {
  params: { page: 1, per_page: 20 }
})

// Create member
const response = await post<Member>('/admin/members', {
  first_name: 'John',
  last_name: 'Doe',
  // ... other fields
})

// Update member
const response = await patch<Member>('/admin/members/{id}', {
  first_name: 'Jane'
})

// Delete member
await del<void>('/admin/members/{id}')
```

**Automatic features**:
- Session cookies sent automatically
- Bearer token from localStorage added to requests
- Auto-redirect to login on 401 Unauthorized
- Error logging on failures

## Testing

### Unit Tests

```bash
npm run test
```

Run with watch mode:

```bash
npm run test -- --watch
```

### E2E Tests

```bash
npm run test:e2e
```

Run with UI:

```bash
npm run test:e2e:ui
```

## Code Quality

### Type Checking

```bash
npm run type-check
```

### Linting

```bash
npm run lint
```

Fix linting issues:

```bash
npm run lint -- --fix
```

### Code Formatting

```bash
npm run format
```

## Implementation Phases

### Phase 1 ✅ (Current)
- Project setup with Vite + TypeScript
- Design system implementation
- Component library (Button, Input, Card)
- Authentication (login, session)
- Layout and routing
- Page placeholders

### Phase 2 (Next)
- Members page (CRUD, search, filter)
- Products page (CRUD, categories)
- Transaction journal (list, filter, export)
- Settlements page (create, preview, export)
- Statistics page (metrics, charts)

### Phase 3
- Advanced features (SEPA config, settlement workflows)
- Modal dialogs for forms
- Batch operations
- Advanced filtering

### Phase 4
- Comprehensive E2E tests
- Performance optimization
- Accessibility improvements

### Phase 5
- Production deployment
- Documentation
- Performance monitoring

## Backend API Reference

See `api/admin.yaml` for OpenAPI specification.

**Base URL**: `http://localhost:8080/api/admin`

**Authentication**: Session cookies (same-origin requests)

**Key Endpoints**:
- `GET /members` - List members
- `POST /members` - Create member
- `PATCH /members/{id}` - Update member
- `DELETE /members/{id}` - Delete member
- `GET /products` - List products
- `POST /products` - Create product
- `GET /transactions` - List transactions
- `GET /settlements` - List settlements
- `POST /settlements` - Create settlement
- `GET /dashboard` - Dashboard metrics

## Development Tips

### Hot Module Reloading

Changes to React components and styles automatically reload without losing state.

### API Proxy

All requests to `/api` are proxied to backend at `http://localhost:8080`.

### Environment Variables

Create `.env.local` to override defaults:

```
VITE_API_BASE_URL=http://localhost:8080/api
```

### Debugging

Use browser DevTools:
- React DevTools extension
- Redux DevTools (optional, if Redux added)
- Network tab for API requests

## Common Issues

### "Cannot GET /"

Start development server: `npm run dev`

### API requests fail with CORS

Ensure backend is running at `http://localhost:8080` and proxy is configured in `vite.config.ts`.

### Login redirect loop

Check localStorage for valid session (`admin_id` key). Clear and re-login if corrupted.

## Contributing

1. Follow TypeScript strict mode
2. Use components from `src/components/common/` for consistency
3. Add tests for new features
4. Format code: `npm run format`
5. Check types: `npm run type-check`
6. Lint: `npm run lint`

## License

Same as Club Bar project (see root LICENSE file)
