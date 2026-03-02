# Sauna Token Dispenser Integration - Design Document

**Date:** 2026-02-14
**Status:** Design Complete, Ready for Implementation
**Architecture Model:** Dispense-First, Pay-After

---

## Overview

Integration of a physical token dispenser (ESP8266 + Azkoyen Hopper) into the Club Bar terminal for purchasing sauna tokens. Users can add tokens to their shopping cart alongside regular products. During checkout, tokens are physically dispensed before payment is recorded, ensuring customers are never charged for tokens they don't receive.

---

## Architecture Principles

✅ **Dispense-First, Pay-After**: Physical tokens dispensed BEFORE creating payment transaction
✅ **Idempotent Operations**: Client-generated `tx_id` for safe retries
✅ **Eventual Consistency**: Terminal reconciles with ESP8266 on crash recovery
✅ **Offline-First**: Terminal functions for regular products even if dispenser offline
✅ **Customer Protection**: Exact token count tracking, transparent billing for partial dispenses
✅ **Data Minimization**: Dispenser configuration is terminal-local (not synced from backend)

---

## High-Level Integration Model

### Option A: Tokens as Product Type (Selected)

**Flow:**
1. Member adds sauna tokens to cart (like any other product)
2. At checkout, terminal detects tokens in cart
3. **Before creating any transactions**, terminal:
   - Generates unique `tx_id` for dispense operation
   - Calls `POST /dispense` to ESP8266
   - Polls `GET /dispense/{tx_id}` every 250ms until done/error
4. **Only after successful dispense**:
   - Create transaction in `transactions_local` with actual dispensed count
   - Then create transactions for other (non-token) products

**Key Difference from Regular Products:**
- Regular products: Add to cart → checkout → create all transactions immediately
- Token products: Add to cart → checkout → **dispense first** → create transaction only if successful

---

## Checkout Flow

### Cart Contents Example
```
Cart: [2 beers, 3 sauna tokens, 1 snack]
```

### Checkout Process
```
1. Member taps "Buy" button
   ↓
2. Separate tokens from regular products
   - Token products: [3 sauna tokens]
   - Regular products: [2 beers, 1 snack]
   ↓
3. Dispense tokens FIRST
   - Generate tx_id: "a3f8c012"
   - POST /dispense {tx_id, quantity: 3}
   - Poll GET /dispense/a3f8c012 every 250ms
   - Show progress UI: ●●○ (2/3 tokens)
   ↓
4. Dispense completes → Create transaction for tokens
   - INSERT transactions_local (3 tokens, amount = 3 × price, dispenser_tx_id = "a3f8c012")
   ↓
5. Create transactions for regular products
   - INSERT transactions_local (2 beers)
   - INSERT transactions_local (1 snack)
   ↓
6. Show confirmation screen
   - [Done] or [Continue Shopping]
```

---

## User Interface Flow

### Step 1: Member Taps "Buy" in Cart

If cart contains tokens → Show dispensing progress overlay
If cart has only regular products → Skip to transaction creation

### Step 2: Dispensing Progress Overlay

```
┌─────────────────────────────────────┐
│  Dispensing Sauna Tokens...         │
│                                     │
│  Progress: ●●○ (2/3 tokens)         │
│                                     │
│  [Animated dispenser icon]          │
│                                     │
│  Please wait...                     │
└─────────────────────────────────────┘
```

- Poll `GET /dispense/{tx_id}` every 250ms
- Update `●●○` indicator based on `dispensed` count
- Cannot dismiss/cancel (tokens are physically dispensing)

### Step 3: After Dispense Completes → Create Transactions

**Full Success (3/3):**
```
┌─────────────────────────────────────┐
│  ✅ Purchase Complete!               │
│                                     │
│  3 sauna tokens                     │
│  2 beers                            │
│  1 snack                            │
│                                     │
│  Total charged: €15.00              │
│  New balance: €48.30                │
│                                     │
│     [Done]  [Continue Shopping]     │
└─────────────────────────────────────┘
```

**Partial Success (2/3 tokens due to jam):**
```
┌─────────────────────────────────────┐
│  ⚠️ Only 2 tokens dispensed          │
│                                     │
│  You have been charged for           │
│  2 sauna tokens only.               │
│  Sorry for the inconvenience.       │
│                                     │
│  • 2 sauna tokens  €6.00            │
│  • 2 beers         €5.00            │
│  • 1 snack         €1.50            │
│                                     │
│  Total: €12.50 (not €15.00)         │
│  New balance: €45.80                │
│                                     │
│     [Done]  [Continue Shopping]     │
└─────────────────────────────────────┘
```

