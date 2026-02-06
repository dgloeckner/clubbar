# Admin Frontend i18n Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add full internationalization (German/English) to the admin frontend with react-i18next.

**Architecture:** Install react-i18next, create translation files, wrap app with I18nextProvider, extract all hardcoded strings, integrate with auth locale, add language tabs for product/category editing.

**Tech Stack:** react-i18next, i18next, TypeScript, React 18

---

## Task 1: Install i18n Dependencies

**Files:**
- Modify: `admin-frontend/package.json`

**Step 1: Install react-i18next and i18next**

Run:
```bash
cd admin-frontend && npm install react-i18next i18next
```

**Step 2: Verify installation**

Run:
```bash
cd admin-frontend && npm ls react-i18next i18next
```

Expected: Both packages listed with versions

**Step 3: Commit**

```bash
git add admin-frontend/package.json admin-frontend/package-lock.json
git commit -m "chore: install react-i18next and i18next dependencies"
```

---

## Task 2: Create German Translation File

**Files:**
- Create: `admin-frontend/public/locales/de.json`

**Step 1: Create locales directory**

Run:
```bash
mkdir -p admin-frontend/public/locales
```

**Step 2: Create German translations**

Create `admin-frontend/public/locales/de.json`:

```json
{
  "nav": {
    "members": "Mitglieder",
    "products": "Produkte",
    "categories": "Kategorien",
    "journal": "Buchungsjournal",
    "settlements": "Abrechnungen",
    "statistics": "Statistik",
    "settings": "Einstellungen",
    "auditLog": "Audit-Log",
    "profile": "Profil",
    "logout": "Abmelden"
  },
  "common": {
    "save": "Speichern",
    "cancel": "Abbrechen",
    "delete": "Löschen",
    "edit": "Bearbeiten",
    "create": "Erstellen",
    "search": "Suchen",
    "filter": "Filter",
    "loading": "Laden...",
    "noResults": "Keine Ergebnisse",
    "confirm": "Bestätigen",
    "yes": "Ja",
    "no": "Nein",
    "close": "Schließen",
    "actions": "Aktionen",
    "status": "Status",
    "active": "Aktiv",
    "inactive": "Inaktiv",
    "all": "Alle",
    "name": "Name",
    "description": "Beschreibung",
    "price": "Preis",
    "category": "Kategorie",
    "date": "Datum",
    "amount": "Betrag",
    "balance": "Kontostand",
    "total": "Gesamt",
    "perPage": "Pro Seite",
    "page": "Seite",
    "of": "von",
    "showing": "Zeige",
    "to": "bis",
    "entries": "Einträge",
    "sortBy": "Sortieren nach",
    "ascending": "Aufsteigend",
    "descending": "Absteigend"
  },
  "auth": {
    "email": "E-Mail",
    "password": "Passwort",
    "login": "Anmelden",
    "loggingIn": "Anmeldung...",
    "loginFailed": "Anmeldung fehlgeschlagen",
    "logout": "Abmelden",
    "logoutConfirm": "Möchten Sie sich wirklich abmelden?"
  },
  "validation": {
    "required": "Pflichtfeld",
    "invalidEmail": "Ungültige E-Mail-Adresse",
    "minLength": "Mindestens {{min}} Zeichen erforderlich",
    "maxLength": "Maximal {{max}} Zeichen erlaubt",
    "passwordMismatch": "Passwörter stimmen nicht überein",
    "invalidIban": "Ungültige IBAN",
    "invalidPrice": "Ungültiger Preis",
    "atLeastOneLanguage": "Mindestens eine Sprache erforderlich"
  },
  "errors": {
    "generic": "Ein Fehler ist aufgetreten",
    "networkError": "Netzwerkfehler - bitte versuchen Sie es erneut",
    "unauthorized": "Nicht autorisiert",
    "notFound": "Nicht gefunden",
    "serverError": "Serverfehler"
  },
  "dates": {
    "today": "Heute",
    "yesterday": "Gestern",
    "never": "Nie"
  },
  "members": {
    "title": "Mitglieder",
    "createMember": "Mitglied erstellen",
    "editMember": "Mitglied bearbeiten",
    "deleteMember": "Mitglied löschen",
    "deleteConfirm": "Möchten Sie \"{{name}}\" wirklich löschen?",
    "firstName": "Vorname",
    "lastName": "Nachname",
    "displayName": "Anzeigename",
    "iban": "IBAN",
    "mandateReference": "Mandatsreferenz",
    "mandateSignedAt": "Mandat unterzeichnet am",
    "preferredLanguage": "Bevorzugte Sprache",
    "lastTransaction": "Letzte Buchung",
    "memberSince": "Mitglied seit",
    "noMembers": "Keine Mitglieder gefunden"
  },
  "products": {
    "title": "Produkte",
    "createProduct": "Produkt erstellen",
    "editProduct": "Produkt bearbeiten",
    "deleteProduct": "Produkt löschen",
    "deactivateProduct": "Produkt deaktivieren",
    "activateProduct": "Produkt aktivieren",
    "deleteConfirm": "Möchten Sie \"{{name}}\" wirklich löschen? Dies kann nicht rückgängig gemacht werden.",
    "deactivateConfirm": "Möchten Sie \"{{name}}\" wirklich deaktivieren? Das Produkt wird im Terminal nicht mehr angezeigt.",
    "productName": "Produktname",
    "icon": "Symbol",
    "selectIcon": "Symbol auswählen",
    "selectCategory": "Kategorie auswählen",
    "noProducts": "Keine Produkte gefunden",
    "allCategories": "Alle Kategorien"
  },
  "categories": {
    "title": "Kategorien",
    "createCategory": "Kategorie erstellen",
    "editCategory": "Kategorie bearbeiten",
    "deleteCategory": "Kategorie löschen",
    "deleteConfirm": "Möchten Sie \"{{name}}\" wirklich löschen?",
    "categoryName": "Kategoriename",
    "sortOrder": "Sortierung",
    "productCount": "Produkte",
    "noCategories": "Keine Kategorien gefunden"
  },
  "journal": {
    "title": "Buchungsjournal",
    "transaction": "Buchung",
    "transactionType": "Buchungsart",
    "member": "Mitglied",
    "product": "Produkt",
    "quantity": "Menge",
    "unitPrice": "Einzelpreis",
    "totalAmount": "Gesamtbetrag",
    "createdAt": "Erstellt am",
    "settledAt": "Abgerechnet am",
    "notSettled": "Nicht abgerechnet",
    "noTransactions": "Keine Buchungen gefunden",
    "types": {
      "purchase": "Kauf",
      "manual_adjustment": "Manuelle Korrektur",
      "settlement": "Abrechnung",
      "reversal": "Storno"
    }
  },
  "settlements": {
    "title": "Abrechnungen",
    "createSettlement": "Abrechnung erstellen",
    "settlementDate": "Abrechnungsdatum",
    "memberCount": "Mitglieder",
    "transactionCount": "Buchungen",
    "totalAmount": "Gesamtbetrag",
    "status": "Status",
    "pending": "Ausstehend",
    "completed": "Abgeschlossen",
    "cancelled": "Storniert",
    "exportSepa": "SEPA-Export",
    "noSettlements": "Keine Abrechnungen gefunden"
  },
  "statistics": {
    "title": "Statistik",
    "overview": "Übersicht",
    "revenue": "Umsatz",
    "transactions": "Buchungen",
    "topProducts": "Top-Produkte",
    "topMembers": "Top-Mitglieder",
    "period": "Zeitraum",
    "thisMonth": "Dieser Monat",
    "lastMonth": "Letzter Monat",
    "thisYear": "Dieses Jahr",
    "custom": "Benutzerdefiniert"
  },
  "settings": {
    "title": "Einstellungen",
    "general": "Allgemein",
    "sepaConfig": "SEPA-Konfiguration",
    "creditorId": "Gläubiger-ID",
    "creditorName": "Gläubiger-Name",
    "creditorIban": "Gläubiger-IBAN",
    "creditorBic": "BIC",
    "paymentReferencePrefix": "Verwendungszweck-Präfix"
  },
  "auditLog": {
    "title": "Audit-Log",
    "action": "Aktion",
    "entity": "Entität",
    "entityId": "Entitäts-ID",
    "adminUser": "Admin-Benutzer",
    "timestamp": "Zeitstempel",
    "details": "Details",
    "noEntries": "Keine Einträge gefunden"
  },
  "profile": {
    "title": "Profil",
    "personalInfo": "Persönliche Daten",
    "changePassword": "Passwort ändern",
    "currentPassword": "Aktuelles Passwort",
    "newPassword": "Neues Passwort",
    "confirmPassword": "Passwort bestätigen",
    "language": "Sprache",
    "languageHint": "Sprache für die Benutzeroberfläche",
    "passwordChanged": "Passwort erfolgreich geändert",
    "profileUpdated": "Profil erfolgreich aktualisiert",
    "saveFailed": "Speichern fehlgeschlagen"
  },
  "languages": {
    "de": "Deutsch",
    "en": "English"
  },
  "confirmDialog": {
    "title": "Bestätigung",
    "areYouSure": "Sind Sie sicher?"
  }
}
```

