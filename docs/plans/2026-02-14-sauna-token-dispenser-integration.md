# Sauna Token Dispenser Integration - Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Integrate physical token dispenser (ESP8266 + Azkoyen Hopper) into terminal for purchasing sauna tokens with dispense-first, pay-after model.

**Architecture:** Products marked with `requires_dispenser` flag. Terminal detects tokens in cart during checkout, dispenses tokens first via ESP8266 HTTP API, polls for completion, then creates transaction with actual dispensed count. Backend and admin extended to support the flag.

**Tech Stack:** PHP 8.3 (backend), React/TypeScript (admin), Flutter/Dart (terminal), Playwright (E2E tests), PHPUnit (backend tests)

**Design Document:** `docs/plans/2026-02-14-sauna-token-dispenser-integration-design.md`

---

## Phase 1: Backend & Database

### Task 1.1: Database Migration - Add requires_dispenser Column

**Files:**
- Create: `backend/database/migrations/2026_02_14_100000_add_requires_dispenser_to_products.php`
- Reference: `backend/database/migrations/` (existing migration examples)

**Step 1: Write migration file**

Create migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('requires_dispenser')
                  ->default(false)
                  ->after('is_active');

            $table->index('requires_dispenser');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['requires_dispenser']);
            $table->dropColumn('requires_dispenser');
        });
    }
};
```

**Step 2: Run migration**

```bash
cd backend
docker compose exec backend php artisan migrate
```

Expected output: "Migration completed successfully"

**Step 3: Verify migration**

```bash
docker compose exec database mysql -u root -proot clubbar -e "DESCRIBE products;"
```

Expected: Column `requires_dispenser` present (TINYINT(1), default 0)

**Step 4: Commit**

```bash
git add backend/database/migrations/2026_02_14_100000_add_requires_dispenser_to_products.php
git commit -m "feat(backend): add requires_dispenser column to products table

Migration adds boolean flag to mark products requiring physical dispenser.
Includes index for filtering queries.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 1.2: Update ProductDTO - Add requiresDispenser Field

**Files:**
- Modify: `backend/app/Modules/Products/DTOs/ProductDTO.php`
- Reference: `backend/patterns/003-data-transfer-objects.md`

**Step 1: Write failing test**

Create test file if not exists, or add test:

```php
// backend/tests/Unit/ProductDTOTest.php

public function test_product_dto_includes_requires_dispenser_field()
{
    $product = new \stdClass();
    $product->id = 'uuid-123';
    $product->category_id = 'cat-uuid';
    $product->names = json_encode(['de' => 'Token', 'en' => 'Token']);
    $product->descriptions = null;
    $product->price_cents = 300;
    $product->is_active = 1;
    $product->requires_dispenser = 1; // NEW FIELD
    $product->updated_at = '2026-02-14 10:00:00';

    $dto = ProductDTO::fromModel($product);

    $this->assertTrue($dto->requiresDispenser);
    $this->assertEquals(1, $dto->toArray()['requires_dispenser']);
}
```

**Step 2: Run test to verify it fails**

```bash
cd backend
php artisan test --filter=test_product_dto_includes_requires_dispenser_field
```

Expected: FAIL with "Undefined property: requiresDispenser"

**Step 3: Implement ProductDTO changes**

Modify `backend/app/Modules/Products/DTOs/ProductDTO.php`:

```php
class ProductDTO
{
    public function __construct(
        public string $id,
        public string $categoryId,
        public array $names,
        public ?array $descriptions,
        public int $priceCents,
        public bool $isActive,
        public bool $requiresDispenser, // NEW FIELD
        public string $updatedAt
    ) {}

    public static function fromModel($product): self
    {
        return new self(
            id: $product->id,
            categoryId: $product->category_id,
            names: json_decode($product->names, true),
            descriptions: $product->descriptions ? json_decode($product->descriptions, true) : null,
            priceCents: (int) $product->price_cents,
            isActive: (bool) $product->is_active,
            requiresDispenser: (bool) $product->requires_dispenser, // NEW FIELD
            updatedAt: $product->updated_at
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->categoryId,
            'names' => $this->names,
            'descriptions' => $this->descriptions,
            'price_cents' => $this->priceCents,
            'is_active' => $this->isActive ? 1 : 0,
            'requires_dispenser' => $this->requiresDispenser ? 1 : 0, // NEW FIELD
            'updated_at' => $this->updatedAt,
        ];
    }
}
```

**Step 4: Run test to verify it passes**

```bash
php artisan test --filter=test_product_dto_includes_requires_dispenser_field
```

Expected: PASS

**Step 5: Commit**

```bash
git add backend/app/Modules/Products/DTOs/ProductDTO.php backend/tests/Unit/ProductDTOTest.php
git commit -m "feat(backend): add requiresDispenser to ProductDTO

Add boolean field for marking products requiring physical dispenser.
Updated fromModel and toArray methods.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 1.3: Update Product Service - Handle requiresDispenser in Create/Update

**Files:**
- Modify: `backend/app/Modules/Products/Services/ProductService.php`
- Modify: `backend/app/Modules/Products/Http/Requests/CreateProductRequest.php`
- Modify: `backend/app/Modules/Products/Http/Requests/UpdateProductRequest.php`
- Reference: `backend/patterns/004-service-layer.md`, `backend/patterns/001-form-requests.md`

**Step 1: Write failing test**

```php
// backend/tests/Feature/ProductServiceTest.php

public function test_create_product_with_requires_dispenser_flag()
{
    $data = [
        'category_id' => 'cat-uuid',
        'names' => ['de' => 'Sauna-Token', 'en' => 'Sauna Token'],
        'descriptions' => ['de' => '1 Token für Sauna', 'en' => '1 token for sauna'],
        'price_cents' => 300,
        'is_active' => true,
        'requires_dispenser' => true, // NEW FIELD
    ];

    $request = CreateProductRequest::createFromBase(
        Request::create('/api/admin/products', 'POST', $data)
    );

    $product = $this->productService->createProduct($request);

    $this->assertTrue($product->requiresDispenser);
}
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --filter=test_create_product_with_requires_dispenser_flag
```

Expected: FAIL

**Step 3: Update CreateProductRequest**

Modify `backend/app/Modules/Products/Http/Requests/CreateProductRequest.php`:

```php
public function rules(): array
{
    return [
        'category_id' => ['required', 'string', 'exists:categories,id'],
        'names' => ['required', 'array'],
        'names.de' => ['required', 'string', 'max:255'],
        'names.en' => ['required', 'string', 'max:255'],
        'descriptions' => ['nullable', 'array'],
        'price_cents' => ['required', 'integer', 'min:0'],
        'is_active' => ['boolean'],
        'requires_dispenser' => ['boolean'], // NEW RULE
    ];
}

public function requiresDispenser(): bool
{
    return (bool) $this->input('requires_dispenser', false);
}
```

**Step 4: Update UpdateProductRequest (same pattern)**

Modify `backend/app/Modules/Products/Http/Requests/UpdateProductRequest.php`:

```php
public function rules(): array
{
    return [
        // ... existing rules
        'requires_dispenser' => ['boolean'], // NEW RULE
    ];
}

public function requiresDispenser(): bool
{
    return (bool) $this->input('requires_dispenser', false);
}
```

**Step 5: Update ProductService**

Modify `backend/app/Modules/Products/Services/ProductService.php`:

```php
public function createProduct(CreateProductRequest $request): ProductDTO
{
    $product = $this->productRepository->create([
        'id' => Uuid::uuid4()->toString(),
        'category_id' => $request->categoryId(),
        'names' => json_encode($request->names()),
        'descriptions' => $request->descriptions() ? json_encode($request->descriptions()) : null,
        'price_cents' => $request->priceCents(),
        'is_active' => $request->isActive(),
        'requires_dispenser' => $request->requiresDispenser(), // NEW FIELD
    ]);

    return ProductDTO::fromModel($product);
}

public function updateProduct(string $id, UpdateProductRequest $request): ProductDTO
{
    $product = $this->productRepository->update($id, [
        'category_id' => $request->categoryId(),
        'names' => json_encode($request->names()),
        'descriptions' => $request->descriptions() ? json_encode($request->descriptions()) : null,
        'price_cents' => $request->priceCents(),
        'is_active' => $request->isActive(),
        'requires_dispenser' => $request->requiresDispenser(), // NEW FIELD
    ]);

    return ProductDTO::fromModel($product);
}
```

**Step 6: Run test to verify it passes**

```bash
php artisan test --filter=test_create_product_with_requires_dispenser_flag
```

Expected: PASS

**Step 7: Commit**

```bash
git add backend/app/Modules/Products/Services/ProductService.php \
        backend/app/Modules/Products/Http/Requests/CreateProductRequest.php \
        backend/app/Modules/Products/Http/Requests/UpdateProductRequest.php \
        backend/tests/Feature/ProductServiceTest.php
git commit -m "feat(backend): add requiresDispenser to product create/update

Updated form requests and service layer to handle dispenser flag.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 1.4: Update Sync Endpoint - Include requiresDispenser in Response

**Files:**
- Modify: `backend/app/Modules/Sync/Services/SyncService.php`
- Test: E2E test in Phase 5

**Step 1: Write API test**

Create test file (will run in Phase 5, but write now for reference):

```typescript
// e2etests/tests/api/sync-products.spec.ts

test('GET /api/sync/products includes requires_dispenser field', async ({ request }) => {
  // Seed product with requires_dispenser = 1
  await seedProduct({
    id: 'token-uuid',
    names: { de: 'Sauna-Token', en: 'Sauna Token' },
    requires_dispenser: 1
  });

  const response = await request.get('/api/sync/products');
  expect(response.status()).toBe(200);

  const data = await response.json();
  const tokenProduct = data.products.find(p => p.id === 'token-uuid');

  expect(tokenProduct).toBeDefined();
  expect(tokenProduct.requires_dispenser).toBe(1);
});
```

**Step 2: Verify SyncService returns DTOs**

Check `backend/app/Modules/Sync/Services/SyncService.php`:

```php
public function getProductsDelta(?string $since): array
{
    $products = $this->productRepository->getUpdatedSince($since);

    return [
        'products' => array_map(
            fn($product) => ProductDTO::fromModel($product)->toArray(),
            $products
        ),
        'last_sync' => now()->toIso8601String(),
    ];
}
```

The DTO already includes `requires_dispenser` from Task 1.2, so no code changes needed here.

**Step 3: Manual verification**

