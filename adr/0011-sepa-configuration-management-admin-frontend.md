# ADR-0011: SEPA Configuration Management in Admin Frontend

**Status**: Accepted

**Date**: 2025-01-23

**Deciders**: Architecture Team

---

## Context

Organization-level SEPA configuration (Gläubiger-ID, creditor account details, address) is stored in the backend ([ADR-0007](./0007-organization-sepa-configuration-storage.md)). However, non-technical admins need a user-friendly interface to:

1. **Initial setup**: Enter SEPA configuration during system onboarding
2. **View current config**: See what's currently configured
3. **Update settings**: Modify bank account details, address, organization name (but NOT creditor_id)
4. **Understand constraints**: Learn why creditor_id cannot be changed
5. **Get help**: Know how to obtain a Gläubiger-ID and comply with SEPA requirements
6. **Audit trail**: Understand that changes are logged

This ADR addresses the admin panel UI/UX for SEPA configuration management.

---

## Decision

**SEPA Configuration is managed via a dedicated settings panel in the Admin Frontend, with a form that enforces immutability of the Gläubiger-ID, provides real-time validation, and guides admins through the setup process. The UI clearly distinguishes between immutable and mutable fields, and provides helpful documentation.**

### Core Principles

1. **Single source of truth**: Configuration stored in backend (ADR-0007); UI is thin client
2. **Immutability enforcement**: creditor_id field visually disabled after initial set
3. **Real-time validation**: IBAN checksum, creditor_id format validated as user types
4. **Guided setup**: Wizard-like experience for first-time configuration
5. **Helpful documentation**: Links to Bundesbank, SEPA rules, and requirements
6. **Clear feedback**: Success/error messages on save, indication of what changed
7. **Change tracking**: "Last updated by X at Y" metadata visible to admin

---

## Implementation

### UI Component Structure

```
/admin-frontend/src/features/settings/
├── SEPAConfigPanel.jsx          # Main container
├── SEPAConfigForm.jsx           # Reusable form component
├── SEPAConfigDisplay.jsx        # Read-only view
├── SEPASetupWizard.jsx          # First-time setup flow
└── hooks/
    └── useSEPAConfig.js         # Fetch/update config
```

### SEPA Configuration Panel Layout

```jsx
// Admin Panel → Settings → SEPA Configuration

function SEPAConfigPanel() {
  const [config, setConfig] = useState(null);
  const [isEditing, setIsEditing] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    fetchSEPAConfig();
  }, []);

  const fetchSEPAConfig = async () => {
    try {
      const response = await api.get('/api/admin/sepa-config');
      setConfig(response.data);
      setIsLoading(false);
    } catch (err) {
      setError(err.message);
      setIsLoading(false);
    }
  };

  if (isLoading) return <LoadingSpinner />;
  if (error) return <ErrorAlert message={error} />;

  // First-time setup (empty config)
  if (!config?.creditor_id) {
    return <SEPASetupWizard onComplete={fetchSEPAConfig} />;
  }

  // Display current config
  if (!isEditing) {
    return (
      <SEPAConfigDisplay
        config={config}
        onEdit={() => setIsEditing(true)}
      />
    );
  }

  // Edit mode
  return (
    <SEPAConfigForm
      initialConfig={config}
      onSave={handleSave}
      onCancel={() => setIsEditing(false)}
    />
  );
}
```

### Read-Only Display View

```jsx
/**
 * Display current SEPA configuration
 */
function SEPAConfigDisplay({ config, onEdit }) {
  return (
    <div className="sepa-config-display">
      <h2>SEPA Configuration</h2>

      <Alert icon={InfoIcon} color="blue">
        Organization-wide settings for SEPA Direct Debit collection.
        All settlements will use this configuration.
      </Alert>

      <Grid cols={2} gutter="lg">

        <div className="field">
          <Label>Gläubiger-ID (Creditor ID)</Label>
          <Code>{config.creditor_id}</Code>
          <HelpText>
            Unique identifier assigned by the Bundesbank.
            Cannot be changed once set.
          </HelpText>
        </div>

        <div className="field">
          <Label>Organization Name</Label>
          <Text>{config.creditor_name}</Text>
        </div>

        <div className="field">
          <Label>Organization IBAN</Label>
          <Code>{config.creditor_iban}</Code>
          <HelpText>
            Bank account for receiving SEPA Direct Debit payments.
          </HelpText>
        </div>

        <div className="field">
          <Label>Address</Label>
          <Text>
            {config.creditor_address_street}<br />
            {config.creditor_address_city}<br />
            {config.creditor_address_country}
          </Text>
        </div>

      </Grid>

      <div className="metadata">
        <Text size="sm" color="dimmed">
          Last updated: {formatDateTime(config.updated_at)}
        </Text>
      </div>

      <Button onClick={onEdit}>Edit Configuration</Button>
    </div>
  );
}
```

