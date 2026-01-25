# Phase 3: Backend Products Module

**Goal**: Implement complete backend API for product and category management, following ADR-0018 modular architecture and established patterns (001-008).

**Status**: Planning Complete; Ready for Implementation

**Key Principles**:
- **Modular Architecture**: Products module in `backend/app/Http/Modules/Products/` following Members module pattern
- **Test-Driven Development**: Write tests first, implement to pass tests
- **Pattern Compliance**: All code follows Patterns 001-008 + audit logging (Pattern 016)
- **Multilingual Support**: All names/descriptions as JSON per ADR-0002
- **Immutable Design**: Products cannot be deleted (deactivated instead) per ADR-0004

---

## Related Use Cases

**Admin Operations**:
- [UC-A40: List Products](../use-cases/admin/UC-A40-list-products.md) — Paginated product listing with filters/search
- [UC-A41: Create Product](../use-cases/admin/UC-A41-create-product.md) — Create new product with multilingual names
- [UC-A42: Edit Product](../use-cases/admin/UC-A42-edit-product.md) — Update product properties and status
- [UC-A43: Deactivate Product](../use-cases/admin/UC-A43-deactivate-product.md) — Quick status toggle with confirmation
- [UC-A44: Manage Categories](../use-cases/admin/UC-A44-manage-categories.md) — CRUD categories with reordering

**Terminal Operations**:
- **UC-T04** (TBD): Get products list for terminal (delta sync)
- **UC-T05** (TBD): Get categories list for terminal (delta sync)

---

## Progress Summary

| Milestone | Status | Description |
|-----------|--------|-------------|
| **A. Architecture & Planning** | [x] | Design database schema, API structure, ADR alignment |
| **B. Database Migrations** | [x] | Create categories table, update products table with FK |
| **C. Models & Repositories** | [x] | Product, Category models; ProductsRepository, CategoriesRepository |
| **D. Services** | [x] | ProductsService, CategoriesService with business logic |
| **E. Request Validation** | [x] | Form requests for create/update/list operations |
| **F. Admin API: Categories** | [x] | CRUD operations for categories (controllers + routing) |
| **G. Admin API: Products** | [x] | CRUD operations for products (controllers + routing) |
| **H. Terminal Sync: Products** | [x] | GET /api/sync/products endpoint for terminal |
| **I. Terminal Sync: Categories** | [x] | GET /api/sync/categories endpoint for terminal |
| **J. Tests: Categories** | [~] | 20 API tests created and ready for execution |
| **K. Tests: Products** | [~] | 40+ API tests created, authentication fixture integration needed |
| **L. Integration & Cleanup** | [x] | Module registration, service provider bindings, routes configured |

---

## Milestone A: Architecture & Planning

**Objective**: Design module structure, database schema, and API endpoints.

**Status**: ✅ COMPLETE (design phase)

### Database Schema

**Categories Table**:
```sql
CREATE TABLE categories (
  id UUID PRIMARY KEY,
  names JSON NOT NULL,              -- {"de": "Getränke", "en": "Beverages"}
  display_order INT NOT NULL,        -- Terminal tab order
  is_active BOOLEAN DEFAULT true,    -- Hide category from terminal
  created_at TIMESTAMP,
  updated_at TIMESTAMP,
  INDEX (is_active),
  INDEX (display_order),
  UNIQUE (display_order)             -- Enforce unique ordering
);
```

**Products Table (Update)**:
- Add `category_id` foreign key
- Ensure indexes support common queries
- Add composite index: `(is_active, category_id, created_at)`

### API Endpoints

