# Public Use Cases

Use cases for the club's public-facing surface — pages and endpoints reachable
by someone who holds no credential the system issued at all.

## System Purpose

Every other domain in `use-cases/` is built on top of a credential: the admin
panel runs behind a session cookie (UC-A01), the terminal behind a bearer
token (Pattern 012), and even the DSGVO flows act on behalf of somebody who is
already a member of record. `public/` is different in kind, not just in
audience: its primary actor is a stranger the system has never seen, on a
device that has never talked to it, arriving with nothing but a URL. The one
thing standing between that stranger and a write is a long-lived secret
printed on a poster — not a password, not a session, and not proof of who is
holding the phone.

That difference is why this is its own domain rather than another entry under
`admin/`. An admin use case assumes an authenticated actor and asks what they
are allowed to do; a public use case has to assume the opposite — an anonymous
one — and spends most of its rules on what an anonymous caller must be *unable*
to learn, rather than on what they can do once let in. Today that is a single
flow: a prospective member registers themselves from a QR code. Anything else
this club ever exposes without a login belongs here alongside it.

## Actor

| Actor | Description |
|-------|-------------|
| **Prospective Member** | An unauthenticated visitor on their own phone, holding a URL scanned off the club's QR code. No account, no session, no token — the secret in that URL is the only thing distinguishing them from anyone else who finds the address |

## Use Case Index

| ID | Name | Description |
|----|------|-------------|
| [UC-P01](./UC-P01-member-self-registration.md) | Register as a Member by Scanning the Club's QR Code | A prospective member submits their own details and downloads the club's Anmeldung, pre-filled, to sign. Submitting creates no member and no mandate — an admin still has to see the signed paper and approve (UC-A17) before anyone is onboarded |

## Non-Functional Requirements

| Requirement | Value |
|-------------|-------|
| Layout | Mobile-first — the phone that just scanned the code is the only device assumed |
| Session | None. Nothing in this domain issues, reads or requires one |
| Transport | HTTPS required |
| Bundle | Served by the backend as its own small page, distinct from the admin SPA |
| Languages | Selected by the applicant on the form itself, before any account exists to carry a preference |
