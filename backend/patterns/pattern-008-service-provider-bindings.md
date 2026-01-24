# Pattern 008: Service Provider for Dependency Injection Bindings

**Category**: Infrastructure & Dependency Injection
**Pattern Type**: Structural Pattern
**Related ADR**: ADR-0018 (Modular Architecture - Independence)

---

## Problem

Without service providers, dependencies are tightly coupled:

```php
// ❌ Problematic: Manual instantiation in controller
class SyncController extends Controller
{
    public function __construct()
    {
        // Manually construct entire dependency tree
        $db = new PDO('...'); // Hardcoded connection
        $memberModel = new Member($db);
        $memberRepo = new EloquentMemberRepository($memberModel);
        $categoryModel = new Category($db);
        $categoryRepo = new EloquentCategoryRepository($categoryModel);

        $this->syncService = new SyncService($memberRepo, $categoryRepo);
    }
}

// Another controller duplicates construction
class TransactionController extends Controller
{
    public function __construct()
    {
        // Duplicate all of the above!
        // Plus more dependencies for transaction operations
        $this->transactionService = new TransactionService(...);
    }
}
```

Issues:
- Dependencies duplicated across controllers
- Hard to swap implementations (for testing, caching, etc.)
- Configuration scattered across code
- Difficult to add/remove dependencies
- Testing requires manual mock injection

---

## Solution

Use **Service Providers** to:
- Centralize dependency bindings (interfaces to implementations)
- Configure how dependencies are instantiated
- Decouple controllers from concrete implementations
- Enable easy testing with mocked dependencies
- Manage singleton/shared instances

---

## Implementation Pattern

### Repository Service Provider

```php
// app/Providers/RepositoryServiceProvider.php
<?php

namespace App\Providers;

use App\Repositories\CategoryRepository;
use App\Repositories\Eloquent\EloquentCategoryRepository;
use App\Repositories\Eloquent\EloquentMemberRepository;
use App\Repositories\Eloquent\EloquentProductRepository;
use App\Repositories\Eloquent\EloquentTransactionRepository;
use App\Repositories\MemberRepository;
use App\Repositories\ProductRepository;
use App\Repositories\TransactionRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Define bindings between interfaces and implementations
     */
    public array $bindings = [
        MemberRepository::class => EloquentMemberRepository::class,
        CategoryRepository::class => EloquentCategoryRepository::class,
        ProductRepository::class => EloquentProductRepository::class,
        TransactionRepository::class => EloquentTransactionRepository::class,
    ];

    /**
     * Register services in the container
     */
    public function register(): void
    {
        // $bindings array is automatically processed by Laravel
        // No additional code needed
    }

    /**
     * Bootstrap services (runs after all services registered)
     */
    public function boot(): void
    {
        // Optional: Additional setup after all bindings registered
    }
}
```

### Service Provider

```php
// app/Providers/ServiceProvider.php
<?php

namespace App\Providers;

use App\Services\SyncService;
use App\Services\DefaultSyncService;
use App\Services\TransactionService;
use App\Services\DefaultTransactionService;
use App\Repositories\{
    MemberRepository,
    CategoryRepository,
    ProductRepository,
};
use Illuminate\Support\ServiceProvider;

class ServiceProviderRegistration extends ServiceProvider
{
    /**
     * Register services
     */
    public function register(): void
    {
        // Singleton: same instance throughout request lifecycle
        $this->app->singleton(
            SyncService::class,
            function ($app) {
                return new DefaultSyncService(
                    $app->make(MemberRepository::class),
                    $app->make(CategoryRepository::class),
                    $app->make(ProductRepository::class),
                );
            }
        );

        $this->app->singleton(
            TransactionService::class,
            function ($app) {
                return new DefaultTransactionService(
                    $app->make(TransactionRepository::class),
                    $app->make(MemberRepository::class),
                );
            }
        );
    }

    public function boot(): void
    {
        // Any additional setup
    }
}
```

### Register in config/app.php

```php
// config/app.php
'providers' => [
    // ...
    App\Providers\RepositoryServiceProvider::class,
    App\Providers\ServiceProviderRegistration::class,
    // ...
],
```

---

## Controller Using Injected Dependencies

Controllers now receive fully-constructed dependencies:

```php
// app/Http/Controllers/SyncController.php
<?php

namespace App\Http\Controllers;

use App\Services\SyncService;
use App\Services\TransactionService;

final class SyncController extends Controller
{
    /**
     * Laravel's container automatically constructs these services
     * with all their dependencies resolved recursively
     */
    public function __construct(
        private readonly SyncService $syncService,
        private readonly TransactionService $transactionService,
    ) {}

    // Controller methods use injected services
    public function members(SyncRequest $request)
    {
        // $this->syncService is fully constructed, ready to use
        return response()->json(
            $this->syncService->syncMembers($request->since())->toResponse('members')
        );
    }
}
```