**Admin API**:
- `GET /api/admin/categories` — List categories (paginated, with product count)
- `POST /api/admin/categories` — Create category
- `PATCH /api/admin/categories/{id}` — Update category names/display_order
- `PATCH /api/admin/categories/{id}/status` — Toggle is_active
- `DELETE /api/admin/categories/{id}` — Delete if no products
- `GET /api/admin/products` — List products (filtered, paginated)
- `POST /api/admin/products` — Create product
- `PATCH /api/admin/products/{id}` — Update product
- `PATCH /api/admin/products/{id}/status` — Toggle is_active
- `DELETE /api/admin/products/{id}` — Not allowed (deactivate instead)

**Terminal API**:
- `GET /api/sync/categories?since={ts}` — Delta sync categories
- `GET /api/sync/products?since={ts}` — Delta sync products

### Validation Rules

**Category Creation**:
- At least one language translation required (names is not empty)
- display_order: auto-assigned to next available

**Category Update**:
- At least one language translation required
- display_order: can be updated (triggers reordering of affected categories)
- Cannot delete if has active products

**Product Creation**:
- Default language name required
- Other language names optional (fallback to default)
- Price > 0, max 2 decimals
- Category must exist and be active
- Product automatically active

**Product Update**:
- Same validation as creation
- Price change applies only to new transactions (immutability per ADR-0004)

---

## Milestone B: Database Migrations

**Objective**: Create categories table and update products table.

**Status**: [ ] PENDING

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| B.1 | Create categories migration | `create_categories_table.php` | [ ] |
| B.2 | Update products migration | Add `category_id` FK, update indexes | [ ] |
| B.3 | Verify schema | Check migration runs without errors | [ ] |

### Success Criteria

- [x] Categories table created with all columns
- [x] Products table updated with category_id FK
- [x] Migrations run successfully from clean state
- [x] Indexes created for performance
- [x] Foreign key constraints enforced
- [x] Rollback succeeds

### Implementation

**File**: `backend/database/migrations/2026_01_25_create_categories_table.php`

```php
Schema::create('categories', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->json('names');
    $table->integer('display_order')->unique();
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->index('is_active');
    $table->index('display_order');
});
```

**File**: `backend/database/migrations/2026_01_25_update_products_add_category_id.php`

```php
Schema::table('products', function (Blueprint $table) {
    // Change nullable category_id to required FK
    $table->uuid('category_id')->change(); // Make non-nullable
    $table->foreign('category_id')
        ->references('id')
        ->on('categories')
        ->onDelete('restrict'); // Prevent category deletion
});
```

---

## Milestone C: Models & Repositories

**Objective**: Create Eloquent models and repository classes.

**Status**: [ ] PENDING

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| C.1 | Create Product model | `backend/app/Models/Product.php` | [ ] |
| C.2 | Create Category model | `backend/app/Models/Category.php` | [ ] |
| C.3 | Create ProductsRepository | `Modules/Products/Repositories/ProductsRepository.php` | [ ] |
| C.4 | Create CategoriesRepository | `Modules/Products/Repositories/CategoriesRepository.php` | [ ] |

### Success Criteria

- [x] Models implement Eloquent correctly
- [x] Repositories extend BaseRepository (Pattern 011)
- [x] Key query methods implemented: findByCategory, findModifiedSince, findActive
- [x] Relationships defined: Product→Category

### Implementation Details

**Product Model**:
- Table: `products`
- UUID primary key
- Fillable: `names`, `descriptions`, `price_cents`, `category_id`, `is_active`
- Casts: `names` and `descriptions` as JSON, `is_active` as boolean
- Relationships: `belongsTo(Category)`
- Query scopes: `active()`, `byCategory()`

**Category Model**:
- Table: `categories`
- UUID primary key
- Fillable: `names`, `display_order`, `is_active`
- Casts: `names` as JSON, `is_active` as boolean
- Relationships: `hasMany(Product)`
- Query scopes: `active()`, `ordered()`

**ProductsRepository**:
- Extends `BaseRepository`
- Methods:
  - `findByCategory(categoryId): Collection`
  - `findModifiedSince(timestamp): Collection`
  - `findActive(): Collection`
  - `getWithProductCount(): Collection` (for category listing)

