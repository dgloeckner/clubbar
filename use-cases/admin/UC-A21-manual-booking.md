# UC-A21: Manual Booking

## Actor
Admin

## Preconditions
- Admin is logged in
- Member exists

## Trigger
Admin clicks "Add Booking" on member

## Main Flow
1. Admin opens member detail
2. Admin clicks "Add Booking"
3. System displays booking form
4. Admin enters:
   - Amount (positive or negative)
   - Reason (required)
5. Admin submits form
6. System creates transaction record
7. System updates member balance
8. System displays confirmation

## Form Fields

| Field | Required | Validation |
|-------|----------|------------|
| Amount | Yes | Non-zero, max 2 decimal places |
| Reason | Yes | Non-empty, max 255 chars |

## Amount Convention
- Positive: Charge to member (increases balance)
- Negative: Credit to member (decreases balance)

## Common Use Cases
- Correction: Fix terminal error
- Credit: Refund, compensation
- Manual charge: Event without terminal
- Balance adjustment: Reconciliation

## Postconditions
- Transaction created (type: correction)
- Member balance updated
- Audit log entry with reason

## Error Cases

### E1: Zero Amount
- Display "Amount cannot be zero"

### E2: Missing Reason
- Display "Reason is required"

## Test Derivation
- Positive amount: balance increases
- Negative amount: balance decreases
- Zero amount: validation error
- Missing reason: validation error
- Transaction created: appears in history
- Audit log: booking logged with reason
- Balance calculation: verify correct sum
