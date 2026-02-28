# Pattern 009: Module Structure & Organization (ADR-0018 Implementation)

**Status**: Active
**Category**: Modularity & Organization
**Pattern Type**: Structural Pattern
**Related ADR**: ADR-0018 (Modular Architecture)

**Tech stack**: Slim 4 (PSR-7/PSR-15), PDO (raw SQL), PHP 8.3, custom ServiceFactory (PSR-11 ContainerInterface).

---

## Context

The backend implements two distinct APIs:
- **Terminal API** (`/api/sync/*`): Low-bandwidth delta sync for offline POS terminals
- **Admin API** (`/api/admin/*`): Full-featured administrative operations (CRUD, exports, etc.)

Without module organization:
- Controllers and services scatter across flat directories
- Related Terminal and Admin operations for the same entity are distant
- Unclear which code handles which domain
- New features require searching multiple directories

**Solution**: Organize by **feature module**, where a module encompasses all code for one functional domain.

---

## Pattern Definition

### Module Structure

Each module is a self-contained unit with the following structure:

```
src/Modules/{ModuleName}/
├── Controllers/
│   ├── AdminController.php         # Admin API endpoints (Pattern 006)
│   └── SyncController.php          # Terminal sync endpoints (Pattern 006)
├── Services/
│   └── {Entity}Service.php         # Business logic (Pattern 004)
├── Repositories/
│   └── {Entity}Repository.php      # PDO data access (Pattern 005)
├── DTOs/
│   ├── {Entity}Dto.php             # Response objects (Pattern 003)
│   └── {Entity}ListDto.php
└── Enums/
    └── {DomainEnum}.php            # Type-safe domain values (Pattern 002)
```

### Example: Members Module

```
src/Modules/Members/
├── Controllers/
│   ├── AdminController.php         # Admin: CRUD, export, anonymize
│   └── SyncController.php          # Terminal: GET /api/sync/members
├── Services/
│   └── MembersService.php          # Shared business logic
├── Repositories/
│   └── MembersRepository.php       # PDO data access
├── DTOs/
│   ├── MemberDto.php               # Single member response
│   ├── MemberAdminDto.php          # Admin-specific member response
│   └── MemberSyncDto.php           # Terminal sync response
└── Enums/
    └── SupportedLanguage.php       # de, en, fr (Pattern 002)
```

### Module Ownership

A **Members module owns**:

**Terminal API** (sync operations):
- `GET /api/sync/members?since=<timestamp>` — Delta member sync
- `PATCH /api/sync/members/{memberId}/language` — Update member's language preference

**Admin API** (full CRUD + administrative):
- `GET /api/admin/members` — List members (paginated, filterable)
- `GET /api/admin/members/{memberId}` — View member detail
- `POST /api/admin/members` — Create member
- `PATCH /api/admin/members/{memberId}` — Update member
- `DELETE /api/admin/members/{memberId}` — Delete member
- `POST /api/admin/members/{memberId}/export` — GDPR export
- `POST /api/admin/members/{memberId}/anonymize` — GDPR anonymization

---

## Directory Hierarchy

### Backend Root

```
backend/
├── src/
│   ├── Modules/                         # ← All feature modules
│   │   ├── Members/
│   │   │   ├── Controllers/
│   │   │   ├── Services/
│   │   │   ├── Repositories/
│   │   │   ├── DTOs/
│   │   │   └── Enums/
│   │   │
│   │   ├── Products/
│   │   ├── Transactions/
│   │   ├── Settlements/
│   │   ├── Terminals/
│   │   ├── Auth/
│   │   │   ├── Controllers/
│   │   │   ├── Services/
│   │   │   └── Middleware/              # TerminalTokenAuth, AdminSessionAuth
│   │   ├── AdminUsers/
│   │   ├── AuditLog/
│   │   └── Dashboard/
│   │
│   ├── Shared/                          # ← Cross-cutting concerns
│   │   ├── Controllers/
│   │   │   └── HealthController.php
│   │   ├── Services/
│   │   │   └── AuditService.php         # Shared audit logging
│   │   ├── DTOs/
│   │   │   ├── PaginatedResultDto.php   # Shared pagination response
│   │   │   └── SyncResultDto.php        # Shared sync response
│   │   ├── Exceptions/
│   │   │   ├── AppException.php         # Base exception (Pattern 007)
│   │   │   ├── NotFoundException.php
│   │   │   └── ValidationException.php
│   │   ├── Middleware/
│   │   │   ├── ErrorHandler.php         # PSR-15 error handler (Pattern 007)
│   │   │   ├── CorsMiddleware.php
│   │   │   └── JsonBodyParser.php
│   │   ├── Validation/
│   │   │   └── Validator.php            # Custom rule-based validator (Pattern 001)
│   │   ├── Enums/
│   │   │   ├── AuditAction.php
│   │   │   └── EntityType.php
│   │   └── Utils/
│   │       └── IbanMasker.php
│   │
│   ├── ServiceFactory.php               # ← DI container (Pattern 008)
│   └── routes.php                       # ← All route definitions
│
├── public/
│   └── index.php                        # ← Application entry point
│
└── composer.json
```

