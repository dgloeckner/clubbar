# Club Bar

A member-managed bar/club POS system: members identify via RFID at an offline-capable terminal, purchases accumulate on their tab, and the club settles periodically via SEPA direct debit.

## Language

### Terminal

**Session**:
The period during which one member is logged in at a terminal, from card scan on the idle screen to session end. A session ends in exactly three ways: explicit logout, inactivity timeout, or checkout completion — nothing else ends or replaces it (a foreign card tap mid-session is rejected).

Unqualified, "session" always means this one. The admin panel's is always the **admin session** — never bare "session".
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

**Jugendschutz**:
The refusal to sell a product to a member who is too young for it. The limit belongs to the **product** (`min_age`, a number the Getränkewart sets), the age belongs to the **member** (a date of birth, from which age is computed — never a stored number), and the refusal happens at **checkout**, offline, on the terminal.

It is a standing condition, not a transient failure: nothing about it will be different if the member tries again, so the refusal offers no Retry. The message names the age the product requires and never the member's own — the terminal screen is read by whoever is at the bar. See [ADR-0045](./adr/0045-age-restricted-products.md).
_Avoid_: age check, age gate, 18+, adult verification, ID check (the system verifies no identity — it trusts the birth date on file)

**Limit**:
The ceiling a member's **Deckel** may reach. One number, in cents, and it is always *that member's*: their own where the club has set one for them, the club's default otherwise. Set by the Kassenwart — the club default in Einstellungen → Limits, an individual one on the member — and never computed by the system from tenure, arrears or anything else.

Two values that look alike mean opposite things and both are deliberate: **empty** on a member means *follow the club*, so raising the club's ceiling lifts them too, while **0** means *no ceiling for this member*. A negative one is refused rather than stored, because `<= 0` already reads as "not enforced".

Enforced at **checkout**, offline, like Jugendschutz — with a warning band below it, a club-wide share of whatever ceiling the member ended up with. Unlike Jugendschutz it is about money, not law: a sale a stale terminal let through is still recorded. See [ADR-0047](./adr/0047-configurable-credit-limits.md).
_Avoid_: credit limit (the member is not being lent anything — the club is deciding how large a tab it will carry), balance limit, max balance, Kreditrahmen, overdraft

### Accounting

**Transaction**:
An immutable, append-only record of a purchase or a storno by a member. Never updated or deleted.

A purchase is always made at a terminal and always names a product — there is no admin-booked purchase and no free-amount entry ([UC-A21 rejected](./use-cases/admin/UC-A21-manual-purchase.md)). **No amount is ever typed in, anywhere in the system**: a purchase takes the product's price, a storno negates its original exactly, a settlement sums the transactions it covers. If a money amount ever needs typing, something has been designed wrong.
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

**Settlement method**:
What actually happened to the money in a settlement — never a plan for what might.

`direct_debit` — the club collects by SEPA. The only method that batches, exports and carries a Vorabankündigung.
`bank_transfer` — the member has already paid; the treasurer is recording receipt. Never cancellable (the money is in), only reversible.
`write_off` — the club has given up collecting. No money moves either way.

A settlement records money the club has collected, has received, or has given up on. **Nothing in the system ever asks a member to send money.** A member SEPA cannot collect from — no mandate, or on collection hold after a bank return — is contacted by the Kassenwart directly, by phone or email.
_Avoid_: manual settlement (says how it was entered, not what happened), payment request

### Access

**Admin session**:
The period during which one person is logged in to the admin panel. Always qualified — bare "session" means a member's terminal Session, which is a different thing with a different lifecycle.
_Avoid_: session (unqualified), admin login

**Role**:
Which of the club's jobs a person's admin account is for. Every account has at least one; a person may hold both lesser roles at once. A role says what the account is *for*, never how much power it has in the abstract — there is no "read-only" or "junior" account, only accounts shaped like the office they serve. See [ADR-0044](./adr/0044-tiered-admin-roles.md).
_Avoid_: permission, tier, level, access rights

**Admin**:
Whoever holds the server. Unlimited access to everything, including the encryption private key and the power to create accounts and grant roles. Not a Vereinsamt — nobody is elected to it, which is why it keeps an English name among German ones.
_Avoid_: superadmin, owner, root, administrator

