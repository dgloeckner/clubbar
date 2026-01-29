// ============================================
// Toggle Components for frgs-admin.html
// ============================================

// ========== BASIC TOGGLE ==========
// Usage: <Toggle enabled={isActive} onChange={setIsActive} />
// Props:
//   - enabled: boolean - current state
//   - onChange: (value: boolean) => void - callback
//   - size: 'small' | 'default' | 'large' - toggle size
//   - showLabel: boolean - show Aktiv/Inaktiv label
//   - labelOn/labelOff: custom labels
//   - disabled: boolean - disable interaction

function Toggle({ 
  enabled, 
  onChange, 
  size = 'default',
  showLabel = false,
  labelOn = 'Aktiv',
  labelOff = 'Inaktiv',
  disabled = false,
}) {
  const sizes = {
    small: { width: 36, height: 20, knob: 14, translate: 16 },
    default: { width: 44, height: 24, knob: 18, translate: 20 },
    large: { width: 52, height: 28, knob: 22, translate: 24 },
  };

  const s = sizes[size];

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
      <button
        role="switch"
        aria-checked={enabled}
        onClick={() => !disabled && onChange(!enabled)}
        disabled={disabled}
        style={{
          position: 'relative',
          width: s.width,
          height: s.height,
          borderRadius: s.height / 2,
          border: 'none',
          background: enabled ? '#22c55e' : 'rgba(71, 85, 105, 0.5)',
          cursor: disabled ? 'not-allowed' : 'pointer',
          transition: 'background 0.2s',
          opacity: disabled ? 0.5 : 1,
          padding: 0,
          outline: 'none',
        }}
      >
        <span
          style={{
            position: 'absolute',
            top: (s.height - s.knob) / 2,
            left: enabled ? s.translate : (s.height - s.knob) / 2,
            width: s.knob,
            height: s.knob,
            borderRadius: '50%',
            background: enabled ? 'white' : '#94a3b8',
            transition: 'left 0.2s, background 0.2s',
            boxShadow: '0 2px 4px rgba(0,0,0,0.2)',
          }}
        />
      </button>
      
      {showLabel && (
        <span style={{
          fontSize: size === 'small' ? 12 : 13,
          fontWeight: 500,
          color: enabled ? '#22c55e' : '#94a3b8',
          minWidth: 50,
        }}>
          {enabled ? labelOn : labelOff}
        </span>
      )}
    </div>
  );
}


// ========== TOGGLE WITH ICONS ==========
// Same usage but with check/X icons in the toggle

function ToggleWithIcons({ enabled, onChange, disabled = false }) {
  const CheckIcon = () => (
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
      <polyline points="20 6 9 17 4 12"/>
    </svg>
  );

  const XIcon = () => (
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
      <line x1="18" y1="6" x2="6" y2="18"/>
      <line x1="6" y1="6" x2="18" y2="18"/>
    </svg>
  );

  return (
    <button
      role="switch"
      aria-checked={enabled}
      onClick={() => !disabled && onChange(!enabled)}
      disabled={disabled}
      style={{
        position: 'relative',
        width: 52,
        height: 28,
        borderRadius: 14,
        border: 'none',
        background: enabled ? '#22c55e' : 'rgba(71, 85, 105, 0.5)',
        cursor: disabled ? 'not-allowed' : 'pointer',
        transition: 'background 0.2s',
        opacity: disabled ? 0.5 : 1,
        padding: 0,
        outline: 'none',
      }}
    >
      {/* Track icon */}
      <span style={{
        position: 'absolute',
        top: '50%',
        left: enabled ? 10 : 'auto',
        right: enabled ? 'auto' : 10,
        transform: 'translateY(-50%)',
        color: 'rgba(255,255,255,0.5)',
        display: 'flex',
      }}>
        {enabled ? <CheckIcon /> : <XIcon />}
      </span>
      
      {/* Knob with icon */}
      <span
        style={{
          position: 'absolute',
          top: 3,
          left: enabled ? 27 : 3,
          width: 22,
          height: 22,
          borderRadius: '50%',
          background: enabled ? 'white' : '#94a3b8',
          transition: 'left 0.2s, background 0.2s',
          boxShadow: '0 2px 4px rgba(0,0,0,0.2)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          color: enabled ? '#22c55e' : '#475569',
        }}
      >
        {enabled ? <CheckIcon /> : <XIcon />}
      </span>
    </button>
  );
}


// ========== STATUS BADGE ==========
// Alternative: pill-style status indicator
// Usage: <StatusBadge status="active" /> or <StatusBadge status="inactive" onClick={handleClick} />

