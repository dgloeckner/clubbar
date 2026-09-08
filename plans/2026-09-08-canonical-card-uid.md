# Canonical Card UID Normalization

**Issue**: hardening RFID detection — the same chip reaches the system in seven
different spellings, and `members.card_uid` is matched by exact string
comparison.

**ADR**: [ADR-0055](../adr/0055-canonical-card-uid.md) (amends
[ADR-0014](../adr/0014-rfid-scanning-integration.md),
[ADR-0021](../adr/0021-rfid-card-assignment-workflow.md))

**Status**: Implemented — M1–M6 done, each verified.

---

## Why

What a USB keyboard-wedge reader types depends on how the reader is configured,
not on the card. The same 4-byte chip arrives as `001EB4CB`, `001eb4cb`,
`00:1E:B4:CB`, `0x001EB4CB`, `1EB4CB`, `0002012363` (decimal) or `CBB41E00`
(bytes reversed).

Two failures follow from storing whichever spelling arrived:

1. **A replacement reader invalidates every card in the club.** The old reader
   dies, the club buys what is in stock, and its factory configuration differs
   by one setting. No member is recognised, every UID has to be re-entered by
   hand, and each card simply reads as unknown — nothing in the failure says
   why.
2. **The uniqueness check stops working.** `001EB4CB` and `00:1E:B4:CB` are one
   card and `UNIQUE` sees two values, so one chip reaches two members and the
   bookings go to whoever tapped.

The lowercase half was already fixed for the terminal (issue #18). Case is one
dialect out of seven.

---

## Decision, in one line

Parse the input, reduce it to **uppercase hex, no separators, whole bytes, four
to ten of them** — `001EB4CB` — and store only that. Never guess at decimal or
byte order; both are answered by the terminal's configured reader profile or by
an admin converting a value explicitly.

---

## Milestones

### M1 — the canonical form, three times `[x]`

Each surface receives UIDs from outside, so each parses.

| Task | Status | Verified by |
|------|--------|-------------|
| `App\Shared\Utils\CardUid` — `canonicalize()`, `isCanonical()`, `PATTERN` | `[x]` | `CardUidTest` 20/20 |
| `terminal-frontend/lib/utils/card_uid.dart` — `CardUidFormat`, encoding + byte order | `[x]` | `test/utils/card_uid_test.dart` 29/29 |
| `admin-frontend/src/utils/cardUid.ts` — the same rules plus the explicit decimal conversion | `[x]` | `src/utils/cardUid.test.ts` 31/31 |

**The asymmetry is deliberate**: the terminal pads a short scan out to four
bytes, the other two refuse it. Input at a reader is hardware, where a short
value is a suppressed leading zero and a padded miss costs nothing; input at the
member form is fingers, where `ABCD` is somebody who stopped typing and
accepting it would file a member under a UID no card carries. Both complete a
half-written byte, which cannot be a typo boundary in any spelling.

### M2 — the backend stores one spelling `[x]`

| Task | Status | Verified by |
|------|--------|-------------|
| `AdminController::withCanonicalCardUid()` runs ahead of validation on create and update | `[x]` | `AdminControllerCardUidTest` 28/28 |
| `card_uid` rule tightened to whole bytes (`CardUid::PATTERN`) | `[x]` | same |
| The uniqueness check sees the canonical value, so two spellings cannot reach two members | `[x]` | `card-uid-canonicalization.spec.ts` |
| `066_canonical_card_uid.sql` brings existing rows into line | `[x]` | applied by `dev-setup.sh`; skips `ANON-…` and any value whose canonical form is taken |
| OpenAPI: `card_uid` documents what is accepted and what is stored | `[x]` | `api/admin.yaml` |

### M3 — the terminal knows what its reader speaks `[x]`

| Task | Status | Verified by |
|------|--------|-------------|
| `rfidReader.uidFormat` / `RFID_READER_UID_FORMAT` — `hex`, `hex-reversed`, `decimal`, `decimal-reversed` | `[x]` | `config_service_test.dart` 50/50 |
| A misspelled profile refuses to load rather than falling back to `hex` | `[x]` | same |
| `RfidProvider.handleCardScan` is the single conversion point | `[x]` | `rfid_provider_test.dart` |
| Capture and `RealRfidService` pass the reader's characters through untouched | `[x]` | `scan_capture_test.dart`, `real_rfid_service_test.dart` |

**Why exactly one conversion**: normalization is *not* idempotent under a
decimal profile — a decimal UID whose hex form is itself all digits would be
converted twice and land on a different card.

### M4 — the volunteer sees what will be stored `[x]`

| Task | Status | Verified by |
|------|--------|-------------|
| The field keeps separators and `0x` while typing, canonicalizes on blur | `[x]` | `cardUid.test.ts`, `tsc`, `vitest` 664/664 |
| "Wird gespeichert als 001EB4CB" under the field when it differs | `[x]` | `member-form-card-uid-canonical` |
| The decimal reading is *offered* as a button, never applied | `[x]` | `member-form-card-uid-decimal` |
| Both locales carry the new strings; the placeholder no longer teaches a decimal UID | `[x]` | `de.json`, `en.json` |

Stripping non-hex per keystroke had to go: it turned a pasted `0x001EB4CB` into
`0001EB4CB` — the `x` gone, the `0` left behind, and a UID one nibble adrift
that still looks plausible.

### M5 — end to end `[x]`

| Task | Status | Verified by |
|------|--------|-------------|
| Every dialect posted to the real endpoint reads back canonical | `[x]` | `e2etests/tests/api/card-uid-canonicalization.spec.ts` |
| Two spellings of one chip are refused with 422 | `[x]` | same |
| A PATCH is canonicalized like a create | `[x]` | same |
| A digits-only UID is stored as hex, not read as decimal | `[x]` | same |

### M6 — documentation `[x]`

| Task | Status |
|------|--------|
| ADR-0055 + index row | `[x]` |
| Pattern 014 gains "The UID Has One Spelling" | `[x]` |
| `terminal-frontend/INSTALL.md`: config key, "Card UID format" section, env row, troubleshooting row | `[x]` |

---

## Deliberately out of scope

- **A backend heuristic for decimal.** `0002012363` is a valid 5-byte hex UID
  as well, and a wrong guess in the store of record is silent.
- **Storing every equivalent spelling and matching against all of them.** It
  makes "which member holds this chip" permanently ambiguous instead of
  resolving it once at the door.
- **Changing the column.** `VARCHAR(20) NULL UNIQUE` is unchanged.