```bash
# Seed a product with requires_dispenser = 1
docker compose exec database mysql -u root -proot clubbar -e \
  "INSERT INTO products (id, category_id, names, price_cents, is_active, requires_dispenser)
   VALUES ('test-token', 'cat-uuid', '{\"de\": \"Token\"}', 300, 1, 1);"

# Call sync endpoint
curl -s http://localhost:8080/api/sync/products | jq '.products[] | select(.id == "test-token")'
```

Expected: Output includes `"requires_dispenser": 1`

**Step 4: Commit (documentation update)**

```bash
git add e2etests/tests/api/sync-products.spec.ts
git commit -m "test(e2e): add test for requires_dispenser in sync endpoint

Test will be run in Phase 5.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 1.5: Update Transaction Schema - Add Dispenser Metadata Fields

**Files:**
- Create: `backend/database/migrations/2026_02_14_110000_add_dispenser_fields_to_transactions.php`

**Step 1: Write migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('dispenser_tx_id', 16)->nullable()->after('notes');
            $table->integer('dispenser_requested')->nullable()->after('dispenser_tx_id');
            $table->integer('dispenser_actual')->nullable()->after('dispenser_requested');

            $table->index('dispenser_tx_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['dispenser_tx_id']);
            $table->dropColumn(['dispenser_tx_id', 'dispenser_requested', 'dispenser_actual']);
        });
    }
};
```

**Step 2: Run migration**

```bash
cd backend
docker compose exec backend php artisan migrate
```

Expected: "Migration completed successfully"

**Step 3: Verify**

```bash
docker compose exec database mysql -u root -proot clubbar -e "DESCRIBE transactions;"
```

Expected: Columns `dispenser_tx_id`, `dispenser_requested`, `dispenser_actual` present

**Step 4: Commit**

```bash
git add backend/database/migrations/2026_02_14_110000_add_dispenser_fields_to_transactions.php
git commit -m "feat(backend): add dispenser metadata fields to transactions

Adds fields for tracking dispenser transaction ID, requested quantity,
and actual dispensed count for audit trail and reconciliation.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 1.6: Update Transaction Service - Store Dispenser Metadata

**Files:**
- Modify: `backend/app/Modules/Transactions/Services/TransactionService.php`
- Modify: `backend/app/Modules/Transactions/Http/Requests/CreateTransactionRequest.php`

**Step 1: Write failing test**

```php
// backend/tests/Feature/TransactionServiceTest.php

public function test_transaction_stores_dispenser_metadata()
{
    $data = [
        'member_id' => 'member-uuid',
        'product_id' => 'token-uuid',
        'amount_cents' => 600,
        'transaction_type' => 'purchase',
        'dispenser_tx_id' => 'a3f8c012',
        'dispenser_requested' => 3,
        'dispenser_actual' => 2,
    ];

    $transaction = $this->transactionService->createTransaction($data);

    $this->assertEquals('a3f8c012', $transaction->dispenserTxId);
    $this->assertEquals(3, $transaction->dispenserRequested);
    $this->assertEquals(2, $transaction->dispenserActual);
}
```

**Step 2: Run test**

```bash
php artisan test --filter=test_transaction_stores_dispenser_metadata
```

Expected: FAIL

**Step 3: Update CreateTransactionRequest**

```php
public function rules(): array
{
    return [
        // ... existing rules
        'dispenser_tx_id' => ['nullable', 'string', 'max:16'],
        'dispenser_requested' => ['nullable', 'integer', 'min:0'],
        'dispenser_actual' => ['nullable', 'integer', 'min:0'],
    ];
}

public function dispenserTxId(): ?string
{
    return $this->input('dispenser_tx_id');
}

public function dispenserRequested(): ?int
{
    return $this->input('dispenser_requested');
}

public function dispenserActual(): ?int
{
    return $this->input('dispenser_actual');
}
```

**Step 4: Update TransactionService**

```php
public function createTransaction(array $data): TransactionDTO
{
    $transaction = $this->transactionRepository->create([
        'id' => $data['id'] ?? Uuid::uuid4()->toString(),
        'member_id' => $data['member_id'],
        'product_id' => $data['product_id'] ?? null,
        'amount_cents' => $data['amount_cents'],
        'transaction_type' => $data['transaction_type'] ?? 'purchase',
        'notes' => $data['notes'] ?? null,
        'dispenser_tx_id' => $data['dispenser_tx_id'] ?? null,
        'dispenser_requested' => $data['dispenser_requested'] ?? null,
        'dispenser_actual' => $data['dispenser_actual'] ?? null,
        'created_at' => now(),
    ]);

    return TransactionDTO::fromModel($transaction);
}
```

**Step 5: Run test**

```bash
php artisan test --filter=test_transaction_stores_dispenser_metadata
```

Expected: PASS

**Step 6: Commit**

```bash
git add backend/app/Modules/Transactions/Services/TransactionService.php \
        backend/app/Modules/Transactions/Http/Requests/CreateTransactionRequest.php \
        backend/tests/Feature/TransactionServiceTest.php
git commit -m "feat(backend): store dispenser metadata in transactions

Transaction service now accepts and stores dispenser tx_id, requested,
and actual counts for audit trail.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Phase 2: Admin Frontend

### Task 2.1: Add Checkbox to Product Form

**Files:**
- Modify: `admin-frontend/src/components/products/ProductForm.tsx`
- Reference: `admin-frontend/patterns/test-ids.md`

**Step 1: Add checkbox field to form**

```tsx
// In ProductForm.tsx, add after isActive checkbox:

<FormField>
  <Label htmlFor="requires-dispenser">
    Requires Physical Dispenser
  </Label>
  <Checkbox
    id="requires-dispenser"
    data-testid="requires-dispenser-checkbox"
    checked={formData.requiresDispenser || false}
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

**Step 2: Update form state interface**

```tsx
interface ProductFormData {
  names: { de: string; en: string };
  descriptions?: { de: string; en: string };
  priceCents: number;
  categoryId: string;
  isActive: boolean;
  requiresDispenser: boolean; // NEW FIELD
}
```

**Step 3: Update form submission**

```tsx
const handleSubmit = async (e: FormEvent) => {
  e.preventDefault();

  const payload = {
    names: formData.names,
    descriptions: formData.descriptions,
    price_cents: formData.priceCents,
    category_id: formData.categoryId,
    is_active: formData.isActive,
    requires_dispenser: formData.requiresDispenser, // NEW FIELD
  };

  // ... rest of submit logic
};
```

**Step 4: Manual verification**

```bash
cd admin-frontend
npm run dev
```

Open browser → Products → Create Product → Verify checkbox appears

**Step 5: Commit**

```bash
git add admin-frontend/src/components/products/ProductForm.tsx
git commit -m "feat(admin): add requires dispenser checkbox to product form

Added checkbox with help text. Form now submits requiresDispenser field.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 2.2: Display Dispenser Badge in Product List

**Files:**
- Modify: `admin-frontend/src/components/products/ProductList.tsx`

**Step 1: Add badge component**

```tsx
// In ProductList.tsx table row:

<TableCell>
  {product.names.de}
  {product.requiresDispenser && (
    <Badge
      variant="secondary"
      data-testid="dispenser-badge"
      className="ml-2"
    >
      Dispenser
    </Badge>
  )}
</TableCell>
```

**Step 2: Update product interface**

```tsx
interface Product {
  id: string;
  names: { de: string; en: string };
  priceCents: number;
  categoryId: string;
  isActive: boolean;
  requiresDispenser: boolean; // NEW FIELD
  updatedAt: string;
}
```

**Step 3: Manual verification**

Create product with checkbox checked → Verify badge appears in list

**Step 4: Commit**

```bash
git add admin-frontend/src/components/products/ProductList.tsx
git commit -m "feat(admin): display dispenser badge in product list

Shows 'Dispenser' badge for products requiring physical dispenser.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 2.3: E2E Test for Product CRUD with Dispenser Flag

**Files:**
- Modify: `e2etests/tests/admin/products.spec.ts`
- Reference: `e2etests/patterns/001-test-data-isolation.md`

**Step 1: Write E2E test**

```typescript
// Add to e2etests/tests/admin/products.spec.ts

test('admin can create product with dispenser requirement', async ({ authenticatedProductsPage }) => {
  const testId = generateTestId();
  const productData = {
    nameDe: `Sauna-Token-${testId}`,
    nameEn: `Sauna-Token-${testId}`,
    price: '3.00',
    categoryId: 'wellness-cat-uuid',
    requiresDispenser: true,
  };

  await authenticatedProductsPage.navigate();
  await authenticatedProductsPage.clickCreateProduct();

  await authenticatedProductsPage.fillProductName('de', productData.nameDe);
  await authenticatedProductsPage.fillProductName('en', productData.nameEn);
  await authenticatedProductsPage.fillProductPrice(productData.price);
  await authenticatedProductsPage.selectCategory(productData.categoryId);
  await authenticatedProductsPage.checkRequiresDispenser();

  await authenticatedProductsPage.clickSaveProduct();

  // Verify in list
  const row = await authenticatedProductsPage.findProductRow(productData.nameDe);
  await expect(row.locator('[data-testid="dispenser-badge"]')).toBeVisible();
  await expect(row.locator('[data-testid="dispenser-badge"]')).toContainText('Dispenser');
});

test('admin can edit product to toggle dispenser requirement', async ({ authenticatedProductsPage }) => {
  const testId = generateTestId();

  // Create product without dispenser
  const productId = await seedProduct({
    names: { de: `Product-${testId}` },
    requiresDispenser: false
  });

  await authenticatedProductsPage.navigate();
  await authenticatedProductsPage.editProduct(productId);

  // Toggle dispenser checkbox
  await authenticatedProductsPage.checkRequiresDispenser();
  await authenticatedProductsPage.clickSaveProduct();

  // Verify badge appears
  const row = await authenticatedProductsPage.findProductRow(`Product-${testId}`);
  await expect(row.locator('[data-testid="dispenser-badge"]')).toBeVisible();
});
```

**Step 2: Run test**

```bash
cd e2etests
npm test -- tests/admin/products.spec.ts --grep "dispenser" --workers=1
```

Expected: PASS (if backend is running)

**Step 3: Commit**

```bash
git add e2etests/tests/admin/products.spec.ts
git commit -m "test(e2e): add tests for product dispenser requirement

Tests create and edit flows with requiresDispenser checkbox.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Phase 3: Terminal - Core Integration