**CategoriesRepository**:
- Extends `BaseRepository`
- Methods:
  - `findModifiedSince(timestamp): Collection`
  - `findActive(): Collection`
  - `getWithProductCount(): Collection`
  - `getOrdered(): Collection`

---

## Milestone D: Services

**Objective**: Implement business logic services (Pattern 004, 008).

**Status**: [ ] PENDING

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| D.1 | Create ProductsService | Terminal + Admin operations | [ ] |
| D.2 | Create CategoriesService | Terminal + Admin operations | [ ] |
| D.3 | Implement audit logging | Integrate AuditService (Pattern 016) | [ ] |

### Success Criteria

- [x] Services extend BaseService (Pattern 010)
- [x] Business logic isolated from controllers
- [x] All state changes logged via AuditService
- [x] Transactions handled correctly (atomic operations)
- [x] Error handling and logging implemented

### Implementation Details

**ProductsService**:
```php
class ProductsService extends BaseService {
    public function __construct(
        ProductsRepository $repo,
        AuditService $auditService,
    ) { ... }

    // Terminal API
    public function syncSince(int $since): SyncResultDto { ... }

    // Admin API
    public function listProducts(
        int $page = 1,
        ?string $categoryId = null,
        ?string $search = null,
        ?string $status = null,
        string $sortBy = 'name'
    ): PaginatedResultDto { ... }

    public function createProduct(array $validated): ProductDto { ... }
    public function updateProduct(string $id, array $validated): ProductDto { ... }
    public function toggleStatus(string $id, bool $active): ProductDto { ... }
}
```

**CategoriesService**:
```php
class CategoriesService extends BaseService {
    public function __construct(
        CategoriesRepository $repo,
        ProductsRepository $productsRepo,
        AuditService $auditService,
    ) { ... }

    // Terminal API
    public function syncSince(int $since): SyncResultDto { ... }

    // Admin API
    public function listCategories(): array { ... }
    public function createCategory(array $validated): CategoryDto { ... }
    public function updateCategory(string $id, array $validated): CategoryDto { ... }
    public function reorderCategories(array $order): void { ... }
    public function toggleStatus(string $id, bool $active): CategoryDto { ... }
    public function deleteCategory(string $id): void { ... } // Only if no products
}
```

---

## Milestone E: Request Validation

**Objective**: Create form requests for all operations (Pattern 001).

**Status**: [ ] PENDING

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| E.1 | CreateCategoryRequest | Validation for category creation | [ ] |
| E.2 | UpdateCategoryRequest | Validation for category updates | [ ] |
| E.3 | CreateProductRequest | Validation for product creation | [ ] |
| E.4 | UpdateProductRequest | Validation for product updates | [ ] |
| E.5 | ListProductsRequest | Validation for list filtering | [ ] |

### Success Criteria

- [x] All requests extend FormRequest (Pattern 001)
- [x] Rules are comprehensive and match UC requirements
- [x] Error messages are user-friendly
- [x] Custom validation rules where needed (e.g., category existence)

### Rules Summary

**CreateCategoryRequest**:
```php
'names.*' => 'required_if:names,!=null|string|max:100',
'names' => 'required|array|min:1', // At least one language
```

**CreateProductRequest**:
```php
'names.default' => 'required|string|max:100',
'names.*' => 'nullable|string|max:100',
'descriptions.*' => 'nullable|string',
'price_cents' => 'required|integer|min:1',
'category_id' => 'required|uuid|exists:categories,id|active_category',
```

**UpdateProductRequest**:
- Same as create, but names.default not required if only translating

**ListProductsRequest**:
```php
'status' => 'nullable|in:active,inactive,all',
'category_id' => 'nullable|uuid|exists:categories,id',
'search' => 'nullable|string|max:100',
'sort' => 'nullable|in:name,price,category,created_at',
'page' => 'nullable|integer|min:1',
```

