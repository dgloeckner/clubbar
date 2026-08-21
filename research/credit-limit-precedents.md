# Research: existing precedents for credit limits and balance semantics

Resolves [#50](https://github.com/dgloeckner/clubbar/issues/50), part of wayfinder map [#49](https://github.com/dgloeckner/clubbar/issues/49).
Informs [#24 (credit limit not enforced)](https://github.com/dgloeckner/clubbar/issues/24) and [#28 (balance color semantics)](https://github.com/dgloeckner/clubbar/issues/28).

All citations are against the repo at commit `aa31186` unless noted otherwise.

---

## 1. Do ADRs, use cases, or data-model docs define credit limits?

**Direct answer: Yes — the terminal use cases already specify the limit behavior in detail: a single global maximum balance, configured in the backend and synced to the terminal, with warn-on-add-to-cart and hard-block-on-checkout. No ADR and no data-model doc defines a limit, no per-member limit exists anywhere, and no concrete threshold value is specified (all messages use `€XX.XX` placeholders).**

### Evidence

**UC-T12 "E2: Maximum Balance Exceeded" is the primary spec** (`use-cases/terminal/UC-T12-error-scenarios.md:54-105`):

- Line 62: "Maximum balance configured in backend (synced to terminal)" — global, backend-owned config, not per-member.
- Lines 77-80, two distinct check points:

  | Action | Check | Result if Exceeded |
  |--------|-------|-------------------|
  | Add to cart | Preview balance vs max | **Warning shown, item still added** |
  | Tap "Buy" | Final balance vs max | **Checkout blocked** |

- Lines 82-91: warning display — "Yellow/orange warning banner", warning icon, message "Balance limit reached. Remove items to continue.", plus current balance / cart total / "Maximum allowed: €XX.XX" breakdown.
- Lines 93-99: checkout blocked display — Buy button disabled (grayed out), tooltip "Balance would exceed limit", message "Cannot complete purchase. Balance would exceed €XX.XX limit."
- Line 312: error catalog entry `E2 | Balance Limit`.

**Cross-references in other terminal use cases:**

- `use-cases/terminal/UC-T01-book-product-to-tab.md:159-164` — "E4: Balance Limit Exceeded": warning banner in cart view, Buy disabled, member must remove items.
- `use-cases/terminal/UC-T11-shopping-cart.md:42-45` — cart view elements include "Balance limit | Maximum allowed balance (from config)", "Limit warning | Shown if new balance would exceed limit", "Buy button | ... (disabled if over limit)"; lines 121-126 repeat the E3 error case.

**Where limits are NOT defined:**

- `adr/` — grep for limit/credit/deckel/debt/schulden finds no ADR about credit limits. The only debt-adjacent ADR is `adr/0020-sepa-mandate-requirement-terminal-access.md:20` ("Members without SEPA data can accumulate debt that cannot be collected"), which gates terminal access on SEPA validity but sets no amount cap.
- `docs/erm-master.md` / `docs/erm-frontend.md` — no limit column on any table. `docs/erm-master.md:334-335` documents `write_off` / `goodwill` settlement item types (debt written off / balance cleared), confirming debt accumulation is expected and handled at settlement time.
- Admin use cases (`use-cases/admin/`) — no use case for configuring a maximum balance; `UC-A60-edit-organization.md` covers only SEPA creditor data.
- No threshold value or soft-warn percentage (e.g. 80 %) appears anywhere in specs; the 80 % idea exists only as a suggestion in issue #24's body.

---

## 2. Does the backend/API already carry a per-member limit field?

**Direct answer: No. Neither API spec, nor the backend members table, nor the terminal sync payload carries any limit field — and there is no backend config/settings transport for a global limit either, despite UC-T12 requiring "configured in backend (synced to terminal)". The unused constant `balanceLimitCents` in `terminal-frontend/lib/config/app_config.dart:17` is the single trace of a limit in the entire implementation.**

### Evidence

- **Terminal API member schema** (`api/terminal.yaml:621-693`, `Member`): properties are `id`, `card_uid`, `first_name`, `last_name`, `preferred_language`, `is_active`, `is_sepa_valid`, `deleted_at`, `created_at`, `updated_at`. No balance field, no limit field. The schema description (lines 623-626) explicitly says the terminal caches "only non-sensitive data". *(That claim was retired on 2026-08-20 by [ADR-0045](../adr/0045-age-restricted-products.md): the payload now carries `date_of_birth`, and `api/terminal.yaml` says so. Quoted here as it stood when this note was written.)*
- **Terminal sync payload for balances**: balances travel only in the transaction-sync response `member_balances` map (`api/terminal.yaml:1160-1173`), per ADR-0023. No limit accompanies them.
- **Admin API** (`api/admin.yaml`): member schema has `balance_cents` ("Current outstanding balance in cents", line 2892) and a `balance` filter enum `[all, with_balance, zero_balance]` (lines 276-281). Every other `limit` hit is pagination. No limit field on create/update member schemas.
- **Backend DB**: `backend/db/migrations/001_initial_schema.sql:39-61` (`members` table) has no limit and no balance column (balance is computed from transactions). No settings/config table exists in any migration (`001`-`006`) that could hold a global limit; the only config table pattern in the project is `sepa_config` (ADR-0007).
- **No config sync endpoint**: `api/terminal.yaml` has sync endpoints for members/categories/products/transactions only — nothing that could deliver a backend-configured limit to the terminal.
- **The only trace**: `terminal-frontend/lib/config/app_config.dart:16-17`:
  ```dart
  // Balance Limit (€100.00 = 10000 cents; configurable from backend later)
  static const int balanceLimitCents = 10000; // €100.00
  ```
  A repo-wide grep for `balanceLimit|balance_limit|credit_limit|creditLimit` (dart/php/yaml/md/ts/sql) returns exactly this one line — it is declared and never referenced, matching issue #24's root-cause analysis.

---

## 3. Is the balance sign convention documented?

**Direct answer: Yes — positive = debt (member owes the club) is documented explicitly in the terminal API spec, and consistently implied by ADR-0023, both ERM docs, the admin API's "outstanding balance" wording, and the CONTEXT.md glossary (on branch `fix/13-session-lifecycle`). The terminal's de-facto computation follows the same convention: `memberDeckel` = synced positive-debt balance + unsynced local charges.**

### Evidence

**Explicit documentation:**

- `api/terminal.yaml:1164` — `member_balances` value description: **"Balance in cents (positive = owes, negative = credit)"**. This is the clearest normative statement in the repo.
- `adr/0023-terminal-balance-state-management.md:13` — "Current balance: Sum of all unsettled transactions (what member owes)".
- `docs/erm-master.md:276` and `docs/erm-frontend.md:160` — `amount_cents`: "positive = charge; negative = credit/reversal" (transaction-level sign convention, from which the balance convention follows).
- `api/admin.yaml:2892` — member `balance_cents`: "Current **outstanding** balance in cents"; dashboard `outstanding_balance_cents` "Total unsettled transaction balance in cents" (line 4219).
- `CONTEXT.md` glossary — **exists only on branch `fix/13-session-lifecycle` (commit `0fb367f`), not yet on `main`** — defines: "**Deckel**: A member's running tab — the sum of unsettled transactions the member owes the club. ... _Avoid_: balance (ambiguous with projected balance), account". This both fixes the sign (owed amount, i.e. positive = debt) and deprecates the word "balance".

**De-facto terminal implementation** (consistent with the docs):

- `terminal-frontend/lib/services/members_service.dart:61-67` — `getEffectiveBalance` = `member.balanceCents + unsyncedAmount` (cached backend positive-debt balance plus unsynced local charge amounts). This value is exposed as `memberDeckel` (`terminal-frontend/lib/providers/members_provider.dart:16-33`).
- `terminal-frontend/lib/screens/shopping_cart_screen.dart:69-71` — projected balance = `currentDeckel + cartTotal`: buying *increases* the number, confirming positive = debt.
- Local fallback: `docs/erm-frontend.md:291` — "Local balance display | `SUM(amount_cents)` from `transactions_local`" (sum of positive charges = debt).

**Gap**: the terminal UI never labels the sign ("you owe" vs "credit") — it just prints "Deckel: €X.XX" / "Balance: €X.XX" (`terminal-frontend/lib/widgets/app_header.dart:72`, `member_bar.dart:94`), which is what issue #28 flags.

---

## 4. Are there existing color/threshold conventions worth aligning with?

**Direct answer: Yes, three conventions exist — semantic color tokens in `design_tokens.dart` (green success / orange warning / red danger), a documented transaction-amount color rule in UC-T02 (charge = red, correction/credit = green), and UC-T12's "yellow/orange warning banner" for the limit warning. There is no documented rule for coloring the *balance* itself, and the current widget code contradicts both the sign convention and each other (issue #28's inventory is accurate).**

### Evidence

**Tokens** (`terminal-frontend/lib/utils/design_tokens.dart:15-19`):

```dart
static const String semanticSuccess = '#22c55e';  // Green - success
static const String semanticWarning = '#f97316';  // Orange - warnings
static const String semanticDanger = '#ef4444';   // Red - errors
```

No balance-specific token or threshold exists. The coral `#FF6B4A` used for balances in two widgets is not a token at all.

**Documented conventions in specs:**

- `use-cases/terminal/UC-T02-view-tab-balance.md:67-70` — transaction amount colors: "Purchase | ... | **Red/negative**", "Correction | ... | **Green/positive**". I.e. charges are red, credits are green.
- `use-cases/terminal/UC-T12-error-scenarios.md:86` — limit warning banner: "**Yellow/orange** warning banner" — matches the existing `semanticWarning` orange `#f97316`.

**Current (contradictory) widget behavior** — confirms issue #28's inventory:

| Location | Rule |
|---|---|
| `lib/widgets/member_bar.dart:94-97`, `lib/widgets/app_header.dart:72-77` | Always coral orange `#FF6B4A`, regardless of value |
| `lib/screens/shopping_cart_screen.dart:319-322` | Projected "New Balance" always green `#22c55e`, even when it is debt |
| `lib/screens/checkout_confirmation_screen.dart:130-137`, `lib/widgets/member_details_modal.dart:546-554`, `lib/widgets/styled_components/member_info_card.dart:19-24` | positive → green, negative → orange — **inverted** given positive = debt |
| `lib/widgets/member_details_modal.dart:479-481` | Transaction amounts: positive (charge) → `#FF6B4A`, negative (credit) → green — the **only** code matching the documented UC-T02 convention |

**Thresholds**: none exist in code or design docs — no amber-above-X rule, no percent-of-limit trigger anywhere.

---

## Implications for #24 and #28

### Already constrained (decisions are made — implement, don't re-decide)

1. **Sign convention is fixed: positive = debt.** `api/terminal.yaml:1164`, ADR-0023, both ERM docs, and the CONTEXT.md glossary all agree. #28 must not treat a positive Deckel as "good"; the current green-for-positive coloring in three widgets is wrong under the documented semantics.
2. **Limit behavior is specified: global limit, warn on add-to-cart, hard-block at checkout.** UC-T12 E2 (with UC-T01 E4 and UC-T11 E3) already answers #24's "block vs warn" question: *both*, at different points — adding to cart over the limit shows a warning banner but still adds the item; tapping Buy is blocked (disabled button + tooltip + message). #24 should implement this spec rather than invent behavior.
3. **Warning presentation is specified**: yellow/orange banner + warning icon + the exact message set with current balance / cart total / maximum breakdown (UC-T12:82-99). This aligns with the existing `semanticWarning` `#f97316` token — #28's amber-near-limit direction is consistent with spec.
4. **Transaction-amount coloring is documented** (UC-T02: charge = red, credit = green) and already correctly implemented in `member_details_modal.dart:479-481` — that polarity is the one to keep and tokenize; the balance coloring should be made consistent with it (debt should not render green).
5. **Limit ownership**: UC-T12:62 says the maximum is "configured in backend (synced to terminal)". A terminal-local constant is at best an interim stopgap; the target state requires a backend config value plus a sync path.

### Free to choose (no precedent constrains these)

1. **The threshold value.** No spec names a number; €100.00 exists only as the never-referenced `balanceLimitCents` default. Any value (and whether it is admin-editable) is open.
2. **Per-member limits.** Nothing anywhere defines them. Adding one would require member-schema changes in both API specs, a members-table migration, sync-payload changes, and — per CLAUDE.md rules — explicit user confirmation for the data-model change plus likely a new ADR. The spec'd global limit does not need any of that.
3. **Backend transport for the global limit.** No settings table or config-sync endpoint exists; the design (key-value settings table à la the ADR-0007 alternatives vs. a dedicated field, and which endpoint carries it to the terminal) is unconstrained.
4. **Soft-warn threshold below the limit** (e.g. amber at 80 %). Not in any spec — free product/design choice, but should be encoded next to the limit check so #24 and #28 stay aligned.
5. **Neutral color for zero/small balances** and the exact label wording ("Open tab" / "Offener Betrag" vs "Credit" / "Guthaben"). Unspecified; note CONTEXT.md deprecates the bare word "balance" in favor of "Deckel", which supports #28's relabeling suggestion.
