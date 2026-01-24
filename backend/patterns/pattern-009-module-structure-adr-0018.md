# Pattern 009: Module Structure & Organization (ADR-0018 Implementation)

**Status**: Active

**Purpose**: Organize backend API code by functional domain (modules) rather than technical layers. Each module owns all operations for its domain across both Terminal and Admin APIs.

---

## Context

The backend implements two distinct APIs:
- **Terminal API** (`/api/sync/*`, `/api/admin/*`): Low-bandwidth sync for offline POS terminals
- **Admin API** (`/api/admin/*`): Full-featured administrative operations (CRUD, exports, etc.)

Without module organization:
- Controllers and services scatter across `app/Http/Controllers/` and `app/Services/`
- Related Terminal and Admin operations for the same entity are distant
- Unclear which code handles which domain
- New features require searching multiple directories

**Solution**: Organize by **feature module**, where a module encompasses all code for one functional domain.

---

## Pattern Definition

### Module Structure

Each module is a self-contained unit with the following structure:

```
backend/app/Http/Modules/{module-name}/
├── Controllers/
│   ├── {Entity}Controller.php           # Thin HTTP handlers
│   └── {Entity}AdminController.php      # (optional) Admin-only endpoints
├── Services/
│   ├── {Entity}Service.php              # Business logic
│   └── {Entity}Repository.php           # Data access (see Pattern 011)
├── Requests/
│   ├── Create{Entity}Request.php        # Form request validators
│   ├── Update{Entity}Request.php
│   └── {Operation}{Entity}Request.php
├── DTOs/
│   ├── {Entity}Dto.php                  # Response objects
│   └── {Entity}ListDto.php
├── routes/
│   ├── terminal.php                     # Terminal API routes (/api/sync/*)
│   └── admin.php                        # Admin API routes (/api/admin/*)
└── README.md                            # Module documentation
```

### Example: Members Module

```
backend/app/Http/Modules/Members/
├── Controllers/
│   ├── SyncController.php               # Terminal: GET /api/sync/members
│   └── AdminController.php              # Admin: GET /api/admin/members, etc.
├── Services/
│   ├── MembersService.php               # Shared business logic
│   └── MembersRepository.php            # Data access abstraction
├── Requests/
│   ├── SyncRequest.php                  # Terminal sync query
│   ├── CreateMemberRequest.php          # Admin create
│   ├── UpdateMemberRequest.php          # Admin update
│   ├── UpdateLanguageRequest.php        # Terminal language update
│   ├── ExportGDPRRequest.php            # Admin GDPR export
│   └── AnonymizeRequest.php             # Admin anonymization
├── DTOs/
│   ├── MemberDto.php                    # Single member response
│   ├── MembersListDto.php               # Paginated members list
│   └── GDPRExportDto.php                # GDPR export response
├── routes/
│   ├── terminal.php                     # GET /api/sync/members, PATCH /api/sync/members/{id}/language
│   └── admin.php                        # GET /api/admin/members, POST, PATCH, DELETE, /export, /anonymize
└── README.md
```

### Module Ownership

A **Members module owns**:

**Terminal API** (sync operations):
- `GET /api/sync/members?since=<timestamp>` — Delta member sync
- `PATCH /api/sync/members/{id}/language` — Update member's language preference

**Admin API** (full CRUD + administrative):
- `GET /api/admin/members` — List members (paginated, filterable)
- `GET /api/admin/members/{id}` — View member detail
- `POST /api/admin/members` — Create member
- `PATCH /api/admin/members/{id}` — Update member
- `DELETE /api/admin/members/{id}` — Delete member
- `POST /api/admin/members/{id}/export` — GDPR export
- `POST /api/admin/members/{id}/anonymize` — GDPR anonymization

---

## Directory Hierarchy

### Backend Root

