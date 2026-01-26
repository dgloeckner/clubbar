# Prototype Analysis: frgs-admin.html

**Purpose**: Complete reference for admin panel design, layout, components, and interactions

**Date**: 2026-01-26

**Prototype File**: `prototypes/frgs-admin.html`

---

## 1. Overall Structure

### Technology
- React 18 (via CDN)
- React DOM 18
- Babel standalone (JSX transpilation)
- Inline styles (no external CSS framework)
- Mock data for testing (no backend integration in prototype)

### Entry Point
```html
<div id="root"></div>
```

### App State Flow
```
App (global state)
  ├── LoginScreen (when not authenticated)
  └── AdminDashboard (main app, when authenticated)
       ├── Header (navigation tabs + user badge)
       ├── Main Content (renders based on activePage)
       │   ├── MembersPage (default)
       │   ├── ProductsPage
       │   ├── JournalPage
       │   ├── SettlementsPage
       │   └── StatisticsPage
       └── Modals (overlaid when needed)
           ├── UserModal
           ├── PostenModal
           ├── ProductModal
           ├── ExportModal
           └── DeleteModal
```

---

## 2. Design System

### Color Palette

**Primary Colors**:
```javascript
colors = {
  bg: '#0a1628',                    // Main background
  bgSecondary: '#0f1d32',           // Header, modals
  bgCard: '#1a2744',                // Card backgrounds
  bgInput: '#0d1829',               // Input fields
}
```

**Semantic Colors**:
```javascript
colors = {
  blue: '#3b82f6',                  // Primary actions, info
  blueHover: '#2563eb',             // Hover state
  green: '#22c55e',                 // Success, positive balance
  orange: '#f97316',                // Warning, outstanding balance
  red: '#ef4444',                   // Danger, delete, errors
}
```

**Border & Text**:
```javascript
colors = {
  border: 'rgba(71, 85, 105, 0.4)',    // Dark borders
  borderLight: 'rgba(71, 85, 105, 0.2)',
  text: '#f1f5f9',                     // Primary text
  textSecondary: '#94a3b8',            // Secondary text (labels)
  textMuted: '#64748b',                // Tertiary text (disabled)
}
```

### Typography

**Font**: System font stack
```
-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif
```

**Sizes & Weights**:
- Headers: 20px (bold) to 28px (bold)
- Body: 14px (regular)
- Small: 12-13px (secondary info)
- Labels: 13px (medium weight)
- Monospace (RFID, IBAN, amounts): `fontFamily: 'monospace'`

### Spacing System

**Gaps**: 4, 8, 12, 16, 20, 24, 32px
**Padding**:
- Cards: 24px
- Modals: 20-24px
- Buttons: 10px vertical, 16-20px horizontal
- Form fields: 12px

**Border Radius**:
- Buttons: 10px
- Cards: 16px
- Modals: 20px
- Small UI elements: 8-10px

### Shadows

```javascript
// Button primary
boxShadow: '0 4px 12px rgba(59, 130, 246, 0.3)'

// Button success
boxShadow: '0 4px 12px rgba(34, 197, 94, 0.3)'

// Modal
boxShadow: '0 25px 50px rgba(0,0,0,0.5)'
```

---

## 3. Components

### 3.1 Icons (SVG as Components)

**Implemented Icons**:
```javascript
UserIcon, LogoutIcon, PlusIcon, EditIcon, TrashIcon, EyeIcon, DownloadIcon,
CloseIcon, SearchIcon, CalendarIcon, CorrectionIcon, CheckCircleIcon,
BookIcon, ChartIcon, ChevronLeftIcon, ChevronRightIcon, HomeIcon,
ReceiptIcon, UndoIcon, PackageIcon, ToggleIcon (with active state),
UsersIcon, BankIcon
```

Each icon:
- Returns JSX <svg> element
- Size: ~24x24px (viewBox="0 0 24 24")
- Color: Inherited from parent (currentColor)
- Used with inline styles for color customization

### 3.2 Basic Elements

#### Button Variants
```javascript
// Primary (blue gradient)
background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)
color: white
boxShadow: 0 4px 12px rgba(59, 130, 246, 0.3)

// Secondary (blue outlined)
background: rgba(59, 130, 246, 0.1)
border: 1px solid rgba(59, 130, 246, 0.3)
color: #3b82f6

// Danger (red outlined)
background: rgba(239, 68, 68, 0.1)
border: 1px solid rgba(239, 68, 68, 0.3)
color: #ef4444

// Success (green gradient)
background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%)
color: white
```

