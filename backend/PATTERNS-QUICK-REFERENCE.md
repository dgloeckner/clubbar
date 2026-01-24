# Backend Patterns Quick Reference

**Location**: `backend/patterns/`

**When**: Read before implementing any backend feature

---

## The 15 Patterns (Quick Overview)

| # | Pattern | Purpose | When to Use |
|---|---------|---------|------------|
| **001** | Form Requests | Input validation (declarative) | All API endpoints |
| **002** | Enums | Type-safe domain values | Languages, types, statuses |
| **003** | DTOs | Immutable response objects | All response data |
| **004** | Service Layer | Business logic isolation | All features |
| **005** | Repository Interface | Data access abstraction | Database queries |
| **006** | Thin Controllers | HTTP handlers only | All controllers |
| **007** | Exception Handling | Consistent error responses | Error scenarios |
| **008** | Service Providers | Dependency injection setup | Configuration |
| **009** | Module Structure (ADR-0018) | Feature-based organization | Module architecture |
| **010** | Base Service | CRUD abstraction | Shared business logic |
| **011** | Base Repository | Data access abstraction | Shared database queries |
| **012** | Terminal Token Auth | Device authentication (Bearer) | Terminal API endpoints |
| **013** | Admin Session Auth | User authentication (sessions) | Admin API endpoints |
| **014** | RFID Identification | Member identification (not auth) | Transaction processing |
| **015** | Authorization Control | Endpoint access restrictions | All protected endpoints |

---

## Quick Decision Tree

### "I need to implement an endpoint"

1. **Create FormRequest** (Pattern 001)
   - Define validation rules
   - File: `Modules/{Module}/Requests/{Operation}{Entity}Request.php`

2. **Create DTO** (Pattern 003)
   - Define response structure
   - File: `Modules/{Module}/DTOs/{Entity}Dto.php`

3. **Create Service** (Pattern 004 + 010)
   - Extend `BaseService`
   - File: `Modules/{Module}/Services/{Entity}Service.php`
   - Implement: `applyFilters()`, `transform()`

4. **Create Repository** (Pattern 005 + 011)
   - Extend `BaseRepository`
   - File: `Modules/{Module}/Repositories/{Entity}Repository.php`
   - Add: domain-specific query methods

5. **Create Controller** (Pattern 006)
   - Thin: only HTTP routing
   - File: `Modules/{Module}/Controllers/{Entity}Controller.php`
   - Delegate to service

6. **Add Routes** (Pattern 009)
   - File: `Modules/{Module}/routes/terminal.php` or `admin.php`

### "I need validation logic"

→ **Pattern 001: Form Requests**

```php
// backend/patterns/pattern-001-form-requests-validation.md
// Declarative validation with typed accessors
class CreateMemberRequest extends FormRequest {
    public function rules() { /* validation rules */ }
    public function firstName(): string { return $this->validated('first_name'); }
}
```

### "I need type-safe constants"

→ **Pattern 002: Enums**

```php
// backend/patterns/pattern-002-enum-type-safety.md
// Type-safe enum for language codes
enum SupportedLanguage: string {
    case German = 'de';
    case English = 'en';
}
```

### "I need to return data"

→ **Pattern 003: DTOs**

```php
// backend/patterns/pattern-003-data-transfer-objects.md
// Immutable response object
final class MemberDto {
    public function __construct(
        public readonly string $id,
        public readonly string $firstName,
        // ...
    ) {}

    public static function from(Member $model): self { /* ... */ }
    public function toArray(): array { /* ... */ }
}
```

### "I need business logic"

→ **Pattern 004: Service Layer + Pattern 010: BaseService**

```php
// backend/patterns/pattern-004-service-layer.md
// Business logic isolated from HTTP
class MembersService extends BaseService {
    public function exportGDPR(string $memberId): GDPRExportDto {
        // Business logic here
    }
}
```

### "I need database queries"

→ **Pattern 005: Repository Interface + Pattern 011: BaseRepository**

```php
// backend/patterns/pattern-005-repository-interface.md
// Data access abstraction
class MembersRepository extends BaseRepository {
    public function findModifiedSince(int $since): Collection {
        // Query logic here
    }
}
```

### "I need an HTTP handler"

→ **Pattern 006: Thin Controllers**

```php
// backend/patterns/pattern-006-thin-controllers.md
// Controllers route, services decide
class MembersController {
    public function __construct(private MembersService $service) {}

    public function store(CreateMemberRequest $request): JsonResponse {
        $member = $this->service->create($request->validated());
        return response()->json($member->toArray(), 201);
    }
}
```

### "I need consistent errors"

→ **Pattern 007: Centralized Exception Handling**

```php
// backend/patterns/pattern-007-centralized-exception-handling.md
// All errors return same format
throw new NotFoundException("Member not found: {$memberId}");
```

### "I need to configure services"

→ **Pattern 008: Service Provider Bindings**