### Editable Form

```jsx
/**
 * Edit SEPA Configuration Form
 * - creditor_id field disabled if already set (immutable)
 * - Real-time validation with user feedback
 * - Clear indication of field mutability
 */
function SEPAConfigForm({ initialConfig, onSave, onCancel }) {
  const [errors, setErrors] = useState({});
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [ibanError, setIbanError] = useState(null);

  const form = useForm({
    initialValues: {
      creditor_id: initialConfig?.creditor_id || '',
      creditor_name: initialConfig?.creditor_name || '',
      creditor_iban: initialConfig?.creditor_iban || '',
      creditor_address_street: initialConfig?.creditor_address_street || '',
      creditor_address_city: initialConfig?.creditor_address_city || '',
      creditor_address_country: initialConfig?.creditor_address_country || 'DE'
    },
    validate: {
      creditor_id: (value) => {
        if (!value) return 'Gläubiger-ID required';
        if (!/^[A-Z]{2}[0-9A-Z]{3}[0-9]{10,}$/.test(value)) {
          return 'Invalid format (e.g., DE98ZZZ09999999999)';
        }
        return null;
      },
      creditor_name: (value) => {
        if (!value) return 'Organization name required';
        if (value.length > 70) return 'Max 70 characters';
        if (!/^[a-zA-Z0-9\s\/\-?()\.,\']+$/.test(value)) {
          return 'Only Latin letters, numbers, and basic punctuation allowed';
        }
        return null;
      },
      creditor_iban: (value) => {
        if (!value) return 'IBAN required';
        if (!isValidIBAN(value)) return 'Invalid IBAN checksum';
        return null;
      },
      creditor_address_street: (value) =>
        !value ? 'Street address required' : null,
      creditor_address_city: (value) =>
        !value ? 'City/postal code required' : null
    }
  });

  const handleSubmit = async (values) => {
    setIsSubmitting(true);
    try {
      await api.patch('/api/admin/sepa-config', values);
      toast.success('SEPA configuration updated successfully');
      onSave();
    } catch (err) {
      if (err.response?.data?.errors) {
        setErrors(err.response.data.errors);
      }
      toast.error(err.response?.data?.message || 'Failed to save configuration');
    } finally {
      setIsSubmitting(false);
    }
  };

  const isCreditorIdSetAndImmutable = !!initialConfig?.creditor_id;

  return (
    <form onSubmit={form.handleSubmit(handleSubmit)}>
      <h2>Edit SEPA Configuration</h2>

      <TextInput
        label="Gläubiger-ID (Creditor ID)"
        placeholder="DE98ZZZ09999999999"
        disabled={isCreditorIdSetAndImmutable}
        error={form.errors.creditor_id}
        description={
          isCreditorIdSetAndImmutable
            ? "Immutable after initial set. Request change via Bundesbank."
            : "Apply at: https://www.glaeubiger-id.bundesbank.de"
        }
        {...form.getInputProps('creditor_id')}
      />

      {isCreditorIdSetAndImmutable && (
        <Alert icon={LockIcon} color="gray" title="Immutable Field">
          The Gläubiger-ID cannot be changed once set. This is a SEPA requirement
          to prevent fraud and ensure mandate validity.
        </Alert>
      )}

      <TextInput
        label="Organization Name"
        maxLength={70}
        error={form.errors.creditor_name}
        description="Max 70 characters. Must match bank records."
        {...form.getInputProps('creditor_name')}
      />

      <TextInput
        label="Organization IBAN"
        placeholder="DE89370400440532013000"
        error={form.errors.creditor_iban}
        description="Bank account for receiving SEPA payments. Must be SEPA-enabled."
        onChange={(e) => {
          form.getInputProps('creditor_iban').onChange(e);
          setIbanError(null);
        }}
        {...form.getInputProps('creditor_iban')}
      />

      <Divider label="Address Information" />

      <TextInput
        label="Street Address"
        error={form.errors.creditor_address_street}
        {...form.getInputProps('creditor_address_street')}
      />

      <TextInput
        label="City / Postal Code"
        error={form.errors.creditor_address_city}
        placeholder="12345 City Name"
        {...form.getInputProps('creditor_address_city')}
      />

      <Select
        label="Country"
        data={[
          { label: 'Germany', value: 'DE' },
          { label: 'Austria', value: 'AT' },
          { label: 'Switzerland', value: 'CH' },
          // Add more countries as needed
        ]}
        defaultValue="DE"
        {...form.getInputProps('creditor_address_country')}
      />

      <Group mt="xl">
        <Button
          type="submit"
          loading={isSubmitting}
        >
          Save Configuration
        </Button>
        <Button
          variant="light"
          onClick={onCancel}
          disabled={isSubmitting}
        >
          Cancel
        </Button>
      </Group>
    </form>
  );
}
```

