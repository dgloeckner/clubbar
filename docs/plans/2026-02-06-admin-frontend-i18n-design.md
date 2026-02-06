# Admin Frontend Internationalization (i18n) Design

**Date:** 2026-02-06
**Status:** Approved
**Scope:** Full internationalization of admin-frontend with German/English support

---

## Overview

Implement comprehensive internationalization for the admin frontend supporting German (de) and English (en). This includes UI labels, date/time/currency formatting, and multi-language editing for domain objects (products, categories).

## Design Decisions

| Decision | Choice |
|----------|--------|
| i18n Library | react-i18next |
| Translation file structure | Single file per language |
| Language sync | Auth-driven with localStorage fallback |
| Language switcher location | Profile/Settings page only |
| Domain object display | Admin locale first, fallback chain (de → en → first available) |
| Domain object editing | Language tabs for DE/EN translations |
| Default language | German (de) for new users |

---

## 1. Core i18n Setup

### 1.1 Dependencies

```bash
npm install react-i18next i18next
```

### 1.2 File Structure

```
admin-frontend/
├── public/
│   └── locales/
│       ├── de.json          # German translations
│       └── en.json          # English translations
└── src/
    └── i18n/
        └── config.ts        # i18n initialization
```

### 1.3 Configuration (`src/i18n/config.ts`)

Initialize i18next with:
- react-i18next plugin integration
- Resources loaded from `public/locales/*.json`
- Default/fallback language: German (`de`)
- Initial language from localStorage key `adminLocale`, fallback to `de`
- Interpolation escape disabled (React handles XSS)

### 1.4 App Integration

- Import and initialize i18n in `main.tsx` before React renders
- i18n ready before first render to prevent flash of wrong language

### 1.5 Language Sync with Auth

On successful login:
1. Read `auth.locale` from response
2. Call `i18n.changeLanguage(locale)`
3. Store in localStorage (`adminLocale`)

On app startup:
- Use localStorage value before auth check completes
- Prevents UI flash in wrong language

---

## 2. Translation File Structure

### 2.1 Key Organization

Nested keys organized by feature/domain:

```json
{
  "nav": {
    "members": "...",
    "products": "...",
    "categories": "...",
    "journal": "...",
    "settlements": "...",
    "statistics": "...",
    "settings": "...",
    "auditLog": "...",
    "profile": "...",
    "logout": "..."
  },
  "common": {
    "save": "...",
    "cancel": "...",
    "delete": "...",
    "edit": "...",
    "create": "...",
    "search": "...",
    "filter": "...",
    "loading": "...",
    "noResults": "...",
    "confirm": "...",
    "yes": "...",
    "no": "..."
  },
  "auth": {
    "email": "...",
    "password": "...",
    "login": "...",
    "loggingIn": "...",
    "loginFailed": "..."
  },
  "dates": {
    "today": "...",
    "yesterday": "...",
    "never": "..."
  },
  "validation": {
    "required": "...",
    "invalidEmail": "...",
    "minLength": "..."
  },
  "errors": {
    "generic": "...",
    "networkError": "...",
    "unauthorized": "..."
  },
  "members": { ... },
  "products": { ... },
  "categories": { ... },
  "journal": { ... },
  "settlements": { ... },
  "statistics": { ... },
  "settings": { ... }
}
```

### 2.2 Interpolation

Use i18next interpolation for dynamic content:
```json
{
  "deleteConfirm": "\"{{name}}\" wirklich löschen?",
  "itemCount": "{{count}} Einträge",
  "welcome": "Willkommen, {{username}}!"
}
```

### 2.3 Sample Keys (German)

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
    "no": "Nein"
  },
  "auth": {
    "email": "E-Mail",
    "password": "Passwort",
    "login": "Anmelden",
    "loggingIn": "Anmeldung...",
    "loginFailed": "Anmeldung fehlgeschlagen"
  },
  "dates": {
    "today": "Heute",
    "yesterday": "Gestern",
    "never": "Nie"
  }
}
```

---

## 3. Component Integration

### 3.1 useTranslation Hook

Components use react-i18next's `useTranslation` hook:

```typescript
import { useTranslation } from 'react-i18next';

function ProductsPage() {
  const { t } = useTranslation();

  return (
    <>
      <PageHeader title={t('products.title')} />
      <Button>{t('common.create')}</Button>
    </>
  );
}
```

### 3.2 useFormatters Hook

Create a hook that provides locale-aware formatting functions:

```typescript
// src/hooks/useFormatters.ts
import { useTranslation } from 'react-i18next';
import { formatPrice, formatDate, formatDateTime } from '../utils/design-system';

