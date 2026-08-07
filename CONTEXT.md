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
An immutable, append-only record of a purchase or a storno by a member. Never updated or deleted.
_Avoid_: booking, entry

**Storno**:
The reversal of one specific transaction, in full. A storno always names the transaction it reverses (`related_transaction_id`, never null) and its amount is derived as the exact negation — it is never typed in. This is the *only* way to correct a booking: there is no free-amount adjustment. Overcharged? Storno the wrong transaction, then book the right one. A transaction can be stornoed at most once, and a storno cannot itself be stornoed.

German business term, used in code, specs and UI alike — it is the word the Kassenwart uses and the word the regulation uses (GoBD Rz. 64, *"Korrektur- bzw. Stornobuchungen"*), which is what makes the mandatory linkage self-explaining. See [ADR-0028 §4](./adr/0028-legal-constraints-on-money-handling.md).
_Avoid_: correction (it implied a free-amount adjustment that no longer exists), reversal, adjustment, refund

**Payout**:
Money sent *out* to a member to settle a credit balance. Not a storno and not a correction — legally a separate act, with no `related_transaction_id` and an external document reference to the bank transfer.
_Avoid_: refund (that is what the bank does to a member under the SEPA return right), reimbursement

**Settlement**:
A periodic run that collects members' unsettled transactions into a SEPA direct-debit batch (or manual settlement).
_Avoid_: billing run, invoice
