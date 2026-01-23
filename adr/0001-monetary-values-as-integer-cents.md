# ADR-0001: Store Monetary Values as Integer Cents

**Status**: Accepted

**Date**: 2025-01-23

**Deciders**: Architecture Team

---

## Context

The Member Bar system manages financial transactions for member accounts, product purchases, and settlements. Monetary values must be stored, transmitted, and calculated reliably across multiple components (terminal, backend, database, API).

Key concerns:
- **Precision**: Currency calculations require exact decimal representation (e.g., €3.50, not 3.5000000001)
- **Reliability**: Floating-point arithmetic in databases and programming languages introduces rounding errors
- **Consistency**: Data must be consistent across terminal (SQLite), backend (PHP/MariaDB), and API payloads
- **Simplicity**: Development teams should not need specialized decimal libraries or complex conversion logic
- **Compatibility**: Solution must work across JavaScript/Node.js (terminal), PHP (backend), and JSON (API)

## Decision

**All monetary values are stored and transmitted as integer cents (smallest currency unit).**

Examples:
- €3.50 → `350` cents
- €0.99 → `99` cents
- €100.00 → `10000` cents
- Negative amounts (refunds): `-350` cents

### Implementation Details

**Database (MariaDB/MySQL)**:
```sql
CREATE TABLE transactions (
  id BINARY(16),
  member_id BINARY(16),
  product_id BINARY(16),
  amount_cents INT NOT NULL,          -- ALWAYS integers, no DECIMAL
  created_at DATETIME DEFAULT NOW(),
  PRIMARY KEY (id)
);

CREATE TABLE products (
  id BINARY(16),
  name VARCHAR(255) NOT NULL,
  price_cents INT NOT NULL,           -- Integer cents
  is_active BOOLEAN DEFAULT TRUE,
  PRIMARY KEY (id)
);

CREATE TABLE settlements (
  id BINARY(16),
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  member_id BINARY(16),
  amount_cents INT NOT NULL,          -- Integer cents
  PRIMARY KEY (id)
);
```

**Backend (PHP)**:
```php
// When receiving transaction data
$amountCents = (int) $request['amount_cents'];  // Always cast to int
if ($amountCents < -999999 || $amountCents > 999999) {
    throw new ValidationException('Amount out of range');
}

// When calculating totals
$totalCents = 0;
foreach ($transactions as $t) {
    $totalCents += (int) $t['amount_cents'];  // Integer addition (safe)
}

// When returning to API
return [
    'amount_cents' => $totalCents,              // Return as integer
    'amount_formatted' => $this->formatCents($totalCents)  // €34.56 for UI only
];

// Helper function for display (UI/reports only)
function formatCents($cents, $locale = 'de_DE') {
    return number_format($cents / 100, 2, ',', '.');  // €34,56
}
```

**Terminal (JavaScript/Node.js)**:
```javascript
// When receiving member/product data
const priceCents = parseInt(product.price_cents, 10);  // Always parse as integer

// When building transaction in local SQLite
const transaction = {
  id: uuidv4(),
  member_id: member.id,
  product_id: product.id,
  amount_cents: priceCents,                    // Integer
  created_at: new Date().toISOString()
};

// When calculating basket total
let totalCents = 0;
basket.forEach(item => {
  totalCents += item.amount_cents;             // Integer addition
});

// When uploading batch
const batch = {
  transactions: transactions.map(t => ({
    id: t.id,
    member_id: t.member_id,
    product_id: t.product_id,
    amount_cents: t.amount_cents,              // Send as integer
    created_at: t.created_at
  }))
};

// When displaying to user (UI formatting only)
const displayAmount = (cents) => {
  const euros = Math.floor(cents / 100);
  const centsPart = cents % 100;
  return `€${euros},${centsPart.toString().padStart(2, '0')}`;
};
```

**API (OpenAPI Specification)**:
```yaml
Transaction:
  type: object
  properties:
    amount_cents:
      type: integer
      description: Amount in cents (e.g., 350 = €3.50)
      example: 350

Product:
  type: object
  properties:
    price_cents:
      type: integer
      description: Price in cents
      example: 350
```

