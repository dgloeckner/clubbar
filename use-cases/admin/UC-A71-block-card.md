# UC-A71: Block Card

## Actor
Admin

## Preconditions
- Admin is logged in
- Card UID known (from member or unassigned list)

## Trigger
Admin clicks "Block" on card

## Main Flow
1. Admin navigates to RFID section
2. Admin finds card (in member detail or unassigned list)
3. Admin clicks "Block Card"
4. System displays confirmation dialog
5. Admin enters reason (optional)
6. Admin confirms
7. System adds card to blocklist
8. System displays success message

## Postconditions
- Card UID in blocklist
- Card cannot authenticate at terminal
- If was assigned to member: assignment removed
- Audit log entry with reason

## Blocklist Behavior
- Terminal checks blocklist during authentication
- Blocked cards show "Card blocked" message
- Blocked cards still logged (for audit)

## Variants

### V1: Unblock Card
1. Admin opens blocked cards list
2. Admin clicks "Unblock"
3. System removes from blocklist
4. Card can be reassigned

## Use Cases
- Lost card: Block immediately
- Stolen card: Block and investigate
- Temporary suspension: Block, later unblock

## Test Derivation
- Block card: authentication fails at terminal
- Reason logged: in audit log
- Member's card: assignment also removed
- Unblock: card works again (after reassignment)
- Block history: visible in audit log
