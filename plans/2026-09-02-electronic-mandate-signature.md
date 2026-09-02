# Electronic Mandate Signature in the Browser

**ADR**: [ADR-0053](../adr/0053-electronic-mandate-signature.md) (Proposed) ·
**Research**: [`research/electronic-signature-onboarding.md`](../research/electronic-signature-onboarding.md) ·
**Extends**: [Member Self-Registration via QR Code](./2026-08-31-member-self-registration.md) (ADR-0052, UC-P01, UC-A17, UC-A69)

## Goal

An adult applicant registering for their own account signs the Anmeldung on
their phone instead of printing it: the same request that seals the IBAN
freezes the document and the declarations into a hashed record, one mail asks
the submitted address to confirm, and the Kassenwart approves by reviewing the
record. When a bank asks *kein Mandat?*, the treasurer exports a Mandatskopie
— the sealed document opened with the private key plus an evidence sheet —
inside the seven business days the DK terms allow.

**The invariants this plan must not break**: a pending registration is still
not a member (ADR-0052); the plaintext IBAN still exists for one request only
(ADR-0036); the outbox is still the only sender (ADR-0038); minors and
third-party account holders still sign paper.

## Gate

**Nothing in M1 onward starts until the owner has**: accepted ADR-0053 and
confirmed its schema delta (one table `mandate_signatures`, columns
`mandates.signing_method` and `self_registration_config.membership_declaration`
plus the switch and bank-confirmation fields, one `MailKind` + `MailSubject`,
audit actions); answered the eight open questions in the ADR; and — for
*enabling* rather than shipping — obtained the bank's position on electronic
mandates and read the Satzung's Beitritt clause.

## Milestones

- [ ] **M0 — Specification.** ADR-0053 drafted Proposed; research written;
      this plan. Still to do in M0: UC-P02 (sign electronically, confirm) and
      amendments to UC-P01, UC-A17 (second attestation), UC-A69 (enable
      conditions), a UC for the Mandatskopie export; `docs/erm-master.md`
      draft of `mandate_signatures` and the two columns; a row in the Art. 13
      notice list for proof data (owner, frgs-website). *Verified by*:
      `DocumentationIndexTest` green.
- [ ] **M1 — Record, sealing and the signing request.** Migration for the
      table and columns (+ rollback). `signature` object on
      `POST /api/public/registrations`: typed-name match, both declarations,
      adult and own-account checks, `document_unavailable` refusal. Canonical
      JSON builder with a pinned hash fixture; sealed document container reused
      from ADR-0049; confirmation token minted and hashed. The DK mandate text
      constant with a unit test pinning both languages. *Verified by*: unit
      tests for canonicalisation (byte-stable across PHP versions), gating,
      name normalisation; feature test that the sealed document opens to the
      same SHA-256 the record carries and that no plaintext IBAN appears in any
      column; API spec for every refusal reason.
- [ ] **M2 — Enable conditions and settings.** `bank_confirmation_missing`,
      `membership_declaration_missing`, `creditor_config_incomplete` refused by
      name; `[ADMIN]` fields on the Security & Credentials tab; restore-path
      fail-closed in the service. *Verified by*: API spec asserting the exact
      settings key set (the guarantee is about what is absent), component tests
      for the switch gate, `RouteRoleMap` entries and the completeness test.
- [ ] **M3 — Confirmation mail and endpoint.** `MailKind::REGISTRATION_SIGNATURE_CONFIRM`,
      `MailSubject::REGISTRATION`, the `MailRequestDto` invariant extended,
      `mail_outbox.kind` migration, builder and strings in both languages,
      `POST /api/public/registrations/confirm-signature` with uniform refusal,
      `/register/confirm` page, outbox scrub on reject/purge and re-key at
      approval. *Verified by*: `MailKindTest` exhaustiveness; a `mail-registration`
      ordered project reading the delivered message from Mailpit (Pattern 010),
      clicking the link from the mail body, and asserting the record's
      `confirmed_at`; the hash in the mail equals the stored one; a rejected
      registration leaves no address in the outbox.
- [ ] **M4 — Approval with the record.** `signature_record_reviewed`
      attestation; `signed_at` from the record in club time; refusals for an
      unconfirmed signature and for the wrong attestation shape; re-pointing
      the signature row inside the transaction; `signing_method` on the mandate
      and in the audit payload. Review drawer states and the "what they signed"
      view. *Verified by*: `RegistrationApprovalTest` extended (row re-pointed,
      pending row gone, ciphertext untouched); component tests for both
      attestation shapes; `registrations-inbox.spec.ts` for the badge and drawer.
- [ ] **M5 — Mandatskopie export.** Key-less evidence sheet and the keyed full
      copy behind step-up; `MANDATE_COPY_EXPORTED` audit with masked IBAN;
      pending-state variant. *Verified by*: unit tests rendering the sheet from a
      fixture record; feature test opening the sealed document with the test
      private key and asserting page count and the appended sheet; API spec that
      the key-less variant never contains the IBAN and the keyed one does.
- [ ] **M6 — Public page and E2E flow.** The choice on the review screen,
      declaration texts on screen, typed-name control, the confirmation screen's
      "not yet a member" wording; the full flow in two browser contexts
      (applicant's phone without an admin cookie, Kassenwart in the panel):
      submit → mail → confirm → approve → terminal sync sees the member, and the
      same assertions one step earlier proving it did not. Privacy assertions:
      no plaintext IBAN in any JSON the panel receives or in the database, the
      declaration hash in the mail equals the one in the record. *Verified by*:
      `register` project spec for the screens; `admin-chromium` flow spec;
      backend Unit and both CI lanes green.
- [ ] **M7 — Optional time stamp and retention.** `TSA_URL` config, RFC 3161
      request/response encoding, best-effort at submission, cron backfill ahead
      of the drain; retention classification in `docs/erm-master.md` and the
      anonymisation feature test proving the row survives erasure and is deleted
      with the mandate. *Verified by*: unit tests for the DER encoding against a
      recorded fixture; a test TSA stub; `SecuritySelfCheck` finding for a
      configured TSA that stops answering.

## Testable acceptance, epic-wide

| # | Assertion | Where |
|---|---|---|
| 1 | A paper submission queues no mail; an electronic one queues exactly one | M3 |
| 2 | No row in `mandate_signatures` yields a readable IBAN without the private key | M1 |
| 3 | The sealed document opens to bytes whose SHA-256 equals `document_sha256` | M1, M5 |
| 4 | The declaration hash in the delivered mail equals `declaration_sha256` | M3, M6 |
| 5 | A minor's or a third-party-holder's submission is never offered the electronic path, and a `signature` object on such a submission is refused | M1, M6 |
| 6 | An unconfirmed signature cannot be approved with the electronic attestation | M4 |
| 7 | `signed_at` on the mandate is the club-time calendar day of the record's `signed_at` | M4 |
| 8 | Electronic signing cannot be enabled without the bank confirmation, the declaration text and the creditor config, and each refusal names itself | M2 |
| 9 | The key-less copy never contains the IBAN; the keyed copy does, and the export is audited with the masked IBAN | M5 |
| 10 | The signature row survives member anonymisation and is deleted with the mandate | M7 |
| 11 | A Getränkewart is refused on every route in this feature | M2, M4, M5 |
| 12 | The record's canonical bytes hash identically on PHP 8.3 and 8.4 | M1 |