```php
// backend/patterns/pattern-008-service-provider-bindings.md
// Dependency injection configuration
$this->app->singleton(MembersService::class, function ($app) {
    return new MembersService(
        $app->make(MembersRepository::class),
    );
});
```

### "I'm organizing a feature area"

→ **Pattern 009: Module Structure (ADR-0018)**

```
Modules/Members/
├── Controllers/         ← Pattern 006: HTTP handlers
├── Services/           ← Pattern 004, 010: Business logic
├── Repositories/       ← Pattern 005, 011: Data access
├── Requests/           ← Pattern 001: Validation
├── DTOs/              ← Pattern 003: Responses
└── routes/
    ├── terminal.php    ← Terminal API routes (Pattern 012 auth)
    └── admin.php       ← Admin API routes (Pattern 013 auth)
```

### "I need to protect an endpoint"

→ **Patterns 012-015: Security & Authorization**

```php
// Terminal API (device authentication)
Route::prefix('sync')
    ->middleware([
        Pattern012::AuthenticateTerminalToken,   // Bearer token
        Pattern015::AuthorizeTerminalSync,       // Access control
    ])
    ->group(function () {
        Route::get('/members', [SyncController::class, 'index']);
    });

// Admin API (user authentication)
Route::prefix('admin')
    ->middleware([
        Pattern013::AuthenticateSession,         // Session
        Pattern015::AuthorizeAdminSession,       // Access control
    ])
    ->group(function () {
        Route::apiResource('members', AdminController::class);
    });
```

**Key Distinction** (ADR-0015):
- Pattern 012: **Device** authentication (terminals as devices)
- Pattern 013: **User** authentication (admins as users)
- Pattern 014: **Identification** only (members via RFID, no auth)
- Pattern 015: **Authorization** (who can access what)

### "I need to identify a member by RFID"

→ **Pattern 014: RFID Member Identification**

```php
// This is identification, NOT authentication
$member = $this->membersService->identifyMemberByCard($cardUid);
// ← Looks up member by visible card UID
// ← No security check; anyone with card can use it
```

**Remember**: RFID is **not authentication**. Card UID is visible, public, non-secret.

### "Services have duplicate CRUD logic"

→ **Pattern 010: Shared Base Service**

```php
// backend/patterns/pattern-010-shared-base-service.md
class MembersService extends BaseService {
    // Inherits: listWithPagination(), findById(), create(), update(), delete()
    // Override: applyFilters(), transform()

    protected function applyFilters($query, array $filters) {
        if (isset($filters['is_active'])) {
            $query = $query->where('is_active', $filters['is_active']);
        }
        return $query;
    }

    protected function transform(Model $entity): MemberDto {
        return MemberDto::from($entity);
    }
}
```

### "Repositories have duplicate CRUD queries"

→ **Pattern 011: Shared Base Repository**

```php
// backend/patterns/pattern-011-shared-base-repository.md
class MembersRepository extends BaseRepository {
    // Inherits: findById(), findAll(), create(), updateById(), deleteById()

    public function findModifiedSince(int $since): Collection {
        return $this->query()
            ->where('updated_at', '>=', $since)
            ->get();
    }
}
```

---

## File Location Conventions

```
backend/
├── app/
│   ├── Http/Modules/{module}/
│   │   ├── Controllers/
│   │   │   ├── SyncController.php           (Pattern 006, 009)
│   │   │   └── AdminController.php          (Pattern 006, 009)
│   │   ├── Services/
│   │   │   ├── {Entity}Service.php          (Pattern 004, 010)
│   │   │   └── {Entity}Repository.php       (Pattern 011)
│   │   ├── Requests/
│   │   │   ├── Create{Entity}Request.php    (Pattern 001)
│   │   │   ├── Update{Entity}Request.php    (Pattern 001)
│   │   │   └── {Operation}Request.php       (Pattern 001)
│   │   ├── DTOs/
│   │   │   ├── {Entity}Dto.php              (Pattern 003)
│   │   │   └── {Entity}ListDto.php          (Pattern 003)
│   │   └── routes/
│   │       ├── terminal.php                 (Pattern 009)
│   │       └── admin.php                    (Pattern 009)
│   │
│   ├── Shared/
│   │   ├── Services/
│   │   │   └── BaseService.php              (Pattern 010)
│   │   ├── Repositories/
│   │   │   ├── BaseRepository.php           (Pattern 011)
│   │   │   └── RepositoryInterface.php      (Pattern 005)
│   │   ├── DTOs/
│   │   │   └── ErrorResponseDto.php         (Pattern 003, 007)
│   │   └── Exceptions/
│   │       └── NotFoundException.php        (Pattern 007)
│   │
│   ├── Enums/
│   │   └── SupportedLanguage.php            (Pattern 002)
│   │
│   └── Providers/
│       └── AppServiceProvider.php           (Pattern 008)
│
└── patterns/
    ├── pattern-001-form-requests-validation.md
    ├── pattern-002-enum-type-safety.md
    ├── pattern-003-data-transfer-objects.md
    ├── pattern-004-service-layer.md
    ├── pattern-005-repository-interface.md
    ├── pattern-006-thin-controllers.md
    ├── pattern-007-centralized-exception-handling.md
    ├── pattern-008-service-provider-bindings.md
    ├── pattern-009-module-structure-adr-0018.md
    ├── pattern-010-shared-base-service.md
    └── pattern-011-shared-base-repository.md
```

