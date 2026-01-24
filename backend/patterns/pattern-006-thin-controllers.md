# Pattern 006: Thin Controllers (HTTP Routing Only)

**Category**: Application Layer & HTTP Handling
**Pattern Type**: Behavioral Pattern
**Related ADR**: ADR-0018 (Modular Architecture - Clean Separation)

---

## Problem

Fat controllers mix HTTP concerns with business logic:

```php
// ❌ Problematic: Fat controller with mixed concerns
class SyncController extends Controller
{
    public function updateLanguage(Request $request, string $memberId): JsonResponse
    {
        // Input validation
        $validated = $request->validate([
            'preferred_language' => 'required|string|in:de,en,fr',
        ]);

        // Business logic (shouldn't be here!)
        $member = Member::findOrFail($memberId);
        $member->update(['preferred_language' => $validated['preferred_language']]);

        // Audit logging (mixed in)
        Log::info('Member language updated', ['member_id' => $memberId]);

        // Query building
        $balance = $member->transactions()
            ->where('settled', false)
            ->sum('amount_cents');

        // Response formatting
        return response()->json([
            'member' => [
                'id' => $member->id,
                'first_name' => $member->first_name,
                'preferred_language' => $member->preferred_language,
                'balance_cents' => $balance,
            ],
        ]);
    }
}
```

Issues:
- Business logic not reusable from other HTTP consumers (CLI, queue jobs, etc.)
- Hard to test (requires HTTP context)
- Difficult to maintain (multiple concerns in one method)
- Logic not discoverable (hidden in controller)
- Violates Single Responsibility Principle

---

## Solution

Keep controllers **thin** by:
- Using FormRequest for validation (not controller methods)
- Delegating business logic to Service Layer
- Returning DTOs from services (not raw models)
- Focusing solely on HTTP request → service → HTTP response
- No direct model queries in controller

---

## Implementation Pattern

### Thin Controller Structure

```php
// app/Http/Controllers/SyncController.php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\SyncRequest;
use App\Http\Requests\UpdateLanguageRequest;
use App\Http\Requests\UploadTransactionsRequest;
use App\Services\SyncService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;

final class SyncController extends Controller
{
    // Dependency injection via constructor
    public function __construct(
        private readonly SyncService $syncService,
        private readonly TransactionService $transactionService,
    ) {}

    /**
     * Single responsibility: Route HTTP request → Service → Response
     */
    public function members(SyncRequest $request): JsonResponse
    {
        // 1. Validation happens automatically (FormRequest)
        // 2. Parse HTTP input → call service
        $result = $this->syncService->syncMembers($request->since());

        // 3. Serialize DTO → HTTP response
        return response()->json($result->toResponse('members'));
    }

    public function categories(SyncRequest $request): JsonResponse
    {
        $result = $this->syncService->syncCategories($request->since());
        return response()->json($result->toResponse('categories'));
    }

    public function products(SyncRequest $request): JsonResponse
    {
        $result = $this->syncService->syncProducts($request->since());
        return response()->json($result->toResponse('products'));
    }

    /**
     * Update member language
     *
     * Flow:
     * 1. FormRequest validates input
     * 2. Controller calls service with typed parameters
     * 3. Service returns DTO
     * 4. Controller serializes DTO to JSON
     */
    public function updateLanguage(
        UpdateLanguageRequest $request,
        string $memberId
    ): JsonResponse {
        $member = $this->syncService->updateMemberLanguage(
            $memberId,
            $request->preferredLanguage()  // Typed enum from FormRequest
        );

        return response()->json([
            'member' => $member->toArray(),
            'updated_at' => $member->updatedAt->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * Batch upload transactions
     */
    public function transactions(UploadTransactionsRequest $request): JsonResponse
    {
        $result = $this->transactionService->processBatch(
            $request->validated('transactions')
        );

        return response()->json($result->toArray());
    }
}
```

### Controller Method Anatomy

Every controller method should follow this pattern:

```php
public function someAction(SomeFormRequest $request): JsonResponse
{
    // 1. Validation: Already done by FormRequest
    // No manual validation here!

    // 2. Parse: Extract typed data from request
    $typedInput = $request->someTypedAccessor(); // e.g., preferredLanguage()

    // 3. Delegate: Call service with typed input
    $result = $this->service->performAction($typedInput);

    // 4. Respond: Serialize DTO to JSON
    return response()->json($result->toArray());
}
```

---

## Common Controller Patterns

### List Resource

```php
public function index(ListProductsRequest $request): JsonResponse
{
    // Service returns paginated DTO
    $products = $this->service->listProducts(
        page: $request->page(),
        perPage: $request->perPage(),
        sort: $request->sort(),
    );

    return response()->json($products->toResponse('products'));
}
```

### Show Resource

```php
public function show(string $id): JsonResponse
{
    $product = $this->service->getProduct($id);

    return response()->json($product->toArray());
}
```

### Create Resource

```php
public function store(CreateProductRequest $request): JsonResponse
{
    $product = $this->service->createProduct(
        name: $request->name(),
        price: $request->price(),
        categoryId: $request->categoryId(),
    );

    return response()->json(
        $product->toArray(),
        201  // HTTP 201 Created
    );
}
```

### Update Resource

```php
public function update(
    string $id,
    UpdateProductRequest $request
): JsonResponse {
    $product = $this->service->updateProduct(
        id: $id,
        name: $request->name(),
        price: $request->price(),
    );

    return response()->json($product->toArray());
}
```

### Delete Resource

