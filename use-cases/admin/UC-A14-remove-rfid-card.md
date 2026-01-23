# UC-A14: Remove RFID Card

## Actor
Admin

## Preconditions
- Admin is logged in
- Member exists
- Member has RFID card assigned

## Trigger
Admin clicks "Remove Card" on member

## Main Flow
1. Admin opens member detail
2. Admin clicks "Remove Card"
3. System displays confirmation dialog
4. Dialog shows card UID and warning
5. Admin confirms removal
6. System clears card UID from member
7. System displays success message

## Postconditions
- Card UID removed from member
- Member cannot authenticate at terminal
- Card UID moves to "unassigned" pool
- Audit log entry created

## Test Derivation
- Remove card: confirm → card removed
- Cancel removal: click cancel → card remains
- Terminal access: removed card → "Unknown card" at terminal
- Card in unassigned list: removed card appears in UC-A70
- Audit log: removal logged