**Step 3: Commit**

```bash
git add admin-frontend/public/locales/de.json
git commit -m "feat(i18n): add German translation file"
```

---

## Task 3: Create English Translation File

**Files:**
- Create: `admin-frontend/public/locales/en.json`

**Step 1: Create English translations**

Create `admin-frontend/public/locales/en.json`:

```json
{
  "nav": {
    "members": "Members",
    "products": "Products",
    "categories": "Categories",
    "journal": "Journal",
    "settlements": "Settlements",
    "statistics": "Statistics",
    "settings": "Settings",
    "auditLog": "Audit Log",
    "profile": "Profile",
    "logout": "Logout"
  },
  "common": {
    "save": "Save",
    "cancel": "Cancel",
    "delete": "Delete",
    "edit": "Edit",
    "create": "Create",
    "search": "Search",
    "filter": "Filter",
    "loading": "Loading...",
    "noResults": "No results",
    "confirm": "Confirm",
    "yes": "Yes",
    "no": "No",
    "close": "Close",
    "actions": "Actions",
    "status": "Status",
    "active": "Active",
    "inactive": "Inactive",
    "all": "All",
    "name": "Name",
    "description": "Description",
    "price": "Price",
    "category": "Category",
    "date": "Date",
    "amount": "Amount",
    "balance": "Balance",
    "total": "Total",
    "perPage": "Per page",
    "page": "Page",
    "of": "of",
    "showing": "Showing",
    "to": "to",
    "entries": "entries",
    "sortBy": "Sort by",
    "ascending": "Ascending",
    "descending": "Descending"
  },
  "auth": {
    "email": "Email",
    "password": "Password",
    "login": "Login",
    "loggingIn": "Logging in...",
    "loginFailed": "Login failed",
    "logout": "Logout",
    "logoutConfirm": "Are you sure you want to logout?"
  },
  "validation": {
    "required": "Required",
    "invalidEmail": "Invalid email address",
    "minLength": "Minimum {{min}} characters required",
    "maxLength": "Maximum {{max}} characters allowed",
    "passwordMismatch": "Passwords do not match",
    "invalidIban": "Invalid IBAN",
    "invalidPrice": "Invalid price",
    "atLeastOneLanguage": "At least one language required"
  },
  "errors": {
    "generic": "An error occurred",
    "networkError": "Network error - please try again",
    "unauthorized": "Unauthorized",
    "notFound": "Not found",
    "serverError": "Server error"
  },
  "dates": {
    "today": "Today",
    "yesterday": "Yesterday",
    "never": "Never"
  },
  "members": {
    "title": "Members",
    "createMember": "Create Member",
    "editMember": "Edit Member",
    "deleteMember": "Delete Member",
    "deleteConfirm": "Are you sure you want to delete \"{{name}}\"?",
    "firstName": "First Name",
    "lastName": "Last Name",
    "displayName": "Display Name",
    "iban": "IBAN",
    "mandateReference": "Mandate Reference",
    "mandateSignedAt": "Mandate Signed At",
    "preferredLanguage": "Preferred Language",
    "lastTransaction": "Last Transaction",
    "memberSince": "Member Since",
    "noMembers": "No members found"
  },
  "products": {
    "title": "Products",
    "createProduct": "Create Product",
    "editProduct": "Edit Product",
    "deleteProduct": "Delete Product",
    "deactivateProduct": "Deactivate Product",
    "activateProduct": "Activate Product",
    "deleteConfirm": "Are you sure you want to delete \"{{name}}\"? This cannot be undone.",
    "deactivateConfirm": "Are you sure you want to deactivate \"{{name}}\"? The product will no longer be shown in the terminal.",
    "productName": "Product Name",
    "icon": "Icon",
    "selectIcon": "Select Icon",
    "selectCategory": "Select Category",
    "noProducts": "No products found",
    "allCategories": "All Categories"
  },
  "categories": {
    "title": "Categories",
    "createCategory": "Create Category",
    "editCategory": "Edit Category",
    "deleteCategory": "Delete Category",
    "deleteConfirm": "Are you sure you want to delete \"{{name}}\"?",
    "categoryName": "Category Name",
    "sortOrder": "Sort Order",
    "productCount": "Products",
    "noCategories": "No categories found"
  },
  "journal": {
    "title": "Journal",
    "transaction": "Transaction",
    "transactionType": "Transaction Type",
    "member": "Member",
    "product": "Product",
    "quantity": "Quantity",
    "unitPrice": "Unit Price",
    "totalAmount": "Total Amount",
    "createdAt": "Created At",
    "settledAt": "Settled At",
    "notSettled": "Not settled",
    "noTransactions": "No transactions found",
    "types": {
      "purchase": "Purchase",
      "manual_adjustment": "Manual Adjustment",
      "settlement": "Settlement",
      "reversal": "Reversal"
    }
  },
  "settlements": {
    "title": "Settlements",
    "createSettlement": "Create Settlement",
    "settlementDate": "Settlement Date",
    "memberCount": "Members",
    "transactionCount": "Transactions",
    "totalAmount": "Total Amount",
    "status": "Status",
    "pending": "Pending",
    "completed": "Completed",
    "cancelled": "Cancelled",
    "exportSepa": "SEPA Export",
    "noSettlements": "No settlements found"
  },
  "statistics": {
    "title": "Statistics",
    "overview": "Overview",
    "revenue": "Revenue",
    "transactions": "Transactions",
    "topProducts": "Top Products",
    "topMembers": "Top Members",
    "period": "Period",
    "thisMonth": "This Month",
    "lastMonth": "Last Month",
    "thisYear": "This Year",
    "custom": "Custom"
  },
  "settings": {
    "title": "Settings",
    "general": "General",
    "sepaConfig": "SEPA Configuration",
    "creditorId": "Creditor ID",
    "creditorName": "Creditor Name",
    "creditorIban": "Creditor IBAN",
    "creditorBic": "BIC",
    "paymentReferencePrefix": "Payment Reference Prefix"
  },
  "auditLog": {
    "title": "Audit Log",
    "action": "Action",
    "entity": "Entity",
    "entityId": "Entity ID",
    "adminUser": "Admin User",
    "timestamp": "Timestamp",
    "details": "Details",
    "noEntries": "No entries found"
  },
  "profile": {
    "title": "Profile",
    "personalInfo": "Personal Information",
    "changePassword": "Change Password",
    "currentPassword": "Current Password",
    "newPassword": "New Password",
    "confirmPassword": "Confirm Password",
    "language": "Language",
    "languageHint": "Language for the user interface",
    "passwordChanged": "Password changed successfully",
    "profileUpdated": "Profile updated successfully",
    "saveFailed": "Save failed"
  },
  "languages": {
    "de": "Deutsch",
    "en": "English"
  },
  "confirmDialog": {
    "title": "Confirmation",
    "areYouSure": "Are you sure?"
  }
}
```

