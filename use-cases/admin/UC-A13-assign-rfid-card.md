# UC-A13: Assign RFID Card

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in
- Member exists

## Trigger
Admin opens the member edit form

## Overview

Assigns an RFID card to a member so they can use the terminal. The admin reads
the card UID from the card label or a reader diagnostic tool, then enters it
directly into the member edit form's `card_uid` field.

See [ADR-0021](../../adr/0021-rfid-card-assignment-workflow.md) for architecture
details, including the rejected "unknown cards" upload workflow.

## Main Flow

1. Admin opens member detail
2. Admin opens the member edit form
3. Admin reads the card UID from the card label (or a reader diagnostic tool)
4. Admin enters the card UID into the `card_uid` field
5. System validates format (8-20 hex characters, normalized to uppercase)
6. System checks card is not already assigned to another member
7. System saves card UID to member
8. System displays success message

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
2. Admin reads the card UID from the card label (or reader diagnostic tool)
3. Admin enters card_uid in the member edit form and saves
4. Terminal syncs and receives the updated member record
5. Member scans card at terminal and is recognized
```

## Postconditions
- Card UID stored with member
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

## Test Derivation

**Manual Entry:**
- Valid UID: card assigned successfully
- Invalid format (too short): validation error
- Invalid format (non-hex): validation error
- Duplicate UID: error with member name

**Replace Card:**
- Replace existing: old card stops working, new card works
- Confirm dialog shown: must confirm before replace
- Cancel replace: original card unchanged
