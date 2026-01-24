# Members Module Implementation Checklist

**Status**: Ready for implementation (after Milestone 3 completes)

**Location**: `backend/app/Http/Modules/Members/`

**Dependencies**:
- Milestone 3: ADR-0018 Restructuring complete
- `BaseService` implemented in `app/Shared/Services/`
- `BaseRepository` implemented in `app/Shared/Repositories/`

---

## Directory Structure

```
Members/
├── [ ] Controllers/
│   ├── [ ] SyncController.php
│   └── [ ] AdminController.php
├── [ ] Services/
│   ├── [ ] MembersService.php
│   └── [ ] MembersRepository.php
├── [ ] Requests/
│   ├── [ ] SyncRequest.php
│   ├── [ ] UpdateLanguageRequest.php
│   ├── [ ] AdminListRequest.php
│   ├── [ ] CreateMemberRequest.php
│   ├── [ ] UpdateMemberRequest.php
│   ├── [ ] ExportGDPRRequest.php
│   └── [ ] AnonymizeRequest.php
├── [ ] DTOs/
│   ├── [ ] MemberDto.php
│   ├── [ ] MembersListDto.php
│   └── [ ] GDPRExportDto.php
├── [ ] routes/
│   ├── [ ] terminal.php
│   └── [ ] admin.php
└── [ ] README.md
```

---

## Phase 1: Form Requests & Validation (Pattern 001)

### Terminal Requests

- [ ] **SyncRequest.php**
  - [ ] Validate `since` query parameter (required, unix timestamp)
  - [ ] Provide `since()` accessor method
  - [ ] File: `Modules/Members/Requests/SyncRequest.php`

- [ ] **UpdateLanguageRequest.php**
  - [ ] Validate `preferred_language` in body (enum: de, en, fr, it)
  - [ ] Provide `preferredLanguage()` accessor returning `SupportedLanguage` enum
  - [ ] File: `Modules/Members/Requests/UpdateLanguageRequest.php`

### Admin Requests

- [ ] **AdminListRequest.php**
  - [ ] Validate `limit` (optional, int, default 50, max 100)
  - [ ] Validate `offset` (optional, int, default 0, >= 0)
  - [ ] Validate `filters[is_active]` (optional, boolean)
  - [ ] Validate `filters[language]` (optional, enum: de, en, fr, it)
  - [ ] Provide accessor methods: `limit()`, `offset()`, `filters()`
  - [ ] File: `Modules/Members/Requests/AdminListRequest.php`

- [ ] **CreateMemberRequest.php**
  - [ ] Validate `first_name` (required, string, 1-100 chars)
  - [ ] Validate `last_name` (required, string, 1-100 chars)
  - [ ] Validate `email` (required, email, unique in members table)
  - [ ] Validate `phone` (optional, string, 1-20 chars)
  - [ ] Validate `card_uid` (required, string, unique in members table)
  - [ ] Validate `preferred_language` (optional, enum: de, en, fr, it, default: de)
  - [ ] File: `Modules/Members/Requests/CreateMemberRequest.php`

- [ ] **UpdateMemberRequest.php**
  - [ ] Validate all fields (same as CreateMemberRequest but all optional)
  - [ ] Validate `card_uid` unique if provided (excluding current member)
  - [ ] Validate `email` unique if provided (excluding current member)
  - [ ] File: `Modules/Members/Requests/UpdateMemberRequest.php`

- [ ] **ExportGDPRRequest.php**
  - [ ] No input validation (authorization check in controller)
  - [ ] File: `Modules/Members/Requests/ExportGDPRRequest.php`

- [ ] **AnonymizeRequest.php**
  - [ ] No input validation (authorization check in controller)
  - [ ] File: `Modules/Members/Requests/AnonymizeRequest.php`

---

## Phase 2: DTOs (Data Transfer Objects - Pattern 003)

- [ ] **MemberDto.php** (Terminal & Admin)
  - [ ] Properties: id, first_name, last_name, card_uid, preferred_language, is_active, is_sepa_valid, created_at, updated_at
  - [ ] Constructor with readonly properties
  - [ ] `static from(Member $model): self` factory method
  - [ ] `toArray(): array` serialization method
  - [ ] `toResponse(string $key): array` method for wrapper (key = 'members')
  - [ ] File: `Modules/Members/DTOs/MemberDto.php`

- [ ] **MembersListDto.php** (Admin list response)
  - [ ] Properties: items (array), total (int), limit (int), offset (int), has_more (bool)
  - [ ] Constructor initializes has_more based on total/limit/offset
  - [ ] `toArray(): array` returns paginated response format
  - [ ] File: `Modules/Members/DTOs/MembersListDto.php`

- [ ] **GDPRExportDto.php** (GDPR export)
  - [ ] Properties: member (MemberDto), transactions (array), bookings (array), exported_at (DateTime)
  - [ ] Constructor accepts all properties
  - [ ] `toArray(): array` for JSON response
  - [ ] Methods for file export (if needed)
  - [ ] File: `Modules/Members/DTOs/GDPRExportDto.php`

