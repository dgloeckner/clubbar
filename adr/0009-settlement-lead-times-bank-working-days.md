# ADR-0009: Settlement Lead Times

**Status**: Accepted

**Date**: 2025-01-23

**Deciders**: Architecture Team

---

## Context

SEPA Direct Debit transfers require advance notice before collection. The system uses **RCUR (recurring)** sequence type, which technically requires 2 business days notice.

**Problem**: Complex business day calculation requires:
- Holiday calendar management
- Regional holiday variants
- Weekend exclusions
- Complex validation logic
- Database tables for holiday management

**Solution**: Use a pragmatic fixed lead time of **7 calendar days** instead of calculating business days. This eliminates holiday calendar complexity entirely while providing sufficient buffer for most organizations.

---

## Decision

**Settlement execution dates must be at least 7 calendar days in the future (TODAY + 7 days minimum). No holiday calendar or business day calculations required. Simple date validation only.**

### Core Principles

1. **Fixed 7-day minimum lead time**: TODAY + 7 days or later
2. **Calendar days, not business days**: Simpler, no holiday management needed
3. **Future dates only**: Must be greater than today
4. **No holiday management**: Eliminates bank_holidays table and related logic
5. **Simple validation**: Single rule: execution_date >= TODAY + 7

---

## Implementation

### Backend Validation

```php
<?php
/**
 * Validate settlement execution date
 * Rule: execution_date >= TODAY + 7 days
 */
class SettlementValidator {
    private const LEAD_TIME_DAYS = 7;

    /**
     * Validate execution date
     * Returns: [isValid, message]
     */
    public function validateExecutionDate($executionDate) {
        $minDate = date('Y-m-d', strtotime('+' . self::LEAD_TIME_DAYS . ' days'));

        if ($executionDate < $minDate) {
            return [false, "Execution date must be at least 7 days in future. Earliest valid date: $minDate"];
        }

        return [true, 'Valid'];
    }

    /**
     * Get minimum valid execution date
     * Used for UI date picker constraints
     */
    public function getMinimumExecutionDate() {
        return date('Y-m-d', strtotime('+' . self::LEAD_TIME_DAYS . ' days'));
    }

    /**
     * Get suggested execution date (7 days from now)
     */
    public function suggestExecutionDate() {
        return $this->getMinimumExecutionDate();
    }
}

// Usage
$validator = new SettlementValidator();

// Validate date chosen by admin
[$isValid, $message] = $validator->validateExecutionDate('2025-02-01');

if (!$isValid) {
    throw new ValidationException($message);
}

// Get minimum date for UI
$minDate = $validator->getMinimumExecutionDate();  // '2025-02-01'
```

### API Endpoint

```php
<?php
// GET /api/settlements/execution-date-info
// Returns minimum valid execution date for UI constraints

header('Content-Type: application/json');

$validator = new SettlementValidator();

echo json_encode([
    'minimum_date' => $validator->getMinimumExecutionDate(),
    'lead_time_days' => 7,
    'rule' => 'execution_date >= today + 7 days'
]);

// Response:
// {
//   "minimum_date": "2025-02-01",
//   "lead_time_days": 7,
//   "rule": "execution_date >= today + 7 days"
// }
```

### Admin UI Implementation

