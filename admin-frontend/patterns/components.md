# Admin Frontend Component Patterns

This document describes the reusable UI components available for building admin frontend pages and features.

## Component Library

### Common Components

#### Avatar Component

Displays user avatar with gradient background and initials.

**File**: `src/components/common/Avatar.tsx`

**Props**:
- `name` (string, required): User name (extracts initials)
- `variant` ('blue' | 'green' | 'orange' | 'pink' | 'gray', default: 'blue'): Gradient color scheme
- `size` ('sm' | 'md' | 'lg', default: 'md'): Avatar size
- `inactive` (boolean, default: false): Shows grayscale + opacity 0.5
- `testId` (string, optional): Test ID for E2E testing

**Example**:
```typescript
import { Avatar } from '@/components/common/Avatar'

export function UserRow({ name, isActive }) {
  return (
    <div>
      <Avatar
        name={name}
        variant="blue"
        size="md"
        inactive={!isActive}
        testId={`avatar-${name}`}
      />
    </div>
  )
}
```

**Color Variants**:
- `blue`: Gradient from #3b82f6 to #8b5cf6 (blue-purple)
- `green`: Gradient from #22c55e to #10b981 (green)
- `orange`: Gradient from #f97316 to #fb923c (orange)
- `pink`: Gradient from #ec4899 to #f472b6 (pink)
- `gray`: Gradient from #64748b to #94a3b8 (neutral, for inactive states)

---

#### Badge Component

Status badge with colored dot indicator.

**File**: `src/components/common/Badge.tsx`

**Props**:
- `label` (string, required): Badge text
- `variant` ('success' | 'warning' | 'danger' | 'info' | 'neutral', default: 'neutral'): Color variant
- `showDot` (boolean, default: true): Show colored dot indicator
- `testId` (string, optional): Test ID for E2E testing

**Example**:
```typescript
import { Badge } from '@/components/common/Badge'

export function StatusCell({ isActive }) {
  return (
    <Badge
      label={isActive ? 'Active' : 'Inactive'}
      variant={isActive ? 'success' : 'neutral'}
      showDot={true}
      testId="status-badge"
    />
  )
}
```