#### Input Fields
```javascript
width: 100%
padding: 12px 16px
background: #0d1829
border: 1px solid rgba(71, 85, 105, 0.4)
borderRadius: 10px
color: #f1f5f9
fontSize: 14px
outline: none
transition: border-color 0.15s
```

#### Forms
- Label (13px, medium weight, secondary color)
- Input field (as above)
- Placeholder text in muted color
- Spacing: 16px between fields
- Full width layout

#### Tables
- Monospace font for IDs, IBANs
- Uppercase column headers (12px, secondary color, letter-spacing)
- Row hover effect (optional background change)
- Alternating row heights: 16px padding
- Right-aligned numeric columns
- Action buttons right-aligned in last column

#### Modal Structure
```
┌─ Modal Overlay ─────────────────────┐
│ (fixed, full screen, semi-transparent)
│
│  ┌─ Modal Content ─────────┐
│  │ ┌─ Header ────────┐    │
│  │ │ Title  [Close]  │    │
│  │ └─────────────────┘    │
│  │ ┌─ Body ──────────┐    │
│  │ │ (scrollable)    │    │
│  │ │ Form content    │    │
│  │ └─────────────────┘    │
│  │ ┌─ Footer ────────┐    │
│  │ │ [Cancel] [Save] │    │
│  │ └─────────────────┘    │
│  └─────────────────────────┘
│
└─────────────────────────────────────┘
```

**Modal Dimensions**:
- Width: 100%, maxWidth: 600px
- Height: maxHeight: 90vh
- Body overflow: auto (scroll if content exceeds)

---

## 4. Layout

### 4.1 Header (Fixed, 64px tall)

```
[Logo] [Nav Tabs (Members, Products, Journal, Settlements, Stats)]   [User Badge] [Logout]
```

**Left Section**:
- Ruderbar Admin title (20px, bold)
- Gap: 32px from nav

**Center Section**:
- Nav tabs (5 total)
- Each tab:
  - Padding: 10px 16px
  - Gap between icon and label: 8px
  - Background when active: rgba(59, 130, 246, 0.2)
  - Color when active: #3b82f6
  - Border radius: 8px
  - Font: 14px, 500 weight

**Right Section**:
- Admin badge (icon + "Admin")
- Background: rgba(59, 130, 246, 0.15)
- Border: 1px solid rgba(59, 130, 246, 0.3)
- Border radius: 20px
- Padding: 8px 16px
- Logout button (similar styling, red)

### 4.2 Main Content Area

```
Max width: 1400px
Padding: 24px
Margin: 0 auto
```

---

## 5. Pages

### 5.1 Members Page (Default)

#### Section 1: Summary Cards (3 columns, 20px gap)
```
┌─────────────────┬─────────────────┬─────────────────┐
│ [Members Icon]  │ [Bank Icon]     │ [Calendar Icon] │
│ 5               │ 245.60 €        │ 31.12.2025      │
│ Mitglieder      │ Offene Deckel   │ Letzte          │
│                 │ gesamt          │ Abrechnung      │
└─────────────────┴─────────────────┴─────────────────┘
```

**Card Structure**:
- Flex: icon (56x56px, left) + text (right)
- Icon background: colored (blue, orange, green)
- Large number: 28px bold
- Label: 14px secondary

#### Section 2: Members Table

**Header Row**:
- Search icon + input (250px wide, placeholder "Suchen...")
- "Neues Mitglied" button

**Table Columns** (7 total):
| Name | RFID | IBAN (masked) | BIC | Member Since | Deckel (€) | Actions |
| 20% | 15% | 20% | 15% | 15% | 10% | 5% |

**Column Details**:
- Name: Bold, full name (firstName lastName)
- RFID: Monospace, secondary color (e.g., "RF-4821")
- IBAN: Monospace, small font, masked format (e.g., "DE89 .... 3000")
- BIC: Monospace, small font (e.g., "COBADEFFXXX")
- Member Since: Secondary color, date format (e.g., "15.03.2024")
- Deckel: Monospace, bold
  - If > 0: Orange color (outstanding balance)
  - If = 0: Green color (settled)
- Actions: 3 icon buttons in a row
  - Eye (blue, transparent bg): View posten
  - Edit (blue, transparent bg): Edit member
  - Trash (red, transparent bg): Delete member

**Empty State**:
- Centered text: "Keine Mitglieder gefunden"
- Color: textMuted
- Padding: 40px

### 5.2 Products Page

**Layout** (Similar to Members):
- Add Product button
- Search bar
- Products table/grid

**Table Columns**:
| Product Name | Category | Description | Price | Active | Actions |
| 25% | 15% | 30% | 15% | 10% | 5% |

