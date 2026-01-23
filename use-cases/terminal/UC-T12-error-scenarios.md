# UC-T12: Error Scenarios

## Overview

This use case documents error scenarios and their expected behavior for test derivation and implementation guidance.

---

## E1: Unknown RFID Card

### Actor
Person with unregistered card

### Trigger
Unknown card scanned at terminal

### Flow
1. Person scans RFID card
2. System looks up card_uid in local member cache
3. Card not found
4. System displays error screen

### Error Display

| Element | Content |
|---------|---------|
| Icon | Warning/error icon |
| Title | "Unknown Card" |
| Message | "This card is not registered. Please contact administration." |
| Duration | 5 seconds, then return to idle |

### Postconditions
- No session started
- No data modified
- Return to idle screen after timeout

### UI Requirements
- Clear, readable error message
- No technical jargon
- Localized in terminal's default language
- Large text for visibility

### Test Derivation
- Scan unregistered card: verify error message displayed
- Error timeout: verify return to idle after 5 seconds
- Tap to dismiss: verify tap returns to idle immediately
- Multiple scans: scan unknown card twice, verify error shown both times
- No session: verify no member session created

---

## E2: Maximum Balance Exceeded

### Actor
Member

### Preconditions
- Member has valid RFID card
- Member's current tab balance is at or near configured maximum
- Maximum balance configured in backend (synced to terminal)

### Trigger
Member attempts to add items that would exceed maximum balance

### Flow
1. Member scans RFID card
2. System displays member greeting and current balance
3. Member adds products to cart
4. Cart total would cause balance to exceed maximum
5. System displays balance limit warning
6. Member cannot proceed to checkout

### Balance Check Points

| Action | Check | Result if Exceeded |
|--------|-------|-------------------|
| Add to cart | Preview balance vs max | Warning shown, item still added |
| Tap "Buy" | Final balance vs max | Checkout blocked |

### Warning Display (Add to Cart)

| Element | Content |
|---------|---------|
| Banner | Yellow/orange warning banner |
| Icon | Warning icon |
| Message | "Balance limit reached. Remove items to continue." |
| Current | "Current balance: €XX.XX" |
| Cart | "Cart total: €XX.XX" |
| Limit | "Maximum allowed: €XX.XX" |

### Checkout Blocked Display

| Element | Content |
|---------|---------|
| Buy button | Disabled (grayed out) |
| Tooltip | "Balance would exceed limit" |
| Message | "Cannot complete purchase. Balance would exceed €XX.XX limit." |

### Allowed Actions When Over Limit
- Remove items from cart
- View current balance
- Log out / timeout

### Postconditions
- No transactions created (checkout blocked)
- Cart may contain items (not cleared)
- Member can adjust cart to get under limit

### Configuration

| Setting | Source | Example |
|---------|--------|---------|
| `max_member_balance` | Backend config, synced | 100.00 |

### Edge Cases

| Scenario | Behavior |
|----------|----------|
| Exactly at limit | Can view, cannot add more |
| Cart makes exactly at limit | Allowed (not exceeding) |
| Already over limit (legacy) | Cannot add anything, must wait for settlement |

### Test Derivation
- Warning on exceed: add items to exceed limit, verify warning shown
- Checkout blocked: exceed limit, verify "Buy" button disabled
- Remove to unblock: exceed limit, remove items, verify "Buy" enabled
- Exactly at limit: add items to exactly reach limit, verify checkout allowed
- Already over limit: member already over, verify cannot add any items
- Warning content: verify message shows current, cart, and limit amounts
- Localization: verify messages in correct language

---

## E3: Inactive Member

### Actor
Person with card belonging to inactive member

### Trigger
Card scanned for inactive member account

### Flow
1. Person scans RFID card
2. System finds member but `is_active = false`
3. System displays error screen

### Error Display

| Element | Content |
|---------|---------|
| Icon | Block/prohibited icon |
| Title | "Account Inactive" |
| Message | "Your account is currently inactive. Please contact administration." |
| Duration | 5 seconds, then return to idle |

### Postconditions
- No session started
- No data modified
- Return to idle screen after timeout

### Test Derivation
- Inactive member scan: verify error message displayed
- No product view: verify product selection not accessible
- Error timeout: verify return to idle after 5 seconds

---

## E4: Network Error During Sync

### Actor
System (background process)

### Trigger
Network unavailable during transaction sync