### Task 3.1: Extend Terminal Database Schema

**Files:**
- Modify: `terminal-frontend/lib/database/database.dart`
- Reference: `docs/erm-frontend.md`

**Step 1: Update products_cache table**

```dart
// In database.dart, update _createProductsCacheTable:

static const String _createProductsCacheTable = '''
  CREATE TABLE IF NOT EXISTS products_cache (
    id TEXT PRIMARY KEY,
    category_id TEXT NOT NULL REFERENCES categories_cache(id),
    names TEXT NOT NULL,
    descriptions TEXT,
    price_cents INTEGER NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    requires_dispenser INTEGER NOT NULL DEFAULT 0,  -- NEW FIELD
    updated_at TEXT NOT NULL
  )
''';
```

**Step 2: Update transactions_local table**

```dart
static const String _createTransactionsLocalTable = '''
  CREATE TABLE IF NOT EXISTS transactions_local (
    id TEXT PRIMARY KEY,
    member_id TEXT NOT NULL REFERENCES members_cache(id),
    product_id TEXT REFERENCES products_cache(id),
    amount_cents INTEGER NOT NULL,
    transaction_type TEXT NOT NULL DEFAULT 'purchase',
    notes TEXT,
    created_at TEXT NOT NULL,
    synced INTEGER NOT NULL DEFAULT 0,
    dispenser_tx_id TEXT,                       -- NEW FIELD
    dispenser_requested INTEGER DEFAULT NULL,   -- NEW FIELD
    dispenser_actual INTEGER DEFAULT NULL       -- NEW FIELD
  )
''';
```

**Step 3: Add dispenser_config table**

```dart
static const String _createDispenserConfigTable = '''
  CREATE TABLE IF NOT EXISTS dispenser_config (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
  )
''';

// In initDatabase, add:
await db.execute(_createDispenserConfigTable);

// Initialize default config
await db.execute('''
  INSERT OR IGNORE INTO dispenser_config (key, value)
  VALUES
    ('enabled', '0'),
    ('base_url', ''),
    ('api_key', ''),
    ('timeout_ms', '3000'),
    ('poll_interval_ms', '250')
''');
```

**Step 4: Increment database version**

```dart
static const int _databaseVersion = 2; // Increment from 1
```

**Step 5: Add migration logic**

```dart
Future<void> _onUpgrade(Database db, int oldVersion, int newVersion) async {
  if (oldVersion < 2) {
    // Add requires_dispenser to products_cache
    await db.execute('ALTER TABLE products_cache ADD COLUMN requires_dispenser INTEGER NOT NULL DEFAULT 0');

    // Add dispenser fields to transactions_local
    await db.execute('ALTER TABLE transactions_local ADD COLUMN dispenser_tx_id TEXT');
    await db.execute('ALTER TABLE transactions_local ADD COLUMN dispenser_requested INTEGER DEFAULT NULL');
    await db.execute('ALTER TABLE transactions_local ADD COLUMN dispenser_actual INTEGER DEFAULT NULL');

    // Create dispenser_config table
    await db.execute(_createDispenserConfigTable);
    await db.execute('''
      INSERT OR IGNORE INTO dispenser_config (key, value)
      VALUES
        ('enabled', '0'),
        ('base_url', ''),
        ('api_key', ''),
        ('timeout_ms', '3000'),
        ('poll_interval_ms', '250')
    ''');
  }
}
```

**Step 6: Update openDatabase call**

```dart
_database = await openDatabase(
  path,
  version: _databaseVersion,
  onCreate: _onCreate,
  onUpgrade: _onUpgrade, // ADD THIS
);
```

**Step 7: Test migration (delete old DB and restart)**

```bash
cd terminal-frontend
# Delete old database
rm -rf build/macos/Build/Products/Debug/clubbar_terminal.app/Contents/Frameworks/App.framework/Resources/flutter_assets/database.db
flutter run
```

Expected: App starts, new schema applied

**Step 8: Commit**

```bash
git add terminal-frontend/lib/database/database.dart
git commit -m "feat(terminal): add dispenser fields to database schema

Added requires_dispenser to products_cache, dispenser metadata to
transactions_local, and dispenser_config table. Includes migration.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 3.2: Extend ConfigService for Dispenser

**Files:**
- Modify: `terminal-frontend/lib/services/config_service.dart`

**Step 1: Add dispenser config fields**

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
  int get dispenserTimeoutMs => _dispenserTimeoutMs;
  int get dispenserPollIntervalMs => _dispenserPollIntervalMs;
}
```

**Step 2: Update load() method**

```dart
Future<void> load() async {
  final configFile = await _getConfigFile();

  if (configFile.existsSync()) {
    try {
      final contents = configFile.readAsStringSync();
      final json = jsonDecode(contents) as Map<String, dynamic>;

      // Existing fields...
      _terminalId = json['terminalId'] as String?;
      _apiUrl = json['apiUrl'] as String?;
      _apiToken = json['apiToken'] as String?;

      // Dispenser config (NEW)
      final dispenser = json['dispenser'] as Map<String, dynamic>?;
      if (dispenser != null) {
        _dispenserEnabled = dispenser['enabled'] as bool? ?? false;
        _dispenserBaseUrl = dispenser['baseUrl'] as String?;
        _dispenserApiKey = dispenser['apiKey'] as String?;
        _dispenserTimeoutMs = dispenser['timeoutMs'] as int? ?? 3000;
        _dispenserPollIntervalMs = dispenser['pollIntervalMs'] as int? ?? 250;
      }
    } catch (_) {
      // Corrupt file — leave fields null
    }
  }

  // Environment variable overrides (NEW)
  final env = Platform.environment;
  if (env.containsKey('DISPENSER_ENABLED')) {
    _dispenserEnabled = env['DISPENSER_ENABLED']?.toLowerCase() == 'true';
  }
  if (env.containsKey('DISPENSER_BASE_URL')) {
    _dispenserBaseUrl = env['DISPENSER_BASE_URL'];
  }
  if (env.containsKey('DISPENSER_API_KEY')) {
    _dispenserApiKey = env['DISPENSER_API_KEY'];
  }
}
```

**Step 3: Update save() method**

```dart
Future<void> save({
  required String terminalId,
  required String apiUrl,
  required String apiToken,
  bool? dispenserEnabled,
  String? dispenserBaseUrl,
  String? dispenserApiKey,
}) async {
  _terminalId = terminalId;
  _apiUrl = apiUrl;
  _apiToken = apiToken;

  // Update dispenser config if provided
  if (dispenserEnabled != null) _dispenserEnabled = dispenserEnabled;
  if (dispenserBaseUrl != null) _dispenserBaseUrl = dispenserBaseUrl;
  if (dispenserApiKey != null) _dispenserApiKey = dispenserApiKey;

  final configFile = await _getConfigFile();
  final dir = configFile.parent;
  if (!dir.existsSync()) {
    dir.createSync(recursive: true);
  }

  final json = jsonEncode({
    'terminalId': terminalId,
    'apiUrl': apiUrl,
    'apiToken': apiToken,
    'dispenser': {
      'enabled': _dispenserEnabled,
      'baseUrl': _dispenserBaseUrl ?? '',
      'apiKey': _dispenserApiKey ?? '',
      'timeoutMs': _dispenserTimeoutMs,
      'pollIntervalMs': _dispenserPollIntervalMs,
    }
  });

  configFile.writeAsStringSync(json);

  if (!Platform.isWindows) {
    await Process.run('chmod', ['600', configFile.path]);
  }
}
```

**Step 4: Write unit test**

```dart
// terminal-frontend/test/services/config_service_test.dart

void main() {
  group('ConfigService - Dispenser Config', () {
    test('loads dispenser config from file', () async {
      final tempDir = Directory.systemTemp.createTempSync();
      final config = ConfigService(configDir: tempDir.path);

      final configFile = File('${tempDir.path}/config.json');
      configFile.writeAsStringSync(jsonEncode({
        'terminalId': 'test-terminal',
        'apiUrl': 'http://localhost',
        'apiToken': 'token',
        'dispenser': {
          'enabled': true,
          'baseUrl': 'http://192.168.4.20',
          'apiKey': 'secret-key',
        }
      }));

      await config.load();

      expect(config.dispenserEnabled, true);
      expect(config.dispenserBaseUrl, 'http://192.168.4.20');
      expect(config.dispenserApiKey, 'secret-key');

      tempDir.deleteSync(recursive: true);
    });

    test('environment variables override config file', () async {
      final tempDir = Directory.systemTemp.createTempSync();
      final config = ConfigService(configDir: tempDir.path);

      // Set env var (in real test, use process env)
      Platform.environment['DISPENSER_ENABLED'] = 'true';
      Platform.environment['DISPENSER_BASE_URL'] = 'http://override';

      await config.load();

      expect(config.dispenserEnabled, true);
      expect(config.dispenserBaseUrl, 'http://override');

      tempDir.deleteSync(recursive: true);
    });
  });
}
```

**Step 5: Run test**

```bash
cd terminal-frontend
flutter test test/services/config_service_test.dart
```

Expected: PASS

**Step 6: Commit**

```bash
git add terminal-frontend/lib/services/config_service.dart \
        terminal-frontend/test/services/config_service_test.dart
git commit -m "feat(terminal): add dispenser config to ConfigService

ConfigService now loads and saves dispenser settings (enabled, baseUrl,
apiKey). Supports environment variable overrides.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 3.3: Implement DispenserClient Service

**Files:**
- Create: `terminal-frontend/lib/services/dispenser_client.dart`
- Create: `terminal-frontend/test/services/dispenser_client_test.dart`

**Step 1: Write failing test**

```dart
// terminal-frontend/test/services/dispenser_client_test.dart

import 'package:flutter_test/flutter_test.dart';
import 'package:mockito/mockito.dart';
import 'package:http/http.dart' as http;