```jsx
// Admin creates settlement with execution date
function CreateSettlementFlow() {
  const [executionDate, setExecutionDate] = useState(null);
  const [minDate, setMinDate] = useState(null);

  useEffect(() => {
    // Load minimum execution date
    api.get('/api/settlements/execution-date-info').then(data => {
      setMinDate(data.minimum_date);
      // Suggest minimum date
      setExecutionDate(data.minimum_date);
    });
  }, []);

  const handleChangeExecutionDate = (newDate) => {
    setExecutionDate(newDate);
  };

  const handleFinalize = async () => {
    try {
      const settlement = await api.post('/api/settlements', {
        execution_date: executionDate
      });
      toast.success('Settlement created');
    } catch (err) {
      toast.error(err.message);
    }
  };

  return (
    <div className="settlement-creation">
      <h2>Create Settlement</h2>

      {minDate && (
        <>
          <Card>
            <p>
              <strong>Minimum execution date:</strong> {minDate}
            </p>
            <p>
              <strong>Lead time:</strong> 7 calendar days from today
            </p>
          </Card>

          <DateInput
            label="Execution Date"
            value={executionDate}
            onChange={handleChangeExecutionDate}
            minDate={minDate}
            description={`Settlement must be at least 7 days in future. Minimum: ${minDate}`}
          />

          <Button onClick={handleFinalize}>
            Create Settlement
          </Button>
        </>
      )}
    </div>
  );
}
```

### Settlement Creation API

```php
<?php
// POST /api/settlements
// Create new settlement with execution date validation

$body = json_decode(file_get_contents('php://input'), true);
$executionDate = $body['execution_date'] ?? null;

if (!$executionDate) {
    http_response_code(400);
    echo json_encode(['error' => 'Execution date required']);
    exit;
}

// Validate execution date
$validator = new SettlementValidator();
[$isValid, $message] = $validator->validateExecutionDate($executionDate);

if (!$isValid) {
    http_response_code(400);
    echo json_encode([
        'error' => $message,
        'minimum_date' => $validator->getMinimumExecutionDate()
    ]);
    exit;
}

// Create settlement
$settlement = [
    'id' => generateUUID(),
    'period_start' => $periodStart,  // From request or context
    'period_end' => $periodEnd,
    'sepa_execution_date' => $executionDate,
    'created_at' => date('Y-m-d H:i:s')
];

$db->insert('settlements', $settlement);

// Audit log
auditLog('settlement_created', 'settlements', $settlement['id'], [
    'execution_date' => $executionDate
]);

http_response_code(201);
echo json_encode($settlement);
```

---

## Consequences

### Positive

✅ **Drastically simpler**: No holiday calendar, no regional logic, no complex calculations
✅ **No database management**: Eliminates bank_holidays table entirely
✅ **Safe buffer**: 7 days provides ample lead time for SEPA processing
✅ **Pragmatic**: Covers most real-world use cases (few settlements on weekends)
✅ **Maintainability**: Single validation rule, easy to understand
✅ **No dependencies**: No external holiday services or complex algorithms
✅ **Predictable**: Same rule for all regions and organizations
✅ **Reduced code**: Minimal validation code vs. business day calculator

### Negative

❌ **Less precise**: May reject Saturday/Sunday settlements (no real impact for small orgs)
❌ **Calendar days, not business days**: 7 calendar days > 2 business days (acceptable)
❌ **No flexibility**: Same 7-day rule regardless of organization needs

### Mitigations

1. **Sufficient buffer**: 7 calendar days = 5 business days on average (exceeds SEPA 2-day requirement)
2. **Pragmatic for small orgs**: Weekend settlements rare for member bars
3. **Future adjustment**: If needed, can lower to 5 days (still 3-4 business days)
4. **User communication**: Clear UI explains 7-day rule

---

## Alternatives Considered

### Alternative 1: Business Day Calculation with Holiday Calendar

Track weekends and German holidays; require 2 business days.

**Pros**: More precise, exact SEPA compliance
**Cons**:
- Complex code (BusinessDayCalculator class)
- Holiday database maintenance required
- Regional holiday variants add complexity
- Easter date calculation needed (Gauss algorithm)
- External holiday sync (future enhancement)

**Rejected**: Over-engineered for small organizations. Fixed 7-day rule sufficient.

### Alternative 2: No Lead Time (Execute Immediately)

Allow settlements any time.

**Pros**: Maximum flexibility
**Cons**:
- Bank rejection risk (insufficient SEPA notice)
- Compliance issues
- No time for admin review

**Rejected**: Insufficient notice creates bank processing issues.

