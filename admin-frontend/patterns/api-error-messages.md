# API Error Messages Pattern

**Purpose**: Show an admin why an action failed, in the language they are
reading the panel in.

**Introduced by**: Issue #757
**Related**: [Data Fetching](./data-fetching.md), backend
[Pattern 019](../../backend/patterns/pattern-019-translatable-refusals.md)

---

## The rule

**Never render `err.response.data.message`.** It is written by the backend, in
English, for its log and for a raw API caller. The panel is German unless the
admin changed it, so rendering that string puts the one sentence explaining the
failure in the one language they may not read.

Use the hook instead:

```typescript
const { t } = useTranslation()
const { apiErrorMessage } = useApiError()

try {
  await getMembersFactory().anonymizeMember(member.id, {})
} catch (err) {
  setError(apiErrorMessage(err, t('members.errors.cannotAnonymize')))
}
```

`apiErrorMessage(err, fallback)` resolves, in order:

1. the `reason` code the backend sent → `errors.reasons.<reason>` from the
   panel's own locale file, with `params` interpolated;
2. the per-field `messages` map, which names the input that was rejected;
3. the caller's `fallback` — already translated, because the caller passed
   `t(…)`.

It never falls back to the backend's English sentence. A refusal with no
wording in this build says something generic rather than something foreign, and
`reasons.test.ts` makes shipping one impossible.

## What the backend sends

```json
{
  "error": "business_rule_violation",
  "message": "Cannot anonymize: outstanding balance of €7.50",
  "reason": "member_balance_outstanding",
  "params": { "balance_cents": 750 }
}
```

Params are values, never prose, and the hook localises them before
interpolating:

| Param name | Becomes |
|------------|---------|
| `balance_cents: 750` | `{{balance}}` → `7,50 €` (de) / `€7.50` (en); `{{balance_cents}}` still holds 750 for pluralisation |
| `execution_date: "2026-08-01"`, `submitted_on: …` | replaced in place with `01.08.2026` — a sentence never wants an ISO date |

So a locale string interpolates `{{balance}}`, never `{{balance_cents}}`.

## A code inside a 200

A settlement's gates report *why a button is disabled* on an ordinary success
response — `cancellation_blocked_code`, `reversal_blocked_code`, with matching
`_params`. Same wording, different entry point:

```typescript
const { reasonText } = useApiError()

{reasonText(decision.blockedCode, decision.blockedParams, t('settlements.undoBlockedFallback'))}
```

## Dependency arrays

`apiErrorMessage` and `reasonText` are `useCallback`s keyed on the language, so
they are stable across renders and change when the admin switches language.
**List them in the dependency array** of any effect or callback that uses them —
the lint rule asks for it and the identity is stable, so it costs nothing.

The reason they are keyed on the *language string* rather than on
`useFormatters()` is worth knowing: that hook returns a fresh object every
render, so closing over its functions made the helpers change identity on every
render, and every effect listing one re-ran forever — aborting its own fetch
each time and leaving the list empty. `useApiError.test.ts` guards it.

## Adding a new refusal

1. Add the case to `backend/src/Shared/Exceptions/BusinessRuleReason.php`.
2. Add `errors.reasons.<value>` to **both** `public/locales/de.json` and
   `public/locales/en.json`.
3. Nothing else — `reasons.test.ts` fails the unit suite if you skip step 2.

## Anti-patterns

```typescript
// ✗ The backend's English on a German screen
setError(err.response?.data?.message ?? t('members.errors.cannotAnonymize'))

// ✗ A hardcoded English string as the fallback
setFormError(err.message || `Failed to ${modalMode} category`)

// ✗ Reading the reason yourself and switching on it
if (data.reason === 'member_balance_outstanding') setError('Saldo offen')

// ✓
setError(apiErrorMessage(err, t('members.errors.cannotAnonymize')))
```
