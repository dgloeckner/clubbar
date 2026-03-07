# UC-A34: Settlement Details

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in
- Settlement exists

## Trigger
Admin clicks settlement in list

## Main Flow
1. Admin clicks settlement row
2. System displays settlement detail view
3. View shows:
   - Settlement summary
   - List of included members
   - Download links

## Detail View Sections

### Summary
| Field | Content |
|-------|---------|
| Created | Timestamp |
| Execution date | SEPA execution date |
| Total members | Count |
| SEPA members | Count with mandate |
| Non-SEPA members | Count without mandate |
| Total amount | Sum |

### Member List

| Column | Content |
|--------|---------|
| Name | Member name |
| Amount | Settlement amount |
| SEPA | Yes/No |
| Mandate ref | Reference (if SEPA) |

### Downloads
- SEPA XML button
- CSV button

## Postconditions
- Settlement details displayed

## Test Derivation
- View details: all information shown
- Member list: all included members
- Amounts: sum matches total
- Download buttons: functional
- SEPA/non-SEPA split: counts correct
