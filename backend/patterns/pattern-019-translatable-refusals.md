# Pattern 019: Translatable Refusals — the Reason Travels, Not the Sentence

**Category**: Exception Handling / Internationalization
**Status**: ✅ Required
**Introduced by**: Issue #757
**Related**: Pattern 007 (Centralized Exception Handling), Pattern 018 (Custom Domain Exceptions), Pattern 002 (Enums)

---

## Problem

An admin trying to erase a member with an open tab was told:

> Cannot anonymize: outstanding balance of €7.50

That sentence is written in `MembersService`, in English, and was rendered
verbatim by the admin panel — which runs in German unless the admin changed it.
So the one line on the screen explaining why the action failed was the one line
they could not read, with the amount formatted the English way on top of it.

The panel could not do better, because a refusal carried nothing but prose:

```json
{ "error": "business_rule_violation", "message": "Cannot anonymize: outstanding balance of €7.50" }
```

`business_rule_violation` is the same code for all forty refusals in the
backend, so the client's only alternative was a generic "Cannot anonymize
member" — translated, and silent about the one thing the admin needed.

This is not a missing-translation bug. The string was never in a locale file to
be missed, and adding a fortieth `if (message.includes(…))` in TypeScript would
recreate Pattern 018's message-parsing anti-pattern on the other side of the
wire.

---

## Solution

**A refusal names a reason. The reason is translated by whoever displays it.**

```php
throw new BusinessRuleException(
    BusinessRuleReason::MEMBER_BALANCE_OUTSTANDING,
    "Cannot anonymize: outstanding balance of {$sign}€{$balanceEur}",
    ['balance_cents' => $balanceCents],
);
```

`ErrorHandler` puts all three in the response:

```json
{
  "error": "business_rule_violation",
  "message": "Cannot anonymize: outstanding balance of €7.50",
  "reason": "member_balance_outstanding",
  "params": { "balance_cents": 750 }
}
```

| Field | Audience | Language |
|-------|----------|----------|
| `message` | the application log, a raw API caller | English, always |
| `reason` | the client's locale files | none — it is a code |
| `params` | the client's formatter | none — they are values |

The admin panel renders `errors.reasons.member_balance_outstanding` and formats
750 cents as `7,50 €` or `€7.50` depending on who is reading. The English
sentence is unchanged, so nothing that already asserted on `message` moved.

---

## Rules

1. **Every `BusinessRuleException` names a `BusinessRuleReason`.** The
   constructor requires it — a refusal cannot reach a screen unclassified
   without someone deliberately deleting the argument.
2. **Add the locale strings with the enum case.** `reasons.test.ts` reads
   `BusinessRuleReason.php` directly and fails when `errors.reasons.<value>` is
   missing from `de.json` or `en.json`. A new refusal cannot ship untranslated.
3. **Params are values, never prose.** Integer cents, ids, counts, ISO dates.
   An English fragment interpolated into a German sentence is the same bug in a
   smaller box — split it into two reasons instead (`SEPA_EXPORT_NOTHING_OWED`
   vs `SEPA_EXPORT_EVERY_MEMBER_EXCLUDED` is exactly that split).
4. **Money is integer cents under a `*_cents` key.** The client formats it in
   the reader's locale; a pre-formatted `€7.50` reintroduces the original bug.
   The sign is part of the value — a credit is not the same news as a debt.
5. **A reason value is API contract once released.** Retire a case; never
   rename one.
6. **A gate that both refuses and explains returns a `BlockedReason`.**
   `CancellationGate` and `ReversalGate` answer the service (which throws
   `$blocker->toException()`) and the DTO (which serialises
   `*_blocked_reason`, `*_blocked_code`, `*_blocked_params`) from one object,
   so the disabled-button hint and the 409 cannot drift apart.

---

## Anti-patterns

```php
// ✗ A sentence the client can only show or discard
throw new BusinessRuleException('Cannot anonymize: outstanding balance of €7.50');

// ✗ Pre-formatted money — reads "€7.50" inside a German sentence
throw new BusinessRuleException($reason, $message, ['balance' => '€7.50']);

// ✗ An English clause smuggled through as a value
throw new BusinessRuleException($reason, $message, ['detail' => 'every member owes 0.00 EUR']);
```

```typescript
// ✗ Parsing the sentence — Pattern 018's anti-pattern, moved to TypeScript
if (err.response.data.message.includes('outstanding balance')) { … }

// ✗ Rendering the backend's English on a German panel
setError(err.response?.data?.message ?? t('members.errors.cannotAnonymize'))

// ✓
setError(apiErrorMessage(err, t('members.errors.cannotAnonymize')))
```

---

## Where it does not apply

`ValidationException` reports per field through `messages`, which the client
already renders as-is; those strings are the `Validator`'s and are a separate
surface. Authentication failures have their own code→key map in
`admin-frontend/src/utils/authErrors.ts`, predating this pattern.

---

## Tests

- `backend/tests/Unit/Shared/Exceptions/BusinessRuleReasonTest.php` — the
  contract, plus a sweep proving every throw site in `src/` names a reason
- `backend/tests/Unit/Shared/Middleware/ErrorHandlerTest.php` — what the
  envelope carries, and that only a 409 carries a reason
- `admin-frontend/src/i18n/reasons.test.ts` — every enum case has wording in
  both languages, and no wording interpolates raw cents
- `admin-frontend/src/hooks/useApiError.test.ts` — the rendered sentence
- `e2etests/tests/admin/members-anonymize-i18n.spec.ts` — the reported bug,
  end to end
