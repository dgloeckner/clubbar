# Phase 3: Provider-Based State Management Design

## Overview

Phase 3 implements the state management layer using Flutter's Provider pattern, sitting between the data access layer (repositories) and UI. This layer manages application state, coordinates operations across multiple providers, and handles offline-first synchronization.

**Status:** Design approved, ready for implementation

---

## Architecture: Three-Tier Design

```
┌─────────────────────────────────────┐
│           UI Layer                  │
│  (Widgets consuming Providers)      │
└──────────────┬──────────────────────┘
               │
┌──────────────▼──────────────────────┐
│      Provider Layer (NEW)           │
│  - AuthProvider                     │
│  - MembersProvider                  │
│  - ProductsProvider                 │
│  - CartProvider                     │
│  - SyncProvider                     │
└──────────────┬──────────────────────┘
               │
┌──────────────▼──────────────────────┐
│      Service Layer (NEW)            │
│  - MembersService                   │
│  - ProductsService                  │
│  - CartService                      │
│  - SyncService (Phase 2)            │
│  - NetworkService (Phase 2)         │
└──────────────┬──────────────────────┘
               │
┌──────────────▼──────────────────────┐
│      Repository Layer (Phase 2)     │
│  - MembersRepository                │
│  - ProductsRepository               │
│  - TransactionsRepository           │
│  - SyncRepository                   │
└─────────────────────────────────────┘
```

---

## Service Classes

Services wrap repositories and add business logic, serving as the data access layer for providers.

### MembersService
**Wraps:** MembersRepository

**Methods:**
- `lookupByRfid(String cardUid)` → `(MembersCacheData?, String?)` — Look up member by RFID card, return member and error message
- `getAllMembers()` → `List<MembersCacheData>` — Get all active members
- `refreshMembers()` → `Future<void>` — Reload members from repository

**Validation Logic:**
- Check account is active
- Check SEPA mandate is valid
- Check account not blocked

**Error Handling:**
- Catches repository exceptions
- Converts to typed exceptions: `ValidationException`, `NotFoundException`

### ProductsService
**Wraps:** ProductsRepository

**Methods:**
- `getActiveCategoriesWithProducts()` → `List<(CategoriesCacheData, List<ProductsCacheData>)>` — Get categories with products
- `getProduct(String id)` → `ProductsCacheData?` — Get single product by ID
- `getTranslatedName(ProductsCacheData product, String language)` → `String` — Get product name in member's language with German fallback
- `refreshProducts()` → `Future<void>` — Reload products from repository

**Language Handling:**
- Parse JSON-encoded product names (keys: `de`, `en`, etc.)
- Fallback to German (`de`) if preferred language not available

**Error Handling:**
- Graceful degradation if translation missing
- Returns German name as fallback

### CartService
**Uses:** TransactionsRepository

**Methods:**
- `createTransaction(MembersCacheData member, List<CartItem> items)` → `(String transactionId, Exception?)` — Create and persist transaction
- `validateCartBeforeCheckout(MembersCacheData member, List<CartItem> items)` → `(bool valid, String? errorMsg)` — Validate before checkout

**Validation Logic:**
- Verify member still active
- Verify products still available
- Verify member has sufficient balance (if applicable)

**Error Handling:**
- Returns validation errors without throwing
- Allows UI to show reason and keep cart intact

### Reused Services (Phase 2)
- **SyncService:** Orchestrates sync cycles, handles member/product fetch and transaction sync
- **NetworkService:** HTTP client with Bearer token auth, exception handling

---

## Provider Classes

Providers manage UI-facing state and coordinate services. Each provider tracks:
- Application data (members, products, cart items)
- Operation states: `isLoading`, `isSyncing`
- Error state: `lastError`, `errorType`

### AuthProvider
**Manages:** Authentication state and tokens

**State:**
- `token: String?` — Current auth token
- `isAuthenticated: bool` — Whether user is logged in

**Methods:**
- `setToken(String token)` — Store token
- `clearToken()` — Logout
- `loadTokenFromStorage()` — Load saved token on app startup

**Error Handling:**
- `lastError: String?`
- `errorType: Exception?`

### MembersProvider
**Manages:** Member list and current selected member

**State:**
- `members: List<MembersCacheData>` — All cached members
- `selectedMember: MembersCacheData?` — Currently selected member (for transaction)
- `isSyncing: bool` — Currently syncing members
- `isLoading: bool` — Loading member data
- `lastError: String?` — Last error message
- `errorType: Exception?` — Exception type for detailed error handling