### Validation Rules

- **Range**: -999,999 cents (−€9,999.99) to +999,999 cents (+€9,999.99)
  - Rationale: Prevents integer overflow; realistic for small organizations
  - Configurable per deployment if needed
- **No decimals**: Must be integer; reject requests with `amount_cents: 3.5`
- **No null/undefined**: All monetary fields required; no implicit zero
- **Display formatting**: Always format for UI (e.g., "€3.50"), never display raw cents

---

## Consequences

### Positive

✅ **Eliminates floating-point errors**: No rounding surprises in calculations
✅ **Simple arithmetic**: Integer addition/subtraction is exact across all platforms
✅ **Database efficiency**: `INT` column is smaller and faster than `DECIMAL` or `VARCHAR`
✅ **JSON compatibility**: Integers serialize/deserialize reliably in JSON; no precision loss
✅ **Cross-platform consistency**: PHP, JavaScript, SQLite, MariaDB all handle integers identically
✅ **Easy auditing**: Audit logs show exact integer values (no "rounded" ambiguity)
✅ **No library dependencies**: No need for BigDecimal, Decimal.js, or bcmath (reduces complexity)
✅ **Clear validation**: Invalid amounts are immediately obvious (3.5 is rejected; 350 accepted)

### Negative

❌ **Display complexity**: Developers must remember to format cents for UI (€3.50, not 350)
❌ **API contract**: All API consumers must convert cents to/from display format
❌ **Currency conversion**: Multi-currency support (if needed) becomes more complex (no inherent currency info)
❌ **Rounding on import**: External data (CSV, bank transfers) must be converted to cents with rounding policy defined

### Mitigations

1. **Formatting helpers**: Provide utility functions in all codebases
   - PHP: `formatCents($cents, $locale)`
   - JavaScript: `formatCents(cents, locale)`
   - Python: `format_cents(cents, locale)` (if backend extends)

2. **API documentation**: OpenAPI spec clearly notes "amount in cents"

3. **Code review checklist**: Catch cents/euros confusion in pull requests
   - "No raw cents in UI strings"
   - "All monetary fields validated as integers"

4. **Rounding policy document**: Define how external data is converted to cents (round, truncate, error if non-integer)

---

## Alternatives Considered

### Alternative 1: Floating-Point (FLOAT/DOUBLE)
```php
price_cents: 3.50  // NOT USED
```
**Rejected**: Floating-point arithmetic introduces rounding errors. Example:
```javascript
0.1 + 0.2 === 0.3  // false! (0.30000000000000004)
```
Cannot be trusted for financial calculations.

### Alternative 2: Decimal/Numeric (DECIMAL(10,2))
```sql
price_cents DECIMAL(10, 2)
```
**Rejected**: More complex in code; requires library support in some languages; slower than INT; DECIMAL not natively supported in JavaScript/JSON.

### Alternative 3: String-Based (VARCHAR)
```sql
amount VARCHAR(20)  -- "3.50"
```
**Rejected**: Requires parsing before calculations; inefficient for aggregations in database; no type safety.

### Alternative 4: Separate Integer and Decimal Parts
```php
amount_euros: 3
amount_cents_remainder: 50
```
**Rejected**: More complex; error-prone; unnecessary when single INT suffices.

---

## Related Decisions

- [ADR-0002: API Response Format](./0002-api-response-format.md) (when created)
- Database schema migration: See `/backend/migrations/0001-initial-schema.sql`

---

## References

- ["The Problem with Floating-Point Arithmetic"](https://0.30000000000000004.com/) - Floating-point precision issues
- ["Working with Money"](https://martinfowler.com/eaaDev/pricing.html) - Martin Fowler on money patterns
- ISO 4217 - Currency codes and minor units (cents/pennies/etc.)
- Common practice: Stripe, PayPal, Square (all use integer cents)

---

## Approval

- **Decided by**: Architecture Team
- **Implementation**: Backend + Frontend must comply
- **Review date**: 2025-06-23 (6 months post-implementation)
