# UC-A15: Deactivate Member

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in
- Member exists
- Member is currently active

## Trigger
Admin clicks "Deactivate" on member

## Main Flow
1. Admin opens member detail
2. Admin clicks "Deactivate"
3. System displays confirmation dialog
4. Dialog shows member name and current balance
5. Admin confirms deactivation
6. System sets member to inactive
7. System displays success message

## Postconditions
- Member marked inactive
- Member cannot authenticate at terminal
- ⚠️ ~~Member excluded from settlements~~ — **wrong, corrected 2026-08-07.** Deactivation is **temporary** (a technical issue such as a lost card), and it must **not** suppress collection of debt the member genuinely owes. Excluding them strands a real receivable and hides it from every report. `previewSettlement()` currently gates eligibility on `is_active`; that is a live bug, tracked in [#161](https://github.com/dgloeckner/clubbar/issues/161).

> **Deactivation is not leaving.** `is_active = false` is reversible and operational. Leaving is [offboarding](https://github.com/dgloeckner/clubbar/issues/173), which resolves the final balance and erases — and `deleted_at` is what means "gone".
- Transaction history preserved
- Member can be reactivated

## Business Rules
- Deactivation does NOT delete member (transaction history required)
- Deactivation does NOT clear balance (may still be owed)
- For GDPR deletion, use anonymization workflow (separate process)

## Variants

### V1: Reactivate Member
1. Admin opens inactive member
2. Admin clicks "Reactivate"
3. System confirms and sets active
4. Member can use terminal again

## Error Cases

### E1: Member Has Outstanding Balance
- Warning shown: "Member has balance of X €"
- Deactivation still allowed
- Balance remains for potential collection

## Test Derivation
- Deactivate member: confirm → status = inactive
- Terminal access: inactive member → "Account inactive" error
- Settlement exclusion: inactive member not included
- Reactivate: status = active, terminal works again
- Balance preserved: deactivate → balance unchanged
- Audit log: deactivation logged