**Step 2: Commit**

```bash
git add admin-frontend/public/locales/en.json
git commit -m "feat(i18n): add English translation file"
```

---

## Task 4: Create i18n Configuration

**Files:**
- Create: `admin-frontend/src/i18n/config.ts`

**Step 1: Create i18n directory**

Run:
```bash
mkdir -p admin-frontend/src/i18n
```

**Step 2: Create i18n configuration**

Create `admin-frontend/src/i18n/config.ts`:

```typescript
import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

// Import translations directly for bundling
import de from '../../public/locales/de.json';
import en from '../../public/locales/en.json';

const LOCALE_STORAGE_KEY = 'adminLocale';

// Get initial language from localStorage or default to German
function getInitialLanguage(): string {
  const stored = localStorage.getItem(LOCALE_STORAGE_KEY);
  if (stored && ['de', 'en'].includes(stored)) {
    return stored;
  }
  return 'de';
}

i18n
  .use(initReactI18next)
  .init({
    resources: {
      de: { translation: de },
      en: { translation: en },
    },
    lng: getInitialLanguage(),
    fallbackLng: 'de',
    interpolation: {
      escapeValue: false, // React already escapes values
    },
    react: {
      useSuspense: false, // Avoid suspense for simpler setup
    },
  });

// Helper to change language and persist to localStorage
export function changeLanguage(lang: string): void {
  if (['de', 'en'].includes(lang)) {
    i18n.changeLanguage(lang);
    localStorage.setItem(LOCALE_STORAGE_KEY, lang);
  }
}

// Helper to get current language
export function getCurrentLanguage(): string {
  return i18n.language || 'de';
}

// Export the configured instance
export default i18n;
```

