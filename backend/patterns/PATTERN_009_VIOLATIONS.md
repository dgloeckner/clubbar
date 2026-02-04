# Pattern 009 Violations Report

**Generated**: 2026-02-04
**Pattern**: Custom Domain Exceptions (Type-Safe Exception Handling)

## Summary

Found **40+ violations** of Pattern 009 across the codebase:
- 34 services throwing generic `RuntimeException`
- 6 fragile `str_contains()` message parsing checks
- 1 global middleware using message parsing for status codes

---

## Critical: Global Exception Handler (High Priority)

**File**: `backend/src/Shared/Middleware/ErrorHandler.php`

**Violation**: Maps exception messages to HTTP status codes using `str_contains()`

```php
// ❌ VERY FRAGILE - Changes to exception messages break error handling
$status = match (true) {
    str_contains($e->getMessage(), 'not found') => 404,
    str_contains($e->getMessage(), 'already exists') => 409,
    str_contains($e->getMessage(), 'Cannot deactivate') => 409,
    str_contains($e->getMessage(), 'Cannot ') => 400,
    default => 500,
};
```

**Recommended Fix**: Use custom exception types instead:

```php
$status = match (true) {
    $e instanceof NotFoundException => 404,
    $e instanceof DuplicateResourceException => 409,
    $e instanceof BusinessRuleException => 400,
    $e instanceof ValidationException => 422,
    $e instanceof \InvalidArgumentException => 422,
    $e instanceof \Slim\Exception\HttpNotFoundException => 404,
    default => 500,
};
```

**Impact**: HIGH - This affects all exceptions globally

---

## Service Layer Violations (34 occurrences)

### 1. NotFoundException Cases (19 occurrences)

**Should throw**: `NotFoundException::forResource()`

#### AdminUsersService (5)
```php
// Lines: 94, 110, 129, 145
throw new \RuntimeException("Admin user not found: $id");
```

#### MembersService (5)
```php
// Lines: 43, 61, 113, 150, 170
throw new \RuntimeException("Member not found: $memberId");
```

#### ProductsService (4)
```php
// Lines: 44, 63, 82, 98
throw new \RuntimeException("Product not found: $productId");
throw new \RuntimeException('Category not found');
```

#### TerminalsService (3)
```php
// Lines: 33, 73, 109
throw new \RuntimeException("Terminal not found: $terminalId");
```

#### SettlementsService (2)
```php
// Lines: 166
throw new \RuntimeException("Settlement not found: $settlementId");
```

#### SepaExportService (1)
```php
// Line: 27
throw new \RuntimeException("Settlement not found: $settlementId");
```

#### TransactionsService (1)
```php
// Line: 66
throw new \RuntimeException("Member not found: $memberId");
```

---

### 2. BusinessRuleException Cases (11 occurrences)

**Should throw**: `BusinessRuleException` (needs to be created)

#### AdminUsersService (3)
```php
// Line: 85
throw new \RuntimeException('Cannot deactivate own account');

// Line: 90
throw new \RuntimeException('Cannot deactivate the last active admin');

// Line: 148
throw new \RuntimeException('Current password is incorrect');
```

#### CategoriesService (1)
```php
// Line: 96
throw new \RuntimeException('Cannot delete category with products');
```

#### ProductsService (1)
```php
// Line: 45
throw new \RuntimeException('Category is inactive');
```

#### SettlementsService (3)
```php
// Line: 87
throw new \RuntimeException('Some transactions are already settled');

// Line: 93
throw new \RuntimeException('No valid unsettled transactions found');

// Line: 167
throw new \RuntimeException('Cannot cancel exported settlement');
```

#### TerminalsService (1) - Actually DuplicateResourceException
```php
// Line: 40
throw new \RuntimeException('Device ID already exists');
```

#### SepaExportService (2)
```php
// Line: 23
throw new \RuntimeException('SEPA configuration incomplete');

// Line: 30
throw new \RuntimeException('Settlement has no items');
```

---

### 3. ValidationException / Technical Cases (2 occurrences)

#### SepaExportService (2)
```php
// Line: 114
throw new \RuntimeException('Generated SEPA XML is malformed');

// Line: 121
throw new \RuntimeException('SEPA XML missing GrpHdr element');
```

---

## Controller Violations (2 occurrences)

### AuthController

**File**: `backend/src/Modules/Auth/Controllers/AuthController.php`

**Violation**: Fragile password validation error handling

```php
// ❌ Line 63-67
try {
    $this->adminUsersService->changeOwnPassword(...);
} catch (\RuntimeException $e) {
    if (str_contains($e->getMessage(), 'password is incorrect')) {
        return $this->json($response, ['error' => 'invalid_credentials'], 401);
    }
    throw $e;
}
```

**Recommended Fix**: Create `InvalidCredentialsException`

```php
// In AdminUsersService
if (!password_verify($currentPassword, $admin['password'])) {
    throw new InvalidCredentialsException('Current password is incorrect');
}

// In AuthController
} catch (InvalidCredentialsException $e) {
    return $this->json($response, [
        'error' => $e->getErrorCode(),
        'message' => $e->getMessage()
    ], $e->getHttpStatusCode());
}
```

---

## Required New Exception Classes

To fix all violations, create these exception classes:

1. ✅ **NotFoundException** - Already created
2. ✅ **ValidationException** - Already created
3. ❌ **BusinessRuleException** - Needs creation
4. ❌ **DuplicateResourceException** - Needs creation
5. ❌ **InvalidCredentialsException** - Needs creation
6. ❌ **ConfigurationException** - Needs creation (for SEPA config)

---

## Refactoring Priority

### Phase 1: High Priority (Affects many endpoints)
1. ✅ **CategoriesService** - Already refactored
2. ⚠️ **ErrorHandler middleware** - Global impact, fix first
3. ⚠️ **MembersService** - 5 NotFoundException cases
4. ⚠️ **AdminUsersService** - 5 NotFoundException + 3 BusinessRule cases

### Phase 2: Medium Priority
5. **ProductsService** - 4 NotFoundException + 1 BusinessRule
6. **TerminalsService** - 3 NotFoundException + 1 Duplicate
7. **SettlementsService** - 2 NotFoundException + 3 BusinessRule

### Phase 3: Lower Priority
8. **TransactionsService** - 1 NotFoundException
9. **SepaExportService** - 1 NotFoundException + 4 BusinessRule/Config
10. **AuthController** - 1 fragile catch block

---

## Refactoring Checklist

For each service:
- [ ] Identify exception types (NotFound, BusinessRule, Duplicate, etc.)
- [ ] Import appropriate exception classes
- [ ] Replace `throw new \RuntimeException(...)` with typed exceptions
- [ ] Update controllers to catch specific exception types
- [ ] Remove fragile `str_contains()` checks
- [ ] Test exception handling

---

## Testing Strategy

After refactoring, verify:

```bash
# Test that 404s still work
curl http://localhost:8080/api/admin/categories/invalid-id

# Test that business rules still work
curl -X DELETE http://localhost:8080/api/admin/categories/{id-with-products}

# Test that duplicate detection works
curl -X POST http://localhost:8080/api/admin/terminals -d '{"device_id": "existing-id"}'
```

---

## Benefits After Refactoring

✅ Type-safe exception handling
✅ No message parsing fragility
✅ Consistent error responses
✅ Easier to test
✅ Self-documenting code
✅ IDE autocomplete for exception types
✅ Refactoring-safe (can change messages without breaking handlers)