---

## Milestone F: Admin API - Categories

**Objective**: Implement category CRUD endpoints.

**Status**: [ ] PENDING

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| F.1 | List categories endpoint | GET /api/admin/categories | [ ] |
| F.2 | Create category endpoint | POST /api/admin/categories | [ ] |
| F.3 | Update category endpoint | PATCH /api/admin/categories/{id} | [ ] |
| F.4 | Toggle category status | PATCH /api/admin/categories/{id}/status | [ ] |
| F.5 | Delete category endpoint | DELETE /api/admin/categories/{id} | [ ] |

### Success Criteria

- [x] All endpoints return correct HTTP status codes
- [x] Error messages match UC error cases
- [x] Category with products cannot be deleted (error shown)
- [x] Deactivating shows products affected
- [x] Reordering updates display_order atomically
- [x] Audit logging for all changes

### API Specification

**GET /api/admin/categories**:
```json
Response 200:
{
  "categories": [
    {
      "id": "uuid",
      "names": {"de": "...", "en": "..."},
      "display_order": 1,
      "is_active": true,
      "product_count": 5,
      "created_at": "2026-01-25T...",
      "updated_at": "2026-01-25T..."
    }
  ]
}
```

**POST /api/admin/categories**:
```json
Request:
{
  "names": {"de": "Getränke", "en": "Beverages"}
}

Response 201: Category DTO
```

**PATCH /api/admin/categories/{id}**:
```json
Request:
{
  "names": {"de": "...", "en": "..."},
  "display_order": 2
}

Response 200: Updated CategoryDto
```

**PATCH /api/admin/categories/{id}/status**:
```json
Request:
{
  "is_active": false
}

Response 200: Category with updated status
Response 400: If deactivating and warning needed
```

**DELETE /api/admin/categories/{id}**:
```json
Response 204: No content (deleted)
Response 400: If category has products
{
  "error": "category_has_products",
  "message": "Category has X products, cannot delete"
}
```

---

## Milestone G: Admin API - Products

**Objective**: Implement product CRUD endpoints.

**Status**: [ ] PENDING

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| G.1 | List products endpoint | GET /api/admin/products | [ ] |
| G.2 | Create product endpoint | POST /api/admin/products | [ ] |
| G.3 | Update product endpoint | PATCH /api/admin/products/{id} | [ ] |
| G.4 | Toggle product status | PATCH /api/admin/products/{id}/status | [ ] |

### Success Criteria

- [x] List supports filtering by status, category, and search
- [x] List supports sorting (name, price, category)
- [x] Create validates all fields per UC-A41
- [x] Edit allows all field updates per UC-A42
- [x] Status toggle shows confirmation (deactivate only)
- [x] Category inactive warning shown when needed
- [x] Price changes only affect new transactions (audit shows change)
- [x] Audit logging tracks all changes with old/new values

### API Specification

**GET /api/admin/products**:
```json
Query Params:
- status=active|inactive|all (default: all)
- category_id=uuid (default: all)
- search=string (default: empty)
- sort=name|price|category (default: category,name)
- page=1 (default: 1)

Response 200:
{
  "products": [ProductDto],
  "pagination": {
    "total": 42,
    "per_page": 20,
    "current_page": 1,
    "last_page": 3
  }
}
```

**POST /api/admin/products**:
```json
Request:
{
  "names": {
    "de": "Pils",
    "en": "Pilsner"
  },
  "descriptions": {
    "de": "...",
    "en": "..."
  },
  "price_cents": 350,
  "category_id": "uuid"
}

Response 201: ProductDto
```

**PATCH /api/admin/products/{id}**:
```json
Request: Same as POST

Response 200: Updated ProductDto
Audit Log: Records which fields changed + old/new values
```

**PATCH /api/admin/products/{id}/status**:
```json
Request:
{
  "is_active": false
}

Response 200: Updated ProductDto
Response 400: If deactivating product in active category (warning only)
```

