# Pattern 008: ServiceFactory for Dependency Injection

**Category**: Infrastructure & Dependency Injection
**Pattern Type**: Structural Pattern
**Related ADR**: ADR-0018 (Modular Architecture - Independence)

---

## Problem

Without a DI container, dependencies are tightly coupled:

```php
// ❌ Problematic: Manual instantiation in controller
class MembersController
{
    public function __construct()
    {
        $pdo = new PDO('...');
        $logger = new Logger('...');
        $repo = new MembersRepository($pdo, $logger);
        $auditRepo = new AuditLogRepository($pdo, $logger);
        $auditService = new AuditService($auditRepo);
        $this->service = new MembersService($repo, $auditService);
    }
}
```

Issues:
- Dependencies duplicated across controllers
- Hard to swap implementations (for testing, etc.)
- Configuration scattered across code
- Testing requires manual mock injection

---

## Solution

Use a **`ServiceFactory`** class implementing PSR `ContainerInterface` to:
- Centralize dependency construction
- Wire repositories, services, controllers, and middleware
- Provide singleton instances (lazy-loaded)
- Enable Slim to resolve controller dependencies automatically

---

## Implementation Pattern

### ServiceFactory

```php
// src/ServiceFactory.php
namespace App;

use App\Shared\Config\AppConfig;
use App\Shared\Logging\Logger;
use App\Shared\Validation\Validator;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Members\Services\MembersService;
use App\Modules\Members\Controllers\AdminController as MembersAdminController;
use App\Shared\Services\AuditService;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use PDO;
use Psr\Container\ContainerInterface;

class ServiceFactory implements ContainerInterface
{
    private array $instances = [];

    /**
     * Maps FQCN to getter method names.
     * Slim resolves controllers via ContainerInterface::get($fqcn).
     */
    private const FQCN_MAP = [
        MembersAdminController::class => 'getMembersAdminController',
        // ...all controllers, middleware
    ];

    public function __construct(
        private PDO $pdo,
        private AppConfig $config,
        private Logger $logger,
    ) {}

    // --- Repositories (lazy singletons) ---

    public function getMembersRepository(): MembersRepository
    {
        return $this->resolve(
            MembersRepository::class,
            fn() => new MembersRepository($this->pdo, $this->logger)
        );
    }

    public function getAuditLogRepository(): AuditLogRepository
    {
        return $this->resolve(
            AuditLogRepository::class,
            fn() => new AuditLogRepository($this->pdo, $this->logger)
        );
    }

    // --- Services (depend on repositories) ---

    public function getAuditService(): AuditService
    {
        return $this->resolve(
            AuditService::class,
            fn() => new AuditService($this->getAuditLogRepository())
        );
    }

    public function getMembersService(): MembersService
    {
        return $this->resolve(
            MembersService::class,
            fn() => new MembersService(
                $this->getMembersRepository(),
                $this->getTransactionsRepository(),
                $this->getAuditService(),
            )
        );
    }

    // --- Controllers (depend on services + validator) ---

    public function getMembersAdminController(): MembersAdminController
    {
        return $this->resolve(
            MembersAdminController::class,
            fn() => new MembersAdminController(
                $this->getMembersService(),
                $this->getValidator(),
            )
        );
    }

    // --- Middleware ---

    public function getAdminSessionAuth(): AdminSessionAuth
    {
        return $this->resolve(
            AdminSessionAuth::class,
            fn() => new AdminSessionAuth($this->getAdminUsersRepository())
        );
    }

    // --- Container interface for Slim ---

    public function has(string $id): bool
    {
        return isset(self::FQCN_MAP[$id]);
    }

    public function get(string $id): mixed
    {
        if (isset(self::FQCN_MAP[$id])) {
            $method = self::FQCN_MAP[$id];
            return $this->$method();
        }
        throw new \RuntimeException("Service not found: {$id}");
    }

    // --- Internal ---

    private function resolve(string $key, callable $factory): mixed
    {
        return $this->instances[$key] ??= $factory();
    }
}
```

### How It Works

1. **`FQCN_MAP`**: Maps fully-qualified class names to getter methods. Slim calls `$container->get(MembersAdminController::class)` to resolve controllers.
2. **`resolve()`**: Lazy-loads and caches instances. Each dependency is created once per request.
3. **Dependency chain**: `Controller → Service → Repository → PDO`. Each getter calls other getters, building the tree.

### Application Bootstrap

```php
// public/index.php
$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$config = AppConfig::fromEnv();
$logger = new Logger($config->logDir);

$container = new ServiceFactory($pdo, $config, $logger);
$app = \Slim\Factory\AppFactory::createFromContainer($container);

// Register middleware
$app->add($container->getErrorHandler());
$app->add($container->getJsonBodyParser());
$app->add($container->getCorsMiddleware());

// Load routes
$routes = require __DIR__ . '/../src/routes.php';
$routes($app);

$app->run();
```

---

## Adding a New Module

To add a new module's dependencies:

1. **Add repository getter**:
```php
public function getNewModuleRepository(): NewModuleRepository
{
    return $this->resolve(
        NewModuleRepository::class,
        fn() => new NewModuleRepository($this->pdo, $this->logger)
    );
}
```

2. **Add service getter**:
```php
public function getNewModuleService(): NewModuleService
{
    return $this->resolve(
        NewModuleService::class,
        fn() => new NewModuleService(
            $this->getNewModuleRepository(),
            $this->getAuditService(),
        )
    );
}
```

3. **Add controller getter**:
```php
public function getNewModuleAdminController(): NewModuleAdminController
{
    return $this->resolve(
        NewModuleAdminController::class,
        fn() => new NewModuleAdminController(
            $this->getNewModuleService(),
            $this->getValidator(),
        )
    );
}
```

4. **Register in FQCN_MAP**:
```php
private const FQCN_MAP = [
    // ...existing entries...
    NewModuleAdminController::class => 'getNewModuleAdminController',
];
```

5. **Add routes** in `src/routes.php`.

---

## Benefits

- **Centralized wiring**: All dependencies in one file
- **Lazy loading**: Instances created only when first accessed
- **Singleton pattern**: Same instance reused throughout request
- **PSR-compatible**: Implements `ContainerInterface` for Slim
- **Explicit**: Dependencies are visible in getter methods (no magic)
- **Testable**: Can create test ServiceFactory with mocked dependencies
- **No framework dependency**: Pure PHP, no DI framework needed

---

## When to Use

- All service, repository, and controller dependencies
- Middleware that needs injected dependencies
- Cross-cutting concerns (logging, audit, validation)

---

## When NOT to Use

- Simple value objects (no need for container)
- Configuration values (use `AppConfig`)
- Request-scoped data (use request attributes)

---

## Consistency with Modularity (ADR-0018)

The ServiceFactory is **central infrastructure**:
- Located in `src/ServiceFactory.php`
- Organizes getters by category: Repositories → Services → Controllers → Middleware
- All modules wired through the same factory
- Dependency graph is explicit and traceable

---

## Related Patterns

- **Pattern 004**: Service Layer (services registered here)
- **Pattern 005**: Repository (repositories registered here)
- **Pattern 006**: Thin Controllers (controllers injected with dependencies)

---

## References

- [PSR-11: Container Interface](https://www.php-fig.org/psr/psr-11/)
- [Dependency Injection Pattern](https://en.wikipedia.org/wiki/Dependency_injection)
