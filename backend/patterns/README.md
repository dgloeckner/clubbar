# Backend Code Patterns

This directory contains architectural patterns for the Club Bar backend. These patterns ensure **consistency**, **maintainability**, and **testability** across all modules and are directly aligned with ADR-0018 (Modular Admin Interface Architecture).

All backend code must follow these patterns. Reference them when implementing features.

**Tech stack**: Slim 4 (PSR-7/PSR-15), PDO (raw SQL), PHP 8.3, custom ServiceFactory (PSR-11 ContainerInterface).

---

## Pattern Index

### Input & Validation Layer

**[Pattern 001: Input Validation](./pattern-001-input-validation.md)**
- Rule-based validation using custom `Validator` class
- Declarative rules as arrays (e.g., `['required', 'string', 'max:100']`)
- Database-backed unique checks via PDO
- **When**: All API endpoints accepting user input

**[Pattern 002: Enum for Type-Safe Domain Values](./pattern-002-enum-type-safety.md)**
- PHP 8.1+ backed enums for domain constants
- Type-safe constants (languages, transaction types, statuses)
- Prevents invalid values from being used
- **When**: Any fixed set of valid values (languages, enums, etc.)

---

### Data Transfer & Response Layer

**[Pattern 003: Data Transfer Objects (DTOs)](./pattern-003-data-transfer-objects.md)**
- Immutable response data with type safety
- `fromRow()` factory for PDO row conversion
- `toArray()` method for JSON serialization
- **When**: All API responses (lists, details, batches, etc.)

---

### Business Logic Layer

**[Pattern 004: Service Layer](./pattern-004-service-layer.md)**
- Business logic isolated from HTTP concerns
- Accepts domain objects, returns DTOs
- Reusable across HTTP/CLI contexts
- **When**: All business logic beyond simple CRUD

**[Pattern 005: Repository](./pattern-005-repository-interface.md)**
- Data access via PDO prepared statements
- Centralized query logic per entity
- Returns raw associative arrays (services convert to DTOs)
- **When**: All data queries from services

---

### HTTP & Response Layer

**[Pattern 006: Thin Controllers](./pattern-006-thin-controllers.md)**
- Controllers route PSR-7 requests to services
- No business logic, no direct database queries
- Use Validator for input validation
- **When**: All REST API endpoints

**[Pattern 007: Centralized Exception Handling](./pattern-007-centralized-exception-handling.md)**
- PSR-15 ErrorHandler middleware
- Consistent error response format
- Domain exceptions with HTTP status mapping
- **When**: Error handling across all endpoints

**[Pattern 019: Translatable Refusals](./pattern-019-translatable-refusals.md)**
- Every `BusinessRuleException` names a `BusinessRuleReason`; `message` stays English
- `reason` + `params` travel so the client renders the sentence in the reader's language
- Money as integer cents, never a pre-formatted amount
- **When**: Any refusal an admin can see — every 409 the modules raise

**[Pattern 020: One Clock for the Books](./pattern-020-club-timezone-rendering.md)**
- Columns hold UTC; every conversion back to the club's zone is explicit
- `ClubTimeZone` for one value, `ClubLocalSql` for a `GROUP BY`, `toUtcIso()` for a DTO
- A calendar day is never shifted — the shape of the value is the contract
- **When**: Any `date()`, `DATE()`, `HOUR()` or date filter touching a UTC column

**[Pattern 017: Shared HTTP Layer](./pattern-017-shared-http-layer.md)**
- `JsonResponder` trait, `ListQuery` parser, `PaginatedResponse` envelope
- One list-response shape, one pagination cap, all sort dialects
- Shared `Uuid`, `Csv` and `UnsettledTransactions` helpers
- **When**: Any controller returning JSON or a list, any CSV export

---

### Infrastructure & Configuration

**[Pattern 008: ServiceFactory for Dependency Injection](./pattern-008-service-provider-bindings.md)**
- Manual DI container implementing PSR ContainerInterface
- Lazy-loaded singleton instances
- Explicit dependency wiring (repositories → services → controllers)
- **When**: Wiring services, repositories, and controllers

---

### Modularity & Organization

**[Pattern 009: Module Structure & Organization (ADR-0018)](./pattern-009-module-structure-adr-0018.md)**
- Feature-based module organization
- Terminal + Admin API coexistence in one module
- Route aggregation across modules
- **When**: Implementing any new feature module