void main() {
  group('DispenserClient', () {
    test('generates unique tx_id', () {
      final client = DispenserClient(
        baseUrl: 'http://localhost',
        apiKey: 'test-key',
      );

      final txId1 = client.generateTxId();
      final txId2 = client.generateTxId();

      expect(txId1, isNot(equals(txId2)));
      expect(txId1.length, greaterThanOrEqualTo(8));
      expect(txId1.length, lessThanOrEqualTo(16));
    });

    test('dispenseTokens sends POST request', () async {
      final mockHttp = MockHttpClient();
      final client = DispenserClient(
        baseUrl: 'http://localhost',
        apiKey: 'test-key',
        httpClient: mockHttp,
      );

      when(mockHttp.post(
        Uri.parse('http://localhost/dispense'),
        headers: anyNamed('headers'),
        body: anyNamed('body'),
      )).thenAnswer((_) async => http.Response(
        '{"tx_id":"abc123","state":"dispensing","quantity":3,"dispensed":0}',
        200,
      ));

      final result = await client.dispenseTokens(
        txId: 'abc123',
        quantity: 3,
      );

      expect(result.txId, 'abc123');
      expect(result.state, 'dispensing');
      expect(result.quantity, 3);
      expect(result.dispensed, 0);
    });
  });
}
```

**Step 2: Run test**

```bash
flutter test test/services/dispenser_client_test.dart
```

Expected: FAIL (DispenserClient not defined)

**Step 3: Implement DispenserClient**

```dart
// terminal-frontend/lib/services/dispenser_client.dart

import 'dart:convert';
import 'dart:math';
import 'package:http/http.dart' as http;

class DispenserClient {
  final String baseUrl;
  final String apiKey;
  final http.Client httpClient;
  final int timeoutMs;

  DispenserClient({
    required this.baseUrl,
    required this.apiKey,
    http.Client? httpClient,
    this.timeoutMs = 3000,
  }) : httpClient = httpClient ?? http.Client();

  /// Generate unique transaction ID (8-16 hex chars)
  String generateTxId() {
    final random = Random();
    final length = 8 + random.nextInt(9); // 8-16 chars
    final chars = '0123456789abcdef';
    return List.generate(length, (_) => chars[random.nextInt(chars.length)])
        .join();
  }

  /// Start token dispense
  Future<DispenseResult> dispenseTokens({
    required String txId,
    required int quantity,
  }) async {
    final response = await httpClient
        .post(
          Uri.parse('$baseUrl/dispense'),
          headers: {
            'Content-Type': 'application/json',
            'X-API-Key': apiKey,
          },
          body: jsonEncode({
            'tx_id': txId,
            'quantity': quantity,
          }),
        )
        .timeout(Duration(milliseconds: timeoutMs));

    if (response.statusCode == 200) {
      final data = jsonDecode(response.body) as Map<String, dynamic>;
      return DispenseResult.fromJson(data);
    } else if (response.statusCode == 409) {
      throw DispenserBusyException();
    } else {
      throw DispenserException('HTTP ${response.statusCode}: ${response.body}');
    }
  }

  /// Poll dispense status
  Future<DispenseResult> getStatus(String txId) async {
    final response = await httpClient
        .get(
          Uri.parse('$baseUrl/dispense/$txId'),
          headers: {'X-API-Key': apiKey},
        )
        .timeout(Duration(milliseconds: timeoutMs));

    if (response.statusCode == 200) {
      final data = jsonDecode(response.body) as Map<String, dynamic>;
      return DispenseResult.fromJson(data);
    } else if (response.statusCode == 404) {
      throw DispenserNotFoundException();
    } else {
      throw DispenserException('HTTP ${response.statusCode}');
    }
  }

  /// Get dispenser health
  Future<DispenserHealth> getHealth() async {
    final response = await httpClient
        .get(Uri.parse('$baseUrl/health'))
        .timeout(Duration(milliseconds: timeoutMs));

    if (response.statusCode == 200) {
      final data = jsonDecode(response.body) as Map<String, dynamic>;
      return DispenserHealth.fromJson(data);
    } else {
      throw DispenserException('Health check failed');
    }
  }
}

class DispenseResult {
  final String txId;
  final String state; // "dispensing", "done", "error"
  final int quantity;
  final int dispensed;

  DispenseResult({
    required this.txId,
    required this.state,
    required this.quantity,
    required this.dispensed,
  });

  factory DispenseResult.fromJson(Map<String, dynamic> json) {
    return DispenseResult(
      txId: json['tx_id'],
      state: json['state'],
      quantity: json['quantity'],
      dispensed: json['dispensed'],
    );
  }
}

class DispenserHealth {
  final String status; // "ok", "degraded", "error"
  final String dispenser; // "idle", "dispensing", "error"
  final int totalDispenses;
  final int successful;
  final int jams;
  final double successRate;

  DispenserHealth({
    required this.status,
    required this.dispenser,
    required this.totalDispenses,
    required this.successful,
    required this.jams,
    required this.successRate,
  });

  factory DispenserHealth.fromJson(Map<String, dynamic> json) {
    final metrics = json['metrics'] as Map<String, dynamic>;
    final total = metrics['total_dispenses'] as int;
    final successful = metrics['successful'] as int;

    return DispenserHealth(
      status: json['status'],
      dispenser: json['dispenser'],
      totalDispenses: total,
      successful: successful,
      jams: metrics['jams'],
      successRate: total > 0 ? (successful / total) * 100 : 0,
    );
  }

  factory DispenserHealth.offline() {
    return DispenserHealth(
      status: 'offline',
      dispenser: 'offline',
      totalDispenses: 0,
      successful: 0,
      jams: 0,
      successRate: 0,
    );
  }
}

class DispenserException implements Exception {
  final String message;
  DispenserException(this.message);
  @override
  String toString() => 'DispenserException: $message';
}

class DispenserBusyException extends DispenserException {
  DispenserBusyException() : super('Dispenser is busy');
}

class DispenserNotFoundException extends DispenserException {
  DispenserNotFoundException() : super('Transaction not found');
}
```

**Step 4: Run test**

```bash
flutter test test/services/dispenser_client_test.dart
```

Expected: PASS

**Step 5: Commit**

```bash
git add terminal-frontend/lib/services/dispenser_client.dart \
        terminal-frontend/test/services/dispenser_client_test.dart
git commit -m "feat(terminal): implement DispenserClient service

HTTP client for ESP8266 dispenser API. Supports dispense, status polling,
and health checks. Includes exception handling for busy/offline states.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 3.4: Update Product Model - Add requiresDispenser Field

**Files:**
- Modify: `terminal-frontend/lib/models/product.dart`

**Step 1: Add field to Product model**

```dart
class Product {
  final String id;
  final String categoryId;
  final Map<String, String> names;
  final Map<String, String>? descriptions;
  final int priceCents;
  final bool isActive;
  final bool requiresDispenser; // NEW FIELD
  final String updatedAt;

  Product({
    required this.id,
    required this.categoryId,
    required this.names,
    this.descriptions,
    required this.priceCents,
    required this.isActive,
    required this.requiresDispenser, // NEW FIELD
    required this.updatedAt,
  });

  factory Product.fromJson(Map<String, dynamic> json) {
    return Product(
      id: json['id'],
      categoryId: json['category_id'],
      names: Map<String, String>.from(json['names']),
      descriptions: json['descriptions'] != null
          ? Map<String, String>.from(json['descriptions'])
          : null,
      priceCents: json['price_cents'],
      isActive: json['is_active'] == 1,
      requiresDispenser: json['requires_dispenser'] == 1, // NEW FIELD
      updatedAt: json['updated_at'],
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'category_id': categoryId,
      'names': names,
      'descriptions': descriptions,
      'price_cents': priceCents,
      'is_active': isActive ? 1 : 0,
      'requires_dispenser': requiresDispenser ? 1 : 0, // NEW FIELD
      'updated_at': updatedAt,
    };
  }
}
```

**Step 2: Manual verification**

Check that product sync still works (will test in Phase 5)

**Step 3: Commit**

```bash
git add terminal-frontend/lib/models/product.dart
git commit -m "feat(terminal): add requiresDispenser to Product model

Product model now includes requiresDispenser field from sync response.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 3.5: Implement Product Filtering Logic

**Files:**
- Modify: `terminal-frontend/lib/providers/products_provider.dart`
- Create: `terminal-frontend/test/providers/products_provider_test.dart`

**Step 1: Write failing test**

```dart
// terminal-frontend/test/providers/products_provider_test.dart

void main() {
  group('ProductsProvider - Dispenser Filtering', () {
    test('hides dispenser products when dispenser disabled', () {
      final mockConfig = MockConfigService();
      when(mockConfig.dispenserEnabled).thenReturn(false);

      final provider = ProductsProvider(config: mockConfig);

      provider.setProducts([
        Product(
          id: '1',
          categoryId: 'cat1',
          names: {'de': 'Beer'},
          priceCents: 250,
          isActive: true,
          requiresDispenser: false,
          updatedAt: '2026-02-14',
        ),
        Product(
          id: '2',
          categoryId: 'cat1',
          names: {'de': 'Token'},
          priceCents: 300,
          isActive: true,
          requiresDispenser: true, // Should be hidden
          updatedAt: '2026-02-14',
        ),
      ]);

      final visible = provider.getVisibleProducts('cat1');

      expect(visible.length, 1);
      expect(visible[0].id, '1');
    });

    test('shows dispenser products when dispenser enabled', () {
      final mockConfig = MockConfigService();
      when(mockConfig.dispenserEnabled).thenReturn(true);

      final provider = ProductsProvider(config: mockConfig);

      provider.setProducts([
        Product(id: '1', requiresDispenser: false, /* ... */),
        Product(id: '2', requiresDispenser: true, /* ... */),
      ]);

      final visible = provider.getVisibleProducts('cat1');

      expect(visible.length, 2);
    });
  });
}
```

**Step 2: Run test**

```bash
flutter test test/providers/products_provider_test.dart
```

Expected: FAIL

**Step 3: Implement filtering logic**

```dart
// In terminal-frontend/lib/providers/products_provider.dart

class ProductsProvider extends ChangeNotifier {
  final ConfigService config;
  List<Product> _products = [];

  ProductsProvider({required this.config});

  List<Product> getVisibleProducts(String categoryId) {
    final dispenserEnabled = config.dispenserEnabled;

    return _products
        .where((p) => p.categoryId == categoryId)
        .where((p) => p.isActive)
        .where((p) {
          // Hide dispenser products if dispenser not configured
          if (p.requiresDispenser && !dispenserEnabled) {
            return false;
          }
          return true;
        })
        .toList();
  }

  // ... rest of provider
}
```

**Step 4: Run test**

```bash
flutter test test/providers/products_provider_test.dart
```

Expected: PASS

**Step 5: Commit**

```bash
git add terminal-frontend/lib/providers/products_provider.dart \
        terminal-frontend/test/providers/products_provider_test.dart
git commit -m "feat(terminal): filter dispenser products based on config

ProductsProvider hides products requiring dispenser when dispenser
is not enabled in config.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Phase 4: Terminal - Error Handling & Recovery

