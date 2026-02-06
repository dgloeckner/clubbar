# Flutter Terminal Frontend - Internationalization (i18n) Implementation Plan

## Overview

This plan adds multi-language support to the Flutter terminal frontend, following the same pattern as the admin frontend (i18next) but adapted for Flutter's localization system.

**Goals:**
- Support German (`de`) and English (`en`) languages
- Use member's `preferredLanguage` to automatically set terminal language
- Maintain consistency with admin frontend's translation keys where applicable
- Follow Flutter's official localization approach

**Two Distinct Concerns:**

1. **Product/Category Names** (multilingual data from backend)
   - Infrastructure EXISTS: `ProductsService.getTranslatedName()` takes language param
   - **PROBLEM**: Code hardcodes `'de'` instead of using member's preference
   - **FIX**: Pass `selectedMember.preferredLanguage` to translation methods

2. **UI Strings** (static text in screens/widgets)
   - ~50 hardcoded strings like "Checkout", "Your cart is empty", "Durstig?"
   - **FIX**: Flutter localization with ARB files

**Current State:**
- Member language preference stored in `MemberDTO.preferredLanguage`
- Product/category names already multilingual (`Map<String, String>`)
- `ProductsService.getTranslatedName()` exists for product names
- **BUG**: `product_selection_screen.dart` hardcodes `'de'` (lines 27, 176)
- ~50 hardcoded UI strings in screens/widgets
- `intl` package already installed

---

## Phase 0: Quick Win - Fix Product/Category Language (No Dependencies)

**This can be done immediately** without any new packages or infrastructure.

### Milestone 0.1: Fix Hardcoded Language in ProductSelectionScreen

**File:** `terminal-frontend/lib/screens/product_selection_screen.dart`

The member's `preferredLanguage` is already available via `membersProvider.selectedMember?.preferredLanguage`, but the code ignores it and hardcodes `'de'`.

**Changes needed:**

1. **Line 27** - `_getCategoryName()` hardcodes `'de'`:
   ```dart
   // Before
   return names['de'] ?? 'Category';

   // After - pass language as parameter
   String _getCategoryName(CategoriesCacheData category, String lang) {
     try {
       final names = jsonDecode(category.names) as Map<String, dynamic>;
       return names[lang] ?? names['de'] ?? 'Category';
     } catch (_) {
       return 'Category';
     }
   }
   ```

2. **Line 91** - Call site for `_getCategoryName()`:
   ```dart
   // Before
   categoryName: _getCategoryName(categories[index]),

   // After
   categoryName: _getCategoryName(categories[index], selectedMember?.preferredLanguage ?? 'de'),
   ```

3. **Line 176** - Product name lookup hardcodes `'de'`:
   ```dart
   // Before
   final name = productsProvider.getTranslatedName(product, 'de');

   // After
   final memberLang = context.read<MembersProvider>().selectedMember?.preferredLanguage ?? 'de';
   final name = productsProvider.getTranslatedName(product, memberLang);
   ```

4. **Line 194** - Cart item language hardcodes `'de'`:
   ```dart
   // Before
   'de',

   // After
   memberLang,
   ```

**Success Criteria:**
- [x] German-preferring member sees German product/category names
- [x] English-preferring member sees English product/category names
- [x] Fallback to German works when English translation missing
- [x] No new dependencies required

**Status:** ✅ **COMPLETE** (2026-02-06)

---

## Phase 1: Setup Flutter Localization Infrastructure

### Milestone 1.1: Add Dependencies

**File:** `terminal-frontend/pubspec.yaml`

Add to dependencies:
```yaml
dependencies:
  flutter_localizations:
    sdk: flutter
```

Add to dev_dependencies:
```yaml
dev_dependencies:
  intl_utils: ^2.8.7  # ARB file code generation
```

Add localization config section:
```yaml
flutter:
  generate: true  # Enable code generation for localizations
```

**Success Criteria:**
- [ ] `flutter pub get` completes without errors
- [ ] `flutter_localizations` available for import

### Milestone 1.2: Create l10n Configuration

**File:** `terminal-frontend/l10n.yaml`

