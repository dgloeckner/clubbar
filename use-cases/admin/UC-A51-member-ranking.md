# UC-A51: Member Ranking

**Implementation Status**: Partially implemented — action needed (see plans/action-items-use-cases.md)

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

### ~~Named Mode~~ ⚠️ **Conflicts with [ADR-0029](../../adr/0029-two-tier-retention-and-erasure.md)**

~~Shows member names. Full detail view. For internal use only.~~

A **named ranking of members by consumption** is precisely the consumption-profile view ADR-0029 prohibits: it converts a billing record into a behavioural profile, and it is the strongest evidence a supervisory authority would use to argue that a decade of drink records is Art. 9 special-category data (see [`research/art9-rfid-display-retention.md`](../../research/art9-rfid-display-retention.md)).

"For internal use only" is not a mitigation — the concern is the existence of the profile, not who reads it.

**Decision needed:** remove named mode, or keep it under a narrower justification. Anonymised mode is unaffected.

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