---

## Swapping Implementations for Testing

Service providers enable easy testing:

```php
// tests/Feature/SyncTest.php
<?php

use App\Repositories\MemberRepository;
use App\Services\SyncService;
use Tests\TestCase;

class SyncTest extends TestCase
{
    public function test_sync_members_returns_results(): void
    {
        // Override binding for this test
        $mockRepo = $this->createMock(MemberRepository::class);
        $mockRepo->expects($this->once())
            ->method('getModifiedSince')
            ->willReturn(new SyncResultDto(
                items: [$this->createMemberDto()],
                cursor: 'end',
                hasMore: false,
            ));

        // Bind mock to container
        $this->app->bind(MemberRepository::class, fn() => $mockRepo);

        // Make request; controller gets mocked dependency
        $response = $this->getJson('/api/sync/members?since=2026-01-01T00:00:00Z');

        $response->assertStatus(200)
            ->assertJsonPath('members.0.id', $this->createMemberDto()->id);
    }
}
```

---

## Advanced: Contextual Bindings

Bind different implementations based on context:

```php
// app/Providers/CacheStrategyProvider.php
<?php

namespace App\Providers;

use App\Repositories\MemberRepository;
use App\Repositories\Eloquent\EloquentMemberRepository;
use App\Repositories\Cached\CachedMemberRepository;
use Illuminate\Support\ServiceProvider;

class CacheStrategyProvider extends ServiceProvider
{
    public function register(): void
    {
        // In production, use cached version
        if ($this->app->environment('production')) {
            $this->app->bind(
                MemberRepository::class,
                function ($app) {
                    return new CachedMemberRepository(
                        new EloquentMemberRepository($app->make(Member::class)),
                        $app->make('cache')
                    );
                }
            );
        } else {
            // In dev/test, use direct Eloquent
            $this->app->bind(
                MemberRepository::class,
                EloquentMemberRepository::class
            );
        }
    }
}
```

---

## Singleton vs Transient

Control instance lifecycle:

```php
// app/Providers/ServiceProviderRegistration.php

public function register(): void
{
    // SINGLETON: One instance per request lifecycle
    $this->app->singleton(SyncService::class, function ($app) {
        return new DefaultSyncService(...);
    });

    // TRANSIENT: New instance every time (rare)
    $this->app->bind(TransactionLogger::class, function ($app) {
        return new DefaultTransactionLogger();
    });

    // WITH $bindings array (auto-singleton by default)
    // Single interface → single implementation (most common)
}
```

---

## Service Provider Organization

```
app/Providers/
├── RepositoryServiceProvider.php    # Repository bindings
├── ServiceProviderRegistration.php  # Service layer bindings
├── EventServiceProvider.php         # Event listeners
└── RouteServiceProvider.php         # Route bindings
```

---

## Benefits

✅ **Decoupling**: Controllers don't know concrete implementations
✅ **Testability**: Easy to mock dependencies
✅ **Flexibility**: Swap implementations globally
✅ **Configuration**: Single place to manage bindings
✅ **Reusability**: Same bindings across all controllers
✅ **Dependency resolution**: Container automatically resolves dependency trees
✅ **Lifecycle management**: Control singleton vs transient instances

---

## When to Use

- All service and repository dependencies
- Cross-cutting concerns (logging, caching, validation)
- Any interface that might have multiple implementations
- Configuration of complex objects

---

## When NOT to Use

- Simple value objects (no need to bind scalars)
- Configuration values (use .env and config files)
- Request-scoped data (use request stack instead)

---

## Consistency with Modularity (ADR-0018)

Service providers are **infrastructure**:
- Located in `app/Providers/` directory
- One provider per concern (RepositoryServiceProvider, ServiceProviderRegistration)
- All modules use same providers
- Central place to wire up module dependencies

---

## Related Patterns

- **Pattern 004**: Service Layer (services registered here)
- **Pattern 005**: Repository Interface (interfaces bound here)
- **Pattern 006**: Thin Controllers (controllers injected with dependencies)

---

## References

- [Laravel Service Providers](https://laravel.com/docs/providers)
- [Laravel Service Container](https://laravel.com/docs/container)
- [Dependency Injection Pattern](https://en.wikipedia.org/wiki/Dependency_injection)
