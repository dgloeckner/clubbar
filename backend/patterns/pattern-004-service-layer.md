# Pattern 004: Service Layer for Business Logic

**Category**: Application Layer & Separation of Concerns
**Pattern Type**: Behavioral Pattern
**Related ADR**: ADR-0018 (Modular Architecture - Clean Separation)

---

## Problem

Without a service layer, business logic is scattered across controllers:

```php
// ❌ Problematic: Mixed concerns
public function store(Request $request, Response $response): Response
{
    $body = $request->getParsedBody();

    // Business logic (shouldn't be here!)
    $row = $this->repo->findById($body['member_id']);
    if (!$row) { /* error handling */ }

    // Audit logging (mixed in)
    $this->auditRepo->insert([...]);

    // Response serialization
    $response->getBody()->write(json_encode($row));
    return $response;
}
```

Issues:
- Business logic scattered across multiple controllers
- Difficult to test logic (requires HTTP context)
- Duplicated logic across endpoints
- Hard to reuse logic from other services

---

## Solution

Use **Service Layer** to:
- Encapsulate business logic in dedicated classes
- Accept domain objects (not HTTP requests)
- Return DTOs (not HTTP responses)
- Hide data access complexity
- Enable reuse across multiple controllers/consumers

---

## Implementation Pattern

### Service Class

```php
// src/Modules/Members/Services/MembersService.php
namespace App\Modules\Members\Services;

use App\Modules\Members\DTOs\MemberDto;
use App\Modules\Members\DTOs\MemberAdminDto;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Exceptions\NotFoundException;
use App\Modules\Members\Enums\SupportedLanguage;
use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Transactions\Repositories\TransactionsRepository;
use App\Shared\Services\AuditService;

class MembersService
{
    public function __construct(
        private MembersRepository $membersRepository,
        private TransactionsRepository $transactionsRepository,
        private AuditService $auditService,
    ) {}

    public function getMember(string $memberId): MemberAdminDto
    {
        $member = $this->membersRepository->findById($memberId);
        if (!$member) {
            throw NotFoundException::forResource('Member', $memberId);
        }
        return MemberAdminDto::fromRow($member);
    }

    public function createMember(
        string $firstName,
        string $lastName,
        string $email,
        ?string $phone,
        ?string $cardUid,
        SupportedLanguage $language,
        ?string $iban = null,
        ?string $adminUserId = null,
    ): MemberAdminDto {
        $member = $this->membersRepository->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'card_uid' => $cardUid,
            'preferred_language' => $language->value,
            'is_active' => true,
            'iban' => $iban,
        ]);

        $this->auditService->log(
            action: AuditAction::CREATE,
            entityType: EntityType::MEMBER,
            entityId: $member['id'],
            newValues: ['first_name' => $firstName, 'last_name' => $lastName],
            adminUserId: $adminUserId,
        );

        return MemberAdminDto::fromRow($member);
    }

    public function updateMember(string $memberId, array $updateData, ?string $adminUserId = null): MemberAdminDto
    {
        $oldMember = $this->membersRepository->findById($memberId);
        if (!$oldMember) {
            throw NotFoundException::forResource('Member', $memberId);
        }

        $member = $this->membersRepository->updateById($memberId, $updateData);

        // Detect and audit changes
        $changes = $this->detectChanges($oldMember, $member);
        if (!empty($changes['old'])) {
            $this->auditService->log(
                action: AuditAction::UPDATE,
                entityType: EntityType::MEMBER,
                entityId: $memberId,
                oldValues: $changes['old'],
                newValues: $changes['new'],
                adminUserId: $adminUserId,
            );
        }

        return MemberAdminDto::fromRow($member);
    }
}
```

---

## Service Dependencies

Services depend on **Repositories** and **other Services**, never on controllers or HTTP objects:

```php
// ✅ Correct dependencies
class SettlementsService
{
    public function __construct(
        private SettlementsRepository $settlementsRepository,   // Repository
        private MembersRepository $membersRepository,          // Repository
        private TransactionsRepository $transactionsRepository, // Repository
        private AuditService $auditService,                    // Cross-cutting service
        private \PDO $pdo,                                     // For transactions
    ) {}
}

// ❌ Avoid
class SettlementsService
{
    public function __construct(
        private Request $request,              // Don't depend on HTTP
        private SettlementsController $ctrl,    // Don't depend on controllers
    ) {}
}
```

---

## Controller Using Service

Controllers become thin **HTTP routers**:

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

    public function show(Request $request, Response $response, array $args): Response
    {
        $member = $this->membersService->getMember($args['memberId']);
        return $this->json($response, $member->toArray());
    }

    public function store(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody() ?? [];
        // 1. Validate (Pattern 001)
        // 2. Call service with typed input
        $member = $this->membersService->createMember(...);
        // 3. Serialize DTO to JSON
        return $this->json($response, $member->toArray(), 201);
    }
}
```

---

## Service Composition

Services can use other services:

```php
// src/Modules/Settlements/Services/SettlementsService.php
class SettlementsService
{
    public function __construct(
        private SettlementsRepository $settlementsRepository,
        private MembersRepository $membersRepository,
        private TransactionsRepository $transactionsRepository,
        private AuditService $auditService,
        private \PDO $pdo,
    ) {}

    public function createSettlement(array $data, ?string $adminUserId = null): array
    {
        // Use PDO transaction for atomicity
        $this->pdo->beginTransaction();
        try {
            $settlement = $this->settlementsRepository->create($data);
            // ...link transactions, compute totals...
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->auditService->log(...);
        return $settlement;
    }
}
```

---

## Benefits

- **Separation of concerns**: Business logic isolated from HTTP
- **Reusability**: Logic reused across multiple endpoints/consumers
- **Testability**: Easy unit tests without HTTP context
- **Maintainability**: Changes to logic in one place
- **Composability**: Services can use other services
- **Dependency injection**: Easy to mock/test dependencies

---

## When to Use

- All business logic beyond simple CRUD
- Logic that could be reused across multiple endpoints
- Complex validation or transformation
- Multi-step operations (settlements, batch processing)
- Orchestration across multiple repositories

---

## When NOT to Use

- Simple pass-through operations (health check)
- One-off HTTP-specific logic (belongs in controller/middleware)

---

## Consistency with Modularity (ADR-0018)

Services are **module-owned**:
- Located in `src/Modules/{Module}/Services/`
- Named per domain (e.g., `MembersService`, `ProductsService`)
- Each module has own service layer
- Shared services in `src/Shared/Services/`

---

## Related Patterns

- **Pattern 003**: Data Transfer Objects (services return DTOs)
- **Pattern 005**: Repository (services depend on repositories)
- **Pattern 006**: Thin Controllers (controllers delegate to services)

---

## References

- [Service Layer Pattern - Martin Fowler](https://martinfowler.com/eaaCatalog/serviceLayer.html)
- [SOLID: Single Responsibility Principle](https://en.wikipedia.org/wiki/SOLID)