### Flow
1. Terminal attempts to sync transactions to backend
2. Network request fails (timeout, DNS, connection refused)
3. Transactions remain in local queue
4. System shows sync status indicator

### Sync Status Indicator

| State | Display |
|-------|---------|
| Synced | Green dot / checkmark |
| Pending | Yellow dot / clock icon |
| Failed | Red dot / warning icon |

### User-Facing Behavior
- Purchases continue to work (offline-first)
- Balance shown is local calculation
- Status indicator shows pending sync
- No blocking error to user

### Postconditions
- Transactions stored locally
- Retry scheduled automatically
- User can continue purchasing

### Test Derivation
- Offline purchase: disconnect network, complete purchase, verify success
- Sync indicator: disconnect network, verify pending indicator
- Auto-retry: reconnect network, verify transactions sync
- Local balance: offline purchases update local balance correctly

---

## E5: Session Timeout

### Actor
Member (inactive)

### Trigger
No user interaction for configured timeout period (30 seconds)

### Flow
1. Member scans card and starts session
2. Member adds items to cart (optional)
3. No interaction for 30 seconds
4. System displays timeout warning (last 5 seconds)
5. No interaction during warning
6. System ends session

### Timeout Warning

| Element | Content |
|---------|---------|
| Overlay | Dimmed screen overlay |
| Message | "Session ending in X seconds" |
| Countdown | 5, 4, 3, 2, 1 |
| Action | "Tap to continue" |

### Postconditions
- Session ended
- Shopping cart cleared (no transactions)
- Return to idle screen

### Test Derivation
- Timeout without cart: scan, wait 30s, verify return to idle
- Timeout with cart: add items, wait 30s, verify no transactions created
- Tap to continue: wait 25s, tap, verify session continues
- Timeout warning: wait 25s, verify countdown shown
- Cart discarded: add items, timeout, scan again, verify cart empty

---

---

## E6: SEPA Mandate Invalid

### Actor
Member with missing or invalid SEPA data

### Trigger
Card scanned for member without valid IBAN or mandate_reference

### Flow
1. Member scans RFID card
2. System finds member but `is_sepa_valid = false`
3. System displays error screen

### Error Display

| Element | Content |
|---------|---------|
| Icon | Warning/payment icon |
| Title | "SEPA Mandate Missing" |
| Message (DE) | "SEPA-Mandat fehlt oder ungültig. Bitte wende dich an die Verwaltung, um deine Zahlungsdaten einzurichten." |
| Message (EN) | "SEPA mandate missing or invalid. Please contact administration to set up your payment details." |
| Duration | 5 seconds, then return to idle |

### Postconditions
- No session started
- No data modified
- Return to idle screen after timeout

### SEPA Validity Check
```
is_sepa_valid = (iban IS NOT NULL) AND (mandate_reference IS NOT NULL)
```

Terminal receives `is_sepa_valid` boolean in member sync data; does not have access to actual IBAN/mandate values.

### UI Requirements
- Clear, actionable error message
- Directs member to administration
- Localized in member's preferred language (if known) or terminal default
- Large text for visibility

### Test Derivation
- Missing IBAN: scan card of member without IBAN → error shown
- Missing mandate: scan card of member with IBAN but no mandate_reference → error shown
- Missing both: scan card of member without IBAN and mandate → error shown
- Error timeout: verify return to idle after 5 seconds
- Tap to dismiss: verify tap returns to idle immediately
- No session: verify no member session created
- SEPA restored: admin adds IBAN + mandate, next sync, verify member can login

### Related
- [ADR-0020: SEPA Mandate Requirement for Terminal Access](../../adr/0020-sepa-mandate-requirement-terminal-access.md)

---

## Summary: Error Messages

| Code | Title | Message |
|------|-------|---------|
| E1 | Unknown Card | "This card is not registered. Please contact administration." |
| E2 | Balance Limit | "Cannot complete purchase. Balance would exceed €XX.XX limit." |
| E3 | Account Inactive | "Your account is currently inactive. Please contact administration." |
| E4 | Sync Pending | (Status indicator only, no blocking message) |
| E5 | Session Timeout | "Session ending in X seconds. Tap to continue." |
| E6 | SEPA Mandate Missing | "SEPA mandate missing or invalid. Please contact administration to set up your payment details." |

## Localization

All error messages must be:
- Available in all supported languages
- Displayed in terminal's configured default language
- Clear and non-technical
- Actionable (tell user what to do)
