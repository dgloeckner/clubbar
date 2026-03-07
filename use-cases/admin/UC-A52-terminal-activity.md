# UC-A52: Terminal Activity

**Implementation Status**: Not implemented — action needed (see plans/action-items-use-cases.md)

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin opens Reports → Terminal Activity

## Main Flow
1. Admin clicks "Terminal Activity" in Reports
2. Admin selects date range
3. System displays terminal usage:
   - Sessions (periods of activity)
   - Transactions per session
   - Peak hours
4. Admin can drill down into details

## Report Elements

### Session List

| Column | Content |
|--------|---------|
| Date | Session date |
| Start | First transaction time |
| End | Last transaction time |
| Transactions | Count |
| Revenue | Total amount |

### Activity Chart
- Transactions by hour of day
- Shows peak usage times

### Terminal List
- If multiple terminals configured
- Activity per terminal

## Session Definition
- Gap of 30+ minutes = new session
- Groups continuous activity periods

## Filters
- Date range (required)
- Terminal (optional, if multiple)

## Postconditions
- Activity report displayed

## Use Cases
- Understand peak hours
- Plan staffing
- Monitor terminal usage

## Test Derivation
- Session grouping: 30min gap = new session
- Date range: only activity in range
- Peak hours: chart shows correct distribution
- Multiple terminals: separate tracking
- Empty period: "No activity" message