---

## Phase 3: Repository (Pattern 005 + 011)

- [ ] **MembersRepository.php**
  - [ ] Extend `BaseRepository` from `app/Shared/Repositories/`
  - [ ] Constructor: `public function __construct() { parent::__construct(new Member()); }`
  - [ ] Inherit standard CRUD: `findById()`, `findAll()`, `create()`, `updateById()`, `deleteById()`
  - [ ] Add custom methods:
    - [ ] `findModifiedSince(int $since): Collection` — For terminal sync
    - [ ] `findActiveSince(int $since): Collection` — For reports
    - [ ] `getTransactionHistory(string $memberId): Collection` — For GDPR export
    - [ ] `getBookingHistory(string $memberId): Collection` — For GDPR export
    - [ ] `anonymize(string $memberId): void` — For GDPR deletion
  - [ ] File: `Modules/Members/Repositories/MembersRepository.php`

---

## Phase 4: Service Layer (Pattern 004 + 010)

- [ ] **MembersService.php**
  - [ ] Extend `BaseService` from `app/Shared/Services/`
  - [ ] Constructor: inject `MembersRepository`
  - [ ] Call parent constructor: `parent::__construct($repository)`
  - [ ] Inherit CRUD from BaseService:
    - [ ] `listWithPagination(limit, offset, filters, since?)`
    - [ ] `findById(id)`
    - [ ] `create(validated)`
    - [ ] `update(id, validated)`
    - [ ] `delete(id)`
  - [ ] Implement hook methods:
    - [ ] `protected function applyFilters($query, array $filters)` — Filter by is_active, language
    - [ ] `protected function transform(Model $entity): MemberDto` — Convert to DTO
  - [ ] Add custom methods:
    - [ ] `updateLanguage(string $memberId, SupportedLanguage $lang): MemberDto`
    - [ ] `exportGDPR(string $memberId): GDPRExportDto`
    - [ ] `anonymize(string $memberId): void`
  - [ ] File: `Modules/Members/Services/MembersService.php`

---

## Phase 5: Controllers (Pattern 006)

### SyncController.php (Terminal API)

- [ ] Thin controller pattern (no business logic)
- [ ] Constructor: inject `MembersService`
- [ ] Methods:
  - [ ] `index(SyncRequest $request): JsonResponse`
    - [ ] Call: `$this->service->listWithPagination(since: $request->since())`
    - [ ] Return: `response()->json($result->toResponse('members'))`
  - [ ] `updateLanguage(UpdateLanguageRequest $request, string $memberId): JsonResponse`
    - [ ] Call: `$this->service->updateLanguage($memberId, $request->preferredLanguage())`
    - [ ] Return: `response()->json([...member fields...])`
- [ ] File: `Modules/Members/Controllers/SyncController.php`

### AdminController.php (Admin API)

- [ ] Thin controller pattern (delegate to service)
- [ ] Constructor: inject `MembersService`
- [ ] Methods:
  - [ ] `index(AdminListRequest $request): JsonResponse`
    - [ ] Call: `$this->service->listWithPagination(...)`
    - [ ] Return: `response()->json($result->toArray())`
  - [ ] `show(string $id): JsonResponse`
    - [ ] Call: `$this->service->findById($id)`
    - [ ] Return: `response()->json($member->toArray())`
  - [ ] `store(CreateMemberRequest $request): JsonResponse`
    - [ ] Call: `$this->service->create($request->validated())`
    - [ ] Return: `response()->json($member->toArray(), 201)`
  - [ ] `update(UpdateMemberRequest $request, string $id): JsonResponse`
    - [ ] Call: `$this->service->update($id, $request->validated())`
    - [ ] Return: `response()->json($member->toArray())`
  - [ ] `destroy(string $id): JsonResponse`
    - [ ] Call: `$this->service->delete($id)`
    - [ ] Return: `response()->noContent()`
  - [ ] `export(string $id): Response`
    - [ ] Call: `$this->service->exportGDPR($id)`
    - [ ] Return: `response()->download(...)` or JSON with file URL
  - [ ] `anonymize(string $id): JsonResponse`
    - [ ] Call: `$this->service->anonymize($id)`
    - [ ] Return: `response()->json(['status' => 'anonymized'])`
- [ ] File: `Modules/Members/Controllers/AdminController.php`

---

## Phase 6: Routes (Pattern 009)

- [ ] **routes/terminal.php**
  - [ ] Define routes under `/api/sync` prefix
  - [ ] `GET /api/sync/members` → `SyncController@index`
  - [ ] `PATCH /api/sync/members/{memberId}/language` → `SyncController@updateLanguage`
  - [ ] File: `Modules/Members/routes/terminal.php`

- [ ] **routes/admin.php**
  - [ ] Define routes under `/api/admin` prefix
  - [ ] Add auth middleware (from ADR-0015)
  - [ ] Use `Route::apiResource('members', AdminController::class)`
  - [ ] Add custom routes:
    - [ ] `POST /api/admin/members/{id}/export` → `AdminController@export`
    - [ ] `POST /api/admin/members/{id}/anonymize` → `AdminController@anonymize`
  - [ ] File: `Modules/Members/routes/admin.php`