### First-Time Setup Wizard

```jsx
/**
 * Multi-step wizard for initial SEPA configuration
 * Guides non-technical admins through setup process
 */
function SEPASetupWizard({ onComplete }) {
  const [step, setStep] = useState(0);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const steps = [
    {
      title: 'Welcome to SEPA Setup',
      component: (
        <div className="setup-step">
          <h2>Set Up SEPA Direct Debit</h2>
          <Text>
            To enable automated member billing via SEPA Direct Debit,
            you'll need to provide your organization's bank details.
          </Text>
          <Alert title="Before you start" color="blue">
            <ul>
              <li>Apply for a Gläubiger-ID (takes 2-3 business days)</li>
              <li>Have your bank account IBAN ready</li>
              <li>Ensure your bank account is SEPA-enabled</li>
            </ul>
          </Alert>
          <Button onClick={() => setStep(1)}>Next</Button>
        </div>
      )
    },
    {
      title: 'Gläubiger-ID',
      component: (
        <div className="setup-step">
          <h2>Step 1: Gläubiger-ID (Creditor ID)</h2>
          <Text>
            A Gläubiger-ID is a unique 18-character identifier that identifies
            your organization as a SEPA creditor.
          </Text>
          <Steps>
            <li>
              Visit{' '}
              <Link href="https://www.glaeubiger-id.bundesbank.de">
                Bundesbank Gläubiger-ID Application
              </Link>
            </li>
            <li>Register your organization (free, takes 2-3 business days)</li>
            <li>Receive your ID via email (format: DE98ZZZ09999999999)</li>
          </Steps>
          <Group mt="xl">
            <Button variant="light" onClick={() => setStep(0)}>Back</Button>
            <Button onClick={() => setStep(2)}>Next: Enter Details</Button>
          </Group>
        </div>
      )
    },
    {
      title: 'Organization Details',
      component: (
        <SEPAConfigForm
          initialConfig={{}}
          onSave={() => {
            setStep(3);
            onComplete();
          }}
          onCancel={() => setStep(1)}
        />
      )
    },
    {
      title: 'Setup Complete',
      component: (
        <div className="setup-step">
          <h2>Setup Complete!</h2>
          <Alert icon={CheckIcon} color="green">
            Your SEPA configuration is ready. You can now create settlements
            and export SEPA Direct Debit files.
          </Alert>
          <Button onClick={onComplete}>Go to Dashboard</Button>
        </div>
      )
    }
  ];

  return (
    <div className="setup-wizard">
      <Stepper active={step} onStepClick={setStep} allowNextStepsSelect={false}>
        {steps.map((s, idx) => (
          <Stepper.Step key={idx} label={s.title}>
            {s.component}
          </Stepper.Step>
        ))}
      </Stepper>
    </div>
  );
}
```

### Helper Functions