```
backend/
├── app/
│   ├── Http/
│   │   ├── Modules/                    # ← All feature modules
│   │   │   ├── Members/
│   │   │   │   ├── Controllers/
│   │   │   │   ├── Services/
│   │   │   │   ├── Requests/
│   │   │   │   ├── DTOs/
│   │   │   │   └── routes/
│   │   │   │
│   │   │   ├── Products/
│   │   │   ├── Settlements/
│   │   │   ├── Terminals/
│   │   │   └── ... (other modules)
│   │   │
│   │   ├── Middleware/                # Shared middleware (Auth, CORS, etc.)
│   │   └── Kernel.php
│   │
│   ├── Shared/                        # ← Extracted common logic
│   │   ├── Services/
│   │   │   ├── BaseService.php        # Abstract base for CRUD services
│   │   │   └── BaseRepository.php     # Abstract base for repositories
│   │   ├── DTOs/
│   │   │   ├── PaginatedResultDto.php # Shared pagination response
│   │   │   └── ErrorResponseDto.php   # Shared error response
│   │   ├── Exceptions/
│   │   │   └── NotFoundException.php
│   │   └── Traits/
│   │       └── HasTimestamps.php      # Shared behaviors
│   │
│   ├── Providers/
│   │   └── RouteServiceProvider.php   # ← Aggregates all module routes
│   │
│   └── Models/                        # ← Eloquent models (shared if needed)
│       ├── Member.php
│       ├── Product.php
│       └── ...
│
├── routes/
│   ├── api.php                        # ← Entry point (delegates to modules)
│   └── modules/
│       ├── members.php                # Aggregates Members module routes
│       ├── products.php
│       └── ... (one file per module)
│
└── config/
    └── modules.php                    # Module configuration
```

---

## Implementation Pattern: Thin Controllers + Services

### Terminal Sync Controller (Members Module)

```php
// app/Http/Modules/Members/Controllers/SyncController.php
namespace App\Http\Modules\Members\Controllers;

use App\Http\Modules\Members\Requests\SyncRequest;
use App\Http\Modules\Members\Requests\UpdateLanguageRequest;
use App\Http\Modules\Members\Services\MembersService;
use Illuminate\Http\JsonResponse;

final class SyncController extends Controller
{
    public function __construct(private readonly MembersService $service) {}

    /**
     * GET /api/sync/members - Delta sync members for terminal
     * Implements: Pattern 001 (FormRequest), Pattern 003 (DTO), Pattern 004 (Service)
     */
    public function index(SyncRequest $request): JsonResponse
    {
        $result = $this->service->syncSince($request->since());
        return response()->json($result->toResponse('members'));
    }

    /**
     * PATCH /api/sync/members/{id}/language - Update member language
     * Implements: Pattern 001 (FormRequest), Pattern 002 (Enum), Pattern 003 (DTO)
     */
    public function updateLanguage(UpdateLanguageRequest $request, string $memberId): JsonResponse
    {
        $member = $this->service->updateLanguage($memberId, $request->preferredLanguage());
        return response()->json($member->toArray());
    }
}
```

### Admin Controller (Members Module)

```php
// app/Http/Modules/Members/Controllers/AdminController.php
namespace App\Http\Modules\Members\Controllers;

use App\Http\Modules\Members\Requests\CreateMemberRequest;
use App\Http\Modules\Members\Requests\UpdateMemberRequest;
use App\Http\Modules\Members\Services\MembersService;
use Illuminate\Http\JsonResponse;

final class AdminController extends Controller
{
    public function __construct(private readonly MembersService $service) {}

    /**
     * GET /api/admin/members - List all members (paginated, filterable)
     */
    public function index(AdminListRequest $request): JsonResponse
    {
        $result = $this->service->listMembers(
            limit: $request->limit(),
            offset: $request->offset(),
            filters: $request->filters()
        );
        return response()->json($result->toArray());
    }

    /**
     * POST /api/admin/members - Create member
     */
    public function store(CreateMemberRequest $request): JsonResponse
    {
        $member = $this->service->create($request->validated());
        return response()->json($member->toArray(), 201);
    }

    /**
     * PATCH /api/admin/members/{id} - Update member
     */
    public function update(UpdateMemberRequest $request, string $memberId): JsonResponse
    {
        $member = $this->service->update($memberId, $request->validated());
        return response()->json($member->toArray());
    }

    /**
     * DELETE /api/admin/members/{id} - Delete member
     */
    public function destroy(string $memberId): JsonResponse
    {
        $this->service->delete($memberId);
        return response()->noContent();
    }

    /**
     * POST /api/admin/members/{id}/export - GDPR data export
     */
    public function export(ExportGDPRRequest $request, string $memberId): JsonResponse
    {
        $export = $this->service->exportGDPR($memberId);
        return response()->download($export->path, $export->filename);
    }

    /**
     * POST /api/admin/members/{id}/anonymize - GDPR anonymization
     */
    public function anonymize(AnonymizeRequest $request, string $memberId): JsonResponse
    {
        $this->service->anonymize($memberId);
        return response()->json(['status' => 'anonymized']);
    }
}
```