**Methods:**
- `selectMemberByRfid(String cardUid)` → `Future<void>` — Look up member by RFID, validate, set as selected
  - On success: sets `selectedMember`, clears errors
  - On failure: sets `lastError`, `errorType`, keeps previous `selectedMember`
- `clearSelectedMember()` → `void` — Clear selection between transactions
- `refreshMembers()` → `Future<void>` — Reload members from service
- `clearCache()` → `Future<void>` — Clear all cached members

**Dependencies:** MembersService

### ProductsProvider
**Manages:** Product catalog and categories

**State:**
- `categories: List<CategoriesCacheData>` — All product categories
- `products: List<ProductsCacheData>` — All products
- `isSyncing: bool` — Currently syncing products
- `isLoading: bool` — Loading product data
- `lastError: String?` — Last error message
- `errorType: Exception?` — Exception type

**Methods:**
- `refreshProducts()` → `Future<void>` — Reload categories and products from service
- `getTranslatedName(ProductsCacheData product, String language)` → `String` — Get product name in language
- `clearCache()` → `Future<void>` — Clear cached products

**Dependencies:** ProductsService

### CartProvider
**Manages:** Shopping cart (in-memory only)

**State:**
- `items: List<CartItem>` — Current cart items (in-memory)
  - `CartItem { productId: String, quantity: int, price: double }`
- `total: double` — Cart total (sum of item prices × quantities)
- `itemCount: int` — Number of items in cart
- `isLoading: bool` — Processing checkout
- `lastError: String?` — Last error message
- `errorType: Exception?` — Exception type

**Methods:**
- `addItem(ProductsCacheData product, int quantity)` → `void` — Add product to cart, update total
- `removeItem(String productId)` → `void` — Remove product from cart
- `updateQuantity(String productId, int quantity)` → `void` — Change item quantity
- `checkout(MembersCacheData member)` → `Future<String?>` — Create transaction, return error or null
  - Calls CartService.createTransaction()
  - On success: clears cart, notifies listeners
  - On failure: sets lastError, keeps cart intact for retry
- `clearCart()` → `void` — Clear all items (called after successful checkout)

**Dependencies:** CartService, ProductsProvider (for product names)

**Note:** Cart is in-memory only; transactions persist when `checkout()` completes. No persistence on app restart.

### SyncProvider
**Manages:** Background synchronization and data freshness

**State:**
- `isSyncing: bool` — Currently syncing with backend
- `lastSyncTime: DateTime?` — Last successful sync time
- `retryCount: int` — Number of failed sync attempts
- `lastError: String?` — Last sync error message
- `errorType: Exception?` — Exception type (NetworkException, etc.)
- `isLoading: bool` — (used during manual sync trigger)

**Methods:**
- `startSync()` → `Future<void>` — Manually trigger sync (checks SyncService.isSyncNeeded first)
  - Calls SyncService.syncAll()
  - On success: refreshes MembersProvider and ProductsProvider, clears errors, updates lastSyncTime
  - On failure: sets lastError, errorType, increments retryCount (non-blocking)
- `stopSync()` → `void` — Stop background sync timer (on app shutdown)

**Background Behavior:**
- Timer runs every 60 seconds automatically
- Calls `startSync()` if not already syncing
- Errors are non-blocking; app continues working offline
- Retries automatically on next 60-second interval

**Dependencies:** SyncService, MembersProvider, ProductsProvider

**Error Handling:**
- Sync errors don't block transactions
- Error state stored in provider for optional UI display
- Background retries continue automatically
- User can manually inspect sync status but it's optional

---

## POS Transaction Flow

**Typical checkout workflow:**

```
1. Terminal reads RFID card
   └─ MembersProvider.selectMemberByRfid(cardUid)
      └─ MembersService.lookupByRfid()
         └─ MembersRepository.findByCardUid()

2. Member valid → Display member info + product catalog
   └─ UI reads from MembersProvider.selectedMember
   └─ UI reads from ProductsProvider.products and ProductsProvider.getTranslatedName()

3. User adds products to cart
   └─ CartProvider.addItem(product)
   └─ CartProvider.total updated

4. User confirms checkout
   └─ CartProvider.checkout(selectedMember)
      └─ CartService.validateCartBeforeCheckout()
      └─ CartService.createTransaction()
         └─ TransactionsRepository.insertTransaction()

5. Transaction created → Clear cart for next customer
   └─ CartProvider.clearCart()
   └─ MembersProvider.clearSelectedMember()

6. Background (every 60 seconds):
   └─ SyncProvider automatic timer
      └─ SyncService.syncAll() if needed
         └─ Fetch members, products, sync unsynced transactions
         └─ SyncProvider calls MembersProvider.refreshMembers()
         └─ SyncProvider calls ProductsProvider.refreshProducts()
```