```yaml
arb-dir: lib/l10n
template-arb-file: app_de.arb
output-localization-file: app_localizations.dart
output-class: AppLocalizations
preferred-supported-locales: [de]
```

**Success Criteria:**
- [ ] File exists and is valid YAML

### Milestone 1.3: Create German Translation File (Template)

**File:** `terminal-frontend/lib/l10n/app_de.arb`

This is the template/default locale. ARB format is JSON with metadata.

```json
{
  "@@locale": "de",

  "@_IDLE_SCREEN": {},
  "idleTitle": "Durstig?",
  "@idleTitle": {
    "description": "Main title on idle/waiting screen"
  },
  "idleSubtitle": "Halte deine Karte an den Scanner",
  "@idleSubtitle": {
    "description": "Instruction to scan RFID card"
  },
  "demoScanCard": "Demo: Karte scannen",
  "@demoScanCard": {
    "description": "Demo button to simulate card scan"
  },

  "@_SETUP_SCREEN": {},
  "setupTitle": "Terminal-Einrichtung",
  "setupSubtitle": "Verbinde dieses Terminal mit dem Ruderbar-Backend.",
  "terminalIdLabel": "Terminal-ID",
  "terminalIdRequired": "Terminal-ID ist erforderlich",
  "apiUrlLabel": "API-URL",
  "apiUrlRequired": "API-URL ist erforderlich",
  "apiUrlInvalid": "Gib eine gültige URL ein (z.B. https://club.example.com/api)",
  "apiTokenLabel": "API-Token",
  "apiTokenRequired": "API-Token ist erforderlich",
  "saveAndConnect": "Speichern & Verbinden",
  "connectionFailed": "Verbindung fehlgeschlagen: {error}",
  "@connectionFailed": {
    "placeholders": {
      "error": {
        "type": "String",
        "example": "Network error"
      }
    }
  },

  "@_SHOPPING_CART": {},
  "cartEmpty": "Dein Warenkorb ist leer",
  "cartTotal": "Gesamt",
  "cartNewBalance": "Neuer Kontostand: {balance}",
  "@cartNewBalance": {
    "placeholders": {
      "balance": {
        "type": "String",
        "example": "€12.50"
      }
    }
  },
  "cartEachPrice": "{price} pro Stück",
  "@cartEachPrice": {
    "placeholders": {
      "price": {
        "type": "String",
        "example": "€2.50"
      }
    }
  },
  "checkout": "Bezahlen",
  "memberNotSelected": "Kein Mitglied ausgewählt",

  "@_CHECKOUT_CONFIRMATION": {},
  "checkoutSuccess": "Buchung erfolgreich!",
  "checkoutNewBalance": "Neuer Kontostand: {balance}",
  "@checkoutNewBalance": {
    "placeholders": {
      "balance": {
        "type": "String",
        "example": "€12.50"
      }
    }
  },
  "redirectingIn": "Weiterleitung in {seconds} {seconds, plural, =1{Sekunde} other{Sekunden}}...",
  "@redirectingIn": {
    "placeholders": {
      "seconds": {
        "type": "int",
        "example": "3"
      }
    }
  },

  "@_MEMBER_DETAILS": {},
  "memberActive": "Aktiv",
  "memberInactive": "Inaktiv",
  "sepaYes": "Ja",
  "sepaNo": "Nein",
  "noMemberSelected": "Kein Mitglied ausgewählt",

  "@_MEMBER_BAR": {},
  "welcomeMember": "Willkommen, {name}",
  "@welcomeMember": {
    "placeholders": {
      "name": {
        "type": "String",
        "example": "Max"
      }
    }
  },
  "balance": "Kontostand",
  "viewDetails": "Details",
  "logout": "Abmelden",

  "@_ERROR_MODAL": {},
  "errorTitle": "Fehler",
  "dismiss": "Schließen",
  "retry": "Erneut versuchen",

  "@_PRODUCT_SELECTION": {},
  "categoryDefault": "Kategorie",
  "productDefault": "Produkt",
  "noProducts": "Keine Produkte verfügbar",
  "noCategories": "Keine Kategorien verfügbar",

  "@_COMMON": {},
  "loading": "Laden...",
  "cancel": "Abbrechen",
  "save": "Speichern",
  "continueShopping": "Weiter einkaufen",
  "scanCard": "Karte scannen"
}
```