**Actions**:
- Edit icon
- Delete icon
- Active toggle (visual indicator)

### 5.3 Journal Page (Buchungsjournal)

**Header Section**:
- Date range picker (from/to dates)
- Member filter (dropdown)
- Transaction type filter (all, purchase, correction)
- Search input
- Export button

**Table Columns**:
| Date | Member (Name + RFID) | Item | Qty | Price (€) | Type | Actions |
| 15% | 20% | 25% | 10% | 12% | 10% | 8% |

**Row Details**:
- Date: "22.01.2026 18:34" (datetime format)
- Member: First column "John Doe", second "RF-4821" (RFID in monospace)
- Item: Product name or "Korrektur: ..."
- Qty: Number (right-aligned)
- Price: Monospace, €, right-aligned
- Type: Badge
  - "consumption" (green)
  - "correction" (orange)

### 5.4 Settlements Page

**Section 1: Settlement List**
- Create Settlement button
- Table with columns:
  | Date | Members | Total Amount | Actions |
  | 25% | 15% | 20% | 40% |

- Actions:
  - View (eye icon) → expands/shows detail
  - Undo (undo icon) → reverses settlement

**Section 2: Settlement Details** (when expanded or viewed)
```
Settlement Date: 31.12.2025
Total Amount: 245.60 €
Members: 4

[Table of members in settlement]
├── Member Name (RFID)
├── Items (list of transactions)
└── Amount

[Export Buttons]
├── CSV Export
└── SEPA XML Export
```

### 5.5 Statistics Page

**Metrics Cards** (4 columns):
- Total Members
- Total Revenue (all-time)
- Outstanding Balance
- Settlement Count

**Charts Section**:
- Revenue over time (line chart)
- Transaction distribution (pie chart)
- Top members by spending (bar chart)

**Export**:
- Export Report as PDF button

---

## 6. Modals

### 6.1 User Modal (Create/Edit Member)

**Form Fields**:
```
[First Name]
[Last Name]
[RFID (unique)]
[Email]
[Phone]
[Preferred Language] (de/en dropdown)
[IBAN]
[BIC]
[Active] (checkbox)
```

**Actions**:
- Cancel (secondary button)
- Save (primary button)

### 6.2 Posten Modal (View Member Balance)

**Tabs**:
- Current (open items)
- History (corrections)

**Current Tab**:
```
Balance: 47.50 €

Open Items:
├── 22.01.2026 18:34 | Pils 0,5l | qty:2 | 3.50 €
├── 22.01.2026 18:34 | Brezel   | qty:1 | 1.50 €
└── 19.01.2026 19:12 | Weizen   | qty:1 | 3.80 €
```

**Add Correction Section**:
```
[Amount (€) input]
[Comment textarea]
[Add Correction button]
```

**History Tab**:
```
Corrections:
├── 10.01.2026 10:00 | -5.00 € | Rückerstattung defektes Getränk
```

### 6.3 Product Modal

**Form Fields**:
```
[Product Name]
[Description (German)]
[Description (English)]
[Category] (dropdown)
[Price (€)]
[Active] (checkbox)
```

### 6.4 Export Modal

**Export Options**:
- Format selection (CSV, SEPA XML)
- Date range (from/to)
- Include details (checkbox)
- Export button

### 6.5 Delete Modal (Confirmation)

```
Are you sure you want to delete [Item Name]?

[Cancel] [Delete (red)]
```

---

## 7. Data Models (from Mock Data)

### Member Object
```javascript
{
  id: number,
  rfid: string,           // e.g., "RF-4821"
  firstName: string,
  lastName: string,
  email?: string,
  phone?: string,
  iban: string,           // e.g., "DE89370400440532013000"
  bic: string,            // e.g., "COBADEFFXXX"
  deckel: number,         // Outstanding balance in €
  createdAt: string,      // Date format: "15.03.2024"
  offenePosten: Array,    // Array of transaction items
  korrektionen: Array,    // Array of corrections
}
```

### Transaction Object
```javascript
{
  id: number,
  date: string,           // "22.01.2026 18:34"
  user: string,           // "Max Mustermann"
  rfid: string,           // "RF-4821"
  item: string,           // Product name or "Korrektur: ..."
  qty: number,
  price: number,          // € amount
  type: 'consumption' | 'correction',
}
```

### Product Object
```javascript
{
  id: number,
  name: string,
  category: string,
  price: number,          // € amount
  active: boolean,
  createdAt: string,
}
```

