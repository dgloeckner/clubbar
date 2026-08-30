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

1. The admin creates an account (UC-A62). No password is generated: the row is
   written with a hash of 32 random bytes nobody has ever seen.
2. The system mints a one-time token, stores its SHA-256 digest and a sealed
   copy, and queues an `admin_invitation` message to the account's address.
3. The panel shows the admin that the invitation was sent, with the link, the
   address it went to and when it expires.
4. The scheduler's next drain sends the message (ADR-0038 rule 3).
5. The invitee opens the link. The page greets them by name and shows the
   address the account will sign in with.
6. The invitee sets a password, twice.
7. The system stores the password, spends the invitation, and sends them to the
   sign-in form with their address filled in.
8. The invitee signs in. Having no second factor, they are put through
   Authenticator enrolment — the ordinary first-login path, unchanged.

## Alternative Flow: the link did not arrive

1. The admin opens the admin list and sees the account marked **invitation
   pending**.
2. They send a new invitation, re-proving their own identity with a step-up
   credential.
3. The previous link is revoked in the same call. There is never more than one
   working link to an account.

## Rules

| Rule | Why |
|------|-----|
| The link expires after 7 days | A bearer credential in a mailbox should not outlive the reason it was sent |
| The link works once | Two people holding the same link must not both be able to set a password |
| Issuing a replacement revokes the previous link | An admin who believes they have cancelled something has |
| A replacement is refused once an account has accepted one | The invitation is an onboarding credential, not a second password-reset channel bypassing UC-A63's step-up |
| A replacement is refused for a deactivated account | Deactivating a colleague must also stop their outstanding invitation being renewed |
| The token is stored hashed, plus a sealed copy for the mail | A database dump yields no working link; the drain can still render one at send time (ADR-0038 rule 5) |
| Unknown, expired, spent and revoked answer identically | Distinguishing them for an anonymous caller confirms that a token exists |
| Accepting mints no session | A mail link must not be able to skip the second-factor gate |
| The public endpoints are rate-limited on IP, and refused tokens count as failed attempts | Otherwise this is the one unmetered credential surface in the system |

## Postconditions

- The account has a password only its owner knows.
- `invitation_sent` and `invitation_accepted` audit entries record how the
  account came to be usable. The second is the only entry in the log written by
  a request carrying no session.
- Every session predating the password stops working (`credentials_changed_at`).
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
- The delivered mail contains a link that actually works (end to end, via the
  drain and Mailpit)
- The list marks a pending account and offers resend instead of reset
