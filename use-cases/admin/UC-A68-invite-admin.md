# UC-A68: Onboard an Admin by Invitation Link

**Implementation Status**: Implemented

Extends [UC-A62](./UC-A62-create-admin.md), which it replaces the credential
half of: an account is no longer created with a password for somebody else to
carry.

## Actors

- **Admin** — creates the account and, if needed, sends a replacement link.
- **Invitee** — the person being onboarded. Has no account, no session and no
  password until they complete this use case.

## Motivation

Creating an admin used to mint a random password, show it once to whoever
pressed the button, and leave the two of them to move a live credential between
themselves — by chat, by note, by reading it aloud across a room. The new
admin's password was known to somebody else before they had ever touched the
account, through a channel this system neither chose nor can see.

## Preconditions

- The acting admin is signed in and holds the `admin` role (ADR-0044).
- The new account has a working email address. There is no other way to deliver
  the link.

## Main Flow

1. The admin creates an account (UC-A62), **choosing its roles on the same
   form** — an account never exists without them. No password is generated: the
   row is written with a hash of 32 random bytes nobody has ever seen.
2. The system mints a one-time token, stores its SHA-256 digest and a sealed
   copy, and queues an `admin_invitation` message to the account's address.
3. The panel shows the admin that the invitation was sent, with the link, the
   address it went to and when it expires.
4. The scheduler's next drain sends the message (ADR-0038 rule 3).
5. The invitee opens the link. The browser keeps the token to itself — it is
   in the fragment — and hands it to the server in a request body. The page
   greets them by name and shows the address the account will sign in with
   **and the role they are being onboarded into**.
6. The invitee sets a password, twice.
7. The system stores the password, spends the invitation, **ends whatever
   session this browser was holding**, and sends them to the sign-in form with
   their address filled in.
8. The invitee signs in. Having no second factor, they are put through
   Authenticator enrolment — the ordinary first-login path, unchanged.

## Alternative Flow: the link did not arrive

1. The admin opens the admin list and sees the account marked **invitation
   pending**.
2. They send a new invitation, re-proving their own identity with a step-up
   credential.
3. The previous link is revoked in the same call. There is never more than one
   working link to an account.

## Worked example: onboarding a Getränkewart

The role is chosen **before the account exists**, on the create form, and it
travels with the invitation from there:

| Step | What the new Getränkewart sees |
|------|--------------------------------|
| The admin's create form | `Rolle`: Admin / Kassenwart / **Getränkewart** — the account is created holding exactly what is ticked |
| The mail | "Dein Zugang zu <club>", and a `Deine Rolle: Getränkewart` row. Never "Admin-Zugang" |
| The accept page | Their address, a **Getränkewart** badge, then the password fields |
| After signing in | Authenticator enrolment, then the drinks list — a Getränkewart's landing page, because their dashboard would be a 403 (ADR-0044) |

Nowhere in that path is the account called an administrator's, and nowhere is
another account or another office mentioned.

## Rules

| Rule | Why |
|------|-----|
| The link expires after 7 days | A bearer credential in a mailbox should not outlive the reason it was sent |
| The link works once | Two people holding the same link must not both be able to set a password |
| Issuing a replacement revokes the previous link | An admin who believes they have cancelled something has |
| A replacement is refused once an account has accepted one | The invitation is an onboarding credential, not a second password-reset channel bypassing UC-A63's step-up |
| A replacement is refused for a deactivated account | Deactivating a colleague must also stop their outstanding invitation being renewed |
| The token is stored hashed, plus a sealed copy for the mail | A database dump yields no working link; the drain can still render one at send time (ADR-0038 rule 5) |
| The token is in the link's **fragment** (`/invite#<token>`), never in its path | A fragment is the one part of a URL a browser does not send. A path is written verbatim into every access log in front of the installation — twice per request in the shipped package — so a token there would hand a working invitation to anybody who can read a log file, which is the handover this use case exists to abolish |
| The two public endpoints have **constant URLs** and take the token in the request body — including the one that only reads | Same reason: request lines are logged, request bodies are not. That is worth more than the shape of the verb, so the read is a `POST` too |
| Unknown, expired, spent and revoked answer identically | Distinguishing them for an anonymous caller confirms that a token exists |
| Accepting mints no session | A mail link must not be able to skip the second-factor gate |
| Accepting **ends** the session the request carried | The link is often followed on a machine somebody else is signed in to, and the sign-in form sends an authenticated browser to the dashboard — so without this the invitee landed inside the *inviting* admin's account, having proven only that they can read an email (#798). Looking the link up does not: reading the page is something an admin may legitimately do while signed in |
| The mail and the page name the account's **own** role, and no other | A Getränkewart told they have been given an "admin account" is told something alarming and false; naming the reader's own job is not the org-structure disclosure that withholding it was meant to prevent |
| The public endpoints are rate-limited on IP, and refused tokens count as failed attempts | Otherwise this is the one unmetered credential surface in the system |

## Postconditions

- The account has a password only its owner knows.
- `invitation_sent` and `invitation_accepted` audit entries record how the
  account came to be usable. The second is the only entry in the log written by
  a request carrying no session.
- Every session predating the password stops working (`credentials_changed_at`),
  and the browser that accepted holds no session at all — including one it
  arrived with.
- The account is no longer marked pending in the admin list.

## Error Cases

### E1: The link has expired, been used, or does not exist
The page says the link no longer works and offers the sign-in form. Which of the
four it was is recorded in the application log and never answered on the wire.

### E2: The password does not meet the rules
Eight characters, with a lower-case letter, an upper-case letter and a digit —
`PATCH /api/auth/change-password`'s rule, character for character. Checked in
the browser and again by the server; a failure here is not counted as a failed
attempt, since it is somebody mistyping their own new secret rather than probing
for a token.

### E3: The mail could not be queued
The account and the invitation still exist, and the link is in the response the
admin is looking at. Recorded in the application log; it never fails the
creation.

### E4: The account has already accepted an invitation
`admin_already_onboarded`. The admin is pointed at the password reset (UC-A63).

## Test Derivation

- Creating an admin returns an invitation and **no** password
- The created account cannot sign in before the link is used
- The link sets a password, and that password then signs in
- Signing in for the first time demands Authenticator enrolment
- Re-using a spent link is refused
- An expired link is refused
- A resend revokes the previous link
- A resend is refused for an account that has accepted
- A resend is refused for a deactivated account
- Accepting through a browser that already holds an admin session leaves it
  signed in as nobody, on the sign-in form — not on that admin's dashboard
- Looking a link up through such a browser leaves that session alone
- The delivered mail contains a link that actually works (end to end, via the
  drain and Mailpit)
- The list marks a pending account and offers resend instead of reset
- A Getränkewart invitation names `Getränkewart` on the page and in the mail,
  and calls the account an admin one nowhere
- The issued link carries its token in the fragment, and neither public
  endpoint takes a token in its path