---

## Reading Order

**For new developers:**
1. Read ADR-0018 (architecture overview)
2. Read Pattern 009 (module structure)
3. Read Pattern 001-006 (core patterns)
4. Read Pattern 010-011 (shared abstractions)
5. Read Pattern 007-008 (infrastructure)

**For quick reference:**
1. Check this file for your task type
2. Jump to specific pattern file
3. Copy example and adapt

---

## Common Mistakes

### ❌ DON'T: Business logic in controller

```php
// WRONG: Logic in controller
class MembersController {
    public function store(Request $request) {
        $member = Member::create($request->all());  // ← Business logic!
        return response()->json($member);
    }
}
```

### ✅ DO: Business logic in service

```php
// CORRECT: Service handles logic
class MembersController {
    public function __construct(private MembersService $service) {}

    public function store(CreateMemberRequest $request): JsonResponse {
        $member = $this->service->create($request->validated());
        return response()->json($member->toArray(), 201);
    }
}
```

---

### ❌ DON'T: Direct Eloquent in service

```php
// WRONG: Service depends on Eloquent
class MembersService {
    public function list() {
        return Member::all();  // ← Eloquent leaked!
    }
}
```

### ✅ DO: Repository abstraction

```php
// CORRECT: Repository abstraction
class MembersService {
    public function __construct(private MembersRepository $repo) {}

    public function list(): MembersListDto {
        $members = $this->repo->findAll();  // ← Abstracted!
        return new MembersListDto($members);
    }
}
```

---

### ❌ DON'T: Duplicate CRUD logic

```php
// WRONG: MembersService
class MembersService {
    public function list($limit, $offset) {
        return Member::limit($limit)->offset($offset)->get();
    }
}

// WRONG: ProductsService (same pattern!)
class ProductsService {
    public function list($limit, $offset) {
        return Product::limit($limit)->offset($offset)->get();
    }
}
```

### ✅ DO: Extract to BaseService

```php
// CORRECT: MembersService extends BaseService
class MembersService extends BaseService {
    // Inherits list() with pagination
}

// CORRECT: ProductsService extends BaseService
class ProductsService extends BaseService {
    // Inherits same list() method
}
```

---

## Security Pattern Selection

Use this table to choose the right security pattern:

| Question | Answer | Pattern |
|----------|--------|---------|
| **Who is calling?** | Terminal device | Pattern 012 |
| | Admin user | Pattern 013 |
| | Member? | Not applicable (no API auth) |
| **Does auth prove identity?** | Yes (secret/password) | Pattern 013 |
| | No (public identifier) | Pattern 014 |
| | Not applicable | Pattern 012 |
| **Can member log in?** | Members don't have accounts | Pattern 014 |
| | Admin logs in via email | Pattern 013 |
| | Terminal paired once | Pattern 012 |
| **Is RFID secret?** | Card UID is printed on card | Pattern 014 |
| | Token is 256-bit random | Pattern 012 |
| | Password is secret | Pattern 013 |

## Security Principles (ADR-0015)

These patterns implement four core security principles:

1. **Separation of Identification and Authentication**
   - RFID identifies members (Pattern 014)
   - RFID does NOT authenticate (Pattern 014)
   - Authentication is separate (Patterns 012-013)

2. **Device-level Terminal Authentication**
   - Terminals authenticate as **devices** (Pattern 012)
   - Not as users
   - Bearer token per device

3. **Session-based Admin Authentication**
   - Admins authenticate as **users** (Pattern 013)
   - Traditional email + password
   - Secure HTTP-only cookies

4. **No Member Authentication**
   - Members never log in
   - Never access API directly
   - Identified only via RFID for billing

---

## Phase 1 Timeline

**Complete** (Terminal API):
- ✅ Milestone 1-2: Docker + Health + Sync endpoints (with Pattern 012 auth)

**Starting** (ADR-0018 + Admin API):
- → Milestone 3: Restructure to modular architecture (Pattern 009)
- → Milestone 4: Members admin module (Patterns 001-011, 013, 015)
- → Milestone 5-7: Testing and verification

**Patterns Used**:
- Terminal API: 012 (auth), 015 (authorization)
- Admin API: 013 (auth), 015 (authorization)
- All modules: 001, 002, 003, 004, 005, 006, 007, 008, 009, 010, 011

---

## Need Help?

1. **Architecture**: Read ADR-0018
2. **Module setup**: Read Pattern 009
3. **CRUD patterns**: Read Pattern 010-011
4. **Specific task**: Find your task in this document
5. **Implementation details**: Read the corresponding pattern file

All patterns include full code examples and explanations.
