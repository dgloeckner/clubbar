# Settlement UX Improvements

**Date:** 2026-02-25
**Status:** Design approved — ready for implementation

## Problem Statement

Two UX issues in the Journal page settlement flows:

1. **"Abrechnung (alle)" uses native `confirm()` dialog** — unstyled, can't show meaningful context (member count, total amount).
2. **No feedback after settlement creation** — user doesn't know it succeeded and has no path to the newly created settlement on the Settlements page.

The same issues apply to "Abrechnung (Auswahl)" for consistency.

---

## Design

### Section 1: New `SettlementConfirmModal` component

**File:** `admin-frontend/src/components/modals/SettlementConfirmModal.tsx`

Follows the existing modal pattern from `CreateAdminModal`.

**Props:**
- `isOpen: boolean`
- `transactions: GlobalTransaction[]`
- `onConfirm: () => void`
- `onCancel: () => void`
- `isLoading: boolean`

**Derives from `transactions` (no extra API call):**
- Transaction count: `transactions.length`
- Unique member count: `new Set(transactions.map(t => t.member_id)).size`
- Total amount in cents: `transactions.reduce((sum, t) => sum + t.amount_cents, 0)` → formatted as EUR
- Settlement date: today (`YYYY-MM-DD`)
- Execution date: today + 7 days

**Renders:**
- Title (translated)
- 5-item summary list: transaction count, member count, total amount, settlement date, execution date
- Two buttons: "Abbrechen" + "Abrechnung erstellen" (disabled + loading indicator while `isLoading`)
- Backdrop click closes modal (cancels)

All labels go through `t()`.

---

### Section 2: Changes to `JournalPage.tsx`

**New state:**
```ts
const [confirmModalOpen, setConfirmModalOpen] = useState(false)
const [pendingTransactions, setPendingTransactions] = useState<GlobalTransaction[]>([])
```

**Modified `handleSettleAll`** — no longer calls `confirm()` or the API:
1. Filters unsettled transactions from current view
2. If count is 0 → sets inline error (no `alert()`)
3. Otherwise → sets `pendingTransactions` + sets `confirmModalOpen = true`

**Modified `handleConcludeSettlement`** (Abrechnung Auswahl):
1. If no transactions selected → sets inline error (no `alert()`)
2. Otherwise → collects selected transactions from state, sets `pendingTransactions` + opens modal

**New `handleConfirmSettlement`** — called when user confirms in the modal:
1. Calculates settlement date (today) and execution date (+7 days)
2. Calls `createSettlement(pendingTransactions.map(t => t.id), settlementDate, executionDate)`
3. On success: closes modal, clears `pendingTransactions` + selections, navigates to `/settlements` via `useNavigate()`
4. On error: sets error state (modal stays open, shows error message)

**Modal rendered** at the bottom of JSX alongside other modals.

---

### Section 3: i18n strings

New keys added under `journal.settlementConfirm` in both `de.json` and `en.json`:

| Key | German | English |
|-----|--------|---------|
| `journal.settlementConfirm.title` | Abrechnung erstellen | Create Settlement |
| `journal.settlementConfirm.transactions` | Transaktionen | Transactions |
| `journal.settlementConfirm.members` | Mitglieder | Members |
| `journal.settlementConfirm.totalAmount` | Betrag | Total amount |
| `journal.settlementConfirm.settlementDate` | Buchungsdatum | Settlement date |
| `journal.settlementConfirm.executionDate` | Ausführungsdatum | Execution date |
| `journal.settlementConfirm.confirm` | Abrechnung erstellen | Create settlement |
| `journal.settlementConfirm.cancel` | Abbrechen | Cancel |
| `journal.settlementNoOpen` | Keine offenen Transaktionen | No open transactions |

---

## Files to change

| File | Change |
|------|--------|
| `admin-frontend/src/components/modals/SettlementConfirmModal.tsx` | **Create** — new modal component |
| `admin-frontend/src/pages/JournalPage.tsx` | **Modify** — replace `confirm()`/`alert()`, add modal state, add `handleConfirmSettlement`, add `useNavigate` |
| `admin-frontend/public/locales/de.json` | **Modify** — add `journal.settlementConfirm.*` keys |
| `admin-frontend/public/locales/en.json` | **Modify** — add `journal.settlementConfirm.*` keys |

---

## Acceptance criteria

- [ ] Clicking "Abrechnung (alle)" opens a styled modal (not browser `confirm()`)
- [ ] Modal shows: transaction count, unique member count, total EUR, settlement date, execution date
- [ ] Clicking "Abbrechen" dismisses the modal without any API call
- [ ] Clicking "Abrechnung erstellen" in modal creates the settlement and redirects to `/settlements`
- [ ] "Abrechnung (Auswahl)" flow has identical modal treatment
- [ ] No `confirm()` or `alert()` calls remain in settlement flows
- [ ] All modal labels are translatable (de + en)
- [ ] Error during creation is shown inside the modal (modal stays open)