**Step 3: Commit**

```bash
git add admin-frontend/src/i18n/config.ts
git commit -m "feat(i18n): add i18n configuration with react-i18next"
```

---

## Task 5: Initialize i18n in App Entry Point

**Files:**
- Modify: `admin-frontend/src/main.tsx`

**Step 1: Read current main.tsx**

Read the file to understand current structure.

**Step 2: Add i18n import**

Add at the top of `admin-frontend/src/main.tsx` (after other imports):

```typescript
// Initialize i18n before React renders
import './i18n/config';
```

This import must be before the React render call to ensure translations are ready.

**Step 3: Verify the app still runs**

Run:
```bash
cd admin-frontend && npm run dev
```

Expected: App starts without errors, check browser console for no i18n warnings.

**Step 4: Commit**

```bash
git add admin-frontend/src/main.tsx
git commit -m "feat(i18n): initialize i18n in app entry point"
```

---

## Task 6: Create i18n Helper Utilities

**Files:**
- Create: `admin-frontend/src/utils/i18n-helpers.ts`

**Step 1: Create helper utilities**

Create `admin-frontend/src/utils/i18n-helpers.ts`:

```typescript
/**
 * Get localized name from a multilingual names object.
 * Falls back through: requested locale -> 'de' -> 'en' -> first available -> empty string
 */
export function getLocalizedName(
  names: Record<string, string> | undefined | null,
  locale: string
): string {
  if (!names) return '';

  // Try requested locale first
  if (names[locale]) return names[locale];

  // Fallback chain: de -> en -> first available
  if (names['de']) return names['de'];
  if (names['en']) return names['en'];

  // Return first available value
  const values = Object.values(names);
  return values.length > 0 ? values[0] : '';
}

/**
 * Check if a multilingual names object has at least one non-empty value
 */
export function hasAnyName(names: Record<string, string> | undefined | null): boolean {
  if (!names) return false;
  return Object.values(names).some(v => v && v.trim().length > 0);
}

/**
 * Get the locale string for Intl APIs (de -> de-DE, en -> en-GB)
 */
export function getIntlLocale(lang: string): string {
  switch (lang) {
    case 'de':
      return 'de-DE';
    case 'en':
      return 'en-GB';
    default:
      return 'de-DE';
  }
}
```

**Step 2: Commit**

```bash
git add admin-frontend/src/utils/i18n-helpers.ts
git commit -m "feat(i18n): add i18n helper utilities"
```

---

## Task 7: Create useFormatters Hook

**Files:**
- Create: `admin-frontend/src/hooks/useFormatters.ts`

**Step 1: Create the hook**

Create `admin-frontend/src/hooks/useFormatters.ts`:

```typescript
import { useTranslation } from 'react-i18next';
import { formatPrice, formatDate, formatDateTime } from '../styles/design-system';
import { getIntlLocale } from '../utils/i18n-helpers';

/**
 * Hook that provides locale-aware formatting functions.
 * Uses the current i18n language for all formatting.
 */
export function useFormatters() {
  const { i18n, t } = useTranslation();
  const intlLocale = getIntlLocale(i18n.language);

  return {
    /**
     * Format a price in cents to currency string
     */
    formatPrice: (cents: number) => formatPrice(cents, intlLocale),

    /**
     * Format a date string to localized date
     */
    formatDate: (date: string) => formatDate(date, intlLocale),

    /**
     * Format a date string to localized date with time
     */
    formatDateTime: (date: string) => formatDateTime(date, intlLocale),

    /**
     * Format a date with relative labels (Today, Yesterday, or full date)
     */
    formatRelativeDate: (dateString: string) => {
      if (!dateString) return t('dates.never');

      const today = new Date();
      today.setHours(0, 0, 0, 0);

      const yesterday = new Date(today);
      yesterday.setDate(yesterday.getDate() - 1);

      const date = new Date(dateString);
      date.setHours(0, 0, 0, 0);

      if (date.getTime() === today.getTime()) {
        return t('dates.today');
      }
      if (date.getTime() === yesterday.getTime()) {
        return t('dates.yesterday');
      }

      return formatDate(dateString, intlLocale);
    },

    /**
     * Get the current Intl locale string (e.g., 'de-DE', 'en-GB')
     */
    intlLocale,

    /**
     * Get the current i18n language code (e.g., 'de', 'en')
     */
    language: i18n.language,
  };
}
```