**Success Criteria:**
- [ ] File is valid JSON
- [ ] All current hardcoded strings have translation keys

### Milestone 1.4: Create English Translation File

**File:** `terminal-frontend/lib/l10n/app_en.arb`

```json
{
  "@@locale": "en",

  "idleTitle": "Thirsty?",
  "idleSubtitle": "Hold your card to the scanner",
  "demoScanCard": "Demo: Scan Card",

  "setupTitle": "Terminal Setup",
  "setupSubtitle": "Connect this terminal to the Ruderbar backend.",
  "terminalIdLabel": "Terminal ID",
  "terminalIdRequired": "Terminal ID is required",
  "apiUrlLabel": "API URL",
  "apiUrlRequired": "API URL is required",
  "apiUrlInvalid": "Enter a valid URL (e.g. https://club.example.com/api)",
  "apiTokenLabel": "API Token",
  "apiTokenRequired": "API Token is required",
  "saveAndConnect": "Save & Connect",
  "connectionFailed": "Connection failed: {error}",

  "cartEmpty": "Your cart is empty",
  "cartTotal": "Total",
  "cartNewBalance": "New Balance: {balance}",
  "cartEachPrice": "{price} each",
  "checkout": "Checkout",
  "memberNotSelected": "Member not selected",

  "checkoutSuccess": "Transaction successful!",
  "checkoutNewBalance": "New Balance: {balance}",
  "redirectingIn": "Redirecting in {seconds} {seconds, plural, =1{second} other{seconds}}...",

  "memberActive": "Active",
  "memberInactive": "Inactive",
  "sepaYes": "Yes",
  "sepaNo": "No",
  "noMemberSelected": "No member selected",

  "welcomeMember": "Welcome, {name}",
  "balance": "Balance",
  "viewDetails": "Details",
  "logout": "Log out",

  "errorTitle": "Error",
  "dismiss": "Dismiss",
  "retry": "Retry",

  "categoryDefault": "Category",
  "productDefault": "Product",
  "noProducts": "No products available",
  "noCategories": "No categories available",

  "loading": "Loading...",
  "cancel": "Cancel",
  "save": "Save",
  "continueShopping": "Continue Shopping",
  "scanCard": "Scan Card"
}
```

**Success Criteria:**
- [ ] File is valid JSON
- [ ] All keys from German file are present
- [ ] Translations are accurate

### Milestone 1.5: Generate Localization Code

Run code generation:
```bash
cd terminal-frontend
flutter gen-l10n
```

**Success Criteria:**
- [ ] `lib/l10n/app_localizations.dart` generated
- [ ] `lib/l10n/app_localizations_de.dart` generated
- [ ] `lib/l10n/app_localizations_en.dart` generated
- [ ] No generation errors

---

## Phase 2: Create Locale Provider

### Milestone 2.1: Create LocaleProvider

**File:** `terminal-frontend/lib/providers/locale_provider.dart`

```dart
import 'package:flutter/material.dart';

/// Manages the app's current locale based on member preference.
///
/// Follows the pattern from admin frontend where locale is determined
/// by the authenticated member's `preferredLanguage` setting.
class LocaleProvider extends ChangeNotifier {
  Locale _locale = const Locale('de'); // Default: German

  Locale get locale => _locale;

  /// Supported locales (matches admin frontend)
  static const List<Locale> supportedLocales = [
    Locale('de'),
    Locale('en'),
  ];

  /// Update locale when member is selected/changed.
  /// Called from MembersProvider when a member is selected.
  void setLocaleFromMember(String? preferredLanguage) {
    final langCode = preferredLanguage ?? 'de';

    // Validate language code is supported
    if (['de', 'en'].contains(langCode)) {
      final newLocale = Locale(langCode);
      if (_locale != newLocale) {
        _locale = newLocale;
        notifyListeners();
      }
    }
  }

  /// Reset to default locale (e.g., when member logs out)
  void resetToDefault() {
    if (_locale != const Locale('de')) {
      _locale = const Locale('de');
      notifyListeners();
    }
  }
}
```