- [ ] **Aggregate routes** (after Milestone 3)
  - [ ] Create `routes/modules/members.php` that requires both terminal.php and admin.php
  - [ ] Update `routes/api.php` to include `routes/modules/members.php`

---

## Phase 7: Documentation & Testing

- [ ] **README.md**
  - [ ] Overview of Members module
  - [ ] List all endpoints with descriptions
  - [ ] Code organization explanation
  - [ ] Patterns used (001, 003, 004, 006, 009-011)
  - [ ] File: `Modules/Members/README.md`

- [ ] **Service Provider Registration** (Pattern 008)
  - [ ] Register `MembersRepository` in `AppServiceProvider`
  - [ ] Register `MembersService` in `AppServiceProvider`
  - [ ] Dependency injection configured

- [ ] **Unit Tests** (PHPUnit - TBD per ADR-0022)
  - [ ] Test MembersService methods
  - [ ] Test MembersRepository queries
  - [ ] Test form request validation

- [ ] **API Tests** (Playwright - Milestone 5)
  - [ ] members-list.spec.ts (5 tests)
  - [ ] members-create.spec.ts (3 tests)
  - [ ] members-update.spec.ts (4 tests)
  - [ ] members-delete.spec.ts (2 tests)
  - [ ] members-export-gdpr.spec.ts (5 tests)
  - [ ] members-anonymize.spec.ts (4 tests)

---

## Implementation Order

**Recommended completion order** (dependencies flow bottom-up):

1. **Form Requests** (no dependencies, enable validation)
2. **DTOs** (no dependencies, enable response formatting)
3. **Repository** (depends on: BaseRepository, Member model)
4. **Service** (depends on: Repository, DTOs, BaseService)
5. **Controllers** (depends on: Service, Requests)
6. **Routes** (depends on: Controllers)
7. **Documentation** (depends on: above)
8. **Tests** (depends on: above)

---

## Success Criteria

### Code Quality
- [ ] All files follow PSR-12 style
- [ ] All patterns implemented correctly (see pattern files for examples)
- [ ] No business logic in controllers
- [ ] All error handling uses Pattern 007 (exceptions)
- [ ] All responses use DTOs (Pattern 003)

### Functionality
- [ ] All 7 admin endpoints work (list, show, create, update, delete, export, anonymize)
- [ ] Terminal API endpoints unchanged (`/api/sync/members` works as before)
- [ ] Form validation works correctly
- [ ] Pagination and filtering work
- [ ] GDPR operations (export, anonymize) work
- [ ] Error responses consistent format

### Testing
- [ ] All 23 admin API tests pass
- [ ] All 35 terminal API tests still pass (regression verification)
- [ ] Edge cases covered (not found, validation errors, etc.)

---

## Testing Locally

### Before starting
```bash
cd backend && composer install
docker compose up -d
docker compose logs backend | grep "listening"
```

### After each phase
```bash
# Test Terminal API (should still pass)
cd e2etests && npm install
npx playwright test tests/api/sync-members.spec.ts
npx playwright test tests/api/member-language.spec.ts

# Manual API testing
curl -X GET http://localhost:8080/api/sync/members?since=0 | jq .
curl -X POST http://localhost:8080/api/admin/members \
  -H 'Content-Type: application/json' \
  -d '{"first_name":"Test","last_name":"User",...}'
```

### After completion
```bash
# All tests (Terminal + Admin)
npx playwright test tests/api/
# Should see: ✓ 58 passed (35 terminal + 23 admin)
```

---

## Notes

- **Member Model**: Assumed to exist with fields: id, first_name, last_name, email, phone, card_uid, preferred_language, is_active, is_sepa_valid, created_at, updated_at
- **Auth**: Admin endpoints require middleware (ADR-0015, not yet implemented)
- **Audit Logging**: Admin operations should be logged (ADR-0013, to be implemented)
- **Error Handling**: Use Pattern 007 exceptions (NotFoundException, ValidationException, etc.)
- **Timestamps**: Always return UTC ISO8601 format in DTOs

---

## Files to Review

Before starting:
- Read `backend/patterns/pattern-009-module-structure-adr-0018.md` (module structure)
- Read `backend/patterns/pattern-010-shared-base-service.md` (BaseService)
- Read `backend/patterns/pattern-011-shared-base-repository.md` (BaseRepository)
- Review existing Milestone 1.5 code (health module as reference)
- Review existing Milestone 2 code (sync module as reference)

---

## Questions?

- **Pattern details**: See `backend/patterns/pattern-00X-*.md`
- **Structure questions**: See `ADR-0018-IMPLEMENTATION-SUMMARY.md`
- **Quick reference**: See `backend/PATTERNS-QUICK-REFERENCE.md`
- **Progress tracking**: See `plans/phase1-backend-foundation.md` Milestone 4