**Step 2: Commit**

```bash
git add admin-frontend/src/hooks/useFormatters.ts
git commit -m "feat(i18n): add useFormatters hook for locale-aware formatting"
```

---

## Task 8: Translate MainLayout Navigation

**Files:**
- Modify: `admin-frontend/src/components/layout/MainLayout.tsx`

**Step 1: Read current MainLayout**

Read the file to find the navItems array.

**Step 2: Add useTranslation import**

Add import at top of file:

```typescript
import { useTranslation } from 'react-i18next';
```

**Step 3: Update component to use translations**

Inside the `MainLayout` component function, add:

```typescript
const { t } = useTranslation();
```

**Step 4: Replace hardcoded navItems labels**

Replace the navItems array with:

```typescript
const navItems = [
  { label: t('nav.members'), path: '/members', icon: <UsersIcon size={20} /> },
  { label: t('nav.products'), path: '/products', icon: <PackageIcon size={20} /> },
  { label: t('nav.categories'), path: '/categories', icon: <NavigationIconRegistry.CategoryIcon size={20} /> },
  { label: t('nav.journal'), path: '/journal', icon: <BookIcon size={20} /> },
  { label: t('nav.settlements'), path: '/settlements', icon: <ReceiptIcon size={20} /> },
  { label: t('nav.statistics'), path: '/statistics', icon: <ChartIcon size={20} /> },
  { label: t('nav.settings'), path: '/settings', icon: <SettingsIcon size={20} /> },
  { label: t('nav.auditLog'), path: '/audit-log', icon: <AuditLogIcon size={20} />, testId: 'nav-audit-log' },
];
```

**Step 5: Translate logout button**

Find the logout button and replace its text:

```typescript
{t('nav.logout')}
```

**Step 6: Verify in browser**

Run the app and verify navigation labels appear in German. Change localStorage `adminLocale` to `en` and refresh to verify English.

**Step 7: Commit**

```bash
git add admin-frontend/src/components/layout/MainLayout.tsx
git commit -m "feat(i18n): translate MainLayout navigation labels"
```

---

## Task 9: Translate LoginForm

**Files:**
- Modify: `admin-frontend/src/components/forms/LoginForm.tsx`

**Step 1: Read current LoginForm**

**Step 2: Add useTranslation import and hook**

```typescript
import { useTranslation } from 'react-i18next';

// Inside component:
const { t } = useTranslation();
```

**Step 3: Replace hardcoded strings**

Replace all hardcoded labels:
- `"Email"` → `{t('auth.email')}`
- `"Password"` → `{t('auth.password')}`
- `"Login"` → `{t('auth.login')}`
- `"Logging in..."` → `{t('auth.loggingIn')}`
- Validation errors like `"Email is required"` → `{t('validation.required')}`

**Step 4: Commit**

```bash
git add admin-frontend/src/components/forms/LoginForm.tsx
git commit -m "feat(i18n): translate LoginForm labels and validation"
```

---

## Task 10: Translate ProductsPage

**Files:**
- Modify: `admin-frontend/src/pages/ProductsPage.tsx`

**Step 1: Read current ProductsPage**

**Step 2: Add imports**

```typescript
import { useTranslation } from 'react-i18next';
import { getLocalizedName } from '../utils/i18n-helpers';
import { useFormatters } from '../hooks/useFormatters';
```

**Step 3: Use hooks inside component**

```typescript
const { t, i18n } = useTranslation();
const { formatPrice } = useFormatters();
```

**Step 4: Replace hardcoded strings**

- Page title: `t('products.title')`
- Create button: `t('products.createProduct')`
- Edit modal title: `t('products.editProduct')`
- Delete confirmation: `t('products.deleteConfirm', { name: productName })`
- Form labels: `t('products.productName')`, `t('common.price')`, `t('common.category')`, etc.
- Status filter: `t('common.all')`, `t('common.active')`, `t('common.inactive')`
- Table headers: `t('common.name')`, `t('common.price')`, `t('common.category')`, etc.

**Step 5: Update product name display**

Replace:
```typescript
product.names.de || product.names.en || 'Unnamed Product'
```

With:
```typescript
getLocalizedName(product.names, i18n.language)
```

**Step 6: Commit**

```bash
git add admin-frontend/src/pages/ProductsPage.tsx
git commit -m "feat(i18n): translate ProductsPage"
```

---

## Task 11: Translate CategoriesPage

**Files:**
- Modify: `admin-frontend/src/pages/CategoriesPage.tsx`

**Step 1: Add imports and hooks**

Same pattern as ProductsPage.

**Step 2: Replace hardcoded strings**

- Page title, create/edit buttons, form labels, delete confirmation
- Table headers and empty state

**Step 3: Update category name display**

Use `getLocalizedName(category.names, i18n.language)` throughout.

**Step 4: Commit**

```bash
git add admin-frontend/src/pages/CategoriesPage.tsx
git commit -m "feat(i18n): translate CategoriesPage"
```

