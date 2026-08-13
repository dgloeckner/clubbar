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
| Today's Revenue | Sum of today's purchases — stornos and payouts excluded, the same definition the revenue reports use, so the two screens agree for a given period |
| Terminal Status | Online/Offline indicator |

Outstanding Balance is the figure that nets stornos and payouts off: revenue says what
the bar sold, the balance says what is still owed. They answer different questions and
are not expected to move together.

### Alert Badges

| Condition | Display | Action |
|-----------|---------|--------|
| Members with SEPA issues | Yellow/red badge: "X members need SEPA data" | Links to [UC-A82](./UC-A82-sepa-invalid-report.md) |

Badge color: Yellow (1-5 members), Red (6+ members). No badge if all members have valid SEPA data.

### Members Close to Their Limit

Lists the members whose Deckel has reached the terminal's credit-limit warning
band, biggest tab first — the people the bar is about to have to turn away.
Without it the club hears of a blocked card at the worst possible moment: from
the member standing at the bar.

| Element | Content |
|---------|---------|
| Heading | The limit itself, so the amounts have a scale |
| Row | Member name, their unsettled tab, and a bar showing its share of the limit |
| Row status | "X % of the limit used" while inside the band; "over the limit" once past it |
| Empty state | "No member is close to their limit" |
| Overflow | The list is capped; a trailing line names how many further members are over the threshold |

The band and the ceiling are the terminal's own — 80 % of the limit to warn,
past the limit to block ([UC-T11](../terminal/UC-T11-shopping-cart.md) E3,
[UC-T12](../terminal/UC-T12-error-scenarios.md)) — so a member appears here
exactly when the terminal has started warning them. Deactivated and deleted
members are left out: the terminal serves neither, so there is nothing to warn
about. What they still owe stays in Outstanding Balance and in the next
settlement run.

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
- Near-limit list: a member inside the warning band is named with their tab and share
- Near-limit verdict: a member past the limit reads as over it, not merely warned
- Near-limit threshold: a member one cent below the band does not appear
- Near-limit scope: deactivating a member takes them off the list
