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

#### SecretDisplayModal Component

Modal for a value the backend shows exactly once (terminal API token, generated
admin password). Such a value cannot be recovered, so the modal is deliberately
harder to dismiss than an ordinary one.

**File**: `src/components/modals/SecretDisplayModal.tsx`

**Rules** (issue #126):
- The clipboard write is **awaited** (`useClipboardCopy`) — a rejected write
  (non-secure origin, unfocused document, denied permission) is reported, never
  mistaken for success.
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

### Form Components

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

### Table Components

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
  success: { bg: 'rgba(34, 197, 94, 0.1)', text: '#22c55e', dot: '#22c55e' },
  warning: { bg: 'rgba(251, 146, 60, 0.1)', text: '#f97316', dot: '#f97316' },
  danger: { bg: 'rgba(239, 68, 68, 0.1)', border: 'rgba(239, 68, 68, 0.3)', text: '#ef4444', dot: '#ef4444' },
  info: { bg: 'rgba(59, 130, 246, 0.1)', text: '#3b82f6', dot: '#3b82f6' },
  neutral: { bg: 'rgba(107, 114, 128, 0.1)', text: '#64748b', dot: '#64748b' },
}
```

`theme.badges.danger.bg`/`.border` aren't only for the status-badge component —
per #289, they're the canonical danger tint reused wherever an error banner,
delete button, or danger-adjacent panel needs the same translucent red
background (and, where it has a border at all, the same border): `ProfilePage`
error banners, `MainLayout`'s logout buttons, `ReportsPage`'s error style,
`TransactionModal`'s error message, `TerminalsTab`'s revoke button, and
others. Reuse the existing badge token instead of hand-writing a new
`rgba(239, 68, 68, ...)` literal — that's the pattern the issue calls out
("the existing `theme.badges.*.bg` pattern already does this ... extend it").

Same story for `theme.badges.info.bg` (`rgba(59, 130, 246, 0.1)`): reused for
edit-button hover backgrounds (`CategoriesPage`, `MembersPage`, `ProductsPage`,
`TerminalsTab`), a selected table row (`NewSettlementPage`), an idle filter
background (`TransactionModal`), and focus box-shadows (`Input`,
`SepaConfigTab`) — reuse it instead of a new `rgba(59, 130, 246, 0.1)` literal.

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
  primary: 'rgba(59, 130, 246, 0.15)', // derived via withAlpha('#3b82f6', 0.15)
}
```

```tsx
<button style={{ background: isSelected ? theme.activeTint.primary : 'transparent' }}>
```

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