### Task 4.1: Implement Checkout Flow with Dispense Logic

**Files:**
- Modify: `terminal-frontend/lib/providers/cart_provider.dart`
- Reference: Design document Section 2 (Checkout UI/UX Flow)

**Step 1: Add dispense logic to checkout**

```dart
// In CartProvider.checkout() method:

Future<void> checkout(BuildContext context) async {
  if (_cart.isEmpty) return;

  try {
    // Separate tokens from regular products
    final tokenProducts = _cart.entries.where((e) => e.key.requiresDispenser);
    final regularProducts = _cart.entries.where((e) => !e.key.requiresDispenser);

    // If tokens in cart, dispense first
    if (tokenProducts.isNotEmpty) {
      final dispenserEnabled = _configService.dispenserEnabled;

      if (!dispenserEnabled) {
        // Should never happen (filtered in UI), but safety check
        throw Exception('Dispenser not configured');
      }

      // Show dispensing progress overlay
      showDialog(
        context: context,
        barrierDismissible: false,
        builder: (_) => DispensingProgressDialog(
          products: tokenProducts,
          onComplete: (result) {
            // Create transactions based on result
            _createDispenserTransactions(result);
          },
          onError: (error) {
            // Show error dialog with options
            _showDispenserErrorDialog(context, error);
          },
        ),
      );
    } else {
      // No tokens, just create transactions normally
      await _createRegularTransactions(regularProducts);
      _showSuccessConfirmation(context);
    }
  } catch (e) {
    _showErrorDialog(context, e.toString());
  }
}
```

**Step 2: Create DispensingProgressDialog widget**

Create file: `terminal-frontend/lib/widgets/dispensing_progress_dialog.dart`

```dart
import 'package:flutter/material.dart';
import '../services/dispenser_client.dart';
import '../services/config_service.dart';

class DispensingProgressDialog extends StatefulWidget {
  final Iterable<MapEntry<Product, int>> products;
  final Function(DispenseResult) onComplete;
  final Function(DispenserException) onError;

  const DispensingProgressDialog({
    Key? key,
    required this.products,
    required this.onComplete,
    required this.onError,
  }) : super(key: key);

  @override
  State<DispensingProgressDialog> createState() => _DispensingProgressDialogState();
}

class _DispensingProgressDialogState extends State<DispensingProgressDialog> {
  late DispenserClient _client;
  String _txId = '';
  int _quantity = 0;
  int _dispensed = 0;
  String _state = 'starting';

  @override
  void initState() {
    super.initState();

    final config = context.read<ConfigService>();
    _client = DispenserClient(
      baseUrl: config.dispenserBaseUrl!,
      apiKey: config.dispenserApiKey!,
      timeoutMs: config.dispenserTimeoutMs,
    );

    // Calculate total tokens to dispense
    _quantity = widget.products.fold(0, (sum, e) => sum + e.value);

    _startDispense();
  }

  Future<void> _startDispense() async {
    try {
      _txId = _client.generateTxId();

      final result = await _client.dispenseTokens(
        txId: _txId,
        quantity: _quantity,
      );

      setState(() {
        _state = result.state;
        _dispensed = result.dispensed;
      });

      // Start polling
      _pollStatus();
    } on DispenserBusyException {
      widget.onError(DispenserBusyException());
    } on DispenserException catch (e) {
      widget.onError(e);
    }
  }

  Future<void> _pollStatus() async {
    final config = context.read<ConfigService>();
    final pollInterval = Duration(milliseconds: config.dispenserPollIntervalMs);

    while (_state == 'dispensing') {
      await Future.delayed(pollInterval);

      try {
        final result = await _client.getStatus(_txId);

        setState(() {
          _state = result.state;
          _dispensed = result.dispensed;
        });

        if (_state == 'done' || _state == 'error') {
          widget.onComplete(result);
          Navigator.of(context).pop();
          break;
        }
      } catch (e) {
        widget.onError(DispenserException('Polling failed: $e'));
        Navigator.of(context).pop();
        break;
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Dialog(
      child: Padding(
        padding: const EdgeInsets.all(24.0),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'Dispensing Sauna Tokens...',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 24),
            _buildProgressIndicator(),
            const SizedBox(height: 16),
            const CircularProgressIndicator(),
            const SizedBox(height: 16),
            Text('Please wait...'),
          ],
        ),
      ),
    );
  }

  Widget _buildProgressIndicator() {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: List.generate(_quantity, (index) {
        final dispensed = index < _dispensed;
        return Padding(
          padding: const EdgeInsets.symmetric(horizontal: 4.0),
          child: Text(
            dispensed ? '●' : '○',
            style: TextStyle(
              fontSize: 24,
              color: dispensed ? Colors.green : Colors.grey,
            ),
          ),
        );
      }),
    );
  }
}
```

**Step 3: Manual testing (requires mock dispenser)**

Will be tested in Phase 5 with mock dispenser

**Step 4: Commit**

```bash
git add terminal-frontend/lib/providers/cart_provider.dart \
        terminal-frontend/lib/widgets/dispensing_progress_dialog.dart
git commit -m "feat(terminal): implement checkout flow with token dispensing

Checkout now separates token products, dispenses tokens first, polls
for completion, then creates transactions. Shows progress overlay.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 4.2: Implement Error Dialogs (Busy/Offline)

**Files:**
- Create: `terminal-frontend/lib/widgets/dispenser_error_dialog.dart`

**Step 1: Create error dialog widget**

```dart
import 'package:flutter/material.dart';

enum DispenserErrorType { busy, offline }

class DispenserErrorDialog extends StatelessWidget {
  final DispenserErrorType errorType;
  final VoidCallback onCancel;
  final VoidCallback onBuyWithoutTokens;