```js
// Validation helpers
export function isValidIBAN(iban) {
  // Remove spaces
  iban = iban.replace(/\s/g, '');

  // Check length (15-34 characters)
  if (iban.length < 15 || iban.length > 34) return false;

  // Check format (country code + check digits + account)
  if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/.test(iban)) return false;

  // Verify checksum (mod-97 algorithm)
  return validateIBANChecksum(iban);
}

function validateIBANChecksum(iban) {
  iban = iban.replace(/\s/g, '');
  const rearranged = iban.slice(4) + iban.slice(0, 4);
  const numeric = rearranged.replace(/[A-Z]/g, (char) => {
    return (10 + char.charCodeAt(0) - 65).toString();
  });

  let remainder = numeric;
  while (remainder.length > 2) {
    const block = remainder.slice(0, 9);
    remainder = (parseInt(block) % 97).toString() + remainder.slice(9);
  }

  return parseInt(remainder) % 97 === 1;
}

// Formatting helpers
export function formatDateTime(dateString) {
  return new Date(dateString).toLocaleString('de-DE', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit'
  });
}
```

### Integration with Settlements

When creating a settlement, the UI validates SEPA config before allowing export:

```jsx
function SettlementExport() {
  const [sepaConfig, setSEPAConfig] = useState(null);
  const [isMissing, setIsMissing] = useState(false);

  useEffect(() => {
    const checkSEPAConfig = async () => {
      try {
        const response = await api.get('/api/admin/sepa-config');
        if (!response.data?.creditor_id) {
          setIsMissing(true);
        } else {
          setSEPAConfig(response.data);
        }
      } catch (err) {
        setIsMissing(true);
      }
    };
    checkSEPAConfig();
  }, []);

  if (isMissing) {
    return (
      <Alert icon={WarningIcon} color="red">
        SEPA configuration is incomplete.
        <Link to="/settings/sepa">Configure SEPA settings first</Link>
      </Alert>
    );
  }

  return <ExportOptions config={sepaConfig} />;
}
```

---

## Consequences

### Positive

✅ **User-friendly setup**: Wizard guides admins step-by-step through initial configuration
✅ **Clear immutability**: Visual affordance (disabled input) makes creditor_id immutable
✅ **Real-time feedback**: Validation errors shown as user types (better UX than form submission)
✅ **Helpful documentation**: Links to Bundesbank and SEPA rules embedded in UI
✅ **Prevents data loss**: Confirmation dialogs and clear "what changed" feedback
✅ **Accessible layout**: Form grouped logically (creditor vs address)
✅ **Integration**: Settlement export requires valid config (prevents bank rejection)

### Negative

❌ **Client-side validation**: Duplicate validation logic (also in backend)
❌ **State management**: Multiple components accessing shared SEPA config
❌ **Offline unavailable**: Admin panel requires network (unlike terminal)

### Mitigations

1. **Validation duplication**: Use shared validation library (separate module) for client/server
2. **State management**: Use Zustand/Redux store for SEPA config (single source of truth in frontend)
3. **Network resilience**: Show cached config if API fails; alert user that changes not saved

---

## Alternatives Considered

### Alternative 1: Minimal Admin UI (Just Read-Only Display)

```jsx
function SEPAConfigPanel() {
  return (
    <div>
      <h2>SEPA Configuration (Read-Only)</h2>
      <Alert>To update SEPA settings, contact your system administrator</Alert>
      <DisplayCurrentConfig />
    </div>
  );
}
```

**Pros**: Simplest implementation; prevents accidental changes
**Cons**:
- Admins can't update bank account details without technical help
- Reduces self-service capability
- No onboarding experience for first-time setup

**Rejected**: Admins need autonomy to update SEPA settings (address, bank account changes).

### Alternative 2: Backend Admin Endpoint Only (No UI)

Configuration manageable only via:
- Direct API calls (curl/Postman)
- Backend configuration file

**Pros**: Minimal frontend code
**Cons**:
- Non-technical admins can't use
- Error-prone (no validation UX)
- No audit trail visibility
- Difficult to debug issues

**Rejected**: Admin panel must provide accessible interface for all user roles.

### Alternative 3: Inline Editing (No Modal/Page)

Display config inline with "edit" buttons next to each field:
- Click field → becomes editable input
- Click checkmark → save that field
- Click X → cancel

**Pros**: Quick edits, no page navigation
**Cons**:
- No clear form boundaries
- Partial saves confusing ("which fields changed?")
- Harder to validate cross-field dependencies
- Less clear about immutable fields

**Rejected**: Form approach better for validation and user understanding.

