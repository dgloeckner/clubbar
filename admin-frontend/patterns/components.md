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

## Design System Tokens

All components use tokens from `src/styles/design-system.ts`:

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
  danger: { bg: 'rgba(239, 68, 68, 0.1)', text: '#ef4444', dot: '#ef4444' },
  info: { bg: 'rgba(59, 130, 246, 0.1)', text: '#3b82f6', dot: '#3b82f6' },
  neutral: { bg: 'rgba(107, 114, 128, 0.1)', text: '#64748b', dot: '#64748b' },
}
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