**[Pattern 010: Shared Base Service Layer](./pattern-010-shared-base-service.md)**
- Extract common CRUD patterns to base service
- Module-specific services extend base class
- Hooks for filtering, transformation, domain logic
- **When**: Creating module services with standard CRUD

**[Pattern 011: Shared Base Repository](./pattern-011-shared-base-repository.md)**
- Extract common data access to base repository
- Module-specific repositories extend base class
- Domain-specific query methods in extensions
- **When**: Creating module repositories

---

### Security & Authentication (ADR-0015)

**[Pattern 012: Terminal API Token Authentication](./pattern-012-terminal-api-token-authentication.md)**
- Device-level authentication via Bearer tokens
- 256-bit cryptographically secure tokens
- SHA-256 hashing; no plaintext storage. Not bcrypt: a 256-bit random token has
  nothing for a slow hash to defend against, and a fast one is an indexed lookup
  rather than a scan of every terminal
- Bounded token lifetime (`API_TOKEN_TTL_DAYS`, default 90), enforced in the
  repository lookup so no caller can authenticate around it
- Token generation, validation, rotation, revocation
- **Key Principle**: Terminals authenticate as **devices**, not users
- **When**: Terminal API endpoints (`/api/sync/*`)

**[Pattern 013: Admin Session Authentication](./pattern-013-admin-session-authentication.md)**
- Traditional session-based admin authentication
- Secure HTTP-only cookies with SameSite attribute
- Session regeneration to prevent fixation attacks
- Idle timeout (2 hours) + absolute timeout (24 hours), enforced by
  `SessionTimeout` in the application — never left to `session.gc_maxlifetime`
- Two-step login: a password buys an MFA-pending session, TOTP authenticates it
- **Key Principle**: Admins authenticate as **users** with a password *and* a
  second factor
- **When**: Admin API endpoints (`/api/admin/*`)

**[Pattern 014: RFID Member Identification](./pattern-014-rfid-member-identification.md)**
- RFID card UID identifies members for transactions
- **CRITICAL**: This is **identification, NOT authentication**
- Card UID is visible (not secret) and non-revocable per card
- Used for transaction linking and audit trails
- **Key Principle**: Members **never authenticate**; they are **identified** by RFID
- **When**: Transaction processing, member lookup

**[Pattern 015: Authorization & Access Control](./pattern-015-authorization-access-control.md)**
- Two axes: credential type (route group middleware), then office (`RouteRoleMap`)
- Terminal Bearer token → `/api/sync/*`; admin session → `/api/admin/*`
- **Default-deny**: a route with no map entry is `admin`-only, and the
  completeness test fails until somebody classifies it
- `insufficient_role` (403) vs `admin_not_authenticated` (401), and allow-lists
  where the boundary lands on a parameter rather than a route (ADR-0044)
- **When**: adding any admin route; changing who may reach one

---

## How Patterns Work Together

### Typical Request Flow

1. **HTTP Request arrives** → Slim routes to controller
2. **Middleware stack** (ErrorHandler → CORS → JSON parser → Auth)
3. **Authentication Middleware** (Pattern 012 or 013) → Validates credentials
4. **Controller receives PSR-7 request** (thin, Pattern 006)
5. **Validator checks input** (Pattern 001) → Returns 422 on failure
6. **Controller calls service** with typed parameters
7. **Service executes business logic** (Pattern 004) → Uses repositories
8. **Repository queries via PDO** (Pattern 005) → Returns raw rows
9. **Service transforms to DTO** (Pattern 003) → Uses type-safe enums (Pattern 002)
10. **Controller serializes DTO** → PSR-7 JSON response
11. **ErrorHandler catches exceptions** (Pattern 007) → Formats error response

### Data Flow Diagram

```
HTTP Request
    ↓
Slim Router
    ↓
[007: ErrorHandler Middleware] → Catches all exceptions
    ↓
[CORS + JSON Parser Middleware]
    ↓
[012/013: Authentication Middleware] → Bearer token OR Session
    ↓
[006: Thin Controller]
    ↓
[001: Validator] → Validates input
    ↓
[004: Service Layer] → Business logic
    ↓ (depends on)
[005: Repository] → PDO data access
    ↓ (returns raw rows)
[003: DTOs] (containing [002: Enums])
    ↓
Controller → json() helper → PSR-7 Response
```