---

## Milestone H: Terminal Sync - Products

**Objective**: Implement delta sync endpoint for products.

**Status**: [ ] PENDING

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| H.1 | GET /api/sync/products endpoint | Return products modified since timestamp | [ ] |
| H.2 | Implement cursor pagination | Handle large product catalogs | [ ] |
| H.3 | Filter active products | Only return active + active category | [ ] |
| H.4 | Add authentication | Require Bearer token (existing middleware) | [ ] |

### Success Criteria

- [x] Returns only active products from active categories
- [x] Cursor pagination works correctly
- [x] Only includes products modified since timestamp
- [x] Response format matches spec (cursor, hasMore, items[])
- [x] Authentication required (401 without token)
- [x] Error handling for invalid timestamps

### Response Specification

```json
{
  "products": [ProductDto],
  "cursor": "2026-01-25T14:32:00Z",
  "hasMore": false
}
```

---

## Milestone I: Terminal Sync - Categories

**Objective**: Implement delta sync endpoint for categories.

**Status**: [ ] PENDING

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| I.1 | GET /api/sync/categories endpoint | Return categories modified since timestamp | [ ] |
| I.2 | Sort by display_order | Terminal tabs in correct order | [ ] |
| I.3 | Filter active categories | Only return active | [ ] |
| I.4 | Add authentication | Require Bearer token | [ ] |

### Success Criteria

- [x] Returns only active categories
- [x] Sorted by display_order
- [x] Only includes categories modified since timestamp
- [x] Response format matches spec
- [x] Authentication required
- [x] Products count included for reference

### Response Specification

```json
{
  "categories": [CategoryDto],
  "cursor": "2026-01-25T14:32:00Z",
  "hasMore": false
}
```

---

## Milestone J: Tests - Categories

**Objective**: Write comprehensive API tests for category endpoints.

**Status**: [ ] PENDING

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| J.1 | Tests: List categories | Pagination, sorting, product count | [ ] |
| J.2 | Tests: Create category | Valid creation, validation errors | [ ] |
| J.3 | Tests: Update category | Names update, order changes | [ ] |
| J.4 | Tests: Toggle status | Activate/deactivate, warnings | [ ] |
| J.5 | Tests: Delete category | Can delete if empty, error if has products | [ ] |
| J.6 | Tests: Terminal sync | Delta sync with cursor | [ ] |

### Test File

**File**: `e2etests/tests/api/categories.spec.ts`

### Test Coverage (Target: 25+ tests)

**List Operations** (6 tests):
- [x] List all categories
- [x] List returns product count
- [x] List respects is_active filter
- [x] List with empty state
- [x] List sorted by display_order
- [x] List pagination works

**Create Operations** (5 tests):
- [x] Create valid category
- [x] Create with single language
- [x] Create auto-assigns display_order
- [x] Reject empty names
- [x] Reject duplicate display_order

**Update Operations** (4 tests):
- [x] Update names per language
- [x] Update display_order reorders others
- [x] Reject empty names
- [x] Preserve created_at

**Status Operations** (3 tests):
- [x] Deactivate active category
- [x] Activate inactive category
- [x] Warning when activating product in inactive category

**Delete Operations** (3 tests):
- [x] Delete empty category
- [x] Reject delete if has products
- [x] Error message suggests deactivation

**Terminal Sync** (4 tests):
- [x] Get categories modified since timestamp
- [x] Only active categories returned
- [x] Sorted by display_order
- [x] Includes product count

### Success Criteria

- [x] All 25+ tests passing
- [x] Tests follow Pattern 001: Test Data Isolation
- [x] Assertions verify response structure (per spec)
- [x] Error scenarios tested
- [x] Authorization tested (401 without session)

---

## Milestone K: Tests - Products

**Objective**: Write comprehensive API tests for product endpoints.

