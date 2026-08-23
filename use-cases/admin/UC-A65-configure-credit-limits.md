# UC-A65: Configure Credit Limits

**Implementation Status**: Implemented ([ADR-0047](../../adr/0047-configurable-credit-limits.md), epic [#555](https://github.com/dgloeckner/clubbar/issues/555))

## Actor
Admin or Kassenwart

Both verbs are TREASURY, deliberately unlike `sepa-config`, whose read is
TREASURY and whose writes are `admin`-only. That asymmetry is about blast
radius: a wrong Gläubiger-ID means a rejected collection run and a
Vorabankündigung that no longer matches what happened, while a wrong ceiling
mints no credential, exports no data, and is undone by typing the right number.
Deciding what members may run up on their Deckel is the Kassenwart's own job.

## Preconditions
- Caller is logged in and holds `admin` or `kassenwart`

## Trigger
Treasurer opens Settings → Limits, or edits a member

## Two club settings, and one per-member override

| Setting | Where | Meaning |
|---------|-------|---------|
| Club default ceiling | Settings → Limits | The ceiling for every member who has none of their own. Raising it lifts all of them at once |
| Warning band | Settings → Limits | The share of the *effective* ceiling from which the terminal warns but keeps serving. Club-wide; a member's override sets their ceiling, never their band |
| Member override | Member form | This member's own ceiling. Empty means they follow the club default |

## Main Flow — the club default
1. Treasurer opens Settings → Limits
2. System displays the current ceiling, the warning band, and the derived
   figure the warning actually starts at
3. Treasurer edits the ceiling and/or the band
4. System validates and stores both numbers
5. System displays a success message and the recomputed warning figure
6. Every member without an override moves with the new ceiling immediately;
   terminals pick it up on their next `/sync/config` poll

## Main Flow — a member's own ceiling
1. Treasurer opens a member for editing
2. The credit limit field is empty and its placeholder names the club figure
   the member currently inherits
3. Treasurer types an amount, and saves
4. That member is measured against their own ceiling from then on — on the
   dashboard, in the Deckelauszug, and at the terminal

## Field Rules

| Field | Required | Validation |
|-------|----------|------------|
| `default_limit_cents` | Yes | Integer cents, 0–10,000,000 |
| `warn_threshold_percent` | Yes | Integer, 1–100 |
| `members.credit_limit_cents` | **No** | Integer cents, 0–10,000,000, or absent/null |

## What `0` and empty mean

This is the distinction the whole feature turns on:

| Value | Meaning |
|-------|---------|
| **empty / null** | Follow the club default, and keep following it when it changes |
| **0** | No ceiling for this member — the terminal refuses nothing on Deckel grounds |
| *n* | This member's ceiling, in cents |

`0` is not a way to clear the field. An emptied input must reach the API as
`null`; sending `0` instead grants that member unlimited credit, silently. The
API refuses a **negative** ceiling rather than reading it as unlimited, for the
same reason — `< 0` would pass the `<= 0` test that means "not enforced".

A club default of `0` means the club caps nobody. A member who was
deliberately given a ceiling still has one.

## Postconditions
- Configuration or member row updated
- Audit log entry naming both numbers before and after (club default), or the
  changed override (member)
- The dashboard's near-limit panel re-measures every member against the ceiling
  that now applies to them
- Terminals enforce the new club default from their next `/sync/config` poll;
  a member's override rides their own row on `/sync/members`

## Error Cases

### E1: Negative ceiling
- 422, `default_limit_cents` (or `credit_limit_cents`) named in `messages`
- Refused rather than reinterpreted as "unlimited"

### E2: Ceiling above the maximum
- 422 — the bound is a fat-finger stop, not a business rule

### E3: Warning band outside 1–100
- 422, `warn_threshold_percent` named

### E4: Getränkewart attempts either verb
- 403 `insufficient_role`; the Limits tab is not shown to them at all

## Test Derivation
- Club default read and written by an admin and by a Kassenwart
- Getränkewart refused on both verbs, and shown no Settings tab
- `0` accepted as a ceiling; `-1` refused; non-integer refused
- Member created with no override stores `NULL`
- Member override round-trips through the edit form; clearing it stores `NULL`,
  not `0`; a typed `0` stores `0`
- The dashboard measures each member against their own ceiling, and a raised
  override takes them off the panel
- `GET /api/sync/config` answers the configured policy, and `401` without a
  terminal token

## Related
- [ADR-0047: Configurable Credit Limits](../../adr/0047-configurable-credit-limits.md)
- [ADR-0044: Tiered Admin Roles](../../adr/0044-tiered-admin-roles.md) — why both verbs are TREASURY
- [UC-T11](../terminal/UC-T11-shopping-cart.md), [UC-T12](../terminal/UC-T12-error-scenarios.md) — where the ceiling is enforced