function StatusBadge({ status, onClick }) {
  const statusConfig = {
    active: { label: 'Aktiv', color: '#22c55e' },
    inactive: { label: 'Inaktiv', color: '#ef4444' },
    pending: { label: 'Ausstehend', color: '#f97316' },
  };

  const config = statusConfig[status] || statusConfig.inactive;

  return (
    <button
      onClick={onClick}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 8,
        padding: '6px 14px',
        background: `${config.color}15`,
        border: `1px solid ${config.color}30`,
        borderRadius: 20,
        cursor: onClick ? 'pointer' : 'default',
        transition: 'all 0.15s',
      }}
    >
      <span style={{
        width: 8,
        height: 8,
        borderRadius: '50%',
        background: config.color,
      }} />
      <span style={{
        color: config.color,
        fontSize: 13,
        fontWeight: 500,
      }}>
        {config.label}
      </span>
    </button>
  );
}


// ========== USAGE EXAMPLES ==========
/*

// Basic toggle in table cell
<td style={{ textAlign: 'center' }}>
  <Toggle
    enabled={product.isActive}
    onChange={(val) => updateProduct(product.id, { isActive: val })}
    size="small"
  />
</td>

// Toggle with label
<Toggle
  enabled={isEnabled}
  onChange={setIsEnabled}
  showLabel
  labelOn="Aktiviert"
  labelOff="Deaktiviert"
/>

// Toggle with icons (larger, more visual)
<ToggleWithIcons
  enabled={isEnabled}
  onChange={setIsEnabled}
/>

// Disabled toggle
<Toggle
  enabled={true}
  onChange={() => {}}
  disabled
/>

// Status badge (non-interactive)
<StatusBadge status="active" />
<StatusBadge status="inactive" />
<StatusBadge status="pending" />

// Clickable status badge
<StatusBadge status={currentStatus} onClick={() => cycleStatus()} />

*/


// ========== COPY DIRECTLY INTO frgs-admin.html ==========
/*

    // Basic Toggle
    function Toggle({ enabled, onChange, size = 'default', showLabel = false, labelOn = 'Aktiv', labelOff = 'Inaktiv', disabled = false }) {
      const sizes = {
        small: { width: 36, height: 20, knob: 14, translate: 16 },
        default: { width: 44, height: 24, knob: 18, translate: 20 },
        large: { width: 52, height: 28, knob: 22, translate: 24 },
      };
      const s = sizes[size];
      return (
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <button role="switch" aria-checked={enabled} onClick={() => !disabled && onChange(!enabled)} disabled={disabled}
            style={{
              position: 'relative', width: s.width, height: s.height, borderRadius: s.height / 2, border: 'none',
              background: enabled ? '#22c55e' : 'rgba(71,85,105,0.5)', cursor: disabled ? 'not-allowed' : 'pointer',
              transition: 'background 0.2s', opacity: disabled ? 0.5 : 1, padding: 0, outline: 'none',
            }}>
            <span style={{
              position: 'absolute', top: (s.height - s.knob) / 2, left: enabled ? s.translate : (s.height - s.knob) / 2,
              width: s.knob, height: s.knob, borderRadius: '50%', background: enabled ? 'white' : '#94a3b8',
              transition: 'left 0.2s, background 0.2s', boxShadow: '0 2px 4px rgba(0,0,0,0.2)',
            }} />
          </button>
          {showLabel && <span style={{ fontSize: size === 'small' ? 12 : 13, fontWeight: 500, color: enabled ? '#22c55e' : '#94a3b8', minWidth: 50 }}>{enabled ? labelOn : labelOff}</span>}
        </div>
      );
    }

    // Status Badge
    function StatusBadge({ status, onClick }) {
      const cfg = { active: { label: 'Aktiv', color: '#22c55e' }, inactive: { label: 'Inaktiv', color: '#ef4444' }, pending: { label: 'Ausstehend', color: '#f97316' } };
      const c = cfg[status] || cfg.inactive;
      return (
        <button onClick={onClick} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '6px 14px', background: `${c.color}15`, border: `1px solid ${c.color}30`, borderRadius: 20, cursor: onClick ? 'pointer' : 'default' }}>
          <span style={{ width: 8, height: 8, borderRadius: '50%', background: c.color }} />
          <span style={{ color: c.color, fontSize: 13, fontWeight: 500 }}>{c.label}</span>
        </button>
      );
    }

*/

export { Toggle, ToggleWithIcons, StatusBadge };