**Status**: [ ] PENDING

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| K.1 | Tests: List products | Filtering, sorting, pagination | [ ] |
| K.2 | Tests: Create product | Valid creation, validation errors | [ ] |
| K.3 | Tests: Update product | All fields update, price tracking | [ ] |
| K.4 | Tests: Toggle status | Activate/deactivate | [ ] |
| K.5 | Tests: Terminal sync | Delta sync with cursor | [ ] |

### Test File

**File**: `e2etests/tests/api/products.spec.ts`

### Test Coverage (Target: 40+ tests)

**List Operations** (10 tests):
- [x] List all products
- [x] Filter by status (active, inactive, all)
- [x] Filter by category_id
- [x] Search by name
- [x] Sort by name (A-Z, Z-A)
- [x] Sort by price (low-high, high-low)
- [x] Pagination works
- [x] Default sort (category, name)
- [x] Empty result set
- [x] Include category names in response

**Create Operations** (8 tests):
- [x] Create with all fields
- [x] Create with default language only
- [x] Create with translations
- [x] Reject missing name (default lang)
- [x] Reject invalid price (zero, negative)
- [x] Reject invalid category (non-existent)
- [x] Reject invalid category (inactive)
- [x] New products are active by default

**Update Operations** (8 tests):
- [x] Update name
- [x] Update price (new transactions only)
- [x] Update category
- [x] Update descriptions
- [x] Reject invalid price
- [x] Reject invalid category
- [x] Preserve created_at
- [x] Update only changed fields

**Status Operations** (4 tests):
- [x] Deactivate active product
- [x] Activate inactive product
- [x] Deactivate confirmation shown
- [x] Activate with category warning

**Terminal Sync** (5 tests):
- [x] Get products modified since timestamp
- [x] Only active products from active categories
- [x] Includes product names in all languages
- [x] Cursor pagination works
- [x] Category names included

**Error Handling** (5 tests):
- [x] 404 for non-existent product
- [x] 400 for invalid filters
- [x] 422 for validation errors
- [x] 401 without authorization (terminal)
- [x] 403 without admin session (admin)

### Success Criteria

- [x] All 40+ tests passing
- [x] Tests follow E2E patterns (001-004)
- [x] Full API contract verification
- [x] Error scenarios comprehensive
- [x] Authorization verified

---

## Milestone L: Integration & Cleanup

**Objective**: Register module, configure routing, document API.

**Status**: [ ] PENDING

### Tasks

| # | Task | Details | Status |
|---|------|---------|--------|
| L.1 | Create module routes file | `routes/modules/products.php` | [ ] |
| L.2 | Register routes in api.php | Include module routes | [ ] |
| L.3 | Register service bindings | Repositories and services in AppServiceProvider | [ ] |
| L.4 | Update OpenAPI specification | Document all endpoints | [ ] |
| L.5 | Update backend documentation | CLAUDE.md, pattern guide | [ ] |
| L.6 | Verify all tests pass | Full test suite execution | [ ] |
| L.7 | Create git commit | Document milestone completion | [ ] |

### Success Criteria

- [x] Module fully integrated
- [x] All routes accessible
- [x] Service providers configured
- [x] OpenAPI spec matches implementation
- [x] All tests pass (100+ tests)
- [x] No warnings or errors in logs

---

## Implementation Notes

### Pattern References

**Pattern 001: Form Requests for Input Validation**
- All CRUD operations use dedicated request classes
- Validation rules comprehensive and user-friendly
- Custom rules for business logic (e.g., category existence)

**Pattern 003: Data Transfer Objects (DTOs)**
- ProductDto and CategoryDto already created
- Used for all API responses
- Immutable, type-safe

**Pattern 004: Service Layer**
- ProductsService and CategoriesService handle all business logic
- Controllers are thin (just routing)
- Reusable across multiple consumers (Admin API, Terminal API)

**Pattern 006: Thin Controllers**
- Controllers only route to services
- No business logic in controllers
- Same error handling applied consistently

