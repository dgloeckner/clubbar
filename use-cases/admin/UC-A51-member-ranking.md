# UC-A51: Member Ranking

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin opens Reports → Member Ranking

## Main Flow
1. Admin clicks "Member Ranking" in Reports
2. Admin selects date range
3. Admin chooses display mode (named or anonymized)
4. System calculates and displays ranking
5. Admin can view top N members by consumption

## Display Modes

### Named Mode
- Shows member names
- Full detail view
- For internal use only

### Anonymized Mode
- Shows "Member 1", "Member 2", etc.
- For public display or sharing

## Ranking Table

| Column | Content |
|--------|---------|
| Rank | Position (1, 2, 3...) |
| Member | Name or "Member N" |
| Total | Total consumption amount |
| Count | Transaction count |

## Options
- Top N (10, 25, 50, All)
- Minimum transactions (filter out one-time)

## Postconditions
- Ranking displayed

## Privacy Note
- Anonymized mode for sharing outside organization
- Named mode requires admin access

## Test Derivation
- Ranking order: highest first
- Date range: only transactions in range
- Anonymized: no real names shown
- Named: real names shown
- Top N: correct count displayed
- Ties: same rank for equal amounts
- Empty period: "No data" message