### Service Layer (Shared Logic)

```php
// app/Http/Modules/Members/Services/MembersService.php
namespace App\Http\Modules\Members\Services;

use App\Http\Modules\Members\DTOs\MemberDto;
use App\Http\Modules\Members\DTOs\MembersListDto;

final class MembersService
{
    public function __construct(
        private readonly MembersRepository $repository,
    ) {}

    /**
     * Terminal: Sync members modified since timestamp
     */
    public function syncSince(int $since): SyncResultDto
    {
        $members = $this->repository->findModifiedSince($since);
        return new SyncResultDto('members', $members);
    }

    /**
     * Terminal: Update member's language preference
     */
    public function updateLanguage(string $memberId, SupportedLanguage $language): MemberDto
    {
        $member = $this->repository->findOrFail($memberId);
        $member->update(['preferred_language' => $language->value]);
        return MemberDto::from($member);
    }

    /**
     * Admin: List members with pagination and filtering
     */
    public function listMembers(int $limit, int $offset, array $filters = []): MembersListDto
    {
        $query = $this->repository->query();

        if (isset($filters['is_active'])) {
            $query = $query->where('is_active', $filters['is_active']);
        }

        $total = $query->count();
        $members = $query->limit($limit)->offset($offset)->get();

        return new MembersListDto($members, $total, $limit, $offset);
    }

    /**
     * Admin: Create new member
     */
    public function create(array $validated): MemberDto
    {
        $member = $this->repository->create($validated);
        return MemberDto::from($member);
    }

    /**
     * Admin: Update member
     */
    public function update(string $memberId, array $validated): MemberDto
    {
        $member = $this->repository->updateById($memberId, $validated);
        return MemberDto::from($member);
    }

    /**
     * Admin: Delete member
     */
    public function delete(string $memberId): void
    {
        $this->repository->deleteById($memberId);
    }

    /**
     * Admin: GDPR export
     */
    public function exportGDPR(string $memberId): GDPRExportDto
    {
        $member = $this->repository->findOrFail($memberId);
        $transactions = $this->repository->getTransactionHistory($memberId);

        return new GDPRExportDto(
            member: $member,
            transactions: $transactions,
            exportedAt: now()
        );
    }

    /**
     * Admin: GDPR anonymization
     */
    public function anonymize(string $memberId): void
    {
        $this->repository->anonymize($memberId);
    }
}
```

---

## Route Aggregation

### Module Routes (Members)

```php
// app/Http/Modules/Members/routes/terminal.php
use App\Http\Modules\Members\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('sync')->group(function () {
    Route::get('/members', [SyncController::class, 'index']);
    Route::patch('/members/{memberId}/language', [SyncController::class, 'updateLanguage']);
});
```

```php
// app/Http/Modules/Members/routes/admin.php
use App\Http\Modules\Members\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth', 'admin'])->group(function () {
    Route::apiResource('members', AdminController::class);
    Route::post('/members/{id}/export', [AdminController::class, 'export']);
    Route::post('/members/{id}/anonymize', [AdminController::class, 'anonymize']);
});
```

### Global Route Entry Point