---

## Task 12: Translate MembersPage

**Files:**
- Modify: `admin-frontend/src/pages/MembersPage.tsx`

**Step 1: Add imports and hooks**

**Step 2: Replace hardcoded strings**

- Page title, CRUD buttons, form labels
- Table headers, empty state, validation messages

**Step 3: Use useFormatters for dates**

Replace direct `formatDate` calls with `formatters.formatDate`.

**Step 4: Commit**

```bash
git add admin-frontend/src/pages/MembersPage.tsx
git commit -m "feat(i18n): translate MembersPage"
```

---

## Task 13: Translate JournalPage

**Files:**
- Modify: `admin-frontend/src/pages/JournalPage.tsx`

**Step 1: Add imports and hooks**

**Step 2: Replace hardcoded strings**

- Page title, filters, table headers
- Transaction type labels: `t('journal.types.purchase')`, etc.
- Date formatting with `useFormatters`

**Step 3: Commit**

```bash
git add admin-frontend/src/pages/JournalPage.tsx
git commit -m "feat(i18n): translate JournalPage"
```

---

## Task 14: Translate SettlementsPage

**Files:**
- Modify: `admin-frontend/src/pages/SettlementsPage.tsx`

**Step 1: Add imports and hooks**

**Step 2: Replace hardcoded strings**

- Page title, create button, table headers
- Status labels, SEPA export button
- Date and price formatting with `useFormatters`

**Step 3: Commit**

```bash
git add admin-frontend/src/pages/SettlementsPage.tsx
git commit -m "feat(i18n): translate SettlementsPage"
```

---

## Task 15: Translate StatisticsPage

**Files:**
- Modify: `admin-frontend/src/pages/StatisticsPage.tsx`

**Step 1: Add imports and hooks**

**Step 2: Replace hardcoded strings**

- Page title, section headers
- Period labels, chart labels
- Price and date formatting

**Step 3: Commit**

```bash
git add admin-frontend/src/pages/StatisticsPage.tsx
git commit -m "feat(i18n): translate StatisticsPage"
```

---

## Task 16: Translate SettingsPage

**Files:**
- Modify: `admin-frontend/src/pages/SettingsPage.tsx`

**Step 1: Add imports and hooks**

**Step 2: Replace hardcoded strings**

- Page title, tab labels
- SEPA configuration labels
- Save/cancel buttons

**Step 3: Commit**

```bash
git add admin-frontend/src/pages/SettingsPage.tsx
git commit -m "feat(i18n): translate SettingsPage"
```

---

## Task 17: Translate AuditLogPage

**Files:**
- Modify: `admin-frontend/src/pages/AuditLogPage.tsx`

**Step 1: Add imports and hooks**

**Step 2: Replace hardcoded strings**

- Page title, table headers
- Empty state, date formatting

**Step 3: Commit**

```bash
git add admin-frontend/src/pages/AuditLogPage.tsx
git commit -m "feat(i18n): translate AuditLogPage"
```

---

## Task 18: Update ProfilePage with Language Selector

**Files:**
- Modify: `admin-frontend/src/pages/ProfilePage.tsx`

**Step 1: Add i18n imports**

```typescript
import { useTranslation } from 'react-i18next';
import { changeLanguage } from '../i18n/config';
```

**Step 2: Add translation hook**

```typescript
const { t, i18n } = useTranslation();
```

**Step 3: Replace hardcoded labels**

Translate all form labels, section headers, and buttons.

**Step 4: Connect language selector to i18n**

When language changes:
1. Call `changeLanguage(newLocale)` to update i18n and localStorage
2. Call the existing `updateProfile` API to persist to backend

```typescript
const handleLanguageChange = async (newLocale: string) => {
  changeLanguage(newLocale);
  setLocale(newLocale);
  try {
    await updateProfile({ locale: newLocale });
  } catch (error) {
    // Language already changed in UI, just log error
    console.error('Failed to save language preference:', error);
  }
};
```

**Step 5: Commit**

```bash
git add admin-frontend/src/pages/ProfilePage.tsx
git commit -m "feat(i18n): translate ProfilePage and connect language selector to i18n"
```

---

## Task 19: Sync Language on Login

**Files:**
- Modify: `admin-frontend/src/services/auth.ts`

**Step 1: Read current auth.ts**

**Step 2: Import changeLanguage**

```typescript
import { changeLanguage } from '../i18n/config';
```

**Step 3: Update login function**

After successful login, sync i18n language:

```typescript
// In the login function, after storing to localStorage:
const userLocale = response.data.admin?.locale || 'de';
changeLanguage(userLocale);
```

**Step 4: Commit**

```bash
git add admin-frontend/src/services/auth.ts
git commit -m "feat(i18n): sync i18n language on login"
```

---

## Task 20: Create LanguageTabsInput Component

**Files:**
- Create: `admin-frontend/src/components/forms/LanguageTabsInput.tsx`

**Step 1: Create the component**

Create `admin-frontend/src/components/forms/LanguageTabsInput.tsx`:

