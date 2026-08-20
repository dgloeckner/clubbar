# UC-T12: Error Scenarios

**Implementation Status**: Implemented

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
Member without an **active mandate**

### Trigger
Card scanned for a member without an active mandate. This is SEPA-only ([#171](https://github.com/dgloeckner/ruderbar/issues/171)): a member with no active mandate cannot start a terminal session at all — refused at card scan, before any product view opens, with no grace period.

### Flow
1. Member scans RFID card
2. System finds member but `is_sepa_valid = false` — no active mandate
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

**Amended 2026-08-07.** The check used to require both the IBAN field and the mandate reference field to be present. That predicate could not carry the weight this use case puts on it: the mandate reference used to be auto-generated the instant an IBAN was typed, so both fields became non-empty together and "has a valid SEPA mandate" collapsed to "somebody typed an IBAN" — data entry, not a signed mandate.

The check is now:

```
is_sepa_valid = member has an active mandate
```

where a mandate is its own record carrying reference, IBAN and signature date ([ADR-0006](../../adr/0006-sepa-mandate-reference-strategy.md), amended). Terminal receives the `is_sepa_valid` boolean in member sync data; does not have access to actual mandate values.

### UI Requirements
- Clear, actionable error message
- Directs member to administration
- Localized in member's preferred language (if known) or terminal default
- Large text for visibility

### Test Derivation
- No mandate: scan card of member with no active mandate → error shown
- IBAN alone is not enough: scan card of a member with an IBAN but no signed mandate → error shown — this is the case the old NOT-NULL predicate wrongly admitted
- Missing both: scan card of member without IBAN and without a mandate → error shown
- Error timeout: verify return to idle after 5 seconds
- Tap to dismiss: verify tap returns to idle immediately
- No session: verify no member session created
- SEPA restored: admin records a new active mandate, next sync, verify member can login

### Related
- [ADR-0020: SEPA Mandate Requirement for Terminal Access](../../adr/0020-sepa-mandate-requirement-terminal-access.md)

---

## E7: Product Age-Restricted

**Implementation Status**: Planned — epic [#582](https://github.com/dgloeckner/clubbar/issues/582), [ADR-0045](../../adr/0045-age-restricted-products.md)

### Actor
Member younger than a product's `min_age`

### Trigger
Checkout is attempted with a cart containing at least one product whose `min_age` exceeds the member's age, computed **at the moment of checkout** from the member's date of birth. Unlike E6 this is *not* refused at card scan: an underage member has a perfectly normal session and may buy every unrestricted product on the grid.

### Flow
1. Member scans card, session starts normally
2. Restricted tiles render in a disabled state naming the required age — a courtesy, not the control
3. Member reaches checkout with a restricted product in the cart
4. `CartService.validateCartBeforeCheckout()` refuses — this is the authority, and it holds with the UI bypassed
5. Refusal is displayed; the cart and the session survive

### Error Display

| Element | Content |
|---------|---------|
| Icon | Warning icon |
| Title (DE) | "Altersbeschränkung" |
| Title (EN) | "Age restriction" |
| Message (DE) | "Dieses Getränk gibt es erst ab {age} Jahren – bitte wähle etwas anderes." |
| Message (EN) | "This drink is only available from age {age} — please choose something else." |
| Retry | **None** — see below |

The copy names a next step, because every terminal error must: this is the one
refusal nobody at the bar can lift, so "see the bar staff" would be a false
promise and choosing a different drink is the only true answer.

`{age}` is the **product's** required age. The message must never state the member's age or date of birth: the terminal screen is read by whoever is standing at the bar (`research/art9-rfid-display-retention.md`), and rule 6 of ADR-0045 makes this binding.

### Why there is no Retry
Age is a standing condition, like E2's balance limit and unlike E4's network error. Nothing about a second attempt would be different, so a Retry button would be an invitation to keep tapping. The member removes the item or ends the session.

### Check ordering
The age check runs **after** the empty-cart check and **before** the credit-limit check. A refusal on legal grounds must not be masked by a message about money.

### A NULL date of birth
Refused, never allowed. A member row with no date of birth is an anonymized member ([ADR-0045](../../adr/0045-age-restricted-products.md) rule 3), who is also inactive and stopped at E3 before ever reaching a cart. There is no "unknown age" path.

### Postconditions
- No transaction created
- Cart unchanged — the member may remove the item and check out the rest
- Session continues

### Test Derivation
- Under age: member born 17 years ago, product `min_age = 18` → refused
- **Exactly of age today**: member born exactly 18 years ago today → **allowed**. The birthday boundary is where off-by-one errors live
- Day before the birthday: born 18 years ago tomorrow → refused
- Over age → allowed
- Unrestricted product (`min_age` NULL) → always allowed, whatever the member's age
- NULL date of birth → refused
- Mixed cart: one restricted item among unrestricted ones → whole checkout refused, cart intact
- UI bypassed: item forced into the cart without going through the tile → still refused

### Related
- [ADR-0045: Age-Restricted Products](../../adr/0045-age-restricted-products.md)
- `research/juschg-age-limits.md`

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
| E7 | Age restriction | "This drink is only available from age {age} — please choose something else." |

## Localization

All error messages must be:
- Available in all supported languages
- Displayed in terminal's configured default language
- Clear and non-technical
- Actionable (tell user what to do)