```php
// routes/api.php
use Illuminate\Support\Facades\Route;

// Terminal API routes (no auth required)
require base_path('routes/modules/members.php');
require base_path('routes/modules/products.php');
// ... other modules

// Each module file aggregates its routes:
// routes/modules/members.php
return [
    ...require app_path('Http/Modules/Members/routes/terminal.php'),
    ...require app_path('Http/Modules/Members/routes/admin.php'),
];
```

---

## Module-Level DTOs

```php
// app/Http/Modules/Members/DTOs/MemberDto.php
final class MemberDto
{
    public function __construct(
        public readonly string $id,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $cardUid,
        public readonly SupportedLanguage $preferredLanguage,
        public readonly bool $isActive,
        public readonly bool $isSepaValid,
        public readonly DateTime $createdAt,
        public readonly DateTime $updatedAt,
    ) {}

    public static function from(Member $model): self
    {
        return new self(
            id: $model->id,
            firstName: $model->first_name,
            lastName: $model->last_name,
            cardUid: $model->card_uid,
            preferredLanguage: SupportedLanguage::from($model->preferred_language),
            isActive: $model->is_active,
            isSepaValid: $model->is_sepa_valid,
            createdAt: $model->created_at,
            updatedAt: $model->updated_at,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'card_uid' => $this->cardUid,
            'preferred_language' => $this->preferredLanguage->value,
            'is_active' => $this->isActive,
            'is_sepa_valid' => $this->isSepaValid,
            'created_at' => $this->createdAt->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $this->updatedAt->format('Y-m-d\TH:i:s\Z'),
        ];
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
- Query params: `since` (required, Unix timestamp)
- Response: Array of MemberDto

### PATCH /api/sync/members/{id}/language
Update member's preferred language.
- Body: `{ "preferred_language": "de" | "en" | "fr" | "it" }`
- Response: MemberDto

## Admin API Endpoints

### GET /api/admin/members
List all members with pagination and filtering.
- Query params: `limit`, `offset`, `filters[is_active]`
- Response: PaginatedResultDto<MemberDto>

### POST /api/admin/members
Create new member.

### PATCH /api/admin/members/{id}
Update member.

### DELETE /api/admin/members/{id}
Delete member.

### POST /api/admin/members/{id}/export
Export member data (GDPR).

### POST /api/admin/members/{id}/anonymize
Anonymize member data (GDPR).

## Code Organization

- **Controllers**: HTTP request handlers (thin, <50 lines each)
- **Services**: Business logic
- **Repositories**: Data access abstraction (Pattern 011)
- **Requests**: Validation (Pattern 001)
- **DTOs**: Response objects (Pattern 003)

## Patterns Used

- Pattern 001: Form Requests for validation
- Pattern 003: DTOs for responses
- Pattern 004: Service Layer for business logic
- Pattern 006: Thin Controllers
- Pattern 011: Repository Interface for data access
```

---

## Migration Path from Current Structure

If migrating existing flat code to modules:

1. Create module directory structure
2. Move controllers to `Modules/{Entity}/Controllers/`
3. Move services to `Modules/{Entity}/Services/`
4. Move form requests to `Modules/{Entity}/Requests/`
5. Move DTOs to `Modules/{Entity}/DTOs/`
6. Create route files in `Modules/{Entity}/routes/`
7. Update route aggregation in `routes/api.php`
8. Update autoloading if necessary (PSR-4 namespacing should handle it)

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

1. **Create module scaffolding script** to auto-generate directory structure
2. **Extract common CRUD to BaseService** (Pattern 010)
3. **Use IDE shortcuts** (Go to Definition, cmd+p) to navigate deep hierarchies
4. **Document module checklist** for consistency across new modules

---

## See Also

- **ADR-0018**: Modular Admin Interface Architecture (architectural decision)
- **Pattern 010**: Shared Base Service Layer (extracting common logic)
- **Pattern 011**: Repository Interface Pattern (data access abstraction)
- **Pattern 004**: Service Layer (business logic organization)
- **Pattern 006**: Thin Controllers (HTTP request handlers)
