# UC-A69: Configure Self-Registration and Print the QR Poster

**Implementation Status**: Not implemented — specified

## Actors

- **Admin** — generates the poster secret, reprints the poster, and rotates
  the secret. All three are grouped under `[ADMIN]` for the same reason:
  reading the poster URL means reading the secret in plaintext, whether it is
  a brand-new secret or the one already on the wall.
- **Admin or Kassenwart** — switches self-registration on and off and writes
  the reason members see while it is off.
- **Prospective member** — scans the poster on the wall. Never signs in, and
  is the reader every state in this use case is written for (ADR-0052
  decision 2).

## Motivation

ADR-0052 turns a printed piece of paper into a standing write credential for
the public internet, and the whole design leans on that credential being
tightly held: **fail closed by default**, mintable and revocable only by
`admin`, and reprintable without ever having to be revoked. This use case is
where that design becomes a set of buttons — and the buttons that look almost
identical (reprint vs. rotate) have to feel very different, because one
changes nothing and the other silently retires every poster the club has
taped to a wall.

## Preconditions

- Caller is signed in and holds `admin` for secret generation, reprint, or
  rotation; `admin` or `kassenwart` for the availability switch.
- The backend already serves `/register` as part of the deployment (ADR-0052
  decision 11) — there is no separate step to stand that page up.

## Main Flow — generating the first secret

1. The admin opens Settings → Self-Registration. Until a secret has ever been
   generated, the page states plainly that self-registration is off and no
   poster exists — this is the fail-closed default (ADR-0052 decision 2), and
   it holds regardless of anything else on this page.
2. The admin generates the first secret: `POST
   /api/admin/self-registration/secret`. The system mints 32 random bytes,
   stores `secret_hash` (SHA-256, the lookup key a dump yields no working
   poster from) and a sealed copy the panel can render again later.
3. Generating the secret does **not**, by itself, start accepting
   registrations. `/api/admin/self-registration/availability` is its own
   switch, gated to a different role set (below) precisely because it is a
   different decision — *a secret exists* and *the club is currently
   accepting submissions* are independent facts, and both default closed.
4. The admin (still `admin`-only) opens the poster to print it — a QR code
   encoding `https://<club>/register#<secret>`. The secret sits in the URL
   fragment, never the path, for the reason UC-A68's invitation links already
   establish: a fragment is the one part of a URL a browser never sends, and
   a path is written into every access log in front of the installation.
5. An admin configures the club's **Datenschutz URL** — where the club
   publishes its own Datenschutzhinweise — on the instance configuration
   screen, one entry per language it has published. Club Bar authors no legal
   text; it links the club's document (ADR-0052 decision 6). This is the second
   precondition, and it is `[ADMIN]` because it is already an admin-only
   surface.
6. An admin or Kassenwart turns the availability switch on. It is accepted only
   with **both** preconditions met — a secret to point the poster at, and a
   document to point the applicant at. Only from this point does a scan of the
   printed poster reach the registration form.

## Main Flow — reprinting the poster without rotating

1. An admin (only) returns to Settings → Self-Registration — a poster faded,
   a second location needs a copy, or the original was simply lost.
2. The admin asks to view or download the poster again.
3. The system renders the QR code from the **stored sealed copy** — it does
   not generate anything new, and `secret_hash` is untouched.
4. The printed poster is byte-for-byte the same URL as every other poster
   already on a wall. None of them stop working.

## Alternative Flow: rotating the secret

Rotation is the one action in this use case that must never be confused with
reprinting, so the confirmation says, in plain language, what is about to
happen before it happens.

1. The admin chooses "Rotate secret" — reserved for a poster believed
   compromised (photographed and shared outside the club, for instance), not
   for routine hygiene the way a terminal token's overlap rotation is
   (UC-A54).
2. The system shows an explicit warning: **every poster currently printed
   stops working the moment this is confirmed.** There is no overlap window
   here the way there is for a terminal token — a terminal is a device an
   admin can walk over to and re-key in person; a poster is paper already
   handed out, and the entire point of rotating in response to a leak is that
   the leaked copy must die immediately, not gracefully.
3. The admin confirms. `POST /api/admin/self-registration/secret` generates a
   fresh 32 bytes and **replaces** `secret_hash` and the sealed copy — the old
   values are gone, not archived alongside a pending one.