### Settlement Object
```javascript
{
  id: number,
  date: string,           // "31.12.2025"
  totalAmount: number,    // € amount
  userCount: number,
  users: Array,           // Array of members in settlement
  // Each member in settlement:
  {
    userId: number,
    name: string,
    rfid: string,
    iban: string,
    bic: string,
    amount: number,         // Member's share € amount
    posten: Array,          // Transactions included
  }
}
```

---

## 8. Interactions & State Management

### Navigation Flow
```
Login
  ↓
Dashboard (Members page)
  ├─ [Navigation Tabs] → Switch between pages (Members, Products, Journal, Settlements, Stats)
  ├─ [Actions] → Open modals (Create, Edit, Delete)
  ├─ [Modals] → Form submission or cancel
  └─ [Logout] → Back to Login
```

### Member Page Actions
```
List → View Posten (PostenModal)
    → Edit (UserModal)
    → Delete (DeleteModal)
    → Search/Filter
    → Create (UserModal)
```

### Settlement Workflow
```
Export Modal (preview members, total)
  ↓
Create Settlement (confirm)
  ↓
Settlement created, balances reset
  ↓
View Settlement
  ↓
Undo Settlement (restore balances)
```

### State Variables (in AdminDashboard)
```javascript
const [users, setUsers] = useState(MOCK_USERS);
const [transactions, setTransactions] = useState(MOCK_TRANSACTIONS);
const [settlements, setSettlements] = useState(MOCK_SETTLEMENTS);
const [products, setProducts] = useState(MOCK_PRODUCTS);
const [search, setSearch] = useState('');
const [editUser, setEditUser] = useState(null);              // Current editing member
const [isNewUser, setIsNewUser] = useState(false);           // Create vs. edit mode
const [postenUser, setPostenUser] = useState(null);          // For PostenModal
const [deleteUser, setDeleteUser] = useState(null);          // For DeleteModal
const [showExport, setShowExport] = useState(false);         // For ExportModal
const [lastSettlement, setLastSettlement] = useState('...');
const [activePage, setActivePage] = useState('users');       // Current page
```

---

## 9. Key Formatting Functions

```javascript
const formatPrice = (price) => price.toFixed(2).replace('.', ',') + ' €';
// 47.50 → "47,50 €"

const formatIban = (iban) => iban.replace(/(.{4})/g, '$1 ').trim();
// "DE89370400440532013000" → "DE89 3704 0044 0532 0130 00"
```

---

## 10. Locale & Language

**Date Format**: DD.MM.YYYY (German format)
- "15.03.2024"
- "31.12.2025"

**Currency**: € (Euro, comma as decimal separator)
- "47,50 €"
- "245,60 €"

**UI Language**: German
- Mitglieder (Members)
- Produkte (Products)
- Buchungsjournal (Transaction Journal)
- Abrechnungen (Settlements)
- Statistik (Statistics)
- Abmelden (Logout)
- Neues Mitglied (New Member)
- Offene Deckel (Outstanding Balance)
- Letzte Abrechnung (Last Settlement)

---

## 11. Responsive Considerations

**Current Prototype**: Fixed width 1024px viewport

**For Production**, ensure responsiveness:
- Desktop: 1024px+
- Tablet: 768px - 1023px
- Mobile: 375px - 767px

**Breakpoints**:
```
Mobile: < 768px
Tablet: 768px - 1023px
Desktop: 1024px+
```

**Mobile Adaptations Needed**:
- Stack table columns vertically or horizontal scroll
- Modal full width (small margins)
- Reduce padding/spacing
- Single column layouts

---

## 12. Accessibility Notes

**Keyboard Navigation**:
- Tab: Cycle through buttons, inputs, links
- Enter: Activate button, open modal, submit form
- Escape: Close modal
- Space: Toggle checkbox

**Screen Reader**:
- Use semantic HTML (button, input, table, form)
- Label form fields with <label>
- Provide alt text for icons
- Use ARIA attributes (aria-label, aria-hidden) where needed

**Color Contrast**:
- All text meets WCAG AA standards (4.5:1 for small text, 3:1 for large)
- Don't rely on color alone for meaning (use icons + color)

---

## Summary

This prototype provides a complete, working implementation of the admin panel. Key takeaways:

1. **Dark theme** with blue/green/orange accent colors
2. **Clean table-based layouts** for listing data
3. **Modal-based interactions** for CRUD operations
4. **Consistent spacing** and typography throughout
5. **German localization** with appropriate date/currency formats
6. **Simple state management** with React useState hooks
7. **Monospace font** for technical data (RFID, IBAN)
8. **Icon-based actions** (view, edit, delete)
9. **Badge/color coding** for status (balance, transaction type)
10. **Mock data included** for realistic testing

**The React component should replicate this design exactly** for the production admin frontend.