### Alternative 3: Fixed Execution Date (e.g., always 15th of month)

All settlements execute on same date.

**Pros**: Simple, predictable
**Cons**:
- Not flexible per settlement
- Doesn't match organization preferences
- May fall on weekend

**Rejected**: Admin needs per-settlement control.

### Alternative 4: 3-Day Lead Time (Calendar Days)

Shorter buffer.

**Pros**: More frequent settlements
**Cons**:
- Closer to SEPA minimum (2 business days)
- Less margin for error
- May cause bank rejections

**Rejected**: 7 days provides safer buffer.

### Alternative 5: 14-Day Lead Time (Calendar Days)

Longer buffer.

**Pros**: Very conservative, very safe
**Cons**:
- Much longer settlement cycle
- May not match business needs
- Excessive for small organizations

**Rejected**: 7 days sufficient; 14 days excessive.

---

## Implementation Checklist

### Backend

- [ ] Implement `SettlementValidator` class with fixed 7-day rule
- [ ] Add `validateExecutionDate()` method
- [ ] Add `getMinimumExecutionDate()` method
- [ ] Add API endpoint: `GET /api/settlements/execution-date-info`
- [ ] Add validation on settlement creation (POST /api/settlements)
- [ ] Return clear error messages with minimum valid date
- [ ] Log execution date selection in audit trail
- [ ] Remove any existing bank_holidays table (if present)
- [ ] Remove BusinessDayCalculator class (if present)

### Admin UI

- [ ] Add date picker in settlement creation form
- [ ] Disable dates before minimum execution date
- [ ] Show minimum execution date with explanation
- [ ] Show suggested execution date (7 days from now)
- [ ] Display validation error with minimum valid date
- [ ] Real-time feedback on date selection

### Testing

- [ ] Valid date (> 7 days): accepted
- [ ] Exactly 7 days: accepted
- [ ] 6 days: rejected with error
- [ ] Today: rejected with error
- [ ] Past date: rejected with error
- [ ] Error message includes minimum valid date
- [ ] UI date picker constraints enforced
- [ ] Audit log records execution date

### Documentation

- [ ] Update CLAUDE.md: Remove bank_holidays table reference
- [ ] Admin guide: Settlement creation (7-day rule)
- [ ] Technical docs: Simple 7-day validation rule
- [ ] Troubleshooting: Invalid execution date errors
- [ ] Rationale: Why 7 days (SEPA + RCUR + calendar days)

---

## Related Decisions

- [ADR-0004: Immutable Transaction Storage](./0004-immutable-transaction-storage.md) - Settlement workflow
- [ADR-0008: SEPA XML Export Format](./0008-sepa-xml-export-format-selection.md) - Execution date in XML
- [ADR-0006: SEPA Mandate Reference Strategy](./0006-sepa-mandate-reference-strategy.md) - RCUR (no FRST)

---

## References

- **SEPA Standards**:
  - RCUR (recurring) requires 2 business days notice
  - 7 calendar days ≈ 5 business days (exceeds SEPA minimum)

- **ISO 8601**:
  - Date format: YYYY-MM-DD

---

## Approval

- **Decided by**: Architecture Team
- **Rationale**: Pragmatic 7-day calendar rule eliminates complex holiday management; sufficient for SEPA RCUR; suitable for small organizations
- **Implementation start**: Phase 2 (SEPA settlement)
- **Review date**: 2025-04-23 (after first settlement)
- **Sign-off**:
  - Backend Lead: _________________ Date: _______
  - Payment Processing Lead: _________________ Date: _______
  - Admin UI Lead: _________________ Date: _______

---

## Post-Implementation Monitoring

- [ ] All settlements have execution date >= 7 days
- [ ] Bank accepts all settlement dates without issues
- [ ] User feedback: Is 7-day rule acceptable?
- [ ] Audit log: Track execution dates chosen
- [ ] Any edge cases requiring adjustment?