**Success Criteria:**
- [ ] Provider compiles without errors
- [ ] Provider follows same pattern as other providers in codebase

### Milestone 2.2: Integrate LocaleProvider into MultiProvider

**File:** `terminal-frontend/lib/main.dart`

Add LocaleProvider to the MultiProvider chain:
```dart
MultiProvider(
  providers: [
    ChangeNotifierProvider(create: (_) => LocaleProvider()),
    // ... existing providers
  ],
  // ...
)
```

**Success Criteria:**
- [ ] LocaleProvider is accessible via `Provider.of<LocaleProvider>(context)`
- [ ] App starts without errors

### Milestone 2.3: Connect MembersProvider to LocaleProvider

**File:** `terminal-frontend/lib/providers/members_provider.dart`

When a member is selected, update the locale:
```dart
void selectMember(MemberDTO member) {
  _selectedMember = member;
  _memberDeckel = member.deckelValue;

  // Update locale based on member preference (new)
  final localeProvider = // Get from context or inject via constructor
  localeProvider.setLocaleFromMember(member.preferredLanguage);

  notifyListeners();
}

void clearSelectedMember() {
  _selectedMember = null;
  _memberDeckel = null;

  // Reset locale to default (new)
  localeProvider.resetToDefault();

  notifyListeners();
}
```

**Alternative approach:** Use `ProxyProvider` to listen to MembersProvider changes.

**Success Criteria:**
- [ ] Selecting a member with `preferredLanguage: 'en'` changes app locale to English
- [ ] Clearing member resets to German

---

## Phase 3: Configure MaterialApp Localization

### Milestone 3.1: Update MaterialApp Configuration

**File:** `terminal-frontend/lib/main.dart`

```dart
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_gen/gen_l10n/app_localizations.dart';

// In build method:
MaterialApp.router(
  // Localization delegates
  localizationsDelegates: const [
    AppLocalizations.delegate,
    GlobalMaterialLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
  ],

  // Supported locales
  supportedLocales: LocaleProvider.supportedLocales,

  // Current locale from provider
  locale: localeProvider.locale,

  // ... rest of config
)
```

**Success Criteria:**
- [ ] App compiles and runs
- [ ] `AppLocalizations.of(context)` returns non-null
- [ ] German is default language

---

## Phase 4: Replace Hardcoded Strings in Screens

### Milestone 4.1: Update IdleWaitingScreen

**File:** `terminal-frontend/lib/screens/idle_waiting_screen.dart`

Replace:
```dart
// Before
Text('Durstig?')
Text('Halte deine Karte an den Scanner')
Text('Demo: Scan Card')

// After
final l10n = AppLocalizations.of(context)!;
Text(l10n.idleTitle)
Text(l10n.idleSubtitle)
Text(l10n.demoScanCard)
```

**Success Criteria:**
- [ ] No hardcoded strings remain
- [ ] Screen displays correctly in German
- [ ] Screen displays correctly in English (when locale changed)

### Milestone 4.2: Update SetupScreen

**File:** `terminal-frontend/lib/screens/setup_screen.dart`

Replace all hardcoded strings:
- `'Terminal Setup'` → `l10n.setupTitle`
- `'Connect this terminal...'` → `l10n.setupSubtitle`
- `'Terminal ID'` → `l10n.terminalIdLabel`
- `'Terminal ID is required'` → `l10n.terminalIdRequired`
- `'API URL'` → `l10n.apiUrlLabel`
- `'API URL is required'` → `l10n.apiUrlRequired`
- `'Enter a valid URL...'` → `l10n.apiUrlInvalid`
- `'API Token'` → `l10n.apiTokenLabel`
- `'API Token is required'` → `l10n.apiTokenRequired`
- `'Save & Connect'` → `l10n.saveAndConnect`
- `'Connection failed: $error'` → `l10n.connectionFailed(error)`

