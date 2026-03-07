# UC-A80: Dashboard

**Implementation Status**: Partially implemented — action needed (backend ready, frontend page missing; see plans/action-items-use-cases.md)

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin logs in or clicks Dashboard

## Main Flow
1. Admin arrives at dashboard (default after login)
2. System displays overview:
   - Key metrics
   - Recent activity
   - System status
3. Admin can navigate to detailed views

## Dashboard Elements

### Metric Cards

| Card | Content |
|------|---------|
| Active Members | Count of active members |
| Outstanding Balance | Total unpaid tab balance |
| Today's Revenue | Sum of today's transactions |
| Terminal Status | Online/Offline indicator |

### Alert Badges

| Condition | Display | Action |
|-----------|---------|--------|
| Members with SEPA issues | Yellow/red badge: "X members need SEPA data" | Links to [UC-A82](./UC-A82-sepa-invalid-report.md) |

Badge color: Yellow (1-5 members), Red (6+ members). No badge if all members have valid SEPA data.

### Recent Transactions
- Last 10 transactions
- Shows: time, member, product, amount
- Click to view member detail

### Quick Actions
- New Member button
- New Settlement button
- View Reports button

### System Status
- Terminal connectivity (last sync)
- Pending sync transactions
- Last settlement date

## Auto-Refresh
- Dashboard refreshes every 60 seconds
- Or on manual refresh

## Postconditions
- Dashboard displayed with current data

## Test Derivation
- Metrics accurate: match calculated totals
- Recent transactions: latest 10
- Terminal status: reflects actual connectivity
- Outstanding balance: sum of unsettled transactions
- Auto-refresh: data updates
- Quick actions: navigate correctly
- SEPA badge: create member without IBAN, verify badge appears
- SEPA badge click: verify navigates to SEPA Issues report
- SEPA badge colors: 1 member = yellow, 6 members = red
- No SEPA badge: all members have SEPA data, verify no badge