```typescript
import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';

interface LanguageTabsInputProps {
  values: { de: string; en: string };
  onChange: (values: { de: string; en: string }) => void;
  label: string;
  placeholder?: string;
  required?: boolean;
  multiline?: boolean;
  testIdPrefix?: string;
}

const LANGUAGES = ['de', 'en'] as const;

export function LanguageTabsInput({
  values,
  onChange,
  label,
  placeholder,
  required = false,
  multiline = false,
  testIdPrefix = 'lang-input',
}: LanguageTabsInputProps) {
  const { t } = useTranslation();
  const [activeTab, setActiveTab] = useState<'de' | 'en'>('de');

  const handleChange = (lang: 'de' | 'en', value: string) => {
    onChange({ ...values, [lang]: value });
  };

  const hasContent = (lang: 'de' | 'en') => values[lang]?.trim().length > 0;

  return (
    <div data-testid={`${testIdPrefix}-container`}>
      <label style={{ display: 'block', marginBottom: '4px', fontWeight: 500 }}>
        {label}
        {required && <span style={{ color: 'var(--color-danger)' }}> *</span>}
      </label>

      {/* Language Tabs */}
      <div style={{ display: 'flex', gap: '4px', marginBottom: '8px' }}>
        {LANGUAGES.map((lang) => (
          <button
            key={lang}
            type="button"
            onClick={() => setActiveTab(lang)}
            data-testid={`${testIdPrefix}-tab-${lang}`}
            style={{
              padding: '4px 12px',
              border: '1px solid var(--color-border)',
              borderRadius: '4px',
              background: activeTab === lang ? 'var(--color-primary)' : 'var(--color-bg-secondary)',
              color: activeTab === lang ? 'white' : 'var(--color-text-primary)',
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              gap: '4px',
            }}
          >
            {t(`languages.${lang}`)}
            {hasContent(lang) && (
              <span
                style={{
                  width: '6px',
                  height: '6px',
                  borderRadius: '50%',
                  background: activeTab === lang ? 'white' : 'var(--color-success)',
                }}
              />
            )}
          </button>
        ))}
      </div>

      {/* Input Field */}
      {multiline ? (
        <textarea
          value={values[activeTab]}
          onChange={(e) => handleChange(activeTab, e.target.value)}
          placeholder={placeholder}
          data-testid={`${testIdPrefix}-input-${activeTab}`}
          style={{
            width: '100%',
            minHeight: '80px',
            padding: '8px 12px',
            border: '1px solid var(--color-border)',
            borderRadius: '4px',
            background: 'var(--color-bg-input)',
            color: 'var(--color-text-primary)',
            resize: 'vertical',
          }}
        />
      ) : (
        <input
          type="text"
          value={values[activeTab]}
          onChange={(e) => handleChange(activeTab, e.target.value)}
          placeholder={placeholder}
          data-testid={`${testIdPrefix}-input-${activeTab}`}
          style={{
            width: '100%',
            padding: '8px 12px',
            border: '1px solid var(--color-border)',
            borderRadius: '4px',
            background: 'var(--color-bg-input)',
            color: 'var(--color-text-primary)',
          }}
        />
      )}
    </div>
  );
}
```

**Step 2: Commit**

```bash
git add admin-frontend/src/components/forms/LanguageTabsInput.tsx
git commit -m "feat(i18n): add LanguageTabsInput component for multilingual editing"
```

---

## Task 21: Update ProductsPage Form with Language Tabs

**Files:**
- Modify: `admin-frontend/src/pages/ProductsPage.tsx`

**Step 1: Import LanguageTabsInput**

```typescript
import { LanguageTabsInput } from '../components/forms/LanguageTabsInput';
import { hasAnyName } from '../utils/i18n-helpers';
```

**Step 2: Update form state**

Change product form state from single name to multilingual:

```typescript
const [formData, setFormData] = useState({
  names: { de: '', en: '' },
  descriptions: { de: '', en: '' },
  price: '',
  categoryId: '',
  // ... other fields
});
```

**Step 3: Replace name input with LanguageTabsInput**

```typescript
<LanguageTabsInput
  values={formData.names}
  onChange={(names) => setFormData({ ...formData, names })}
  label={t('products.productName')}
  required
  testIdPrefix="products-form-name"
/>
```

**Step 4: Update validation**

```typescript
if (!hasAnyName(formData.names)) {
  errors.push(t('validation.atLeastOneLanguage'));
}
```

**Step 5: Update form submission**

Ensure the API call sends the full `names` object.

**Step 6: Update edit mode**

When editing, load existing product names into form:

```typescript
setFormData({
  names: {
    de: product.names.de || '',
    en: product.names.en || ''
  },
  // ...
});
```

**Step 7: Commit**

```bash
git add admin-frontend/src/pages/ProductsPage.tsx
git commit -m "feat(i18n): add language tabs to ProductsPage form"
```

---

## Task 22: Update CategoriesPage Form with Language Tabs

**Files:**
- Modify: `admin-frontend/src/pages/CategoriesPage.tsx`

**Step 1: Import LanguageTabsInput**

**Step 2: Update form state and replace name input**

Same pattern as ProductsPage.

**Step 3: Update validation and submission**

**Step 4: Commit**

```bash
git add admin-frontend/src/pages/CategoriesPage.tsx
git commit -m "feat(i18n): add language tabs to CategoriesPage form"
```

---

## Task 23: Write E2E Test for Language Switching

**Files:**
- Create: `e2etests/tests/admin/i18n-language-switch.spec.ts`

**Step 1: Create test file**