**Success Criteria:**
- [ ] All validation messages translated
- [ ] Form labels translated
- [ ] Error messages use interpolation correctly

### Milestone 4.3: Update ShoppingCartScreen

**File:** `terminal-frontend/lib/screens/shopping_cart_screen.dart`

Replace:
- `'Your cart is empty'` → `l10n.cartEmpty`
- `'Total'` → `l10n.cartTotal`
- `'New Balance: €...'` → `l10n.cartNewBalance(formattedBalance)`
- `'€...each'` → `l10n.cartEachPrice(formattedPrice)`
- `'Checkout'` → `l10n.checkout`
- `'Member not selected'` → `l10n.memberNotSelected`

**Success Criteria:**
- [ ] All strings translated
- [ ] Currency formatting works with both locales

### Milestone 4.4: Update CheckoutConfirmationScreen

**File:** `terminal-frontend/lib/screens/checkout_confirmation_screen.dart`

Replace:
- `'New Balance: €...'` → `l10n.checkoutNewBalance(balance)`
- `'Redirecting in $_secondsRemaining second(s)...'` → `l10n.redirectingIn(_secondsRemaining)`

**Note:** Plural handling for "second/seconds" is built into ARB format.

**Success Criteria:**
- [ ] Plural forms work correctly (1 second vs 3 seconds)
- [ ] Success message translated

### Milestone 4.5: Update MemberDetailsScreen

**File:** `terminal-frontend/lib/screens/member_details_screen.dart`

Replace:
- `'Active'` / `'Inactive'` → `l10n.memberActive` / `l10n.memberInactive`
- `'Yes'` / `'No'` → `l10n.sepaYes` / `l10n.sepaNo`
- `'No member selected'` → `l10n.noMemberSelected`

**Success Criteria:**
- [ ] Status labels translated
- [ ] SEPA indicator translated

### Milestone 4.6: Fix Product/Category Language Selection (QUICK WIN)

**File:** `terminal-frontend/lib/screens/product_selection_screen.dart`

**This is a bug fix** - the infrastructure exists but hardcodes `'de'`.

**Current (broken):**
```dart
// Line 27 - hardcoded German for categories
return names['de'] ?? 'Category';

// Line 176 - hardcoded German for products
final name = productsProvider.getTranslatedName(product, 'de');

// Line 194 - hardcoded German in cart item
'de',
```

**Fixed:**
```dart
// Get member's preferred language once
final memberLang = membersProvider.selectedMember?.preferredLanguage ?? 'de';

// Use it for categories
String _getCategoryName(CategoriesCacheData category, String lang) {
  final names = jsonDecode(category.names) as Map<String, dynamic>;
  return names[lang] ?? names['de'] ?? l10n.categoryDefault;
}

// Use it for products
final name = productsProvider.getTranslatedName(product, memberLang);

// Use it for cart items
cartProvider.addItem(product.id, name, product.priceCents, 1, memberLang, iconName: product.iconName);
```

**Success Criteria:**
- [ ] Category names display in member's preferred language
- [ ] Product names display in member's preferred language
- [ ] Cart stores the correct language for display
- [ ] Fallback to German (`'de'`) if translation missing
- [ ] Fallback to localized "Category"/"Product" if no names at all

**Note:** This can be done independently of UI string localization (Phases 1-3). It's a quick win that only requires accessing `selectedMember.preferredLanguage`.

---

## Phase 5: Update Widgets

### Milestone 5.1: Update ErrorModal

**File:** `terminal-frontend/lib/widgets/error_modal.dart`

Replace:
- `'Error'` → `l10n.errorTitle`
- `'Dismiss'` → `l10n.dismiss`
- `'Retry'` → `l10n.retry`

### Milestone 5.2: Update MemberBar

**File:** `terminal-frontend/lib/widgets/member_bar.dart`

Replace:
- `'Welcome, ${member.firstName}'` → `l10n.welcomeMember(member.firstName ?? 'Member')`
- `'Balance'` → `l10n.balance`
- `'Details'` → `l10n.viewDetails`
- `'Log out'` / `'Abmelden'` → `l10n.logout`

### Milestone 5.3: Update Any Remaining Widgets

