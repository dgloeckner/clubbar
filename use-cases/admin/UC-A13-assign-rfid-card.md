# UC-A13: Assign RFID Card

**Implementation Status**: Implemented (diverges from spec)
**Divergence**: Card UID is managed as a field on the member entity via the edit form, not via dedicated assign-card endpoint. Confirmed acceptable by stakeholder.

## Actor
Admin

## Preconditions
- Admin is logged in
- Member exists

## Trigger
Admin clicks "Assign RFID Card" on member

## Overview

Assigns an RFID card to a member so they can use the terminal. Two methods available:

1. **Select from unknown cards** (recommended): Choose from cards recently scanned at terminal
2. **Manual entry**: Type card UID directly (for known card IDs)

See [ADR-0021](../../adr/0021-rfid-card-assignment-workflow.md) for architecture details.

## Main Flow (Select from Unknown Cards)

1. Admin opens member detail
2. Admin clicks "Assign RFID Card"
3. System displays card assignment dialog
4. System shows list of recent unknown cards (from terminal scans)
5. Admin selects card from list
6. System checks card is not already assigned to another member
7. System saves card UID to member
8. System removes card from unknown cards list
9. System displays success message

## Unknown Cards List

| Column | Description |
|--------|-------------|
| Card UID | The card identifier (e.g., "A1B2C3D4E5F6") |
| Terminal | Which terminal scanned the card |
| Last Scanned | When card was most recently scanned |
| Scan Count | Number of times card was scanned |

**Sort**: Most recently scanned first

**Limit**: Last 20 cards

**Empty State**: "No unassigned cards found. Ask the member to scan their card at the terminal."

## Variant: Manual Entry

1. Admin clicks "Enter card ID manually"
2. System shows text input field
3. Admin types card UID
4. System validates format (8-20 hex characters)
5. Continue from main flow step 6

**Use cases for manual entry**:
- Card UID is known from external source
- Replacement card with pre-printed UID
- Testing/debugging

## Variant: Replace Existing Card

1. Member already has card assigned
2. System shows current card info with "Replace" button
3. Admin clicks "Replace card"
4. System shows confirmation: "This will unlink the current card"
5. Admin confirms
6. Continue from main flow step 4

## Onboarding Workflow

Recommended steps for new member card assignment:

```
1. Admin creates member (with SEPA data)
2. Admin says: "Please scan your card at the terminal"
3. Member scans card at terminal
4. Terminal shows "Unknown card" message
5. Card appears in admin panel within seconds (sent immediately, not batched)
6. Admin selects the card from list
7. Card assigned to member
8. Member scans again → terminal shows welcome
```

**Note**: Unknown cards are sent to the backend immediately (not waiting for sync interval), so the admin can assign the card right away while the member is still present.

## Postconditions
- Card UID stored with member
- Card removed from unknown_card_scans table
- Previous card (if replaced) unlinked
- Member can authenticate at terminal
- Audit log entry created

## Error Cases

### E1: Card Already Assigned
- Card belongs to another member
- Display "Card already assigned to [Member Name]"
- Offer to reassign (with confirmation)

### E2: Invalid Card Format
- Card UID doesn't match expected format (8-20 hex characters)
- Display "Invalid card format"

### E3: No Unknown Cards Available
- List is empty
- Display instructions to scan card at terminal first

### E4: Card No Longer in List
- Card was assigned to another member between list load and selection
- Display "Card no longer available"
- Refresh list

## Test Derivation

**Unknown Cards Selection:**
- Select from list: card assigned, member can use terminal
- Empty list: shows instruction message
- Card assigned elsewhere: error with member name
- Refresh list: new scans appear
- List sorted by recency: most recent first

**Manual Entry:**
- Valid UID: card assigned successfully
- Invalid format (too short): validation error
- Invalid format (non-hex): validation error
- Duplicate UID: error with member name

**Replace Card:**
- Replace existing: old card stops working, new card works
- Confirm dialog shown: must confirm before replace
- Cancel replace: original card unchanged

**Reassignment:**
- Reassign with confirmation: card moved to new member
- Previous member's card_uid set to NULL

**Audit:**
- Assignment logged with old/new values
- Reassignment logged for both members

## Related

- [UC-A70: Unassigned Cards](./UC-A70-unassigned-cards.md) - View all unknown cards
- [UC-A14: Remove RFID Card](./UC-A14-remove-rfid-card.md) - Unlink card from member
- [ADR-0021: RFID Card Assignment Workflow](../../adr/0021-rfid-card-assignment-workflow.md)