---

## Offline-First Behavior

**Members and Products:**
- Cached locally in Drift database from last sync
- Available immediately for RFID lookup and product selection
- Sync happens in background every 60 seconds (non-blocking)

**Transactions:**
- Created immediately in local database when checkout completes
- Marked as "unsynced" in TransactionsRepository
- Next sync sends unsynced transactions to backend
- Terminal fully functional without network

**Sync Failures:**
- Non-blocking — errors stored but don't prevent transactions
- Background timer retries every 60 seconds
- User can optionally inspect sync status
- No user action required; works offline indefinitely

---

## Error Handling

**Provider Error States:**
All providers track:
- `isLoading: bool` — Operation in progress
- `isSyncing: bool` — Sync operation in progress
- `lastError: String?` — Human-readable error message
- `errorType: Exception?` — Typed exception for detailed handling

**Error Types:**
- `NetworkException` — Network unavailable or API error
- `ValidationException` — Business logic validation failed (inactive member, missing SEPA, etc.)
- `NotFoundException` — Data not found (member by RFID, product by ID)
- `CartException` — Cart validation failed (member became inactive, product unavailable)

**Error Propagation:**
1. Service calls repository
2. Repository throws exception
3. Service catches, converts to typed exception
4. Provider catches service exception
5. Provider sets `lastError` and `errorType`
6. UI reads provider state and shows error

**Non-Blocking Errors:**
- Sync errors don't prevent transactions
- RFID errors show message but keep terminal functional
- Checkout validation errors keep cart intact for retry

---

## Testing Strategy

**Provider Tests (Unit):**
- Mock service dependencies (MembersService, ProductsService, CartService, SyncService)
- Test state changes in response to service results
- Test error state handling and clearing
- Test background sync timer for SyncProvider
- Target: 80%+ coverage per CLAUDE.md

**Example test cases:**
- `selectMemberByRfid(validUid)` → sets selectedMember, clears errors
- `selectMemberByRfid(invalidUid)` → sets lastError and errorType
- `refreshMembers()` → loads from service, updates members list
- `addItem(product)` → adds to cart, updates total
- `checkout(member)` → calls CartService, clears cart on success
- `startSync()` → calls SyncService, refreshes providers on success
- Sync timer triggers every 60 seconds (mock timer)

**Service Tests (Unit):**
- Mock repository dependencies
- Test business logic (validation, error conversion)
- Test exception handling
- Target: 80%+ coverage

**Integration Notes:**
- Providers tested in isolation with mocked services
- Service integration with repositories tested separately (Phase 2)
- E2E testing deferred to Phase 4 (UI integration)

---

## Dependencies & Imports

**Providers depend on:**
- Flutter Provider package (existing)
- Services (new: MembersService, ProductsService, CartService)
- Phase 2 Services (SyncService, NetworkService)
- DTOs from Phase 2 (MembersCacheData, ProductsCacheData, etc.)

**Services depend on:**
- Phase 2 Repositories (MembersRepository, ProductsRepository, TransactionsRepository, SyncRepository)
- Phase 2 Services (SyncService, NetworkService)
- Phase 2 Models (MembersCacheData, ProductsCacheData, etc.)

**No new external packages required** (Provider already in pubspec.yaml)

---

## Implementation Checklist

- [ ] Create MembersService
- [ ] Create ProductsService
- [ ] Create CartService
- [ ] Create AuthProvider
- [ ] Create MembersProvider
- [ ] Create ProductsProvider
- [ ] Create CartProvider
- [ ] Create SyncProvider with background timer
- [ ] All unit tests passing (80%+ coverage)
- [ ] Commit Phase 3

---

## Success Criteria

✅ All services wrap repositories correctly
✅ All providers expose correct state and methods
✅ Background sync timer runs every 60 seconds
✅ Sync errors non-blocking; app works offline
✅ RFID lookup works with validation
✅ Cart accumulation and checkout works
✅ All providers have 80%+ test coverage
✅ All tests passing (expect ~100+ new tests)