Scan all files in `lib/widgets/` for hardcoded strings.

**Success Criteria:**
- [ ] All widgets use localized strings
- [ ] No hardcoded German or English strings remain

---

## Phase 6: Locale-Aware Formatting

### Milestone 6.1: Create Formatting Utilities

**File:** `terminal-frontend/lib/utils/formatters.dart`

```dart
import 'package:intl/intl.dart';

/// Currency formatter that respects locale.
/// German: 12,50 €
/// English: €12.50
String formatPrice(int cents, String locale) {
  final format = NumberFormat.currency(
    locale: locale == 'de' ? 'de_DE' : 'en_GB',
    symbol: '€',
    decimalDigits: 2,
  );
  return format.format(cents / 100.0);
}

/// Date formatter that respects locale.
String formatDate(DateTime date, String locale) {
  final format = DateFormat.yMd(locale == 'de' ? 'de_DE' : 'en_GB');
  return format.format(date);
}

/// DateTime formatter that respects locale.
String formatDateTime(DateTime date, String locale) {
  final format = DateFormat.yMd(locale == 'de' ? 'de_DE' : 'en_GB').add_Hm();
  return format.format(date);
}
```

### Milestone 6.2: Replace Manual Currency Formatting

Find all instances of:
```dart
(cents / 100.0).toStringAsFixed(2)
'€${...}'
```

Replace with:
```dart
formatPrice(cents, locale)
```

**Success Criteria:**
- [ ] Prices display with correct decimal separator (comma for German, dot for English)
- [ ] Currency symbol positioned correctly per locale

---

## Phase 7: Testing

### Milestone 7.1: Unit Tests for LocaleProvider

**File:** `terminal-frontend/test/providers/locale_provider_test.dart`

Test cases:
- [ ] Default locale is German
- [ ] `setLocaleFromMember('en')` changes to English
- [ ] `setLocaleFromMember('de')` changes to German
- [ ] Invalid language codes fall back to German
- [ ] `resetToDefault()` restores German
- [ ] Notifies listeners on change

### Milestone 7.2: Widget Tests for Localization

**File:** `terminal-frontend/test/screens/idle_waiting_screen_test.dart` (update existing)

Test cases:
- [ ] Screen displays German text by default
- [ ] Screen displays English text when English locale set
- [ ] All text comes from localizations, not hardcoded

### Milestone 7.3: Integration Test for Member Language Switch

Test case:
- [ ] Login with German-preferring member → UI in German
- [ ] Switch to English-preferring member → UI switches to English
- [ ] Logout → UI resets to German

---

## Phase 8: Documentation

### Milestone 8.1: Update README

Document:
- Supported languages
- How to add new translations
- How language is determined (member preference)

### Milestone 8.2: Add ADR for Flutter i18n Decision

**File:** `adr/0024-flutter-terminal-i18n.md`

Document:
- Decision to use Flutter's built-in localization (vs easy_localization, etc.)
- ARB file format choice
- Member-driven language preference
- Consistency with admin frontend approach

---

## Summary

| Phase | Description | Estimated Files Changed |
|-------|-------------|------------------------|
| 1 | Setup infrastructure | 4 new files, 1 modified |
| 2 | Create LocaleProvider | 2 files |
| 3 | Configure MaterialApp | 1 file |
| 4 | Replace screen strings | 6 files |
| 5 | Update widgets | 3-5 files |
| 6 | Locale-aware formatting | 1 new, 5-10 modified |
| 7 | Testing | 3+ test files |
| 8 | Documentation | 2 files |

**Total new translation keys:** ~40-50
**Languages supported:** German (de), English (en)

---

## Implementation Notes

1. **Code Generation**: Run `flutter gen-l10n` after any ARB file changes
2. **Null Safety**: Always use `AppLocalizations.of(context)!` (non-null assertion) since we configure localization at app root
3. **Hot Reload**: Locale changes should hot reload correctly
4. **Fallback Chain**: German → English → key name (for missing translations)
5. **Product/Category Names**: Already handled separately via `ProductsService.getTranslatedName()` - ensure it uses member's `preferredLanguage`