---

## Data Model Changes

### Products Cache (Terminal SQLite)

**Add `requires_dispenser` field:**

```sql
CREATE TABLE IF NOT EXISTS products_cache (
    id TEXT PRIMARY KEY,
    category_id TEXT NOT NULL REFERENCES categories_cache(id),
    names TEXT NOT NULL,
    descriptions TEXT,
    price_cents INTEGER NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    requires_dispenser INTEGER NOT NULL DEFAULT 0,  -- NEW FIELD
    updated_at TEXT NOT NULL
);
```

### Dispenser Configuration (Terminal)

**Extend `config.json` file** (same file as backend credentials):

**Location:**
- macOS: `~/Library/Containers/de.clubbar.clubbarTerminal/Data/Library/Application Support/de.clubbar.clubbarTerminal/config.json`
- Linux: `~/.config/de.clubbar.clubbarTerminal/config.json`
- Windows: `%APPDATA%\de.clubbar.clubbarTerminal\config.json`

**Extended structure:**
```json
{
  "terminalId": "terminal-001",
  "apiUrl": "http://localhost:8080/api",
  "apiToken": "secret-token-here",

  "dispenser": {
    "enabled": true,
    "baseUrl": "http://192.168.4.20",
    "apiKey": "dispenser-secret-key",
    "timeoutMs": 3000,
    "pollIntervalMs": 250
  }
}
```

**Environment variable overrides:**
- `DISPENSER_ENABLED` (true/false)
- `DISPENSER_BASE_URL` (ESP8266 IP)
- `DISPENSER_API_KEY` (authentication key)

**ConfigService modifications:**
```dart
class ConfigService {
  // Existing fields...
  bool _dispenserEnabled = false;
  String? _dispenserBaseUrl;
  String? _dispenserApiKey;
  int _dispenserTimeoutMs = 3000;
  int _dispenserPollIntervalMs = 250;

  bool get dispenserEnabled => _dispenserEnabled;
  String? get dispenserBaseUrl => _dispenserBaseUrl;
  String? get dispenserApiKey => _dispenserApiKey;
  // ...
}
```

### Transactions Local (Terminal SQLite)

**Add dispenser metadata fields:**

```sql
CREATE TABLE IF NOT EXISTS transactions_local (
    id TEXT PRIMARY KEY,
    member_id TEXT NOT NULL REFERENCES members_cache(id),
    product_id TEXT REFERENCES products_cache(id),
    amount_cents INTEGER NOT NULL,
    transaction_type TEXT NOT NULL DEFAULT 'purchase',
    notes TEXT,
    created_at TEXT NOT NULL,
    synced INTEGER NOT NULL DEFAULT 0,
    dispenser_tx_id TEXT,                      -- NEW: ESP8266 tx_id
    dispenser_requested INTEGER DEFAULT NULL,  -- NEW: requested quantity
    dispenser_actual INTEGER DEFAULT NULL      -- NEW: actual dispensed
);
```

**Example transaction record (partial dispense):**
```json
{
  "id": "client-uuid-789",
  "member_id": "member-uuid-123",
  "product_id": "sauna-token-uuid",
  "amount_cents": 600,                    // 2 tokens × €3.00
  "transaction_type": "purchase",
  "created_at": "2026-02-14T18:30:15Z",
  "synced": 0,
  "dispenser_tx_id": "a3f8c012",          // ESP8266 transaction ID
  "dispenser_requested": 3,               // Asked for 3 tokens
  "dispenser_actual": 2                   // Only got 2 tokens
}
```

**Benefits:**
- Audit trail: trace back to exact ESP8266 transaction
- Reconciliation: query `GET /dispense/{dispenser_tx_id}` on crash recovery
- Transparency: clear record of requested vs. actual for partial dispenses

---

## Product Visibility Logic

### Terminal Filtering

**Products requiring dispenser are hidden if dispenser not configured:**

```dart
List<Product> getVisibleProducts(String categoryId) {
  final dispenserEnabled = configService.dispenserEnabled;

  return productsCache
    .where((p) => p.categoryId == categoryId)
    .where((p) => p.isActive == 1)
    .where((p) {
      // Hide dispenser products if dispenser not configured
      if (p.requiresDispenser == 1 && !dispenserEnabled) {
        return false;
      }
      return true;
    })
    .toList();
}
```

