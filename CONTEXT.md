# Club Bar

A member-managed bar/club POS system: members identify via RFID at an offline-capable terminal, purchases accumulate on their tab, and the club settles periodically via SEPA direct debit.

## Language

### Terminal

**Session**:
The period during which one member is logged in at a terminal, from card scan on the idle screen to session end. A session ends in exactly three ways: explicit logout, inactivity timeout, or checkout completion — nothing else ends or replaces it (a foreign card tap mid-session is rejected).
_Avoid_: login, visit

**Cart**:
The set of products a member has selected but not yet checked out. A cart belongs to a session, never to a member: it starts empty when the session starts and is silently discarded when the session ends.
_Avoid_: basket, order

**Deckel**:
A member's running tab — the sum of unsettled transactions the member owes the club. Displayed at the terminal; settled via SEPA settlement runs.
_Avoid_: balance (ambiguous with projected balance), account

**Idle screen**:
The terminal's resting state with no active session, inviting the next member to scan their card.
_Avoid_: home screen, lock screen

**Checkout**:
The act of converting the cart into immutable transactions billed to the session's member. Completing checkout leads to the confirmation screen and then ends the session.
_Avoid_: purchase, payment

### Accounting

**Transaction**:
An immutable, append-only record of a purchase (or reverse correction) by a member. Never updated or deleted; corrections are new reverse transactions.
_Avoid_: booking, entry

**Settlement**:
A periodic run that collects members' unsettled transactions into a SEPA direct-debit batch (or manual settlement).
_Avoid_: billing run, invoice