```php
public function destroy(string $id): JsonResponse
{
    $this->service->deleteProduct($id);

    return response()->json(null, 204); // HTTP 204 No Content
}
```

### Batch Operation

```php
public function batchCreate(BatchCreateRequest $request): JsonResponse
{
    $result = $this->service->createBatch(
        $request->validated('items')
    );

    return response()->json([
        'created' => count($result->created),
        'errors' => $result->errors,
    ]);
}
```

---

## Exception Handling in Controllers

Controllers don't catch exceptions; exception handler deals with them:

```php
// ❌ Don't do this in controller
public function show(string $id): JsonResponse
{
    try {
        $product = $this->service->getProduct($id);
        return response()->json($product->toArray());
    } catch (ResourceNotFoundException $e) {
        return response()->json(['error' => 'not_found'], 404);
    }
}

// ✅ Let exception handler deal with it
public function show(string $id): JsonResponse
{
    $product = $this->service->getProduct($id);
    return response()->json($product->toArray());
    // If not found, service throws; exception handler catches and formats
}
```

---

## Controller Reusability

Thin controllers enable reuse across HTTP/non-HTTP contexts:

```php
// CLI Command using same service
class UpdateMemberLanguageCommand extends Command
{
    public function __construct(
        private SyncService $syncService,  // Reuse service!
    ) {}

    public function handle()
    {
        $memberId = $this->argument('member_id');
        $language = SupportedLanguage::from($this->argument('language'));

        $member = $this->syncService->updateMemberLanguage($memberId, $language);

        $this->info("Updated {$member->firstName} to {$language->value}");
    }
}

// Queue Job using same service
class SendMemberNotificationJob implements ShouldQueue
{
    public function __construct(
        private SyncService $syncService,  // Reuse service!
    ) {}

    public function handle()
    {
        $language = $this->syncService->getMemberLanguage($this->memberId);
        // ... send notification in that language
    }
}
```

---

## Testing Thin Controllers

Controllers are simpler to test:

```php
// tests/Feature/SyncControllerTest.php
<?php

use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class SyncControllerTest extends TestCase
{
    public function test_members_sync_returns_valid_response(): void
    {
        $response = $this->getJson('/api/sync/members?since=2026-01-01T00:00:00Z');

        $response->assertStatus(200)
            ->assertJson(fn (AssertableJson $json) =>
                $json->has('members')
                    ->has('members.0', fn ($json) =>
                        $json->where('id', $this->member->id)
                            ->etc()
                    )
                    ->where('count', 1)
                    ->where('has_more', false)
            );
    }

    public function test_update_language_calls_service(): void
    {
        $mock = $this->mock(SyncService::class);
        $mock->expects('updateMemberLanguage')
            ->with('member123', \Mockery::on(fn($arg) => $arg === SupportedLanguage::German))
            ->andReturn(new MemberDto(...));

        $response = $this->postJson('/api/sync/members/member123/language', [
            'preferred_language' => 'de',
        ]);

        $response->assertStatus(200);
    }
}
```

---

## Anti-Patterns to Avoid

### ❌ Business Logic in Controller

```php
// DON'T DO THIS
public function updateLanguage(Request $request, string $memberId): JsonResponse
{
    $member = Member::findOrFail($memberId);
    $member->update(['preferred_language' => $request->input('language')]);

    // Audit logic
    Log::info('Updated', ['member_id' => $memberId]);

    return response()->json(['member' => $member->toArray()]);
}
```

### ❌ Direct Model Queries

```php
// DON'T DO THIS
public function members(Request $request): JsonResponse
{
    $members = Member::where('updated_at', '>=', $request->query('since'))
        ->limit(100)
        ->get();

    return response()->json($members);
}
```

### ❌ Exception Handling in Controller

```php
// DON'T DO THIS
public function show(string $id): JsonResponse
{
    try {
        $product = Product::findOrFail($id);
        return response()->json($product);
    } catch (ModelNotFoundException $e) {
        return response()->json(['error' => 'not_found'], 404);
    } catch (Exception $e) {
        return response()->json(['error' => 'server_error'], 500);
    }
}
```

---

## Benefits

✅ **Simplicity**: Controllers only route HTTP → Service → Response
✅ **Reusability**: Services used by CLI, queue, other consumers
✅ **Testability**: Easy to mock services; no HTTP context needed
✅ **Maintainability**: Business logic logic changes in service, not controller
✅ **Consistency**: Same service behavior regardless of entry point
✅ **Clarity**: Clear data flow: Request → Service → DTO → Response

---

## When to Use

- All REST API endpoints
- All HTTP controllers
- Any endpoint delegating to business logic

---

## Consistency with Modularity (ADR-0018)

Controllers are **module-specific**:
- Located in `app/Http/Controllers/` or within module
- Named per module (e.g., `MembersController`, `ProductsController`)
- Each controller typically handles one module's REST endpoints
- Controllers import services from same module

---

## Related Patterns

- **Pattern 001**: Form Requests (validation before controller)
- **Pattern 003**: Data Transfer Objects (controllers serialize DTOs)
- **Pattern 004**: Service Layer (controllers delegate to services)
- **Pattern 007**: Exception Handler (centralized error handling)

---

## References

- [Clean Architecture - Robert C. Martin](https://blog.cleancoder.com/uncle-bob/2012/08/13/the-clean-architecture.html)
- [Single Responsibility Principle](https://en.wikipedia.org/wiki/Single-responsibility_principle)
- [Laravel Controllers Best Practices](https://laravel.com/docs/controllers)