**Kassenwart**:
The elected treasurer. Members, settlements, SEPA collection, Storno, and the notifications that go with them. Holds a copy of the encryption private key because collecting by SEPA is impossible without it — so a Kassenwart legitimately sees every member's IBAN. That is the office, not a leak.

Cannot create accounts, mint terminal credentials, manage encryption keys, change mail configuration, or read the audit log. Cannot erase a member: erasure is irreversible and stays with the Admin. Sets the club's **Limit** and any member's own — a money decision, so it sits with the office that makes money decisions.
_Avoid_: treasurer (the club says Kassenwart), finance admin, accountant

**Getränkewart**:
Whoever looks after the bar stock: the drinks list, prices, availability, and what actually sells. Holds no encryption key and therefore *cannot* decrypt any IBAN — the only role whose exclusion from member banking data is cryptographic rather than a matter of which pages they can open.

Sees no member: no names, no Deckel, no transaction rows, no dashboard. Product and terminal figures only. Sets a product's **Jugendschutz** age — a legal number on a drink — and still sees no member and no birth date. Sets no **Limit**: a ceiling is about a member's money, and this office sees no member.
_Avoid_: bar operator, bar manager, Barwart, product admin

### Notifications

**Vorabankündigung**:
The announcement that a settlement will collect a named amount from a member on a named date, sent at least seven days ahead (Nutzungsordnung § 7 Abs. 3). It belongs to one settlement, states the amount that settlement will actually take, and carries the mandate reference and creditor identifier. A collection without it is one the club promised not to make.
_Avoid_: pre-notification in member-facing text (the German term is the one the Nutzungsordnung uses), reminder

**Deckelauszug**:
A periodic statement of a member's Deckel, sent to every member on a fixed calendar boundary regardless of what they owe. It states the Deckel **as it stood at that boundary** — not as it stands when the mail is written — and itemises the unsettled transactions behind it, netted.

It announces nothing and collects nothing. That is what separates it from the Vorabankündigung: the Vorabankündigung is a step in taking money and names a date on which money moves; a Deckelauszug is information about a tab that is simply open, and the same drink appears on every Deckelauszug until a settlement finally claims it. A member who owes nothing still gets one.
_Avoid_: Kontoauszug (that is a bank's document about a bank account), Mahnung and reminder (it never asks to be paid), balance statement (see Deckel)

**Willkommensmail**:
The first message a member ever receives from the club, sent when their **card**
is assigned — not when their record is created. A member row without a card is
paperwork: no Session can start and no Deckel can move, so there is nothing yet
to welcome them to.

It says the card works, how the Deckel accrues, and that a **Vorabankündigung**
arrives at least seven days before every collection. It carries no
Mandatsreferenz and no Gläubiger-ID: the registration form promises those
*„mit der Vorabankündigung zum ersten Einzug"*, and at card time there is often
no mandate on file to name.

It also says the card may not have reached the member yet, because it usually
has not: the Kassenwart enters the UID while *preparing* the onboarding, and the
plastic is handed over later.

That it comes first is a rule, not an accident — no member-addressed notice goes
out before it, which is why an address change for a member with no card is
silent. See [ADR-0051](./adr/0051-member-lifecycle-mail.md).
_Avoid_: registration mail, onboarding mail, activation mail (nothing is
activated by reading it), confirmation mail (there is nothing to confirm)

**Adressänderung**:
A member's email address moving, told at **both ends**: one message to the
address being left, one to the address being taken up. They are two different
messages, not one message twice.

The copy to the old address is the only channel through which a change the
member did not ask for can still reach them — the member's version of the notice
an admin gets when their sign-in address moves. The copy to the new address is
the only thing in this system that ever checks an address exists at all; its
value is the **bounce**, which lands as a failed row months before a collection
depends on the address being real.

Neither names the other address. It is **not** a verification: nothing is gated
on it, there is no token, and the message says so.
_Avoid_: email verification, double opt-in, confirmation (all three promise a
gate that does not exist), address update notice
