# UC-T03: Change Preferred Language

**Implementation Status**: Implemented (diverges from spec)
**Divergence**: Language auto-switches based on member's preferred_language on card scan. No manual language toggle on terminal. Confirmed acceptable by stakeholder.

## Actor
Member

## Preconditions
- Member is identified (RFID scanned)
- Member is on product view or cart view

## Trigger
Member taps language selector in header

## Main Flow
1. Member taps language icon/selector in screen header
2. System displays language selection overlay
3. Overlay shows available languages with flags/labels
4. Current language is highlighted
5. Member taps desired language
6. System updates display immediately
7. Product names switch to selected language
8. UI labels switch to selected language
9. System persists preference to member record (queued for sync)
10. Overlay closes automatically

## Language Display

| Element | Behavior |
|---------|----------|
| Language icon | Globe or current language flag in header |
| Selection overlay | List of enabled languages |
| Language option | Flag + native name (e.g., "Deutsch", "English") |
| Current selection | Highlighted/checked |

## Affected Elements After Change
- Product names (from JSON translations)
- Category tab labels (from i18n)
- UI buttons and labels (from i18n)
- Error messages (from i18n)
- Balance display labels (from i18n)

## Postconditions
- Display language changed immediately
- Member's `preferred_language` updated locally
- Change queued for backend sync
- Preference persists across sessions

## Variants

### V1: Language Not Available for Product
1. Member selects language
2. Product has no translation for selected language
3. System falls back to default language for that product
4. Other products display in selected language

### V2: Change Language During Checkout
1. Member is in cart view
2. Member changes language
3. Cart contents preserved
4. Product names in cart update to new language
5. Checkout flow continues normally

## Business Rules
- Available languages defined by backend configuration
- Fallback chain: selected language → organization default → any available
- Language change does not affect cart contents or prices
- Language preference synced to backend on next sync cycle

## Error Cases

### E1: Sync Fails
- Language change applied locally
- Change queued for retry
- Member sees selected language
- Backend updated on next successful sync

## Test Derivation
- Change language: product names update immediately
- Fallback: missing translation shows default language
- Persistence: logout, scan again, language retained
- Cart preserved: change language in cart, items unchanged
- UI updates: all labels change to new language
- Sync: verify preference synced to backend