### Alternative 4: All Fields Mutable (No Immutability)

Allow creditor_id to be changed via UI without restriction.

**Pros**: Simpler logic (no immutability checks)
**Cons**:
- Violates SEPA business requirement
- Accidental changes break settlements
- Audit trail shows changes but can't be prevented
- Confusing for admins

**Rejected**: creditor_id must be immutable per SEPA rules and ADR-0007.

---

## Implementation Checklist

### Frontend Components

- [ ] SEPAConfigPanel.jsx (main container)
- [ ] SEPAConfigDisplay.jsx (read-only view)
- [ ] SEPAConfigForm.jsx (editable form)
- [ ] SEPASetupWizard.jsx (first-time setup)
- [ ] useSEPAConfig.js (custom hook for fetch/update)

### Validation & Helpers

- [ ] IBAN checksum validation (isValidIBAN)
- [ ] Creditor-ID format validation
- [ ] SEPA character set validation
- [ ] Formatting functions (formatDateTime)
- [ ] Shared validation module (client + server compatible)

### UI/UX

- [ ] Immutability visual affordance (disabled input, lock icon)
- [ ] Real-time validation feedback
- [ ] Success/error toast notifications
- [ ] Help text and links for each field
- [ ] Wizard step indicators for setup
- [ ] Metadata display (last updated by/when)

### Integration

- [ ] Navigation: Admin menu → Settings → SEPA Configuration
- [ ] Settlement export: Check config before allowing export
- [ ] Error handling: Show helpful error messages from backend
- [ ] State management: Store SEPA config in app store (Zustand/Redux)

### Testing

- [ ] Form submission with valid data
- [ ] Form validation (required fields, formats)
- [ ] Creditor-ID immutability (disabled input after set)
- [ ] IBAN checksum validation
- [ ] Real-time validation feedback
- [ ] Error handling (network errors, validation errors)
- [ ] Wizard flow (all steps, skip, back, complete)
- [ ] Settlement export requires valid config
- [ ] Accessibility (keyboard navigation, screen readers)

### Documentation

- [ ] Update CLAUDE.md: Admin panel features section
- [ ] Admin user guide: How to configure SEPA
- [ ] Developer guide: Component structure and state management
- [ ] API reference: GET/PATCH /api/admin/sepa-config
- [ ] Cross-reference to ADR-0007

---

## Related Decisions

- [ADR-0007: Organization-Level SEPA Configuration Storage](./0007-organization-sepa-configuration-storage.md) - Backend storage model
- [ADR-0005: IBAN Storage and Validation](./0005-iban-storage-and-validation.md) - IBAN validation patterns
- [ADR-0006: SEPA Mandate Reference Strategy](./0006-sepa-mandate-reference-strategy.md) - Uses creditor config
- [ADR-0008: SEPA XML Export Format Selection](./0008-sepa-xml-export-format-selection.md) - XML generation uses config
- [ADR-0009: Settlement Lead Times and Bank Working Days](./0009-settlement-lead-times-bank-working-days.md) - Pre-export validation

---

## References

- **Admin Panel Architecture**: Mantine 7 component library, React Hook Form for validation
- **SEPA Standards**: EPC SEPA Rulebook (configuration requirements)
- **Bundesbank**: [Gläubiger-ID Application](https://www.glaeubiger-id.bundesbank.de)
- **UX Patterns**: Wizard patterns, immutability affordances

---

## Approval

- **Decided by**: Architecture Team
- **Rationale**: Admin panel must provide user-friendly, validated interface for SEPA configuration while enforcing backend business rules
- **Implementation start**: Phase 2 (SEPA settlement)
- **Review date**: 2025-04-23 (after first admin onboarding)
- **Sign-off**:
  - Frontend Lead: _________________ Date: _______
  - UX/Design Lead: _________________ Date: _______
  - Product Owner: _________________ Date: _______

---

## Post-Implementation Monitoring

- [ ] Track admin setup completion rate (% of organizations completing SEPA config)
- [ ] Monitor form submission errors (which fields most problematic?)
- [ ] Gather feedback: Is UI intuitive for non-technical admins?
- [ ] Track configuration change frequency (should be rare)
- [ ] Test with real admins: Can they complete setup without support?
- [ ] Bank acceptance: Do exports succeed on first attempt?