4. Every URL built from the old secret now resolves exactly like a wrong
   guess: `registration_unavailable`, no detail (decision 2, row 1). A member
   standing at the old poster learns nothing about why it stopped working —
   which is correct, because from the server's point of view that secret no
   longer names anything.
5. The system shows the new poster. The admin must physically replace every
   copy on every wall; nothing here does that part.
6. The rotation is recorded to the audit log.

## Alternative Flow: switching registration off, and back on

1. An admin or Kassenwart opens Settings → Self-Registration and turns
   availability off, entering a reason addressed to the person who will read
   it on their phone — the ADR's own example is *„Beta-Phase schon voll"*.
2. `PATCH /api/admin/self-registration/availability` stores the switch and
   the reason. **The refusal is enforced on the submission endpoint itself**,
   server-side — not rendering the form is a convenience, not the gate
   (decision 2).
3. Anyone scanning a still-valid poster now reaches the right secret and a
   switched-off club: they see `registration_disabled` **plus that reason
   text**, not a blank refusal. They are standing in the clubhouse holding a
   poster the club printed; telling them nothing would read as broken, not
   as closed.
4. To reopen, an admin or Kassenwart turns the switch back on. Nothing about
   the secret changes, so every poster already on the wall works again
   immediately — turning registration off and back on is reversible in a way
   rotating the secret deliberately is not.

## Worked example: the poster's life, state by state

| Club state | What a scanning member sees | Who can cause this |
|---|---|---|
| No secret ever generated | `registration_unavailable`, no detail — indistinguishable from a wrong guess | `[ADMIN]` — nobody has generated one yet |
| Secret generated, availability still off | Same `registration_unavailable` — a secret existing is not the same as the club accepting anything | `[ADMIN]` generated it; `[ADMIN, KASSENWART]` has not yet turned it on |
| Availability on | The registration form (UC-P01) | `[ADMIN, KASSENWART]` flipped the switch |
| Availability switched off, with a reason | `registration_disabled` plus the club's own reason text | `[ADMIN, KASSENWART]` |
| Secret rotated | Every poster printed before the rotation now answers `registration_unavailable`, identically to a stranger's wrong guess | `[ADMIN]` only |

## Rules

| Rule | Why |
|------|-----|
| No secret ever generated means unconditionally unavailable, independent of the availability switch | Fail closed twice over — a fresh install and a half-finished configuration must both answer "unavailable" (decision 2) |
| Generating and rotating the secret is `[ADMIN]` only | Minting a bearer credential for a public write surface is ADR-0044 rule 2 territory — the same reasoning that makes terminal token rotation admin-only (UC-A54) |
| Reading the poster URL — reprinting it — is also `[ADMIN]` only | Reading the poster is reading the plaintext secret; there is no version of "let a Kassenwart reprint it" that does not also hand them the bearer credential itself |
| Turning availability on or off is `[ADMIN, KASSENWART]` | "The switch belongs to whoever is running the onboarding table" — ADR-0052 decision 8 — the same office already reviews the rows that switch produces (UC-A17) |
| The Getränkewart reaches none of this page | Member onboarding is outside their remit on every surface (ADR-0045 invariant 5); the settings tab is not shown to them |
| Reprinting never changes `secret_hash` | The stored sealed copy exists specifically so a poster can be replaced without a security event (decision 1) |
| Rotating replaces `secret_hash` outright, with no overlap window | Unlike a terminal token, a poster cannot be "re-keyed in person" — the whole reason to rotate is to kill a leaked copy at once, not to let it keep working until somebody notices |
| A rotated secret's old URLs answer exactly like an unknown one | Anything that told the difference would confirm to whoever holds the old poster that it *used to* work, which is information a probe should never get |
| Switching off requires a reason; switching back on requires nothing but the flip | Off is addressed to a person standing at the poster; on restores exactly what was already printed, so nothing needs re-explaining |
| Availability cannot be switched on without a configured Datenschutz URL | Collecting a name, a birth date and an IBAN from somebody who was never told what happens to them is the failure this condition exists to prevent — and it is the half an admin is most likely to skip, because the poster is the visible artefact and the notice is not |
| The refusal names which precondition is missing (`datenschutz_url_missing`), rather than greying the switch out | An admin who cannot turn a feature on and is not told why files a bug against the switch, not against the missing document |
| Club Bar stores a URL, never the document, and never fetches it | It is generic software installed by clubs it knows nothing about, so the legal text is the club's to write and host; and a URL an admin supplies which the server then retrieves is an SSRF primitive |
| The refusal on a wrong or missing secret is enforced on `POST /api/public/registrations` itself | Hiding the form in the UI is not a gate; the endpoint must refuse independently of what the browser rendered |