**Variants**:
- `success`: Green (#22c55e)
- `warning`: Orange (#f97316)
- `danger`: Red (#ef4444)
- `info`: Blue (#3b82f6)
- `neutral`: Gray (#64748b)

---

#### Tooltip Component

Displays tooltip on hover with configurable position.

**File**: `src/components/common/Tooltip.tsx`

**Props**:
- `content` (string, required): Tooltip text
- `position` ('top' | 'bottom' | 'left' | 'right', default: 'top'): Tooltip position
- `children` (ReactNode, required): Element to attach tooltip to
- `testId` (string, optional): Test ID for E2E testing

**Example**:
```typescript
import { Tooltip } from '@/components/common/Tooltip'

export function ActionButton() {
  return (
    <Tooltip content="Edit this user" position="top">
      <button onClick={handleEdit}>✎ Edit</button>
    </Tooltip>
  )
}
```

---

#### ActionMenu Component

Dropdown menu with action items (3-dot menu).

**File**: `src/components/common/ActionMenu.tsx`

**Props**:
- `items` (ActionMenuItem[], required): Array of menu items
- `testId` (string, optional): Test ID for E2E testing

**ActionMenuItem**:
- `label` (string): Menu item text
- `icon` (ReactNode, optional): Icon to display
- `onClick` (() => void): Handler function
- `variant` ('default' | 'danger', default: 'default'): Styling (red text for danger)

**Example**:
```typescript
import { ActionMenu } from '@/components/common/ActionMenu'

export function AdminRow({ admin }) {
  return (
    <ActionMenu
      items={[
        {
          label: 'Deactivate',
          onClick: () => handleDeactivate(admin.id),
          variant: 'danger',
        },
        {
          label: 'Delete',
          onClick: () => handleDelete(admin.id),
          variant: 'danger',
        },
      ]}
      testId={`actions-${admin.id}`}
    />
  )
}
```

---

#### Toggle Component

Enable/disable switch for toggling item state.

**File**: `src/components/common/Toggle.tsx`

**Props**:
- `isEnabled` (boolean, required): Current toggle state
- `onChange` ((enabled: boolean) => void, required): Handler called when toggled
- `disabled` (boolean, default: false): Disable the toggle
- `testId` (string, optional): Test ID for E2E testing

**Colors**:
- Enabled: Green (#22c55e) with white thumb
- Disabled: Gray (rgba(71,85,105,0.3)) with white thumb
- Smooth transition 0.15s

**Example**:
```typescript
import { Toggle } from '@/components/common/Toggle'

export function AdminUserRow({ admin }) {
  return (
    <Toggle
      isEnabled={admin.is_active}
      onChange={(enabled) => {
        if (enabled) {
          handleReactivate(admin.id)
        } else {
          handleDeactivate(admin.id)
        }
      }}
      testId={`admin-toggle-${admin.id}`}
    />
  )
}
```

---

#### Alert Component

Alert message with icon and optional dismiss button.

**File**: `src/components/common/Alert.tsx`

**Props**:
- `variant` ('warning' | 'danger' | 'info' | 'success', default: 'warning'): Color variant
- `icon` (ReactNode, optional): Icon element
- `title` (string, optional): Alert title
- `message` (string, required): Alert message
- `dismissible` (boolean, default: false): Show dismiss button
- `testId` (string, optional): Test ID for E2E testing

**Example**:
```typescript
import { Alert } from '@/components/common/Alert'

export function SettingsPage() {
  return (
    <Alert
      variant="warning"
      icon="⚠️"
      title="Warning"
      message="This field is a legal identifier and cannot be changed."
      dismissible={false}
      testId="sepa-warning"
    />
  )
}
```

---

#### SecretBox Component

The value + copy button for a secret the user must capture before it becomes
unrecoverable. Use it directly when the secret belongs **inline** on a page;
use `SecretDisplayModal` when it belongs in a dialog (that component wraps this
one, so both behave identically).

**File**: `src/components/common/SecretBox.tsx`

**Rules** (issues #126, #386):
- The clipboard write is **awaited** (`useClipboardCopy`) — a rejected write
  (non-secure origin, unfocused document, denied permission) is reported, never
  mistaken for success.
- A failed copy selects the value so it can still be copied by keyboard.
- A new `secret` resets the previous copy verdict.
- Never present such a value as a caption. It gets its own block, at readable
  size, with the copy button — that was the #386 bug.

**Props**:
- `secret` (string, required): The value to display
- `testIdPrefix` (string, required): Prefix for this box's test IDs
- `valueTestId` (string, optional): Test ID of the value element; defaults to
  `${testIdPrefix}-display`. Use it to keep a pre-existing ID stable
- `actions` (ReactNode, optional): Extra buttons rendered next to the copy button
- `variant` ('primary' | 'secondary', default: 'primary'): Weight of the copy
  button. Use `secondary` when the surrounding form has its own primary action —
  two saturated buttons make the real one recede

**Test IDs** derived from the prefix: `-copy-button`, `-copy-status`,
`-copy-error`, plus the value element (`-display` unless overridden).

**Button labels** reuse the shared `settings.secret*` strings (`secretCopy`,
`secretCopyAgain`, `secretCopied`, `secretCopyFailed`) — they are not
settings-page-specific.

**Example** (TOTP enrollment, `src/pages/LoginPage.tsx`):
```typescript
import { SecretBox } from '@/components/common/SecretBox'

<SecretBox
  secret={secret}
  testIdPrefix="totp-setup-backup-key"
  valueTestId="totp-setup-secret"
  variant="secondary"
/>
```

---

#### SecretDisplayModal Component

Modal shell around `SecretBox` for a value the backend shows exactly once
(terminal API token, generated admin password). Such a value cannot be
recovered, so the modal is deliberately harder to dismiss than an ordinary one.

**File**: `src/components/modals/SecretDisplayModal.tsx`

**Rules** (issue #126):
- The value, the awaited clipboard write and its confirmed/failed verdict come
  from `SecretBox` (see above).
- A failed copy keeps the modal open and selects the value so it can be copied
  by hand.
- The backdrop is **inert**. Only the explicit "I have saved it" button closes
  the modal, because the parent clears the secret on close.

**Props**:
- `isOpen` (boolean, required): Whether the modal is shown
- `secret` (string | null, required): The one-time value; nothing renders without it
- `title` (string, required): Translated heading
- `warning` (string, required): Translated "shown only once" warning
- `testIdPrefix` (string, required): Prefix for the modal's test IDs
- `onClose` (() => void, required): Called only on explicit acknowledgement

**Test IDs** derived from the prefix: `-modal`, `-display`, `-copy-button`,
`-copy-status`, `-copy-error`, `-close-button`.

**Example**:
```typescript
import { SecretDisplayModal } from '@/components/modals/SecretDisplayModal'

export function TokenDisplayModal({ isOpen, token, onClose }: TokenDisplayModalProps) {
  const { t } = useTranslation()

  return (
    <SecretDisplayModal
      isOpen={isOpen}
      secret={token}
      title={t('settings.tokenGenerated')}
      warning={t('settings.tokenWarning')}
      testIdPrefix="settings-terminal-token"
      onClose={onClose}
    />
  )
}
```

---

#### ConfirmDialog Component

The one confirmation dialog. Replaces every native `confirm()`; owns the
`role="dialog"`, focus, Escape and backdrop handling so no caller reimplements
them.

**File**: `src/components/modals/ConfirmDialog.tsx`

**Props**:
- `isOpen` (boolean, required)
- `title` (string, optional): Heading
- `message` (ReactNode, required): Plain text for most callers; a node when the
  question needs figures or a checkbox of its own
- `confirmLabel` / `cancelLabel` (string, optional): Default to `common.confirm` / `common.cancel`
- `variant` ('danger' | 'primary', default: 'danger'): Confirm button colour
- `confirmDisabled` (boolean, default: false): The question is asked but cannot
  be answered yet — e.g. an unticked acknowledgement
- `showConfirm` (boolean, default: true): Set to `false` for a dialog that only
  explains why an action is unavailable. A permanently disabled confirm button
  is the dead control such a dialog exists to replace
- `onConfirm` / `onCancel` (() => void, required)

**Test IDs**: `confirm-dialog`, `confirm-dialog-content`, `confirm-dialog-title`,
`confirm-dialog-message`, `confirm-dialog-cancel`, `confirm-dialog-ok` (absent
when `showConfirm` is false).

---

#### UndoSettlementDialog Component

The confirmation shown before a settlement is undone. Composes `ConfirmDialog`;
the settlement it is given decides which of three shapes it takes (issue #127).

**File**: `src/components/modals/UndoSettlementDialog.tsx`

**Rules**:
- The question **names the run**: date, total, member count, transaction count.
  Undoing returns the transactions to the unsettled pool, so the next run
  collects them again — a confirmation true of every settlement alike cannot
  say what is at stake.
- A settlement whose SEPA file exists shows a warning and keeps the confirm
  button disabled until the admin ticks "this file was never submitted to the
  bank". The acknowledgement resets whenever the dialog reopens.
- A settlement the backend refuses to cancel (`is_cancellable === false`) shows
  the backend's `cancellation_blocked_reason` and **no confirm button**.
- Whether an undo is allowed is read from the API's `is_cancellable` /
  `cancellation_blocked_reason` — never re-derived in the client. The rule
  lives in the backend's `CancellationGate`; a second copy is how the button
  and the API drift apart.

**Props**:
- `settlement` (object | null, required): The settlement in question; `null` closes the dialog
- `onConfirm` / `onCancel` (() => void, required)

**Test IDs**: the surrounding `ConfirmDialog`'s, plus
`undo-settlement-detail-{date,amount,members,transactions}`,
`undo-settlement-blocked-reason`, `undo-settlement-export-warning`,
`undo-settlement-export-ack`.

---

#### MarkSubmittedDialog Component

The confirmation shown before a settlement is recorded as having reached the
bank (issue #377, ruling #142 §1). Composes `ConfirmDialog`.

**File**: `src/components/modals/MarkSubmittedDialog.tsx`

**Rules**:
- The question **names the run**: date, total, member count — the same figures
  `UndoSettlementDialog` states, for the same reason.
- It states the consequence the button cannot carry: after this the run can no
  longer be cancelled, and money that comes back comes back as a reversal.
- **No step-up credential**, unlike the SEPA export beside it: nothing is
  decrypted here and the write is a timestamp.
- The caller decides when to offer it, via `awaitsBankConfirmation()` — the
  dialog never re-derives eligibility.

**Props**:
- `settlement` (object | null, required): The settlement in question; `null` closes the dialog
- `onConfirm` / `onCancel` (() => void, required)

**Test IDs**: the surrounding `ConfirmDialog`'s, plus
`mark-submitted-detail-{date,amount,members}` and `mark-submitted-warning`.

---

#### SettlementStatusBadge Component

The badge naming where a settlement stands, for both the table and the mobile
card (issue #377).

**File**: `src/components/common/SettlementStatusBadge.tsx`

**Rules**:
- Renders the server's `status` — one of `draft`, `exported`, `submitted`,
  `partly_reversed`, `fully_reversed`, `cancelled`. **Never derived in the
  client**: the page used to collapse those six into three from `is_cancelled`
  and `exported_at`, which made a run that had gone to the bank look exactly
  like one whose file had merely been generated.
- The label comes from the locale, keyed on `status` via
  `settlementStatusLabelKey()`. The API's `status_label` is English and is used
  only for a status this build has never heard of.
- An exported direct debit additionally carries an **awaiting-confirmation**
  marker, because that is the one state the system cannot tell apart from a
  forgotten "mark as submitted" click (ruling #142 §2).
- One component for both layouts on purpose: the card and the table each used
  to own a vocabulary, and the card's was smaller.

**Props**:
- `settlement` (object, required): anything carrying `status`, `status_label`, `method`
- `testId` (string, required): the badge's test ID; the marker gets `${testId}-awaiting`
- `compact` (boolean, default false): tighter type and padding, for the mobile card

**Helpers** (`src/utils/settlementStatus.ts`): `settlementStatus()`,
`settlementStatusColor()`, `settlementStatusLabelKey()`,
`awaitsBankConfirmation()`, `canExportSepa()`.

---

### Form Components

#### DateField Component

The date control for every date input in the panel — `<input type="date">` is
not used anywhere (see [Date Field Pattern](./date-field.md) for the reasoning
and the full test-ID list).

**File**: `src/components/forms/DateField.tsx` (calendar:
`src/components/forms/DateCalendar.tsx`, logic: `src/utils/dateField.ts`)

**Props**:
- `value` (string, required): ISO `YYYY-MM-DD`, or `''`
- `onChange` ((iso: string) => void, required): ISO, or `''` when empty/unreadable
- `testId` (string, required): base for the field's test IDs
- `min` / `max` (string, optional): ISO bounds, enforced in the field
- `mode` (`'date' | 'birthdate'`, default `'date'`): birth-date mode opens the
  year view and shows the resulting age
- `variant` (`'form' | 'filter'`, default `'form'`): compact toolbar padding
- `clearable` (boolean): offer Clear in the calendar footer
- `required`, `disabled`, `invalid`, `describedBy`, `ariaLabel`, `name`

**Example**:
```typescript
<DateField
  testId="report-filter-date-from"
  variant="filter"
  clearable
  ariaLabel={t('reports.dateFrom')}
  value={dateFrom}
  onChange={setDateFrom}
/>
```

#### CharacterCounter Component

Displays character count with color warning based on limit.

**File**: `src/components/forms/CharacterCounter.tsx`

**Props**:
- `currentLength` (number, required): Current character count
- `maxLength` (number, required): Maximum allowed characters
- `testId` (string, optional): Test ID for E2E testing

**Colors**:
- Normal (0-80%): Secondary text (#94a3b8)
- Warning (80-100%): Orange (#f97316)
- Danger (≥100%): Red (#ef4444)

**Example**:
```typescript
import { CharacterCounter } from '@/components/forms/CharacterCounter'

export function CreditorForm({ creditorName }) {
  return (
    <div>
      <input value={creditorName} placeholder="Organization name" />
      <CharacterCounter
        currentLength={creditorName.length}
        maxLength={70}
        testId="name-counter"
      />
    </div>
  )
}
```

---

#### ValidationIndicator Component

Shows checkmark for valid fields, X for invalid fields.

**File**: `src/components/forms/ValidationIndicator.tsx`

**Props**:
- `isValid` (boolean, required): Validation state
- `show` (boolean, required): Show/hide the indicator
- `testId` (string, optional): Test ID for E2E testing

**Example**:
```typescript
import { ValidationIndicator } from '@/components/forms/ValidationIndicator'

function IbanInput({ iban, validateIban }) {
  const isValid = validateIban(iban)

  return (
    <div style={{ display: 'flex', gap: '8px' }}>
      <input value={iban} />
      <ValidationIndicator
        isValid={isValid}
        show={iban.length > 0}
        testId="iban-validation"
      />
    </div>
  )
}
```

---

#### StoredFieldBox / StoredFieldAction

The read-only box that **stands in for an input** when the value is already on
file and is not routinely retyped, plus the underlined text actions that sit
under it.

**File**: `src/components/members/StoredFieldBox.tsx`

**Why it exists** (issue #392): an empty input with a grey example value in it
reads as "this is what is stored, greyed out" — the opposite of the truth. Two
member fields have exactly that shape, and both used to explain the rule in
prose underneath instead of showing it:

- the **IBAN**, sealed under the club's public key and unreadable by the server
  ([ADR-0036](../../adr/0036-iban-encryption-sealed-box.md)), so the input can
  only ever *overwrite* it;
- the **mandate reference**, minted by the server when the mandate is opened
  ([ADR-0006](../../adr/0006-sepa-mandate-reference-strategy.md)).

**Props** (`StoredFieldBox`):
- `value` (ReactNode, required): The value, already in display form
- `pending` (boolean, default false): Dimmer + italic — for "will be assigned on save"
- `actions` (ReactNode, optional): Buttons rendered under the box
- `monospace` (boolean, default true): Off automatically while `pending`
- `testId` (string, optional)

**Props** (`StoredFieldAction`): `onClick`, `tone` (`'neutral' | 'danger' | 'primary'`), `testId`, children.

**Rules**:
- It is **not** an `<input readOnly>`. A disabled-looking field still invites
  typing, and a focusable one lands in the tab order for nothing.
- Actions are always `type="button"` — inside a form the default `submit` would
  make "Change" save the record.
- Reveal the real input on demand, and move focus into it; otherwise keyboard
  users are stranded on the button that just disappeared.

**Used by**: `MemberIbanField`, `MemberMandateReferenceField` (both in
`src/components/members/`). Each renders one of three states — see
[UC-A12](../../use-cases/admin/UC-A12-edit-member.md) *Banking Fields* for the
state tables.

---

### Layout Components

#### PageHeader Component

The top block every admin page opens with: title left, optional line under it,
optional page-level actions right (issue #375).

**File**: `src/components/layout/PageHeader.tsx`

**Rules**:
- **Every page uses it.** A page that hand-rolls its own `<h1>` is how the tabs
  drifted apart in the first place: Products had a bare `<h1>` at the browser's
  default size, Journal an `<h1 style={{ margin: '0 0 20px 0' }}>`, Members a
  26px one with a count line, Settlements a flex row with the primary action
  beside it, and Journal a *second* bar below the title just for its action.
- **Page actions go in `actions`** — top right, on the title's row. Not in the
  toolbar (that is for filters, search and the result count), not in a bar of
  their own. Products' and Categories' create buttons used to sit in the
  toolbar on desktop and inside `MobileToolbar` on mobile, so the same button
  moved rows when the window was resized; one `PageActionButton` in `actions`
  covers both breakpoints.
- **The page does not add its own padding.** `MainLayout` owns the page gutter
  for every page — half the pages used to add `padding: '20px'` on top of it,
  which is why the same title started at two x-positions depending on the tab.
- **Section tabs come after the header**, not before it: title, then
  `MembersTabs`, then the section's content.

**Props**:
- `title` (ReactNode, required): The page's name — the same word the nav uses
- `subtitle` (ReactNode, optional): One line under the title (a result count, a
  note about what the screen sweeps). Pass a node when it needs a test id
- `actions` (ReactNode, optional): `PageActionButton`s, right-aligned
- `testId` (string, default `page-header`)

**Example** (`src/pages/SettlementsPage.tsx`):
```typescript
import { PageHeader } from '@/components/layout/PageHeader'
import { PageActionButton } from '@/components/common/PageActionButton'

<PageHeader
  title={t('settlements.title')}
  actions={
    <>
      <PageActionButton variant="warning" data-testid="settlements-record-bank-return-btn" onClick={openLookup}>
        {t('settlements.recordBankReturn')}
      </PageActionButton>
      <PageActionButton variant="success" data-testid="settlements-new-btn" onClick={goToNewSettlement}>
        {t('newSettlement.title')}
      </PageActionButton>
    </>
  }
/>
```

There is deliberately no exported title style: a page that wants an `<h1>` of
its own is a page that will drift, so it renders `PageHeader` instead.

---

### Table Components

#### PageActionButton Component

A page-level action, the kind that goes in `PageHeader`'s `actions` slot. It
fixes the button's shape — padding, radius, weight, icon gap, hover — so a call
site chooses only *what kind of action* it is (issue #375).

**File**: `src/components/common/PageActionButton.tsx`

**Why**: the same action did not look like the same action across tabs. "Neue
Abrechnung" was emerald / 6px radius / weight 500 on Settlements and blue /
8px radius / weight 600 / `+ `-prefixed on Journal; Members' three buttons had
a third shape again.

**Props**:
- `children` (ReactNode, required): Button label
- `variant` ('primary' | 'success' | 'warning' | 'secondary', default `primary`):
  `primary` for the page's main action, `success` for one that commits money or
  files, `warning` for one that corrects something after the fact, `secondary`
  for anything that must not compete with them
- `icon` (ReactNode, optional): Rendered before the label
- `iconOnly` (boolean, default false): Drop the label, keep the icon — for
  narrow viewports. Set `title`/`aria-label`, which then carry the name
- Plus any native `<button>` prop (`onClick`, `disabled`, `data-testid`, ...)

**Example**:
```typescript
<PageActionButton
  data-testid="products-create-button"
  onClick={openCreateModal}
  iconOnly={isMobile}
  icon={<PlusIcon size={18} />}
  title={t('products.createProduct')}
>
  {t('products.createProduct')}
</PageActionButton>
```

**`PillActionButton` is the row-level sibling** (below): smaller, one per table
row, and colour-per-call-site because a row action's colour tracks its row's
state. A page action has no row, so it gets a fixed vocabulary instead.

---

#### PillActionButton Component

A small, solid-colour action button for row-level actions (export, undo). It
owns its hover state internally so callers don't re-declare
`onMouseEnter`/`onMouseLeave` handlers that mutate `e.currentTarget.style` — a
pattern that was duplicated per button on `SettlementsPage` (#124).

**File**: `src/components/common/PillActionButton.tsx`

**Props**:
- `children` (ReactNode, required): Button label/content
- `color` (string, required): Background color (usually a `theme.colors.semantic.*` token)
- `hoverColor` (string, required): Background color on hover
- `disabledColor` (string, default: `theme.colors.semantic.neutral`): Background color when disabled
- `disabled` (boolean, default: false)
- Plus any native `<button>` prop (`onClick`, `title`, `aria-label`, `data-testid`, ...)

**Example**:
```typescript
import { PillActionButton } from '@/components/common/PillActionButton'
import { theme } from '@/styles/design-system'

<PillActionButton
  onClick={() => handleExportCsv(settlement.id)}
  disabled={settlement.is_cancelled}
  color={theme.colors.semantic.emerald}
  hoverColor={theme.colors.semantic.emeraldHover}
  data-testid={`settlements-export-csv-btn-${settlement.id}`}
>
  {t('settlements.exportCsv')}
</PillActionButton>
```

**When a color is state-dependent** (e.g. the undo button's color depends on
whether the settlement is cancellable), compute it in a plain function and
pass the result as `color`/`hoverColor` — the component itself stays
state-free about business rules.

---

#### ListLoadingOverlay Component

Lays a spinner over a list's **results region** while a refresh is in flight,
leaving that region — and everything above it — mounted.

**File**: `src/components/tables/ListLoadingOverlay.tsx`

**Props**:
- `loading` (boolean, required): Whether a refresh is in flight
- `label` (string, required): Text beside the spinner; also read out by screen readers
- `children` (ReactNode, required): The table or card list to cover
- `testId` (string, optional): Test ID for E2E testing

**Example**:
```typescript
import { ListLoadingOverlay } from '@/components/tables/ListLoadingOverlay'

<ListLoadingOverlay loading={loading} label={t('common.loading')} testId="products-list-loading">
  <div data-testid="products-table-wrapper" style={tableWrapperStyles}>
    <table>…</table>
  </div>
  {totalItems === 0 && !loading && <EmptyState />}
</ListLoadingOverlay>
```

**Why it exists**: swapping the results region — or worse, the page — for a
loading message unmounts the toolbar above it, and the toolbar holds the search
box the admin is typing into. A search that returned nothing made every further
keystroke fire a request over an empty list, and the page tore the focused input
out mid-word (#137). Wrap the results, never the toolbar; gate a page-replacing
loading state on `useListQuery`'s `hasLoaded` instead of on `loading`.

---

## Design System Tokens

All components use tokens from `src/styles/design-system.ts`:

### Raw hex literals are banned in migrated pages (#124)

`theme.colors` and `src/styles/tableTokens.ts`'s `tableColors` are the single
source of truth for colors. A page that still contains raw hex color literals
in `style={{ ... }}` blocks has drifted from the design system and is exactly
the tech debt #124 tracks — new work on such a page should replace literals
with tokens as it touches them, rather than adding more.

`.eslintrc.cjs` enforces this with a `no-restricted-syntax` rule scoped (via
`overrides`) to files that are already hex-free — see the `files` list in that
override for the current set. Add a file to that list once it no longer
contains any raw hex; this makes the rule a ratchet instead of an
all-or-nothing gate that would fail on every not-yet-migrated page. If a color
you need doesn't have a token yet, add it to `theme.colors` (or `tableColors`
for table-specific colors) instead of typing the hex literal in the page.

### Colouring a monetary value (ADR-0042)

Never pick a colour for an amount at the call site. `src/utils/transactions.ts`
encodes the rule once, and it is the same rule the terminal implements in
`design_tokens.dart` — the two apps drifted apart three times (#28, #93, #376)
precisely because pages decided this for themselves:

| You are rendering | Use | Result |
|---|---|---|
| a member's balance (Deckel) | `getBalanceColor(cents)` | credit green, settled/small tab neutral, tab above `MONEY_WARN_ABOVE_CENTS` amber |
| a single transaction amount | `getTransactionAmountColor(cents)` | credit green, charge neutral |

**A charge is never red.** An everyday booking is not an error, and
`theme.colors.semantic.danger` has to keep meaning "something is wrong" for the
alerts sitting on the same screen. The minus sign — which `formatPrice` renders
via `Intl.NumberFormat`, never prepended by hand — is what carries the meaning;
colour only reinforces it, so nothing may be legible by colour alone.

A page may render an amount *more* muted than the helper returns when a list
needs settled rows to recede (`MembersPage` greys a zero balance for that
reason). That local refinement belongs at the call site. Anything that colours
by a *state* rather than by the sign — past the credit limit, held vs.
no-mandate balances, transaction-type badges — is outside the rule and keeps
its own colours; see ADR-0042's scope section.

### `rgba()` tints, borders, and overlays are the same problem (#289)

The hex rule above doesn't match `rgba(...)` literals, so those drifted the
same way — the same tint (e.g. a modal backdrop, a danger tint) copy-pasted
into dozens of `style={{ ... }}` blocks, sometimes with different comma
spacing for the same color. Migrate these into `theme` the same way, one
proven-duplicated tint at a time (see `theme.overlay.backdrop` below), rather
than typing a new `rgba(...)` literal.

When adding a new tint/border/overlay token, derive it with `withAlpha(hex,
alpha)` (also exported from `design-system.ts`) instead of hand-writing the
`rgba()` string — it guarantees identical formatting for every consumer, so
the next grep for duplication isn't undercounted by spacing differences:

```typescript
export const theme = {
  // ...
  overlay: {
    backdrop: withAlpha('#000000', 0.5),
  },
}
```

`.eslintrc.cjs` does not yet restrict `rgba(...)` literals — that's left for a
follow-up slice once enough of the tint/border families above are tokenized
that a ratchet rule wouldn't immediately fail on the untouched ones.

### Avatar Gradients

```typescript
theme.avatars.gradients = {
  blue: 'linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%)',
  green: 'linear-gradient(135deg, #22c55e 0%, #10b981 100%)',
  orange: 'linear-gradient(135deg, #f97316 0%, #fb923c 100%)',
  pink: 'linear-gradient(135deg, #ec4899 0%, #f472b6 100%)',
  gray: 'linear-gradient(135deg, #64748b 0%, #94a3b8 100%)',
}

theme.avatars.sizes = {
  sm: '32px',
  md: '40px',
  lg: '48px',
}
```

### Badge Styles

```typescript
theme.badges = {
  success: { bg: 'rgba(34, 197, 94, 0.1)', border: 'rgba(34, 197, 94, 0.3)', text: '#22c55e', dot: '#22c55e' },
  warning: { bg: 'rgba(251, 146, 60, 0.1)', text: '#f97316', dot: '#f97316' },
  danger: { bg: 'rgba(239, 68, 68, 0.1)', border: 'rgba(239, 68, 68, 0.3)', strong: 'rgba(239, 68, 68, 0.2)', text: '#ef4444', dot: '#ef4444' },
  info: { bg: 'rgba(59, 130, 246, 0.1)', text: '#3b82f6', dot: '#3b82f6' },
  neutral: { bg: 'rgba(107, 114, 128, 0.1)', text: '#64748b', dot: '#64748b' },
}
```

`theme.badges.danger.strong` is a stronger background variant than `bg`
(`0.2` vs `0.1` alpha) — used for the audit-log delete/login-failed action
badges and hover backgrounds (`MainLayout`'s logout button,
`TerminalsTab`'s delete action). Distinct from `bg` by design — match the
literal's alpha to the correct field.

`theme.badges.danger.bg`/`.border` aren't only for the status-badge component —
per #289, they're the canonical danger tint reused wherever an error banner,
delete button, or danger-adjacent panel needs the same translucent red
background (and, where it has a border at all, the same border): `ProfilePage`
error banners, `MainLayout`'s logout buttons, `ReportsPage`'s error style,
`TerminalsTab`'s revoke button, and others. Reuse the existing badge token instead of hand-writing a new
`rgba(239, 68, 68, ...)` literal — that's the pattern the issue calls out
("the existing `theme.badges.*.bg` pattern already does this ... extend it").

Same story for `theme.badges.info.bg` (`rgba(59, 130, 246, 0.1)`): reused for
edit-button hover backgrounds (`CategoriesPage`, `MembersPage`, `ProductsPage`,
`TerminalsTab`), a selected table row (`NewSettlementPage`), and focus box-shadows
(`Input`, `SepaConfigTab`) — reuse it instead of a new `rgba(59, 130, 246, 0.1)` literal.

And for `theme.badges.success.bg`/`.border` (`rgba(34, 197, 94, 0.1)` /
`rgba(34, 197, 94, 0.3)`): reused for success banners (`ProfilePage`'s profile
and password success messages), the valid-SEPA highlight (`MembersPage`,
`DashboardPage`), and the SEPA success message (`SepaConfigTab`) — the success
mirror of the danger banner pattern above.

### Overlay Backdrop

Every full-screen modal needs the same dimming backdrop — use the token
instead of reimplementing it:

```typescript
theme.overlay = {
  backdrop: 'rgba(0, 0, 0, 0.5)', // derived via withAlpha('#000000', 0.5)
}
```

```tsx
<div
  style={{
    position: 'fixed',
    inset: 0,
    background: theme.overlay.backdrop,
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
  }}
>
```

### Mobile Card

The card-style rows used on narrow viewports (audit log, settlements,
members, products, categories, journal, admin users, terminals) all pair the
same subtle white background tint with the same subtle white border — use
the tokens instead of reimplementing the pair:

```typescript
theme.mobileCard = {
  bg: 'rgba(255, 255, 255, 0.03)',    // derived via withAlpha('#ffffff', 0.03)
  border: 'rgba(255, 255, 255, 0.06)', // derived via withAlpha('#ffffff', 0.06)
}
```

```tsx
<div
  style={{
    background: theme.mobileCard.bg,
    border: `1px solid ${theme.mobileCard.border}`,
    borderRadius: '10px',
    padding: '14px 16px',
  }}
>
```

### Faint Surface Fill

A step fainter than `mobileCard.bg` (`0.04` vs `0.03` — distinct, not a typo)
used for the mobile search input, `MobileToolbar`'s container/panel
backgrounds and its filter-panel divider, and `MobileFilterRow`'s idle pill
background:

```typescript
theme.colors.bg.surfaceSubtle = 'rgba(255, 255, 255, 0.04)' // derived via withAlpha('#ffffff', 0.04)
```

```tsx
<input style={{ background: theme.colors.bg.surfaceSubtle, border: `1px solid ${theme.colors.border.subtle}` }} />
```

### Pill Filter Button (idle state)

Dark-mode pill/toggle filter buttons (e.g. `MembersPage.tsx`'s status/card/SEPA
filter groups) share one idle (unselected) background+text tint pair — the
selected state uses `theme.colors.semantic.primary` / `'white'` already:

```typescript
theme.pillButton = {
  idleBg: 'rgba(255, 255, 255, 0.06)',   // derived via withAlpha('#ffffff', 0.06)
  idleText: 'rgba(255, 255, 255, 0.55)', // derived via withAlpha('#ffffff', 0.55)
}
```

```tsx
<button
  aria-pressed={isSelected}
  style={{
    background: isSelected ? theme.colors.semantic.primary : theme.pillButton.idleBg,
    color: isSelected ? 'white' : theme.pillButton.idleText,
  }}
>
```

### Active Tint (translucent primary background)

A second, more subtle "selected" style — a translucent primary-blue
background rather than `pillButton`'s solid fill — used for active nav
items, sort/filter/language toggles, and the blue `StatCard` variant:

```typescript
theme.activeTint = {
  primary: 'rgba(59, 130, 246, 0.15)',         // derived via withAlpha('#3b82f6', 0.15)
  primaryStrong: 'rgba(59, 130, 246, 0.2)',    // derived via withAlpha('#3b82f6', 0.2)
  profileActive: 'rgba(59, 130, 246, 0.25)',   // derived via withAlpha('#3b82f6', 0.25)
  primaryBorder: 'rgba(59, 130, 246, 0.5)',    // derived via withAlpha('#3b82f6', 0.5)
}
```

```tsx
<button style={{ background: isSelected ? theme.activeTint.primary : 'transparent' }}>
```

`primaryStrong` is a distinct, more opaque variant of the same hue — used for
audit-log action badges, the selected state in `MobileFilterRow`, hover
backgrounds (`LoadingIndicator`, `AdminUsersTab`, `TerminalsTab`), and
`MainLayout`'s sidebar active-item background. `profileActive` is a stronger
variant still, used only for `MainLayout`'s Profile nav item's active/hover
background — also reused (likely coincidentally, same numeric value) as
`ListLoadingOverlay`'s spinner border. `primaryBorder` is the strongest
variant, used as the open/hover-state `border` on dropdowns and toggles
(`ReportsPage`, `CategoryFilter`, `LanguageSelector`, and hover handlers in
`ProductsPage`/`JournalPage`). All four are different alphas by design (all
exist in the app today) — don't conflate them when tokenizing a new call
site; match the literal's alpha to the correct field.

### Soft Color-Coded Tint (0.15 alpha)

`activeTint.primary` is the blue member of a family of translucent
backgrounds at a shared `0.15` alpha — `theme.softTint` completes it for
green/orange/red. Used by `StatCard`'s non-blue variants, credit/held/ok
status chips (`ExcludedFromCollectionPage`), a report card background
(`ReportsPage`), and severity-tinted panels (`MembersPage`):

```typescript
theme.softTint = {
  success: 'rgba(34, 197, 94, 0.15)',  // derived via withAlpha('#22c55e', 0.15)
  warning: 'rgba(249, 115, 22, 0.15)', // derived via withAlpha('#f97316', 0.15)
  danger: 'rgba(239, 68, 68, 0.15)',   // derived via withAlpha('#ef4444', 0.15)
}
```

```tsx
const variants = {
  blue: theme.activeTint.primary,
  green: theme.softTint.success,
  orange: theme.softTint.warning,
  red: theme.softTint.danger,
}
```

Note this is a different alpha from `theme.badges.*.bg` (`0.1`) — the two
families serve different visual weights and aren't interchangeable.

### Subtle Border/Divider Tint

A translucent white tint used for both `border` and solid vertical divider
bars on dark-mode toolbars (the `MembersPage.tsx` filter toolbar's search
input, group dividers, and clear-filters button; `MobileToolbar.tsx`'s
container border and toggle button):

```typescript
theme.colors.border.subtle = 'rgba(255, 255, 255, 0.08)' // derived via withAlpha('#ffffff', 0.08)
```

```tsx
<div style={{ border: `1px solid ${theme.colors.border.subtle}` }} />
<div style={{ width: '1px', height: '28px', background: theme.colors.border.subtle }} />
```

### Slate Border Tint

A translucent slate border — a different hue from the white/primary tints
above — used on empty-state panels (`ExcludedFromCollectionPage.tsx`'s dashed
border), `ProductPreview.tsx`'s solid border, and `PillFilter.tsx`'s idle
border:

```typescript
theme.colors.border.slate = 'rgba(71, 85, 105, 0.4)' // derived via withAlpha('#475569', 0.4)
```

```tsx
<div style={{ border: `1px dashed ${theme.colors.border.slate}` }} />
```

### Uppercase Group Label Text

The small uppercase label above a filter group or picker (`MembersPage.tsx`'s
Status/Card/SEPA filter labels, `JournalPage.tsx`'s mobile filter labels,
`MobileFilterRow.tsx`'s row label) all use the same translucent white text
tint:

```typescript
theme.colors.text.label = 'rgba(255, 255, 255, 0.35)' // derived via withAlpha('#ffffff', 0.35)
```

```tsx
<span style={{ fontSize: '12px', color: theme.colors.text.label, fontWeight: 500, textTransform: 'uppercase' }}>
  {t('members.filters.status.label')}
</span>
```

### Second Modal Shadow Shape

`theme.shadows.modal` is not the modal shadow actually in use — a second
shape, with no negative spread and double the alpha, is reimplemented
identically across `LoginPage.tsx`, `CategoriesPage.tsx`, `MembersPage.tsx`
(×2), `ConfirmDialog.tsx`, and `StornoConfirmDialog.tsx`:

```typescript
theme.shadows.modalStrong = '0 25px 50px rgba(0, 0, 0, 0.5)'
```

```tsx
<div style={{ boxShadow: isMobile ? 'none' : theme.shadows.modalStrong }}>
```

The two shadows are deliberately distinct — don't consolidate them into one
without a design decision; match a new call site's exact shadow string to the
correct token instead.

### Dropdown/Popover Shadow

A third shadow shape, used by open-state dropdowns and popovers
(`ReportsPage.tsx`'s export menu, `CategoryFilter.tsx`, `MobileToolbar.tsx`'s
filter panel, `LanguageSelector.tsx`), reimplemented identically (sometimes
with drifted comma spacing) at every call site:

```typescript
theme.shadows.dropdown = '0 10px 40px rgba(0, 0, 0, 0.4)'
```

```tsx
<div style={{ boxShadow: theme.shadows.dropdown }}>
```

`BottomTabBar.tsx`'s upward shadow (`'0 -4px 20px rgba(0,0,0,0.4)'`) uses the
same alpha but a different shape and isn't duplicated elsewhere, so it's left
as a literal — #289 targets cross-file duplication, not every `rgba()` use.

---

## Utility Functions

### formatRelativeDate()

Format date relative to today (Heute, Gestern, or absolute date).

**File**: `src/styles/design-system.ts`

**Signature**:
```typescript
export function formatRelativeDate(dateString: string, locale: string = 'de-DE'): string
```

**Example**:
```typescript
import { formatRelativeDate } from '@/styles/design-system'

function LoginCell({ lastLoginAt }) {
  return <span>{formatRelativeDate(lastLoginAt)}</span>
}
```

**Output**:
- Today: "Heute"
- Yesterday: "Gestern"
- Older dates: "30.01.2026" (formatted)
- No date: "Never"

---

## Usage Patterns

### Example: User List with Avatars, Badges, and Actions

```typescript
import { Avatar } from '@/components/common/Avatar'
import { Badge } from '@/components/common/Badge'
import { ActionMenu } from '@/components/common/ActionMenu'
import { Tooltip } from '@/components/common/Tooltip'
import { formatRelativeDate } from '@/styles/design-system'

function UserTable({ users }) {
  return (
    <table>
      <tbody>
        {users.map((user, index) => (
          <tr key={user.id}>
            <td>
              <Avatar
                name={user.name}
                variant={getVariant(index)}
                size="sm"
                inactive={!user.is_active}
              />
              {user.name}
            </td>
            <td>
              <Badge
                label={user.is_active ? 'Active' : 'Inactive'}
                variant={user.is_active ? 'success' : 'neutral'}
              />
            </td>
            <td>{formatRelativeDate(user.last_login_at)}</td>
            <td>
              <ActionMenu items={[
                { label: 'Edit', onClick: () => handleEdit(user.id) },
                { label: 'Delete', onClick: () => handleDelete(user.id), variant: 'danger' },
              ]} />
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}
```

---

## Best Practices

1. **Always include test IDs** for E2E testing
2. **Use theme tokens** instead of hardcoded colors
3. **Keep components stateless** - pass handlers as props
4. **Use TypeScript** for type safety
5. **Inline styles only** - no external CSS files
6. **Follow accessibility** - use semantic HTML and ARIA attributes when needed

---

## Related Patterns

- **Test IDs Pattern**: See `admin-frontend/patterns/test-ids.md` for naming conventions
- **E2E Testing Patterns**: See `e2etests/patterns/README.md` for test examples
- **Backend Patterns**: See `backend/patterns/` for API integration examples