  const DispenserErrorDialog({
    Key? key,
    required this.errorType,
    required this.onCancel,
    required this.onBuyWithoutTokens,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return Dialog(
      child: Padding(
        padding: const EdgeInsets.all(24.0),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              errorType == DispenserErrorType.busy
                  ? Icons.hourglass_empty
                  : Icons.error_outline,
              size: 48,
              color: errorType == DispenserErrorType.busy
                  ? Colors.orange
                  : Colors.red,
            ),
            const SizedBox(height: 16),
            Text(
              errorType == DispenserErrorType.busy
                  ? 'Dispenser Busy'
                  : 'Cannot Connect to Dispenser',
              style: Theme.of(context).textTheme.titleLarge,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 16),
            Text(
              errorType == DispenserErrorType.busy
                  ? 'Another customer is using the token dispenser.'
                  : 'The token dispenser is not responding.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            Text(
              'You can still purchase other items without tokens.',
              textAlign: TextAlign.center,
              style: TextStyle(color: Colors.grey[600]),
            ),
            const SizedBox(height: 24),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: onCancel,
                    child: const Text('Cancel & Back to Cart'),
                  ),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: ElevatedButton(
                    onPressed: onBuyWithoutTokens,
                    child: const Text('Buy All Products But Tokens'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
```

**Step 2: Integrate into CartProvider**

```dart
void _showDispenserErrorDialog(BuildContext context, DispenserException error) {
  final errorType = error is DispenserBusyException
      ? DispenserErrorType.busy
      : DispenserErrorType.offline;

  showDialog(
    context: context,
    builder: (_) => DispenserErrorDialog(
      errorType: errorType,
      onCancel: () {
        Navigator.of(context).pop();
        // Return to cart (no action needed)
      },
      onBuyWithoutTokens: () {
        Navigator.of(context).pop();
        // Create transactions for regular products only
        final regularProducts = _cart.entries
            .where((e) => !e.key.requiresDispenser);
        _createRegularTransactions(regularProducts);
        _showSuccessConfirmation(context);
      },
    ),
  );
}
```

**Step 3: Commit**

```bash
git add terminal-frontend/lib/widgets/dispenser_error_dialog.dart \
        terminal-frontend/lib/providers/cart_provider.dart
git commit -m "feat(terminal): add error dialogs for dispenser busy/offline

Consistent error handling with options to cancel or buy without tokens.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 4.3: Implement Confirmation Screens (Partial/Complete Failure)

**Files:**
- Modify: `terminal-frontend/lib/widgets/checkout_confirmation_dialog.dart`

**Step 1: Update confirmation dialog to show partial dispense**

```dart
class CheckoutConfirmationDialog extends StatelessWidget {
  final List<TransactionSummary> transactions;
  final int totalCents;
  final int newBalanceCents;
  final PartialDispenseInfo? partialDispenseInfo; // NEW FIELD

  const CheckoutConfirmationDialog({
    Key? key,
    required this.transactions,
    required this.totalCents,
    required this.newBalanceCents,
    this.partialDispenseInfo,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    final isPartial = partialDispenseInfo != null;

    return Dialog(
      child: Padding(
        padding: const EdgeInsets.all(24.0),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              isPartial ? Icons.warning : Icons.check_circle,
              size: 48,
              color: isPartial ? Colors.orange : Colors.green,
            ),
            const SizedBox(height: 16),
            Text(
              isPartial
                  ? 'Only ${partialDispenseInfo!.actualDispensed} tokens dispensed'
                  : 'Purchase Complete!',
              style: Theme.of(context).textTheme.titleLarge,
              textAlign: TextAlign.center,
            ),
            if (isPartial) ...[
              const SizedBox(height: 8),
              Text(
                'You have been charged for ${partialDispenseInfo!.actualDispensed} sauna tokens only.\nSorry for the inconvenience.',
                textAlign: TextAlign.center,
                style: TextStyle(color: Colors.grey[700]),
              ),
            ],
            const SizedBox(height: 24),
            // Show transaction list
            ...transactions.map((t) => ListTile(
              title: Text(t.productName),
              trailing: Text('€${(t.amountCents / 100).toStringAsFixed(2)}'),
            )),
            const Divider(),
            ListTile(
              title: const Text('Total', style: TextStyle(fontWeight: FontWeight.bold)),
              trailing: Text(
                '€${(totalCents / 100).toStringAsFixed(2)}',
                style: const TextStyle(fontWeight: FontWeight.bold),
              ),
            ),
            if (isPartial) ...[
              Text(
                '(not €${(partialDispenseInfo!.originalTotalCents / 100).toStringAsFixed(2)})',
                style: TextStyle(color: Colors.grey[600]),
              ),
            ],
            const SizedBox(height: 8),
            Text('New balance: €${(newBalanceCents / 100).toStringAsFixed(2)}'),
            const SizedBox(height: 24),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: () => Navigator.of(context).pop(false),
                    child: const Text('Done'),
                  ),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: ElevatedButton(
                    onPressed: () => Navigator.of(context).pop(true),
                    child: const Text('Continue Shopping'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class PartialDispenseInfo {
  final int requestedQuantity;
  final int actualDispensed;
  final int originalTotalCents;

  PartialDispenseInfo({
    required this.requestedQuantity,
    required this.actualDispensed,
    required this.originalTotalCents,
  });
}
```

**Step 2: Commit**

```bash
git add terminal-frontend/lib/widgets/checkout_confirmation_dialog.dart
git commit -m "feat(terminal): add partial dispense warning to confirmation

Confirmation dialog shows warning and adjusted total for partial dispenses.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 4.4: Implement Crash Recovery Logic

**Files:**
- Create: `terminal-frontend/lib/services/dispenser_recovery_service.dart`
- Modify: `terminal-frontend/lib/main.dart` (call recovery on boot)

**Step 1: Write failing test**

```dart
// terminal-frontend/test/services/dispenser_recovery_service_test.dart

void main() {
  group('DispenserRecoveryService', () {
    test('recovers completed dispense transaction', () async {
      final mockDb = MockDatabase();
      final mockClient = MockDispenserClient();

      // Simulate incomplete transaction in DB
      when(mockDb.query('transactions_local',
        where: 'dispenser_tx_id IS NOT NULL AND synced = 0'
      )).thenAnswer((_) async => [
        {
          'id': 'tx-local-123',
          'dispenser_tx_id': 'disp-abc',
          'dispenser_requested': 3,
          'dispenser_actual': null,
        }
      ]);

      // ESP8266 reports completed
      when(mockClient.getStatus('disp-abc')).thenAnswer((_) async =>
        DispenseResult(
          txId: 'disp-abc',
          state: 'done',
          quantity: 3,
          dispensed: 3,
        )
      );

      final service = DispenserRecoveryService(
        database: mockDb,
        client: mockClient,
      );

      await service.recoverIncompleteDispenses();

      verify(mockDb.update('transactions_local',
        {'synced': 1, 'dispenser_actual': 3},
        where: 'id = ?',
        whereArgs: ['tx-local-123'],
      )).called(1);
    });
  });
}
```

**Step 2: Run test**

```bash
flutter test test/services/dispenser_recovery_service_test.dart
```

Expected: FAIL

**Step 3: Implement recovery service**

```dart
// terminal-frontend/lib/services/dispenser_recovery_service.dart

import 'package:sqflite/sqflite.dart';
import './dispenser_client.dart';
import '../utils/logger.dart';

class DispenserRecoveryService {
  final Database database;
  final DispenserClient client;
  final Logger logger;

  DispenserRecoveryService({
    required this.database,
    required this.client,
    required this.logger,
  });

  Future<void> recoverIncompleteDispenses() async {
    logger.info('Starting dispenser transaction recovery...');

    final incompleteTxs = await database.query(
      'transactions_local',
      where: 'dispenser_tx_id IS NOT NULL AND synced = 0',
    );

    if (incompleteTxs.isEmpty) {
      logger.info('No incomplete dispenser transactions found');
      return;
    }

    logger.info('Found ${incompleteTxs.length} incomplete dispenser transactions');

    for (final tx in incompleteTxs) {
      final dispenserTxId = tx['dispenser_tx_id'] as String;
      final productId = tx['product_id'] as String?;

      try {
        // Query ESP8266 for actual state
        final result = await client.getStatus(dispenserTxId);

        if (result.state == 'done') {
          // Dispense completed during crash
          logger.info('Transaction $dispenserTxId completed: ${result.dispensed} tokens');

          await database.update(
            'transactions_local',
            {
              'synced': 1,
              'dispenser_actual': result.dispensed,
            },
            where: 'id = ?',
            whereArgs: [tx['id']],
          );
        } else if (result.state == 'error') {
          // Partial dispense or failure
          logger.warning('Transaction $dispenserTxId failed: ${result.dispensed}/${result.quantity}');

          // Update amount based on actual count
          final pricePerToken = await _getPricePerToken(productId);
          final actualAmount = result.dispensed * pricePerToken;

          await database.update(
            'transactions_local',
            {
              'amount_cents': actualAmount,
              'dispenser_actual': result.dispensed,
            },
            where: 'id = ?',
            whereArgs: [tx['id']],
          );
        }
      } on DispenserNotFoundException {
        // Transaction expired from ESP8266 ring buffer
        logger.warning('Transaction $dispenserTxId not found on dispenser (expired)');
        // Leave as-is for manual reconciliation
      } catch (e) {
        // ESP8266 unreachable
        logger.error('Cannot recover transaction $dispenserTxId: $e');
      }
    }

    logger.info('Dispenser recovery complete');
  }

  Future<int> _getPricePerToken(String? productId) async {
    if (productId == null) return 0;

    final product = await database.query(
      'products_cache',
      where: 'id = ?',
      whereArgs: [productId],
      limit: 1,
    );

    if (product.isEmpty) return 0;
    return product.first['price_cents'] as int;
  }
}
```

**Step 4: Call recovery on app boot**

```dart
// In terminal-frontend/lib/main.dart, add to initState or main():

Future<void> _initApp() async {
  await configService.load();

  // Recovery logic (NEW)
  if (configService.dispenserEnabled) {
    final client = DispenserClient(
      baseUrl: configService.dispenserBaseUrl!,
      apiKey: configService.dispenserApiKey!,
    );

    final recovery = DispenserRecoveryService(
      database: db,
      client: client,
      logger: logger,
    );

    await recovery.recoverIncompleteDispenses();
  }

  // Continue with normal startup...
}
```

**Step 5: Run test**

```bash
flutter test test/services/dispenser_recovery_service_test.dart
```

Expected: PASS

**Step 6: Commit**

```bash
git add terminal-frontend/lib/services/dispenser_recovery_service.dart \
        terminal-frontend/lib/main.dart \
        terminal-frontend/test/services/dispenser_recovery_service_test.dart
git commit -m "feat(terminal): implement crash recovery for dispenser transactions

Recovery service queries ESP8266 on boot to reconcile incomplete
transactions. Updates amount based on actual dispensed count.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 4.5: Implement Health Monitoring Service

**Files:**
- Create: `terminal-frontend/lib/services/dispenser_health_service.dart`
- Modify: `terminal-frontend/lib/screens/info_screen.dart`

**Step 1: Implement health service**

```dart
// terminal-frontend/lib/services/dispenser_health_service.dart

import 'dart:async';
import './dispenser_client.dart';
import '../utils/logger.dart';

class DispenserHealthService {
  final DispenserClient client;
  final Logger logger;
  Timer? _healthTimer;
  DispenserHealth? _lastHealth;

  DispenserHealthService({
    required this.client,
    required this.logger,
  });

  DispenserHealth? get currentHealth => _lastHealth;

  void startMonitoring() {
    logger.info('Starting dispenser health monitoring (60s interval)');

    _healthTimer = Timer.periodic(const Duration(seconds: 60), (_) async {
      try {
        final health = await client.getHealth();
        _lastHealth = health;

        if (health.dispenser == 'error') {
          logger.warning('Dispenser in error state');
        }
      } catch (e) {
        logger.error('Dispenser health check failed: $e');
        _lastHealth = DispenserHealth.offline();
      }
    });
  }

  void stopMonitoring() {
    _healthTimer?.cancel();
    _healthTimer = null;
  }

  void dispose() {
    stopMonitoring();
  }
}
```

**Step 2: Add to info screen**

```dart
// In terminal-frontend/lib/screens/info_screen.dart

class InfoScreen extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final config = context.read<ConfigService>();
    final healthService = context.read<DispenserHealthService>();

    return Scaffold(
      appBar: AppBar(title: const Text('Terminal Information')),
      body: Padding(
        padding: const EdgeInsets.all(24.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Terminal ID: ${config.terminalId}'),
            const SizedBox(height: 24),

            // Backend connectivity section
            const Text('Backend Connectivity:', style: TextStyle(fontWeight: FontWeight.bold)),
            // ... existing backend info

            const SizedBox(height: 24),

            // Dispenser section (NEW)
            if (config.dispenserEnabled) ...[
              const Text('Token Dispenser:', style: TextStyle(fontWeight: FontWeight.bold)),
              const SizedBox(height: 8),
              _buildDispenserStatus(healthService.currentHealth),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildDispenserStatus(DispenserHealth? health) {
    if (health == null) {
      return const Text('Loading...');
    }

    String statusText;
    Color statusColor;
    IconData statusIcon;

    if (health.dispenser == 'idle') {
      statusText = 'Operational';
      statusColor = Colors.green;
      statusIcon = Icons.check_circle;
    } else if (health.dispenser == 'dispensing') {
      statusText = 'Busy (dispensing)';
      statusColor = Colors.orange;
      statusIcon = Icons.hourglass_empty;
    } else if (health.dispenser == 'error') {
      statusText = 'Error (jammed)';
      statusColor = Colors.red;
      statusIcon = Icons.error;
    } else {
      statusText = 'Offline';
      statusColor = Colors.grey;
      statusIcon = Icons.cloud_off;
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(statusIcon, color: statusColor, size: 20),
            const SizedBox(width: 8),
            Text(statusText, style: TextStyle(color: statusColor)),
          ],
        ),
        const SizedBox(height: 4),
        Text('Status: ${health.dispenser}'),
        if (health.dispenser != 'offline') ...[
          Text('Success rate: ${health.successRate.toStringAsFixed(1)}%'),
          Text('Total dispenses: ${health.totalDispenses}'),
          Text('Jams: ${health.jams}'),
        ],
      ],
    );
  }
}
```

**Step 3: Start health monitoring in main.dart**

```dart
// In main.dart initState or main():

if (configService.dispenserEnabled) {
  final healthService = DispenserHealthService(
    client: dispenserClient,
    logger: logger,
  );
  healthService.startMonitoring();
}
```

**Step 4: Commit**

```bash
git add terminal-frontend/lib/services/dispenser_health_service.dart \
        terminal-frontend/lib/screens/info_screen.dart \
        terminal-frontend/lib/main.dart
git commit -m "feat(terminal): add dispenser health monitoring

Health service polls ESP8266 every 60s. Info screen displays
dispenser status, success rate, and metrics.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Phase 5: Testing & Documentation

### Task 5.1: Run Backend Unit Tests

**Step 1: Run all backend tests**

```bash
cd backend
php artisan test
```

Expected: All tests PASS (including new tests from Phase 1)

**Step 2: Check coverage (optional)**

```bash
php artisan test --coverage
```

Expected: >80% coverage for Products and Transactions modules

**Step 3: Commit if any fixes needed**

```bash
# If fixes were needed:
git add backend/tests/
git commit -m "test(backend): fix failing tests after dispenser integration"
```

---

### Task 5.2: Run Terminal Unit Tests

**Step 1: Run all terminal tests**

```bash
cd terminal-frontend
flutter test
```

Expected: All tests PASS

**Step 2: Check coverage**

```bash
flutter test --coverage
genhtml coverage/lcov.info -o coverage/html
open coverage/html/index.html
```

Expected: >80% coverage for dispenser-related services

**Step 3: Commit if fixes needed**

```bash
git add terminal-frontend/test/
git commit -m "test(terminal): fix failing tests after dispenser integration"
```

---

### Task 5.3: Create Mock Dispenser for E2E Tests

**Files:**
- Create: `e2etests/mocks/mock-dispenser-server.ts`

**Step 1: Implement mock server**

```typescript
// e2etests/mocks/mock-dispenser-server.ts

import express from 'express';
import { Server } from 'http';

export class MockDispenserServer {
  private app = express();
  private server?: Server;
  private transactions = new Map<string, any>();
  private simulatedBusy = false;

  constructor(private port: number = 8266) {
    this.app.use(express.json());
    this.setupRoutes();
  }

  private setupRoutes() {
    // Health endpoint
    this.app.get('/health', (req, res) => {
      res.json({
        status: 'ok',
        dispenser: this.simulatedBusy ? 'dispensing' : 'idle',
        uptime: 1000,
        firmware: '1.1.0',
        metrics: {
          total_dispenses: 100,
          successful: 95,
          jams: 2,
          partial: 1,
          failures: 5,
        },
        error: { active: false },
        error_history: [],
      });
    });

    // Dispense endpoint
    this.app.post('/dispense', (req, res) => {
      const { tx_id, quantity } = req.body;
      const apiKey = req.headers['x-api-key'];

      if (apiKey !== 'test-api-key') {
        return res.status(401).json({ error: 'unauthorized' });
      }

      if (!tx_id || !quantity) {
        return res.status(400).json({ error: 'invalid tx_id or quantity' });
      }

      // Check if transaction exists (idempotency)
      if (this.transactions.has(tx_id)) {
        return res.json(this.transactions.get(tx_id));
      }

      // Check if busy
      if (this.simulatedBusy) {
        return res.status(409).json({
          error: 'busy',
          active_tx_id: 'other-tx',
          active_state: 'dispensing',
        });
      }

      // Start new transaction
      const tx = {
        tx_id,
        state: 'dispensing',
        quantity,
        dispensed: 0,
      };

      this.transactions.set(tx_id, tx);

      // Simulate dispensing (increment dispensed every 250ms)
      this.simulateDispensing(tx_id, quantity);

      res.json(tx);
    });

    // Status endpoint
    this.app.get('/dispense/:tx_id', (req, res) => {
      const { tx_id } = req.params;
      const apiKey = req.headers['x-api-key'];

      if (apiKey !== 'test-api-key') {
        return res.status(401).json({ error: 'unauthorized' });
      }

      const tx = this.transactions.get(tx_id);

      if (!tx) {
        return res.status(404).json({ error: 'not found' });
      }

      res.json(tx);
    });
  }

  private simulateDispensing(tx_id: string, quantity: number) {
    let dispensed = 0;

    const interval = setInterval(() => {
      const tx = this.transactions.get(tx_id);
      if (!tx) {
        clearInterval(interval);
        return;
      }

      dispensed++;
      tx.dispensed = dispensed;

      if (dispensed >= quantity) {
        tx.state = 'done';
        clearInterval(interval);
      }

      this.transactions.set(tx_id, tx);
    }, 250);
  }

  async start(): Promise<void> {
    return new Promise((resolve) => {
      this.server = this.app.listen(this.port, () => {
        console.log(`Mock dispenser listening on port ${this.port}`);
        resolve();
      });
    });
  }

  async stop(): Promise<void> {
    return new Promise((resolve) => {
      if (this.server) {
        this.server.close(() => resolve());
      } else {
        resolve();
      }
    });
  }

  // Test helpers
  setBusy(busy: boolean) {
    this.simulatedBusy = busy;
  }

  simulateJam(tx_id: string, dispensedBeforeJam: number) {
    const tx = this.transactions.get(tx_id);
    if (tx) {
      tx.state = 'error';
      tx.dispensed = dispensedBeforeJam;
      this.transactions.set(tx_id, tx);
    }
  }

  reset() {
    this.transactions.clear();
    this.simulatedBusy = false;
  }
}
```

**Step 2: Add to Playwright global setup**

```typescript
// e2etests/global-setup.ts

import { MockDispenserServer } from './mocks/mock-dispenser-server';

let mockDispenser: MockDispenserServer;

export default async function globalSetup() {
  // Start mock dispenser
  mockDispenser = new MockDispenserServer(8266);
  await mockDispenser.start();

  // Store in global for teardown
  (global as any).__MOCK_DISPENSER__ = mockDispenser;
}
```

**Step 3: Add to global teardown**

```typescript
// e2etests/global-teardown.ts

export default async function globalTeardown() {
  const mockDispenser = (global as any).__MOCK_DISPENSER__;
  if (mockDispenser) {
    await mockDispenser.stop();
  }
}
```

**Step 4: Commit**

```bash
git add e2etests/mocks/mock-dispenser-server.ts \
        e2etests/global-setup.ts \
        e2etests/global-teardown.ts
git commit -m "test(e2e): add mock dispenser server for E2E tests

HTTP server simulating ESP8266 dispenser API for automated testing.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 5.4: Write E2E Tests for Full Integration

**Files:**
- Create: `e2etests/tests/terminal/token-dispense.spec.ts`

**Step 1: Write E2E test suite**

```typescript
// e2etests/tests/terminal/token-dispense.spec.ts

import { test, expect } from '@playwright/test';
import { MockDispenserServer } from '../../mocks/mock-dispenser-server';

test.describe('Token Dispenser Integration', () => {
  let mockDispenser: MockDispenserServer;

  test.beforeAll(async () => {
    mockDispenser = (global as any).__MOCK_DISPENSER__;
  });

  test.beforeEach(async ({ terminalPage }) => {
    mockDispenser.reset();

    // Configure dispenser in terminal
    await terminalPage.configureDispenser({
      enabled: true,
      baseUrl: 'http://localhost:8266',
      apiKey: 'test-api-key',
    });

    // Seed sauna token product
    await seedProduct({
      id: 'sauna-token-uuid',
      names: { de: 'Sauna-Token', en: 'Sauna Token' },
      price_cents: 300,
      requires_dispenser: 1,
    });

    await terminalPage.triggerSync();
  });

  test('successfully dispenses tokens and creates transaction', async ({ terminalPage }) => {
    await terminalPage.scanCard('DEADBEEF');
    await terminalPage.addProductToCart('Sauna-Token', 3);
    await terminalPage.clickCheckout();

    // Verify dispensing progress shown
    await expect(terminalPage.locator('[data-testid="dispense-progress"]')).toBeVisible();

    // Wait for completion
    await expect(terminalPage.locator('[data-testid="checkout-success"]')).toBeVisible({ timeout: 10000 });

    // Verify transaction created
    const txs = await terminalPage.getTransactions();
    expect(txs).toHaveLength(1);
    expect(txs[0].amount_cents).toBe(900);
    expect(txs[0].dispenser_actual).toBe(3);
  });

  test('handles partial dispense correctly', async ({ terminalPage }) => {
    await terminalPage.scanCard('DEADBEEF');
    await terminalPage.addProductToCart('Sauna-Token', 3);

    // Trigger checkout
    const checkoutPromise = terminalPage.clickCheckout();

    // Wait for dispense to start, then simulate jam
    await terminalPage.waitForSelector('[data-testid="dispense-progress"]');
    await new Promise(r => setTimeout(r, 500)); // Let it dispense 2 tokens

    // Get tx_id from mock and simulate jam
    const tx_id = Array.from(mockDispenser['transactions'].keys())[0];
    mockDispenser.simulateJam(tx_id, 2);

    await checkoutPromise;

    // Verify partial dispense warning
    await expect(terminalPage.locator('[data-testid="partial-dispense-warning"]'))
      .toContainText('Only 2 tokens dispensed');

    // Verify transaction for 2 tokens only
    const txs = await terminalPage.getTransactions();
    expect(txs[0].amount_cents).toBe(600); // 2 × €3.00
    expect(txs[0].dispenser_actual).toBe(2);
  });

  test('allows buying other items when dispenser busy', async ({ terminalPage }) => {
    mockDispenser.setBusy(true);

    await terminalPage.scanCard('DEADBEEF');
    await terminalPage.addProductToCart('Sauna-Token', 3);
    await terminalPage.addProductToCart('Beer 0.5L', 2);
    await terminalPage.clickCheckout();

    // Verify busy error shown
    await expect(terminalPage.locator('[data-testid="dispenser-busy"]')).toBeVisible();

    // Click "Buy All Products But Tokens"
    await terminalPage.click('[data-testid="buy-without-tokens"]');

    // Verify only beer transaction created
    const txs = await terminalPage.getTransactions();
    expect(txs).toHaveLength(1);
    expect(txs[0].product_id).toContain('beer');
  });

  test('hides token products when dispenser disabled', async ({ terminalPage }) => {
    // Disable dispenser
    await terminalPage.configureDispenser({ enabled: false });
    await terminalPage.triggerSync();

    await terminalPage.scanCard('DEADBEEF');

    // Verify token product not visible
    await expect(terminalPage.locator('[data-testid="product-sauna-token"]')).not.toBeVisible();

    // Regular products still visible
    await expect(terminalPage.locator('[data-testid="product-beer"]')).toBeVisible();
  });
});
```

**Step 2: Run E2E tests**

```bash
cd e2etests
npm test -- tests/terminal/token-dispense.spec.ts --workers=1
```

Expected: All tests PASS

**Step 3: Commit**

```bash
git add e2etests/tests/terminal/token-dispense.spec.ts
git commit -m "test(e2e): add full integration tests for token dispenser

Tests cover success, partial dispense, busy error, and product filtering.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 5.5: Create Manual Hardware Test Plan

**Files:**
- Create: `docs/testing/manual-hardware-token-dispenser.md`

**Step 1: Write manual test plan**

```markdown
# Manual Hardware Test Plan - Token Dispenser

**Prerequisites:**
- [ ] ESP8266 powered on and connected to WiFi
- [ ] Azkoyen Hopper loaded with tokens (minimum 20 tokens)
- [ ] Terminal configured with correct dispenser IP and API key
- [ ] Terminal dispenser health shows "Operational"

---

## Test Cases

### TC-1: Full Dispense Success (3 Tokens)

**Steps:**
1. Scan member RFID card
2. Add 3 sauna tokens to cart
3. Tap "Buy"
4. Observe dispensing progress (●○○ → ●●○ → ●●●)
5. Wait for completion

**Expected:**
- Progress indicator updates as tokens drop
- 3 physical tokens dispensed
- Confirmation shows "3 sauna tokens"
- Transaction created for €9.00 (3 × €3.00)

**Actual:** ___________________

**Status:** [ ] PASS [ ] FAIL

---

### TC-2: Partial Dispense (Jam After 2 Tokens)

**Steps:**
1. Scan member RFID card
2. Add 3 sauna tokens to cart
3. Tap "Buy"
4. **Manually create jam** (block hopper output after 2 tokens)

**Expected:**
- Progress stops at ●●○
- Warning: "Only 2 tokens dispensed"
- Transaction created for €6.00 (2 × €3.00, not €9.00)
- 2 physical tokens dispensed

**Actual:** ___________________

**Status:** [ ] PASS [ ] FAIL

---

### TC-3: Complete Failure (Jam Before Any Tokens)

**Steps:**
1. **Block hopper output completely**
2. Scan member RFID card
3. Add 3 sauna tokens to cart
4. Tap "Buy"

**Expected:**
- Progress stays at ○○○
- Error: "Token Dispense Failed - No tokens dispensed"
- **No transaction created** (member not charged)
- 0 physical tokens dispensed

**Actual:** ___________________

**Status:** [ ] PASS [ ] FAIL

---

### TC-4: Dispenser Busy (Concurrent Request)

**Setup:** Use two terminals or simulate with curl

**Steps (Terminal 1):**
1. Start dispense of 10 tokens (long operation)

**Steps (Terminal 2, during Terminal 1 dispense):**
1. Scan card
2. Add 3 tokens
3. Tap "Buy"

**Expected (Terminal 2):**
- Error: "Dispenser Busy"
- Options: "Cancel & Back to Cart" / "Buy All Products But Tokens"
- No tokens dispensed for Terminal 2 until Terminal 1 completes

**Actual:** ___________________

**Status:** [ ] PASS [ ] FAIL

---

### TC-5: Network Timeout (Disconnect WiFi)

**Steps:**
1. Scan card
2. Add 3 tokens
3. Tap "Buy"
4. **Disconnect WiFi** (unplug ESP8266 or disable WiFi)

**Expected:**
- Error: "Cannot Connect to Dispenser"
- Options: "Cancel & Back to Cart" / "Buy All Products But Tokens"
- No tokens dispensed

**Actual:** ___________________

**Status:** [ ] PASS [ ] FAIL

---

### TC-6: Terminal Crash During Dispense

**Steps:**
1. Scan card
2. Add 3 tokens
3. Tap "Buy"
4. **During dispensing** (after 1-2 tokens), kill terminal process
5. Restart terminal

**Expected:**
- On restart, terminal queries ESP8266
- Transaction updated with actual dispensed count
- Member charged for tokens actually received

**Actual:** ___________________

**Status:** [ ] PASS [ ] FAIL

---

### TC-7: ESP8266 Crash During Dispense (Power Cycle)

**Steps:**
1. Scan card
2. Add 3 tokens
3. Tap "Buy"
4. **During dispensing**, power off ESP8266
5. Wait for timeout
6. Power on ESP8266

**Expected:**
- Terminal shows error after timeout
- On ESP8266 reboot, persisted state shows partial count
- Terminal can query status and reconcile

**Actual:** ___________________

**Status:** [ ] PASS [ ] FAIL

---

### TC-8: Mixed Cart (Tokens + Regular Products)

**Steps:**
1. Scan card
2. Add 2 beers, 3 sauna tokens, 1 snack to cart
3. Tap "Buy"

**Expected:**
- Tokens dispensed first (progress shown)
- After dispense, all transactions created (tokens + beers + snack)
- Confirmation shows all items

**Actual:** ___________________

**Status:** [ ] PASS [ ] FAIL

---

### TC-9: Dispenser Health Monitoring

**Steps:**
1. Open terminal info screen
2. Verify dispenser section shows:
   - Status: Operational
   - Success rate
   - Total dispenses
   - Jams count

**Expected:**
- Dispenser health visible
- Metrics accurate (compare with ESP8266 /health)

**Actual:** ___________________

**Status:** [ ] PASS [ ] FAIL

---

### TC-10: Configuration Validation

**Steps:**
1. Open terminal setup
2. Configure dispenser with **wrong IP** (e.g., 192.168.4.99)
3. Try to save

**Expected:**
- Test connection fails
- Option to "Save Anyway"
- If saved, dispenser products hidden (fail-safe)

**Actual:** ___________________

**Status:** [ ] PASS [ ] FAIL

---

## Summary

**Total Tests:** 10
**Passed:** ___
**Failed:** ___

**Issues Found:**
_____________________________________________
_____________________________________________
_____________________________________________

**Tester:** ___________________
**Date:** ___________________
```

**Step 2: Commit**

```bash
git add docs/testing/manual-hardware-token-dispenser.md
git commit -m "docs: add manual hardware test plan for token dispenser

Comprehensive test checklist for validating real hardware integration.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

### Task 5.6: Update Use Cases (Optional)

**Files:**
- Create: `use-cases/terminal/UC-T15-purchase-sauna-tokens.md`

**Step 1: Write use case document**

```markdown
# UC-T15: Purchase Sauna Tokens

## Actor
Member

## Preconditions
- Member has valid RFID card
- Member is active with valid SEPA data
- Dispenser is configured and operational
- Sauna token product exists with `requires_dispenser = 1`

## Trigger
Member adds sauna tokens to shopping cart

## Main Flow
1. Member scans RFID card
2. Member selects sauna token product
3. Member adds quantity to cart (e.g., 3 tokens)
4. Member optionally adds other products (beers, snacks)
5. Member taps "Buy" button
6. System displays "Dispensing Sauna Tokens..." progress overlay
7. System calls ESP8266 to dispense tokens
8. Progress indicator updates as tokens drop (●●○ → ●●●)
9. System creates transaction with actual dispensed count
10. System creates transactions for other products
11. System displays success confirmation
12. Member takes physical tokens from dispenser
13. Member chooses "Done" or "Continue Shopping"

## Postconditions
- Physical tokens dispensed
- Transaction created with exact count
- Member balance increased
- Cart cleared

## Variants

### V1: Partial Dispense (Jam)
- Step 8: Jam occurs after 2 of 3 tokens
- System detects error, stops dispense
- Transaction created for 2 tokens only
- Warning shown: "Only 2 tokens dispensed - charged €6.00 (not €9.00)"
- Member takes 2 tokens

### V2: Dispenser Busy
- Step 7: ESP8266 returns 409 Conflict
- System shows error: "Dispenser Busy"
- Member chooses:
  - "Cancel & Back to Cart" → Return to cart
  - "Buy All Products But Tokens" → Process beers/snacks only

### V3: Dispenser Offline
- Step 7: Cannot connect to ESP8266
- System shows error: "Cannot Connect to Dispenser"
- Same options as V2

## Error Cases

### E1: No Tokens Dispensed
- Jam occurs before any tokens drop
- No transaction created for tokens
- Member not charged
- Other products still processed

### E2: Terminal Crash During Dispense
- Terminal restarts
- Recovery service queries ESP8266
- Transaction updated with actual count

## Test Derivation
- Happy path: 3 tokens dispensed, transaction created
- Partial dispense: Jam after 2 tokens, charged for 2 only
- Complete failure: Jam before any tokens, not charged
- Mixed cart: Tokens + beers, tokens dispensed first
- Dispenser busy: Show error, allow buying without tokens
- Recovery: Terminal crash, reconciles on restart
```

**Step 2: Commit**

```bash
git add use-cases/terminal/UC-T15-purchase-sauna-tokens.md
git commit -m "docs: add use case for purchasing sauna tokens

Documents main flow, variants, and error cases for token dispenser.

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

---

## Phase 6: Manual Hardware Validation

**This phase is entirely manual - no code changes.**

### Task 6.1: Hardware Setup

**Steps:**
1. Power on ESP8266
2. Load Azkoyen Hopper with tokens (minimum 20)
3. Configure terminal with dispenser IP and API key
4. Verify health check shows "Operational"

**Checklist:**
- [ ] ESP8266 responding to /health
- [ ] Terminal info screen shows dispenser status
- [ ] Test dispense command from terminal succeeds

---

### Task 6.2: Execute Manual Test Plan

**Steps:**
1. Print manual test plan from `docs/testing/manual-hardware-token-dispenser.md`
2. Execute all 10 test cases
3. Record PASS/FAIL and actual results
4. Document any issues found

**Deliverable:** Completed test plan with results

---

### Task 6.3: Performance Testing

**Steps:**
1. Measure dispense latency (time from tap to first token)
2. Measure full dispense time (1 token, 3 tokens, 10 tokens)
3. Test concurrent requests (2 terminals)
4. Test network resilience (WiFi latency, packet loss)

**Metrics to collect:**
- Average dispense time per token
- UI responsiveness during dispensing
- Error recovery time
- Health check polling overhead

---

## Summary

**Total Tasks:** 30+
**Phases:** 6 (5 implementation + 1 manual)

**Key Deliverables:**
- ✅ Backend with `requires_dispenser` support
- ✅ Admin frontend with dispenser checkbox
- ✅ Terminal with full dispenser integration
- ✅ Comprehensive test suite (unit, API, E2E)
- ✅ Manual hardware test plan
- ✅ Use case documentation

**Success Criteria:**
- All automated tests pass
- Manual hardware tests pass
- Performance meets expectations
- Code follows project patterns
- Documentation complete

---

**Next Steps:**

Run `superpowers:executing-plans` skill in a new session to implement this plan task-by-task with checkpoints.