---

## Implementation Pattern: Thin Controllers + Services

### Terminal Sync Controller (Members Module)

```php
// src/Modules/Members/Controllers/SyncController.php
namespace App\Modules\Members\Controllers;

use App\Modules\Members\Services\MembersService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SyncController
{
    public function __construct(
        private MembersService $membersService,
    ) {}

    /**
     * GET /api/sync/members - Delta sync members for terminal
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $since = (int) ($params['since'] ?? 0);

        $result = $this->membersService->syncSince($since);

        $response->getBody()->write(json_encode($result->toResponse('members')));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * PATCH /api/sync/members/{memberId}/language - Update member language
     */
    public function updateLanguage(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody() ?? [];
        $member = $this->membersService->updateLanguage($args['memberId'], $body['preferred_language']);

        $response->getBody()->write(json_encode($member->toArray()));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

### Admin Controller (Members Module)

```php
// src/Modules/Members/Controllers/AdminController.php
namespace App\Modules\Members\Controllers;

use App\Modules\Members\Services\MembersService;
use App\Shared\Validation\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    public function __construct(
        private MembersService $membersService,
        private Validator $validator,
    ) {}

    /**
     * GET /api/admin/members - List all members (paginated, filterable)
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $result = $this->membersService->listMembers(
            limit: (int) ($params['per_page'] ?? 50),
            offset: (int) ($params['offset'] ?? 0),
            sortKey: $params['sort'] ?? 'created_at',
            sortOrder: $params['order'] ?? 'desc',
            search: $params['search'] ?? null,
        );
        return $this->json($response, $result->toArray());
    }

    /**
     * POST /api/admin/members - Create member
     */
    public function store(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];

        if (!$this->validator->validate($body, [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email'],
            'preferred_language' => ['required', 'string', 'in:de,en,fr'],
        ])) {
            return $this->json($response, [
                'error' => 'validation_failed',
                'messages' => $this->validator->errors(),
            ], 422);
        }

        $member = $this->membersService->createMember(
            firstName: $body['first_name'],
            lastName: $body['last_name'],
            email: $body['email'],
            language: $body['preferred_language'],
            adminUserId: $request->getAttribute('admin_user_id'),
        );
        return $this->json($response, $member->toArray(), 201);
    }

    /**
     * DELETE /api/admin/members/{memberId} - Delete member
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->membersService->deleteMember(
            $args['memberId'], $request->getAttribute('admin_user_id')
        );
        return $this->json($response, ['message' => 'Member deleted']);
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
```

### Service Layer (Shared Logic)

```php
// src/Modules/Members/Services/MembersService.php
namespace App\Modules\Members\Services;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Members\DTOs\MemberDto;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\DTOs\SyncResultDto;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Services\AuditService;

class MembersService
{
    public function __construct(
        private MembersRepository $membersRepository,
        private AuditService $auditService,
    ) {}

    /**
     * Terminal: Sync members modified since timestamp
     */
    public function syncSince(int $since): SyncResultDto
    {
        $rows = $this->membersRepository->findModifiedSince($since);
        $members = array_map(fn($row) => MemberDto::fromRow($row)->toArray(), $rows);
        return new SyncResultDto($members, count($members));
    }

    /**
     * Admin: List members with pagination and filtering
     */
    public function listMembers(int $limit, int $offset, string $sortKey, string $sortOrder, ?string $search): PaginatedResultDto
    {
        $result = $this->membersRepository->listPaginated($limit, $offset, [], $sortKey, $sortOrder, $search);
        $items = array_map(fn($row) => MemberDto::fromRow($row)->toArray(), $result['items']);
        return new PaginatedResultDto(items: $items, total: $result['total'], limit: $limit, offset: $offset);
    }

    /**
     * Admin: Get single member by ID
     */
    public function getMember(string $memberId): MemberDto
    {
        $row = $this->membersRepository->findById($memberId);
        if (!$row) {
            throw NotFoundException::forResource('Member', $memberId);
        }
        return MemberDto::fromRow($row);
    }
}
```

---

## Route Configuration (Slim 4)

All routes are defined in a single `src/routes.php` file, organized by API group:

```php
// src/routes.php
use App\Modules\Members\Controllers\AdminController as MembersAdminController;
use App\Modules\Members\Controllers\SyncController as MembersSyncController;
use App\Modules\Products\Controllers\AdminController as ProductsAdminController;
use App\Modules\Auth\Middleware\AdminSessionAuth;
use App\Modules\Auth\Middleware\TerminalTokenAuth;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    // Public health check
    $app->get('/api/health', [HealthController::class, 'check']);

    // Terminal sync endpoints (token auth)
    $app->group('/api/sync', function (RouteCollectorProxy $group) {
        $group->get('/members', [MembersSyncController::class, 'index']);
        $group->patch('/members/{memberId}/language', [MembersSyncController::class, 'updateLanguage']);
        $group->get('/products', [ProductsSyncController::class, 'products']);
        $group->post('/transactions', [TransactionsSyncController::class, 'processBatch']);
    })->add(TerminalTokenAuth::class);

    // Admin endpoints (session auth)
    $app->group('/api/admin', function (RouteCollectorProxy $group) {
        // Members
        $group->get('/members', [MembersAdminController::class, 'index']);
        $group->post('/members', [MembersAdminController::class, 'store']);
        $group->get('/members/{memberId}', [MembersAdminController::class, 'show']);
        $group->patch('/members/{memberId}', [MembersAdminController::class, 'update']);
        $group->delete('/members/{memberId}', [MembersAdminController::class, 'destroy']);

        // Products
        $group->get('/products', [ProductsAdminController::class, 'listProducts']);
        $group->post('/products', [ProductsAdminController::class, 'storeProduct']);
        // ... more module routes
    })->add(AdminSessionAuth::class);
};
```

**Key points:**
- Routes are grouped by API type (`/api/sync/*` vs `/api/admin/*`)
- Authentication middleware is applied per group via `->add()`
- Controller class names are aliased with `as` to avoid collisions between modules
- No separate route files per module — one centralized file for visibility

---

## Module-Level DTOs

```php
// src/Modules/Members/DTOs/MemberDto.php
namespace App\Modules\Members\DTOs;

use App\Modules\Members\Enums\SupportedLanguage;

class MemberDto
{
    public function __construct(
        public readonly string $id,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $cardUid,
        public readonly string $preferredLanguage,
        public readonly bool $isActive,
        public readonly bool $isSepaValid,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {}

    /**
     * Factory from PDO associative array (Pattern 003)
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: $row['id'],
            firstName: $row['first_name'],
            lastName: $row['last_name'],
            cardUid: $row['card_uid'] ?? null,
            preferredLanguage: $row['preferred_language'] ?? 'de',
            isActive: (bool) ($row['is_active'] ?? true),
            isSepaValid: !empty($row['iban']) && !empty($row['mandate_signed_at']),
            createdAt: $row['created_at'],
            updatedAt: $row['updated_at'],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'card_uid' => $this->cardUid,
            'preferred_language' => $this->preferredLanguage,
            'is_active' => $this->isActive,
            'is_sepa_valid' => $this->isSepaValid,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
```

---

## ServiceFactory Wiring (Pattern 008)

Each module's dependencies are wired in the centralized `ServiceFactory`:

```php
// src/ServiceFactory.php
class ServiceFactory implements ContainerInterface
{
    private const FQCN_MAP = [
        MembersAdminController::class => 'getMembersAdminController',
        MembersSyncController::class => 'getMembersSyncController',
        ProductsAdminController::class => 'getProductsAdminController',
        // ... all controllers and middleware
    ];

    // Repository → Service → Controller chain
    public function getMembersRepository(): MembersRepository
    {
        return $this->resolve(MembersRepository::class,
            fn() => new MembersRepository($this->pdo, $this->logger));
    }

    public function getMembersService(): MembersService
    {
        return $this->resolve(MembersService::class,
            fn() => new MembersService(
                $this->getMembersRepository(),
                $this->getTransactionsRepository(),
                $this->getAuditService(),
            ));
    }

    public function getMembersAdminController(): MembersAdminController
    {
        return $this->resolve(MembersAdminController::class,
            fn() => new MembersAdminController(
                $this->getMembersService(),
                $this->getValidator(),
            ));
    }
}
```

---

## Module README

Each module should include a `README.md` documenting its API:

```markdown
# Members Module

## Overview
Handles all member-related operations for both Terminal (sync) and Admin APIs.

## Terminal API Endpoints

### GET /api/sync/members
Delta sync members modified since timestamp.
- Query params: `since` (required, Unix timestamp in ms)
- Response: Array of MemberDto

### PATCH /api/sync/members/{memberId}/language
Update member's preferred language.
- Body: `{ "preferred_language": "de" | "en" | "fr" }`
- Response: MemberDto

## Admin API Endpoints

### GET /api/admin/members
List all members with pagination and filtering.
- Query params: `per_page`, `offset`, `sort`, `order`, `search`
- Response: PaginatedResultDto<MemberDto>

### POST /api/admin/members
Create new member.

### PATCH /api/admin/members/{memberId}
Update member.

### DELETE /api/admin/members/{memberId}
Delete member (soft delete).

## Code Organization

- **Controllers**: PSR-7 HTTP handlers (thin, Pattern 006)
- **Services**: Business logic (Pattern 004)
- **Repositories**: PDO data access (Pattern 005)
- **DTOs**: Response objects with fromRow() factory (Pattern 003)
- **Enums**: Type-safe domain values (Pattern 002)

## Patterns Used

- Pattern 001: Custom Validator for input validation
- Pattern 003: DTOs with fromRow() for PDO row conversion
- Pattern 004: Service Layer for business logic
- Pattern 005: Repository for PDO data access
- Pattern 006: Thin Controllers (PSR-7)
- Pattern 008: ServiceFactory for dependency wiring
```

---

## Migration Path from Current Structure

If migrating existing flat code to modules:

1. Create module directory structure under `src/Modules/{ModuleName}/`
2. Move controllers to `Modules/{Entity}/Controllers/`
3. Move services to `Modules/{Entity}/Services/`
4. Move repositories to `Modules/{Entity}/Repositories/`
5. Move DTOs to `Modules/{Entity}/DTOs/`
6. Add routes to `src/routes.php`
7. Wire dependencies in `src/ServiceFactory.php` (Pattern 008)
8. Update PSR-4 autoloading namespace in `composer.json` if necessary

---

## Consequences

### Positive

- **Cohesion**: All code for one domain in one place
- **Clear ownership**: Each module owns its Terminal + Admin endpoints
- **Scalability**: New modules follow same structure
- **Maintainability**: Changes isolated to one module
- **Discoverability**: Developers know where to find member-related code
- **Frontend alignment**: Module structure mirrors Admin SPA organization (ADR-0018)

### Negative

- **Directory nesting**: Deeper hierarchy than flat structure
- **Duplication risk**: Similar CRUD logic across modules (mitigated by Pattern 010: BaseService)
- **Setup overhead**: Creating first module requires discipline; subsequent modules follow template

### Mitigations

1. **Extract common CRUD to BaseService** (Pattern 010)
2. **Use IDE shortcuts** (Go to Definition, cmd+p) to navigate deep hierarchies
3. **Document module checklist** for consistency across new modules

---

## See Also

- **ADR-0018**: Modular Admin Interface Architecture (architectural decision)
- **Pattern 004**: Service Layer (business logic organization)
- **Pattern 005**: Repository (PDO data access)
- **Pattern 006**: Thin Controllers (PSR-7 HTTP request handlers)
- **Pattern 008**: ServiceFactory (dependency injection)
- **Pattern 010**: Shared Base Service Layer (extracting common logic)
- **Pattern 011**: Shared Base Repository (common data access patterns)
