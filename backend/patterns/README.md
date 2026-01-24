# Backend Code Patterns

This directory contains architectural patterns for the Ruderbar backend. These patterns ensure **consistency**, **maintainability**, and **testability** across all modules and are directly aligned with ADR-0018 (Modular Admin Interface Architecture).

All backend code must follow these patterns. Reference them when implementing features.

---

## Pattern Index

### Input & Validation Layer

**[Pattern 001: Form Requests for Input Validation](./pattern-001-form-requests-validation.md)**
- Declarative validation using Laravel FormRequest
- Typed accessor methods for validated data
- Prevents manual validation in controllers
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
- Consistent response formatting across endpoints
- `toArray()` method for JSON serialization
- **When**: All API responses (lists, details, batches, etc.)

---

### Business Logic Layer

**[Pattern 004: Service Layer](./pattern-004-service-layer.md)**
- Business logic isolated from HTTP concerns
- Accepts domain objects, returns DTOs
- Reusable across HTTP/CLI/queue contexts
- **When**: All business logic beyond simple CRUD

**[Pattern 005: Repository Interface](./pattern-005-repository-interface.md)**
- Abstract data access behind interfaces
- Decouple services from persistence implementation
- Enable easy testing with mocks
- **When**: All data queries from services

---

### HTTP & Response Layer

**[Pattern 006: Thin Controllers](./pattern-006-thin-controllers.md)**
- Controllers route HTTP requests to services
- No business logic, no direct model queries
- Use FormRequest for validation
- **When**: All REST API endpoints

**[Pattern 007: Centralized Exception Handling](./pattern-007-centralized-exception-handling.md)**
- Consistent error response format
- Centralized logging and error mapping
- Domain exceptions for business rules
- **When**: Error handling across all endpoints

---

### Infrastructure & Configuration

**[Pattern 008: Service Provider Bindings](./pattern-008-service-provider-bindings.md)**
- Dependency injection configuration
- Interface → Implementation bindings
- Singleton vs transient lifecycle management
- **When**: Wiring services and repositories

---

## How Patterns Work Together

### Typical Request Flow

1. **HTTP Request arrives** → Routed to controller
2. **FormRequest validates** (Pattern 001) → Typed accessors extract data
3. **Controller receives validated data** (thin, Pattern 006) → Calls service
4. **Service executes business logic** (Pattern 004) → Uses repositories
5. **Repository queries data** (Pattern 005) → Returns domain objects
6. **Service transforms to DTO** (Pattern 003) → Uses type-safe enums (Pattern 002)
7. **Controller serializes DTO** → JSON response
8. **Exception Handler catches errors** (Pattern 007) → Formats response
9. **Service Provider injected dependencies** (Pattern 008) → Resolved at runtime

### Data Flow Diagram

```
User Request
    ↓
Router
    ↓
[006: Thin Controller]
    ↓ (injects)
[008: Service Provider] → Resolves dependencies
    ↓
[001: FormRequest] → Validates input
    ↓
[004: Service Layer] → Business logic
    ↓ (depends on)
[005: Repository] → Data access
    ↓ (returns DTOs)
[003: DTOs] (containing [002: Enums])
    ↓
Controller → Serializes to JSON
    ↓
[007: Exception Handler] → Formats errors
    ↓
JSON Response
```

---

## Key Principles

### 1. Type Safety (Patterns 001, 002, 003)
- FormRequest validates and provides typed accessors
- Enums for domain constants
- DTOs for response data
- **Result**: IDE autocomplete, compile-time checks, impossible invalid states

### 2. Separation of Concerns (Patterns 004, 005, 006)
- Controllers only route HTTP
- Services contain business logic
- Repositories handle data access
- **Result**: Each component has single responsibility; easy to test

### 3. Dependency Inversion (Patterns 005, 008)
- Services depend on interfaces, not concrete classes
- Repositories abstract persistence details
- Service Provider wires implementations
- **Result**: Easy to swap implementations for testing or caching

### 4. Consistency (Patterns 003, 007)
- All responses use DTOs with `toArray()`
- All errors handled centrally
- Same structure across all endpoints
- **Result**: Client code is simpler; fewer surprises

### 5. Testability (Patterns 004, 005, 008)
- Services testable without HTTP context
- Repositories mockable in tests
- Service Provider enables dependency injection
- **Result**: Fast unit tests; easy to test business logic

---

## Related Documentation

- **ADR-0018**: [Modular Admin Interface Architecture](../adr/0018-modular-admin-interface-architecture.md) — Overall modularity structure these patterns support
- **ADR-0004**: [Immutable Transaction Storage](../adr/0004-immutable-transaction-storage.md) — Immutability principles reflected in readonly DTOs
- **ADR-0017**: [Input Validation and Injection Prevention](../adr/0017-input-validation-injection-prevention.md) — Validation patterns

---

## Quick Start: Implementing a New Endpoint

1. **Define FormRequest** (Pattern 001)
   - Declare validation rules
   - Add typed accessor methods

2. **Create Service** (Pattern 004)
   - Implement business logic
   - Accept typed parameters
   - Return DTO

3. **Implement Repository** (Pattern 005)
   - Provide data access interface
   - Return DTOs from queries

4. **Write Controller** (Pattern 006)
   - Inject service via constructor
   - Call service method
   - Serialize DTO response

5. **Wire in Service Provider** (Pattern 008)
   - Bind interface to implementation
   - Register service as singleton

---

## Anti-Patterns to Avoid

❌ **Validation in Controllers**
- Use FormRequest instead (Pattern 001)

❌ **Business Logic in Controllers**
- Move to Service Layer (Pattern 004)

❌ **Direct Model Queries**
- Use Repositories (Pattern 005)

❌ **Manual Response Formatting**
- Use DTOs (Pattern 003)

❌ **String Constants for Domain Values**
- Use Enums (Pattern 002)

❌ **Scattered Error Handling**
- Use centralized Exception Handler (Pattern 007)

❌ **Tightly Coupled Dependencies**
- Use Service Provider (Pattern 008)

---

## For New Contributors

1. Read this README first
2. Review the specific pattern(s) relevant to your task
3. Look at existing implementations in the codebase (e.g., SyncController for controller pattern examples)
4. Follow the patterns exactly—consistency is critical for maintenance
5. If patterns don't fit your use case, ask before deviating

---

## Consistency with Modularity (ADR-0018)

These patterns directly support ADR-0018's module structure:

- **Modules own their patterns**: Each module has its own FormRequests, Services, Repositories, DTOs
- **Shared infrastructure**: Exception Handler, Service Provider, Enums are shared
- **Module isolation**: Patterns enable modules to be developed/tested independently
- **Consistency**: All modules follow same patterns → predictable code structure

---

## References

- **PHP 8.1+ Features**: Enums, readonly properties
- **Laravel Documentation**: FormRequest, Service Container, Repository pattern
- **Design Patterns**: Service Layer, Repository, DTO
- **SOLID Principles**: Single Responsibility, Dependency Inversion
