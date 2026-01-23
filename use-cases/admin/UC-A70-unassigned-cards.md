# UC-A70: Unassigned Cards

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin opens RFID → Unassigned

## Main Flow
1. Admin clicks "Unassigned" in RFID section
2. System displays list of card UIDs that:
   - Have been scanned at terminal
   - Are not assigned to any member
3. Admin can assign card to member or block card

## List Columns

| Column | Content |
|--------|---------|
| Card UID | Card identifier |
| Terminal | Which terminal scanned the card |
| First Seen | First scan timestamp |
| Last Seen | Most recent scan |
| Scan Count | Times scanned |
| Actions | Assign, Block, Dismiss |

**Sort**: Most recently scanned first

## Actions

### Assign to Member
1. Admin clicks "Assign"
2. System shows member search
3. Admin selects member
4. Card assigned (UC-A13)

### Block Card
1. Admin clicks "Block"
2. System confirms
3. Card blocked (UC-A71)

### Dismiss Card
1. Admin clicks "Dismiss" (removes from list without action)
2. System removes card from unknown_card_scans
3. If card is scanned again, it reappears in list

## Postconditions
- Unassigned card list displayed

## Use Cases
- Identify new cards for assignment
- Detect unknown cards (possible security issue)
- Track cards awaiting member assignment

## Test Derivation
- List shows unassigned only: no member-linked cards
- Scan count accurate: matches terminal logs
- Assign action: card moves to assigned, removed from list
- Block action: card blocked
- Dismiss action: card removed from list
- Empty state: "No unassigned cards"
- Sort order: most recent first
- Terminal column: shows correct terminal name

## Related

- [UC-A13: Assign RFID Card](./UC-A13-assign-rfid-card.md) - Assign card to specific member
- [UC-A71: Block Card](./UC-A71-block-card.md) - Block a card
- [ADR-0021: RFID Card Assignment Workflow](../../adr/0021-rfid-card-assignment-workflow.md)