```typescript
import { test, expect } from '@playwright/test';

test.describe('i18n Language Switching', () => {
  test.beforeEach(async ({ page }) => {
    // Login as admin
    await page.goto('/login');
    await page.fill('[data-testid="login-email-input"]', 'admin@example.com');
    await page.fill('[data-testid="login-password-input"]', 'password');
    await page.click('[data-testid="login-submit-button"]');
    await page.waitForURL('/members');
  });

  test('should display navigation in German by default', async ({ page }) => {
    await expect(page.getByTestId('nav-members')).toContainText('Mitglieder');
    await expect(page.getByTestId('nav-products')).toContainText('Produkte');
  });

  test('should switch to English when language changed in profile', async ({ page }) => {
    // Go to profile
    await page.click('[data-testid="header-user-badge"]');
    await page.waitForURL('/profile');

    // Change language to English
    await page.selectOption('[data-testid="profile-locale"]', 'en');

    // Wait for save
    await page.waitForResponse(resp => resp.url().includes('/api/admin/profile'));

    // Verify navigation changed to English
    await page.goto('/members');
    await expect(page.getByTestId('nav-members')).toContainText('Members');
    await expect(page.getByTestId('nav-products')).toContainText('Products');
  });

  test('should persist language after page refresh', async ({ page }) => {
    // Go to profile and switch to English
    await page.click('[data-testid="header-user-badge"]');
    await page.selectOption('[data-testid="profile-locale"]', 'en');
    await page.waitForResponse(resp => resp.url().includes('/api/admin/profile'));

    // Refresh page
    await page.reload();

    // Verify still in English
    await page.goto('/products');
    await expect(page.getByTestId('nav-products')).toContainText('Products');
  });
});
```

**Step 2: Run the test**

```bash
cd e2etests && npm test -- tests/admin/i18n-language-switch.spec.ts --workers=1
```

**Step 3: Commit**

```bash
git add e2etests/tests/admin/i18n-language-switch.spec.ts
git commit -m "test(i18n): add E2E tests for language switching"
```

---

## Task 24: Write E2E Test for Multilingual Product Editing

**Files:**
- Create: `e2etests/tests/admin/i18n-product-translations.spec.ts`

**Step 1: Create test file**

```typescript
import { test, expect } from '@playwright/test';

test.describe('i18n Product Translations', () => {
  test.beforeEach(async ({ page }) => {
    // Login
    await page.goto('/login');
    await page.fill('[data-testid="login-email-input"]', 'admin@example.com');
    await page.fill('[data-testid="login-password-input"]', 'password');
    await page.click('[data-testid="login-submit-button"]');
    await page.waitForURL('/members');
  });

  test('should create product with German and English names', async ({ page }) => {
    await page.goto('/products');

    // Open create modal
    await page.click('[data-testid="products-create-button"]');

    // Enter German name
    await page.click('[data-testid="products-form-name-tab-de"]');
    await page.fill('[data-testid="products-form-name-input-de"]', 'Weizenbier');

    // Enter English name
    await page.click('[data-testid="products-form-name-tab-en"]');
    await page.fill('[data-testid="products-form-name-input-en"]', 'Wheat Beer');

    // Fill other required fields and save
    // ...

    // Verify product appears with German name (default locale)
    await expect(page.locator('text=Weizenbier')).toBeVisible();
  });

  test('should display product name in admin locale', async ({ page }) => {
    // First switch to English
    await page.click('[data-testid="header-user-badge"]');
    await page.selectOption('[data-testid="profile-locale"]', 'en');
    await page.waitForResponse(resp => resp.url().includes('/api/admin/profile'));

    // Go to products
    await page.goto('/products');

    // Verify product shows English name
    await expect(page.locator('text=Wheat Beer')).toBeVisible();
  });
});
```

**Step 2: Run the test**

```bash
cd e2etests && npm test -- tests/admin/i18n-product-translations.spec.ts --workers=1
```

**Step 3: Commit**

```bash
git add e2etests/tests/admin/i18n-product-translations.spec.ts
git commit -m "test(i18n): add E2E tests for multilingual product editing"
```

---

## Task 25: Final Verification and Cleanup

**Step 1: Run full E2E test suite**

```bash
cd e2etests && npm test -- --workers=4
```

Verify all tests pass.

**Step 2: Manual verification checklist**

- [ ] Login page displays in German by default
- [ ] Navigation shows German labels
- [ ] All page titles translated
- [ ] Date formats match locale (DD.MM.YYYY for German, DD/MM/YYYY for English)
- [ ] Price formats match locale (1.234,56 € for German, €1,234.56 for English)
- [ ] Language switch in profile works immediately
- [ ] Language persists after refresh
- [ ] Language persists after re-login
- [ ] Product form has DE/EN tabs
- [ ] Category form has DE/EN tabs
- [ ] Products display in admin's language

**Step 3: Final commit**

```bash
git add -A
git commit -m "feat(i18n): complete admin frontend internationalization

- Added react-i18next with German and English translations
- Translated all pages and components
- Added useFormatters hook for locale-aware formatting
- Integrated language sync with auth
- Added language selector in profile
- Added LanguageTabsInput for multilingual product/category editing
- Added E2E tests for language switching"
```

---

## Summary

| Phase | Tasks | Description |
|-------|-------|-------------|
| 1 | 1-7 | Core i18n setup (library, config, translation files, utilities) |
| 2 | 8-17 | Translate all pages (navigation, login, all feature pages) |
| 3 | 18-19 | Auth integration (profile selector, login sync) |
| 4 | 20-22 | Multi-language editing (LanguageTabsInput, product/category forms) |
| 5 | 23-25 | Testing and verification |

Total: 25 tasks, ~50-75 minutes of implementation time