**User experience:**
- If dispenser enabled: All active products shown (including sauna tokens)
- If dispenser disabled: Only non-dispenser products shown
- No error message needed (products just don't appear)

---

## Backend Extensions

### Database Schema

**Add `requires_dispenser` column to `products` table:**

```sql
-- Migration: add_requires_dispenser_to_products.sql
ALTER TABLE products
ADD COLUMN requires_dispenser TINYINT(1) NOT NULL DEFAULT 0
AFTER is_active;

CREATE INDEX idx_products_requires_dispenser
ON products(requires_dispenser);
```

### API Response Extension

**Sync endpoint includes new field:**

```json
GET /api/sync/products?since=2026-02-14T10:00:00Z

Response:
{
  "products": [
    {
      "id": "uuid-123",
      "category_id": "uuid-cat",
      "names": {"de": "Sauna-Token", "en": "Sauna Token"},
      "price_cents": 300,
      "is_active": 1,
      "requires_dispenser": 1,  // NEW FIELD
      "updated_at": "2026-02-14T12:30:00Z"
    }
  ]
}
```

### Backend Service Changes

**ProductService:**
```php
public function createProduct(CreateProductRequest $request): ProductDTO
{
    $product = $this->productRepository->create([
        // ... existing fields
        'requires_dispenser' => $request->requiresDispenser(), // NEW
    ]);

    return ProductDTO::fromModel($product);
}
```

**ProductDTO:**
```php
class ProductDTO
{
    public function __construct(
        // ... existing fields
        public bool $requiresDispenser, // NEW
    ) {}
}
```

---

## Admin Frontend Extensions

### Product Form

**Add checkbox to create/edit form:**

```tsx
<FormField>
  <Label>Requires Physical Dispenser</Label>
  <Checkbox
    checked={formData.requiresDispenser}
    onChange={(e) => setFormData({
      ...formData,
      requiresDispenser: e.target.checked
    })}
  />
  <HelpText>
    Check this if the product requires a physical token dispenser.
    Terminals without a configured dispenser will not show this product.
  </HelpText>
</FormField>
```

**Form layout:**
```
┌─────────────────────────────────────────┐
│ Create Product                          │
│                                         │
│ Name (German): [Sauna-Token          ] │
│ Name (English): [Sauna Token         ] │
│ Price: [3.00] EUR                       │
│ Category: [Wellness        ▼]           │
│                                         │
│ ☑ Active (visible in terminal)          │
│ ☑ Requires Physical Dispenser           │
│   ℹ️ Terminals without dispenser won't   │
│     show this product                   │
│                                         │
│         [Cancel]  [Save Product]        │
└─────────────────────────────────────────┘
```

---

## Error Handling & Recovery

### Consistent Error Handling for "Cannot Proceed" Scenarios

**All blocking errors use same UX:**

| Error Type | Screen Message | Options |
|------------|---------------|---------|
| Dispenser Busy | "Dispenser Busy" + retry timer | [Cancel & Back to Cart] / [Buy All Products But Tokens] |
| Dispenser Unreachable | "Cannot Connect to Dispenser" | [Cancel & Back to Cart] / [Buy All Products But Tokens] |

### Scenario 1: Dispenser Busy (409 Conflict)

```
┌─────────────────────────────────────┐
│  ⏳ Dispenser Busy                   │
│                                     │
│  Another customer is using the      │
│  token dispenser.                   │
│                                     │
│  Retrying in 3 seconds...           │
│  (Attempt 1 of 3)                   │
│                                     │
│  [Cancel & Back to Cart]            │
│  [Buy All Products But Tokens]      │
└─────────────────────────────────────┘
```

**Retry logic:**
- Automatic retry every 3 seconds (up to 3 attempts)
- If successful: Continue with dispense
- If still busy: Keep showing error screen (user decides)

### Scenario 2: Dispenser Offline/Unreachable

```
┌─────────────────────────────────────┐
│  ❌ Cannot Connect to Dispenser      │
│                                     │
│  The token dispenser is not         │
│  responding.                        │
│                                     │
│  You can still purchase other       │
│  items without tokens.              │
│                                     │
│  [Cancel & Back to Cart]            │
│  [Buy All Products But Tokens]      │
└─────────────────────────────────────┘
```

### Scenario 3: Partial Dispense (Jam/Hardware Error)

**ESP8266 response:**
```json
{
  "tx_id": "a3f8c012",
  "state": "error",
  "quantity": 3,
  "dispensed": 2
}
```

**Terminal shows confirmation (not error):**
```
┌─────────────────────────────────────┐
│  ⚠️ Only 2 tokens dispensed          │
│                                     │
│  You have been charged for           │
│  2 sauna tokens only.               │
│  Sorry for the inconvenience.       │
│                                     │
│  Charged:                           │
│  • 2 sauna tokens  €6.00            │
│  • 2 beers         €5.00            │
│  • 1 snack         €1.50            │
│                                     │
│  Total: €12.50 (not €15.00)         │
│  New balance: €45.80                │
│                                     │
│     [Done]  [Continue Shopping]     │
└─────────────────────────────────────┘
```

### Scenario 4: Complete Dispense Failure (0 tokens)

```
┌─────────────────────────────────────┐
│  ❌ Token Dispense Failed            │
│                                     │
│  No tokens were dispensed.          │
│  You have NOT been charged for      │
│  tokens.                            │
│                                     │
│  Other items charged:               │
│  • 2 beers         €5.00            │
│  • 1 snack         €1.50            │
│                                     │
│  Total: €6.50 (tokens not charged)  │
│  New balance: €39.80                │
│                                     │
│     [Done]  [Continue Shopping]     │
└─────────────────────────────────────┘
```

### Crash Recovery

**On terminal restart:**

```dart
Future<void> recoverIncompleteDispenses() async {
  final incompleteTxs = await db.query(
    'transactions_local',
    where: 'dispenser_tx_id IS NOT NULL AND synced = 0'
  );

  for (final tx in incompleteTxs) {
    final dispenserTxId = tx['dispenser_tx_id'];

    try {
      // Query ESP8266 for actual state
      final response = await dispenserClient.getStatus(dispenserTxId);

      if (response.state == 'done') {
        // Dispense completed during crash
        await updateTransaction(tx['id'],
          synced: 1,
          actualDispensed: response.dispensed
        );
      } else if (response.state == 'error') {
        // Partial/failed - update amount based on actual count
        final actualAmount = response.dispensed * pricePerToken;
        await updateTransaction(tx['id'],
          amountCents: actualAmount,
          actualDispensed: response.dispensed
        );
      }
    } catch (e) {
      // ESP8266 unreachable - log for manual reconciliation
      logger.error('Cannot recover dispense tx: $dispenserTxId');
    }
  }
}
```

**Key principle:** ESP8266 is source of truth for dispense outcome. Terminal reconciles on boot.

---

## Terminal Info Screen Extension

### Dispenser Health Display

**Add dispenser section (if configured):**

```
┌─────────────────────────────────────┐
│  Terminal Information               │
│                                     │
│  Terminal ID: terminal-001          │
│                                     │
│  Backend Connectivity:              │
│  ✅ Connected                        │
│  URL: http://localhost:8080/api     │
│  Last sync: 2 minutes ago           │
│                                     │
│  Token Dispenser:                   │
│  ✅ Operational                      │
│  URL: http://192.168.4.20           │
│  Status: idle                       │
│  Success rate: 95.4%                │
│  Total dispenses: 1,247             │
│  Jams: 3 (0.24%)                    │
│                                     │
│                [Close]              │
└─────────────────────────────────────┘
```

### Health States

**Operational:**
```
Token Dispenser:
✅ Operational
Status: idle
Success rate: 95.4%
```

**Busy:**
```
Token Dispenser:
⏳ Busy (dispensing)
Status: dispensing
```

**Error/Jammed:**
```
Token Dispenser:
❌ Error (jammed)
Status: error
Last error: JAM_PERMANENT
Contact staff for maintenance
```

**Offline:**
```
Token Dispenser:
❌ Offline
Cannot connect to dispenser
Check network and power
```

### Health Polling Service

**Background health check** (runs every 60 seconds):

```dart
class DispenserHealthService {
  Timer? _healthTimer;
  DispenserHealth? _lastHealth;

  void startHealthMonitoring() {
    if (!configService.dispenserEnabled) return;

    _healthTimer = Timer.periodic(Duration(seconds: 60), (_) async {
      try {
        final response = await http.get(
          '${configService.dispenserBaseUrl}/health'
        );

        _lastHealth = DispenserHealth.fromJson(response.data);

        if (_lastHealth!.dispenser == 'error') {
          logger.warning('Dispenser in error state');
        }
      } catch (e) {
        _lastHealth = DispenserHealth.offline();
        logger.error('Dispenser health check failed: $e');
      }
    });
  }
}
```

---

## Testing Strategy

### Test Pyramid

```
        /\
       /  \  E2E Tests (Playwright)
      /────\  - Full integration (mock dispenser)
     /      \ - UI flows, error scenarios
    /────────\ API Tests (Playwright)
   /          \ - Backend endpoints
  /────────────\ - Dispenser protocol validation
 /──────────────\ Unit Tests (Dart/PHP/TypeScript)
/────────────────\ - Business logic, error handling
```

### Automated vs. Manual Testing

**Automated Tests (CI/CD):**
- Mock Dispenser: HTTP server simulating ESP8266 API responses
- Simulated states: Success, partial dispense, busy, error, offline
- No physical hardware required
- Fast execution

**Manual Tests (Real Hardware):**
- Actual ESP8266 + Azkoyen Hopper
- Real network conditions, hardware errors
- End-to-end validation with physical tokens
- Manual test checklist provided

### Unit Tests

**Backend (PHPUnit):**
- Product service with `requires_dispenser` field
- Product sync includes new field
- Transaction stores dispenser metadata

**Terminal (Dart):**
- Product filtering (hide dispenser products when disabled)
- Dispenser client (tx_id generation, polling logic)
- Crash recovery logic

### API Tests (Playwright)

**Backend:**
- POST/PUT /api/admin/products with `requires_dispenser`
- GET /api/sync/products returns new field

**Mock Dispenser:**
- POST /dispense returns dispensing state
- GET /dispense/{tx_id} returns progress
- GET /health returns metrics

### E2E Tests (Playwright)

**Full Integration (Mock Dispenser):**
- Successfully dispense tokens and create transaction
- Handle partial dispense (charge for actual count)
- Handle dispenser busy (show error, allow buying without tokens)
- Hide token products when dispenser disabled
- Mixed cart (tokens + regular products)
- Crash recovery scenarios

**Admin Frontend:**
- Create product with dispenser requirement checkbox
- Edit product to toggle dispenser requirement
- Display dispenser badge in product list

### Test Coverage Targets

| Layer | Coverage Target | Priority Tests |
|-------|----------------|----------------|
| Backend Unit | >80% | Product service, sync endpoints |
| Terminal Unit | >80% | Product filtering, dispenser client, recovery |
| API Tests | 100% endpoints | All dispenser API, product CRUD |
| E2E Tests | Happy + critical paths | Full dispense, partial, errors |

---

## Implementation Phases

### Phase 1: Backend & Database
- Add migration for `requires_dispenser` column
- Update ProductDTO, ProductService, repositories
- Extend sync endpoint responses
- Unit tests for backend changes

### Phase 2: Admin Frontend
- Add checkbox to product form
- Display dispenser badge in product list
- E2E tests for product CRUD

### Phase 3: Terminal - Core Integration
- Extend ConfigService for dispenser settings
- Implement DispenserClient service
- Modify checkout flow (dispense → poll → create transactions)
- Product filtering logic
- Unit tests for terminal services

### Phase 4: Terminal - Error Handling & Recovery
- Implement error handling (busy, offline, partial)
- Implement crash recovery logic
- Add health monitoring service
- Extend terminal info screen
- E2E tests with mock dispenser

### Phase 5: Testing & Documentation
- Complete unit test coverage
- Complete API tests
- Complete E2E tests
- Create manual hardware test plan
- Update use cases and ADRs

### Phase 6: Manual Hardware Validation
- Configure real ESP8266 + Azkoyen Hopper
- Execute manual test checklist
- Validate crash recovery scenarios
- Performance testing

---

## References

- **Dispenser Protocol**: `/Users/dg/dev/remote-token-dispenser/dispenser-protocol.md`
- **Dispenser Architecture**: `/Users/dg/dev/remote-token-dispenser/ARCHITECTURE.md`
- **Terminal ERM**: `/Users/dg/dev/frgs-vereinsbar/docs/erm-frontend.md`
- **Backend ERM**: `/Users/dg/dev/frgs-verenigsbar/docs/erm-master.md`
- **ADR-0022**: Test Strategy and Automation
- **UC-T01**: Book Product to Tab
- **UC-T11**: Shopping Cart Management

---

## Success Criteria

✅ Members can purchase sauna tokens alongside regular products
✅ Tokens are physically dispensed before payment is recorded
✅ Partial dispenses charge only for tokens actually received
✅ Terminal gracefully handles dispenser errors (busy, offline, jammed)
✅ Terminal recovers correctly from crashes during dispensing
✅ Dispenser health is visible on terminal info screen
✅ Products requiring dispenser are hidden when dispenser not configured
✅ Admin can mark products as requiring physical dispenser
✅ All tests pass (unit, API, E2E with mock)
✅ Manual hardware validation complete