export function useFormatters() {
  const { i18n, t } = useTranslation();
  const locale = i18n.language === 'de' ? 'de-DE' : 'en-GB';

  return {
    formatPrice: (cents: number) => formatPrice(cents, locale),
    formatDate: (date: string) => formatDate(date, locale),
    formatDateTime: (date: string) => formatDateTime(date, locale),
    formatRelativeDate: (date: string) => {
      const today = new Date().toISOString().split('T')[0];
      const yesterday = new Date(Date.now() - 86400000).toISOString().split('T')[0];
      const dateOnly = date.split('T')[0];

      if (dateOnly === today) return t('dates.today');
      if (dateOnly === yesterday) return t('dates.yesterday');
      return formatDate(date, locale);
    }
  };
}
```

### 3.3 Domain Object Display Helper

Utility to get localized name with fallback chain:

```typescript
// src/utils/i18n-helpers.ts
export function getLocalizedName(
  names: Record<string, string> | undefined,
  locale: string
): string {
  if (!names) return '';
  return names[locale] || names['de'] || names['en'] || Object.values(names)[0] || '';
}
```

Usage in components:
```typescript
const { i18n } = useTranslation();
const productName = getLocalizedName(product.names, i18n.language);
```

---

## 4. Language Preference UI & Persistence

### 4.1 Profile Page Language Selector

Add language selection to Profile page:
- Dropdown with options: "Deutsch" (de), "English" (en)
- Current selection reflects `i18n.language`
- Changes apply immediately

### 4.2 Change Flow

When user selects a new language:

1. `i18n.changeLanguage(newLocale)` - UI updates immediately
2. `localStorage.setItem('adminLocale', newLocale)` - persist locally
3. `PATCH /api/admin/profile` with `{ locale: newLocale }` - persist to backend
4. Update auth context state
5. Show success toast (in new language)

### 4.3 Error Handling

If backend save fails:
- Keep UI in new language (better UX)
- Show error toast
- Next login restores backend value (acceptable)

### 4.4 Backend API

Endpoint: `PATCH /api/admin/profile`

Request:
```json
{ "locale": "en" }
```

Response: Updated admin user object with new locale.

---

## 5. Multi-Language Editing

### 5.1 Language Tabs Component

Create reusable `LanguageTabsInput` component:

```
[DE] [EN]
┌─────────────────────────────┐
│ Name: [___________________] │
│ Description: [____________] │
└─────────────────────────────┘
```

Features:
- Tabs switch between DE and EN inputs
- Visual indicator (dot) shows which languages have content
- Reusable for Products and Categories

### 5.2 Form State Structure

```typescript
interface ProductFormData {
  names: { de: string; en: string };
  descriptions: { de: string; en: string };
  price: number;
  categoryId: string;
  status: 'active' | 'inactive';
}

interface CategoryFormData {
  names: { de: string; en: string };
  sortOrder: number;
}
```

### 5.3 Validation Rules

- At least one language must have a name
- Description optional in all languages
- Empty strings for unused languages (not undefined)

### 5.4 Existing Data Handling

When editing:
- Load all existing translations into form
- Missing languages show empty fields
- Save sends complete `names`/`descriptions` objects

---

## 6. Implementation Phases

### Phase 1: Core Setup
- [ ] Install react-i18next and i18next
- [ ] Create `src/i18n/config.ts`
- [ ] Create `public/locales/de.json` with all keys
- [ ] Create `public/locales/en.json` with all keys
- [ ] Add i18n initialization to `main.tsx`
- [ ] Create `useFormatters` hook
- [ ] Create `getLocalizedName` utility

### Phase 2: Extract Hardcoded Strings
- [ ] MainLayout navigation labels
- [ ] LoginForm labels and errors
- [ ] Common buttons (save, cancel, delete, edit, create)
- [ ] Page titles and headers (all pages)
- [ ] Table column headers
- [ ] Modal titles and confirmation messages
- [ ] Validation and error messages
- [ ] Status labels (active/inactive)
- [ ] Empty state messages

### Phase 3: Auth Integration
- [ ] Read locale from auth response on login
- [ ] Call `i18n.changeLanguage()` after login
- [ ] Read localStorage on app startup
- [ ] Update auth context type to track locale changes

### Phase 4: Profile Language Selector
- [ ] Add language dropdown to Profile page
- [ ] Implement change handler with i18n switch
- [ ] Add PATCH call to save preference
- [ ] Update localStorage on change
- [ ] Add success/error toast feedback

### Phase 5: Domain Object Localization
- [ ] Update product display to use `getLocalizedName`
- [ ] Update category display to use `getLocalizedName`
- [ ] Create `LanguageTabsInput` component
- [ ] Update ProductForm with language tabs
- [ ] Update CategoryForm with language tabs
- [ ] Update product/category list displays

### Phase 6: Testing
- [ ] E2E: Language switch persists after refresh
- [ ] E2E: UI labels change when language switches
- [ ] E2E: Product names display in admin's language
- [ ] E2E: Multi-language product create/edit

---

## 7. Files to Modify

### New Files
- `src/i18n/config.ts` - i18n initialization
- `src/hooks/useFormatters.ts` - locale-aware formatting
- `src/utils/i18n-helpers.ts` - getLocalizedName utility
- `src/components/forms/LanguageTabsInput.tsx` - multi-language input
- `public/locales/de.json` - German translations
- `public/locales/en.json` - English translations

### Modified Files
- `src/main.tsx` - import i18n config
- `src/components/layout/MainLayout.tsx` - translate nav labels
- `src/components/auth/LoginForm.tsx` - translate form
- `src/pages/*.tsx` - all pages need translation
- `src/components/forms/ProductForm.tsx` - language tabs
- `src/components/forms/CategoryForm.tsx` - language tabs
- `src/pages/ProfilePage.tsx` - language selector
- `src/services/auth.ts` - sync locale on login
- `src/utils/design-system.ts` - update formatRelativeDate

---

## 8. Success Criteria

1. All UI text displays in selected language (de/en)
2. Date, time, currency formats match selected locale
3. Language preference persists across sessions
4. Language preference syncs to backend
5. Products/categories display in admin's language with fallback
6. Products/categories can be edited in multiple languages
7. New admin users default to German
8. E2E tests pass for language switching scenarios