## Postconditions

**After generating the first secret**
- `secret_hash` and a sealed copy exist. Availability is still off until an
  admin or Kassenwart explicitly turns it on.

**After reprinting**
- Nothing in storage changes. The poster shown is identical to every other
  poster already printed from this secret.

**After rotating**
- `secret_hash` and the sealed copy are replaced. Every previously printed
  poster now resolves as `registration_unavailable`. Audit log records the
  rotation.

**After switching availability**
- `PATCH /api/admin/self-registration/availability`'s stored state (on/off
  plus, when off, the reason text) governs every scan from that request
  onward. No poster is reprinted and no secret changes.

## Error Cases

### E1: A Kassenwart attempts to generate, reprint, or rotate the secret
403 `insufficient_role`. The action is not offered on their view of the page
at all — only the availability switch is.

### E2: A Getränkewart opens Settings
403 `insufficient_role`, and the Self-Registration tab is not in their
navigation to begin with (ADR-0044).

### E3: The availability switch is turned on with no secret ever generated
Refused — there is nothing to point a QR code at yet. The panel prompts the
admin to generate a secret first.

### E4: A reprint or rotation is requested with no secret ever generated
Refused for the same reason as E3: nothing to reprint or rotate.

### E4a: Availability is turned on with no Datenschutz URL configured
Refused with a typed `datenschutz_url_missing`, and the panel names the missing
precondition and links straight to the instance configuration screen that sets
it. The switch is never silently greyed out: an admin who is not told which of
the two conditions they are missing has been handed a puzzle instead of a
setting.

### E5: Availability is switched off with no reason text
422 — a blank refusal shown to a member standing at a poster the club printed
is exactly the failure decision 2 exists to prevent.

## Test Derivation

- Availability cannot be switched on while `instance_config.privacy_policy_urls`
  is empty; the refusal is `datenschutz_url_missing` and names the precondition
- Availability cannot be switched on before a secret exists
- With both preconditions met, the switch is accepted and a poster scan reaches
  the form
- Clearing the Datenschutz URL while registration is live is refused, or turns
  registration off in the same write — never leaves it accepting submissions
  with nothing to point the applicant at
- No secret generated: `POST /api/public/registrations` with any fragment
  answers `registration_unavailable`
- Secret generated, availability off: the correct secret still answers
  `registration_unavailable`
- Secret generated, availability on: the correct secret reaches the form
  context; a wrong or missing one still answers `registration_unavailable`
- Availability off with a reason: the correct secret answers
  `registration_disabled` and returns the configured reason text
- Reprint returns the same URL/secret as before, and every previously issued
  poster keeps working
- Rotate replaces the secret; the previous secret now answers
  `registration_unavailable`, identically to an unknown one
- Rotate is refused for a Kassenwart and a Getränkewart (403)
- Generate/reprint/rotate are all refused for a Kassenwart and a Getränkewart
  (403); the availability switch succeeds for a Kassenwart and is refused for
  a Getränkewart
- Turning availability on with no secret ever generated is refused
- Turning availability off with an empty reason is refused (422)
- Rotation and secret generation are both recorded to the audit log
- The QR-encoded URL carries the secret in the fragment, never the path, in
  every state that exposes it

## Related

- [ADR-0052](../../adr/0052-member-self-registration-via-qr-code.md) — the
  decision this specifies, especially decisions 1, 2, 8 and 11
- [UC-P01](../public/UC-P01-member-self-registration.md) — what a member sees
  once availability is on and they have the current secret
- [UC-A17](./UC-A17-review-pending-registrations.md) — where the rows this
  page's "on" switch produces are reviewed
- [UC-A54](./UC-A54-rotate-terminal-token.md) — the overlap-rotation pattern
  this use case deliberately does **not** follow, and why
- [UC-A68](./UC-A68-invite-admin.md) — the fragment-token URL shape this
  reuses
- [ADR-0044](../../adr/0044-tiered-admin-roles.md) — the role derivation this
  use case's Rules table follows