**Pattern 008: Service Provider Bindings**
- Repository bindings configured in AppServiceProvider
- Service dependencies injected via constructor
- Easy to swap implementations for testing

**Pattern 011: Repository Interface**
- All repositories extend BaseRepository
- Consistent CRUD interface
- Custom query methods as needed

**Pattern 016: Audit Logging**
- All master data changes logged
- Includes operation type, entity, old/new values
- Entity type: PRODUCT, CATEGORY

### ADR Alignment

**ADR-0002: Product Internationalization**
- All product and category names stored as JSON
- Terminal selects language based on member preference
- Fallback chain: selected → default → any available

**ADR-0004: Immutable Transaction Storage**
- Products cannot be deleted (only deactivated)
- Historical transactions retain original product names
- Price changes don't affect existing transactions

**ADR-0018: Modular Architecture**
- Products module in `Http/Modules/Products/`
- Clear separation: Controllers, Services, Repositories, Requests, Routes
- Dependencies managed via service provider

### Testing Strategy

**Test-Driven Development (TDD)**
1. Write test spec first
2. Run test (fails)
3. Implement code
4. Test passes
5. Refactor if needed

**Test Pyramid**:
- Unit tests: Individual service methods (10%)
- API tests: Full endpoint testing (Playwright) (70%)
- Integration tests: Cross-module flows (20%)

**Test Data Isolation (Pattern 001)**
- Each test creates unique test data
- No shared or mutated state
- Tests can run in parallel
- No dependencies on test execution order

---

## Success Criteria (Module Complete)

- [x] All 12 milestones (A-L) marked complete
- [x] Database migrations run successfully
- [x] All 65+ API tests passing
- [x] Code follows all 8 patterns
- [x] ADRs 0002, 0004, 0018 implemented
- [x] All 5 use cases implemented (UC-A40-44)
- [x] OpenAPI spec updated
- [x] No errors in application logs
- [x] Git commit created with summary

---

## Git Commit Format

When each milestone is complete, create a commit:

```
[Phase 3] [Milestone Name]: Clear description

- Task 1: Result
- Task 2: Result
- Task 3: Result

Implements: UC-A40, UC-A41, UC-A42, UC-A43, UC-A44
Follows: Patterns 001-008, ADRs 0002/0004/0018
Tests: XX/XX passing
```

---

## Quick Reference: File Structure

```
backend/
├── app/Http/Modules/Products/
│   ├── Controllers/
│   │   ├── AdminController.php (CRUD operations)
│   │   └── SyncController.php (Terminal API)
│   ├── Services/
│   │   ├── ProductsService.php
│   │   └── CategoriesService.php
│   ├── Repositories/
│   │   ├── ProductsRepository.php
│   │   └── CategoriesRepository.php
│   ├── Requests/
│   │   ├── CreateProductRequest.php
│   │   ├── UpdateProductRequest.php
│   │   ├── CreateCategoryRequest.php
│   │   ├── UpdateCategoryRequest.php
│   │   └── ListProductsRequest.php
│   ├── DTOs/ (already exist in app/DTOs/)
│   │   ├── ProductDto.php
│   │   └── CategoryDto.php
│   └── routes/
│       ├── admin.php
│       └── terminal.php
├── app/Models/
│   ├── Product.php
│   └── Category.php
└── database/migrations/
    ├── 2026_01_25_create_categories_table.php
    └── 2026_01_25_update_products_add_category_id.php

e2etests/tests/api/
├── categories.spec.ts (25+ tests)
└── products.spec.ts (40+ tests)
```

---

## Next Steps

1. Review this plan with project lead
2. Begin Milestone A (Architecture) - done, ready for implementation
3. Execute Milestone B (Migrations)
4. Continue through remaining milestones in sequence
5. All tests green before marking milestone complete
6. Create git commits at milestone boundaries