**All dependencies wired via [008: ServiceFactory]**

---

## Key Principles

### 1. Type Safety (Patterns 001, 002, 003)
- Validator ensures input is valid before processing
- Enums for domain constants
- DTOs for response data
- **Result**: IDE autocomplete, compile-time checks, impossible invalid states

### 2. Separation of Concerns (Patterns 004, 005, 006)
- Controllers only route HTTP
- Services contain business logic
- Repositories handle data access
- **Result**: Each component has single responsibility; easy to test

### 3. Explicit Dependencies (Patterns 005, 008)
- Services receive dependencies via constructor
- ServiceFactory wires the dependency graph
- **Result**: Dependencies are visible and traceable

### 4. Consistency (Patterns 003, 007)
- All responses use DTOs with `toArray()`
- All errors handled centrally by ErrorHandler middleware
- Same structure across all endpoints
- **Result**: Client code is simpler; fewer surprises

### 5. Testability (Patterns 004, 005, 008)
- Services testable without HTTP context
- Repositories can be mocked in tests
- ServiceFactory enables dependency injection
- **Result**: Fast unit tests; easy to test business logic

---

## Related Documentation

- **ADR-0018**: [Modular Admin Interface Architecture](../../adr/0018-modular-admin-interface-architecture.md) — Overall modularity structure these patterns support
- **ADR-0004**: [Immutable Transaction Storage](../../adr/0004-immutable-transaction-storage.md) — Immutability principles reflected in readonly DTOs
- **ADR-0017**: [Input Validation and Injection Prevention](../../adr/0017-input-validation-injection-prevention.md) — Validation patterns

---

## Quick Start: Implementing a New Endpoint

1. **Define validation rules** (Pattern 001)
   - Declare rules in controller using `Validator`

2. **Create Service** (Pattern 004)
   - Implement business logic
   - Accept typed parameters
   - Return DTO

3. **Implement Repository** (Pattern 005)
   - Provide data access via PDO
   - Return raw associative arrays

4. **Write Controller** (Pattern 006)
   - Inject service via constructor
   - Validate input, call service, serialize DTO

5. **Wire in ServiceFactory** (Pattern 008)
   - Add repository, service, and controller getters
   - Register controller FQCN in `FQCN_MAP`

6. **Add routes** in `src/routes.php`

---

## Anti-Patterns to Avoid

❌ **Validation in Services**
- Validate in controllers using Validator (Pattern 001)

❌ **Business Logic in Controllers**
- Move to Service Layer (Pattern 004)

❌ **Direct PDO Queries in Services**
- Use Repositories (Pattern 005)

❌ **Manual Response Formatting**
- Use DTOs with `fromRow()` and `toArray()` (Pattern 003)

❌ **String Constants for Domain Values**
- Use Enums (Pattern 002)

❌ **Scattered Error Handling**
- Use centralized ErrorHandler middleware (Pattern 007)

❌ **An English Sentence as the Only Explanation**
- Name a `BusinessRuleReason` so the client can translate it (Pattern 019)

❌ **Manual Dependency Construction**
- Use ServiceFactory (Pattern 008)

---

## For New Contributors

1. Read this README first
2. Review the specific pattern(s) relevant to your task
3. Look at existing implementations in `src/Modules/` (e.g., Members module for complete example)
4. Follow the patterns exactly—consistency is critical for maintenance
5. If patterns don't fit your use case, ask before deviating

---

## Consistency with Modularity (ADR-0018)

These patterns directly support ADR-0018's module structure:

- **Modules own their patterns**: Each module has its own Controllers, Services, Repositories, DTOs
- **Shared infrastructure**: ErrorHandler, Validator, ServiceFactory, Enums are shared
- **Module isolation**: Patterns enable modules to be developed/tested independently
- **Consistency**: All modules follow same patterns → predictable code structure

---

## References

- **PHP 8.3 Features**: Enums, readonly properties, named arguments
- **PSR Standards**: PSR-7 (HTTP Messages), PSR-11 (Container), PSR-15 (Middleware)
- **Slim 4 Documentation**: Routing, middleware, dependency injection
- **Design Patterns**: Service Layer, Repository, DTO
- **SOLID Principles**: Single Responsibility, Dependency Inversion
