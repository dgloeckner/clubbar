# ADR-0055: One Canonical Spelling for a Card UID

**Status**: Accepted

**Date**: 2026-09-08

**Amends**: [ADR-0014](./0014-rfid-scanning-integration.md) — *Card UID Handling*,
and [ADR-0021](./0021-rfid-card-assignment-workflow.md) — *Card UID Validation*.
Both tables now point here and have been corrected where they said something
this ADR contradicts; neither decision itself is changed.

---

## Context

`members.card_uid` is matched by **exact string comparison**. Every lookup is an
equality test — `MembersRepository.findByCardUid` on the terminal, the `UNIQUE`
index in MariaDB — so the *spelling* a UID happens to be stored in decides
whether a member's card works.

That would be harmless if a chip had one spelling. It does not. What a USB
keyboard-wedge reader types depends on how the reader is configured, not on the
card, and the same 4-byte chip reaches the terminal as any of:

| Reader output | What it is |
|---|---|
| `001EB4CB` | uppercase hex |
| `001eb4cb` | lowercase hex — [issue #18](https://github.com/dgloeckner/clubbar/issues/18) |
| `00:1E:B4:CB`, `00-1E-B4-CB`, `00 1E B4 CB` | grouped by byte |
| `0x001EB4CB` | prefixed, as a diagnostic tool prints it |
| `1EB4CB` | leading zero byte dropped |
| `0002012363` | the same value in decimal, zero-padded to ten digits |
| `CBB41E00` | least-significant byte first |

Two failure modes follow from storing whichever spelling arrived:

1. **A replacement reader invalidates every card in the club.** The old reader
   dies, the club buys the model that is in stock, and its factory
   configuration differs by one setting. No member is recognised any more, every
   UID has to be re-entered by hand, and nothing in the failure says why: each
   card simply reads as unknown. This is a real risk for a volunteer-run club
   that buys hardware years apart.
2. **The uniqueness check stops working.** `001EB4CB` and `00:1E:B4:CB` are one
   card, and the `UNIQUE` index sees two values. One chip gets assigned to two
   members, and the bookings go to whichever of them tapped.

The lowercase half of this was already fixed for the terminal
(issue #18: `trim().toUpperCase()`). Case is one dialect out of seven.

---

## Decision

**Parse the input, reduce it to one canonical form, and store only that.**

The canonical form is **uppercase hexadecimal, no separators, whole bytes, four
to ten of them**: `001EB4CB`.

| Property | Value |
|---|---|
| Alphabet | `0`–`9`, `A`–`F` |
| Case | Upper — what readers and card labels print |
| Separators | None |
| Width | Whole bytes; 4 (Mifare Classic) to 10 (ISO 14443), i.e. 8–20 characters |
| Leading zeros | Significant and always written — `001EB4CB`, never `1EB4CB` |
| Column | `VARCHAR(20) NULL UNIQUE`, unchanged |

Normalization is implemented three times, deliberately, because all three
surfaces receive UIDs from outside:

| Surface | Module |
|---|---|
| Backend (store of record) | `App\Shared\Utils\CardUid` |
| Terminal (reader input) | `terminal-frontend/lib/utils/card_uid.dart` |
| Admin panel (card assignment) | `admin-frontend/src/utils/cardUid.ts` |

### What is decidable from the string, and what is not

Four of the seven dialects are pure notation and are resolved by parsing:
case, separators, an `0x` prefix, and a dropped leading zero *nibble* (an odd
digit count is half a written byte).

**Two are not decidable from the string at all**, and this is the crux:

- `0002012363` is the decimal spelling of `001EB4CB` **and** a perfectly
  well-formed 5-byte hex UID. Nothing in the value says which.
- A byte-reversed UID is a valid UID.

So they are **never guessed**. A heuristic here fails silently: the wrong
reading files a card under a UID no reader will ever produce, and the only
symptom is a card that does not work. Instead each is answered where the answer
is actually known:

| Question | Answered by |
|---|---|
| Is this decimal? | The terminal's **reader profile** (`rfidReader.uidFormat`), or an admin explicitly converting the value in the member form |
| Which byte order? | The same reader profile |

The reader profile is a property of the hardware, not of the card, so it belongs
to the terminal's configuration. Replacing a reader is then **one line of
`config.json`** instead of the re-registration of every member card — which is
the whole point of this ADR.

```json
{
  "rfidReader": {
    "uidFormat": "hex"
  }
}
```

| `uidFormat` | Reader emits | `001EB4CB` arrives as |
|---|---|---|
| `hex` (default) | hex, most significant byte first | `001EB4CB` |
| `hex-reversed` | hex, least significant byte first | `CBB41E00` |
| `decimal` | the decimal value | `0002012363` |
| `decimal-reversed` | the decimal value of the reversed bytes | `3417579008` |

Also settable as `RFID_READER_UID_FORMAT`. An unrecognised name **refuses to
load** rather than falling back to `hex`: a silent fallback's symptom is that
every card in the club stops working, which reads as broken hardware and says
nothing about the typo that caused it.

### One conversion, exactly once

`normalizeCardUid` is **not idempotent under a decimal profile** — a decimal UID
whose hex form is itself all digits would be converted twice and land on a
different card. So the terminal converts at exactly one point,
`RfidProvider.handleCardScan`, where every input path converges. The keyboard
capture and the RFID service above it trim whitespace and pass the reader's
characters through untouched; the scan log records the raw characters, which is
all an unfamiliar reader dialect can be diagnosed from.

### The reader has no fingers

The terminal pads a short scan out to four bytes; the backend and the admin
panel refuse the same value as too short. That asymmetry is deliberate:

- Input at the **terminal** comes from a reader. A short scan is a suppressed
  leading zero and nothing else, and a padded value that belongs to nobody
  simply reads as an unknown card — which costs nothing, since no data is
  written.
- Input at the **member form** comes from a volunteer, where `ABCD` is somebody
  who stopped typing. Accepting it would file a member under a UID no card
  carries. It is refused, which is the typo defence the form has always had
  (#131).

Both sides complete a half-written byte, because an odd digit count cannot be a
typo boundary in any spelling — it is a leading zero that went missing.

### Showing the volunteer what will be stored

A UID is twenty characters of hex that nobody reads back, so the only moment a
mistyped one is catchable is before Speichern. The member form therefore:

- keeps separators and an `0x` while the value is being typed, and canonicalizes
  when the field is left — stripping per keystroke turns a pasted `0x001EB4CB`
  into `0001EB4CB`, one nibble adrift and still plausible-looking;
- shows the canonical value under the field whenever it differs from what is in
  the box ("Wird gespeichert als 001EB4CB");
- **offers** the decimal reading when the entry could be one, as a button the
  volunteer presses — never as an automatic conversion.

---

## Consequences

### Positive

- A reader swap is a configuration change, not a re-registration of every card.
- One chip cannot be assigned to two members through two spellings.
- The terminal, the admin panel and the backend agree on what a UID is, and each
  states the rule in one module rather than at each call site.
- A volunteer sees the value that will be stored before storing it.

### Negative

- Three implementations of one rule, which can drift. Mitigated by each carrying
  its own test suite over the same worked example (`001EB4CB` in every dialect),
  and by the backend being the authority in every case.
- The reader profile is a setting somebody must get right. Mitigated by the
  default being what every reader shipped so far emits, and by a wrong value
  refusing to start instead of failing silently.
- Existing rows may hold a spelling the API would no longer write. Migration
  `066_canonical_card_uid.sql` brings them into line, skipping the `ANON-…`
  placeholder and any value whose canonical form is already taken.

### Neutral

- The API accepts *more* than before (dialects) and stores *less* (one
  spelling). The `card_uid` column, its width and its `UNIQUE` index are
  unchanged.

---

## Alternatives Considered

### Alternative 1: Sniff decimal from the string

Read a 10-digit, all-decimal value as decimal. Rejected: `0002012363` is a valid
5-byte hex UID, so the rule is wrong for an entire class of real cards, and it
is wrong *silently* — the member's card just never works.

### Alternative 2: Store every equivalent spelling and match against all of them

Keep the canonical form plus the reversed and decimal readings, and look a scan
up against all of them. Rejected: it multiplies the rows a `UNIQUE` index has to
protect, makes "which member holds this chip" ambiguous, and the ambiguity would
then be permanent rather than resolved once at the door.

### Alternative 3: Configure the reader instead of the software

Ask clubs to configure every replacement reader to match the original. Rejected
as the *only* measure: it is a manual step nobody will remember years later,
some readers cannot be reconfigured at all, and the failure it guards against is
exactly the one nobody can diagnose from the symptom. The profile setting is the
same idea with the knowledge written down where the software can act on it.

---

## References

- [ADR-0014](./0014-rfid-scanning-integration.md) — RFID scanning integration
- [ADR-0021](./0021-rfid-card-assignment-workflow.md) — Card assignment workflow
- [Pattern 014](../backend/patterns/pattern-014-rfid-member-identification.md) — RFID identification, not authentication
- [issue #18](https://github.com/dgloeckner/clubbar/issues/18) — the lowercase half of this, fixed earlier
