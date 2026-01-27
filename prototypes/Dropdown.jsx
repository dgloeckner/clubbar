// ============================================
// Dropdown Component for frgs-admin.html
// ============================================
// Copy the entire Dropdown function into your code
// Usage: <Dropdown options={[...]} value={val} onChange={setVal} />

// ========== COPY INTO ICONS SECTION ==========
/*
    const ChevronDownIcon = () => (
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <polyline points="6 9 12 15 18 9"/>
      </svg>
    );

    const CheckIcon = () => (
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
        <polyline points="20 6 9 17 4 12"/>
      </svg>
    );

    const FilterIcon = () => (
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
      </svg>
    );
*/

// ========== COPY INTO COMPONENTS SECTION ==========

// Simple status dot for options
const StatusDot = ({ color }) => (
  <span style={{
    width: 8, height: 8, borderRadius: '50%',
    background: color, display: 'inline-block',
  }} />
);

// Main Dropdown Component
function Dropdown({ 
  options,           // Array of { value, label, icon?, count? }
  value,             // Currently selected value
  onChange,          // Callback when value changes
  placeholder = 'Auswählen...', 
  label,             // Optional label above dropdown
  icon,              // Optional icon in trigger
  width = 200,       // Width in pixels
  variant = 'default', // 'default' | 'minimal' | 'pill'
}) {
  const [isOpen, setIsOpen] = useState(false);
  const dropdownRef = React.useRef(null);

  // Close on outside click
  useEffect(() => {
    const handleClickOutside = (e) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
        setIsOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const selectedOption = options.find(opt => opt.value === value);

  // Variant styles
  const variantStyles = {
    default: {
      trigger: {
        background: colors.bgInput,
        border: `1px solid ${isOpen ? 'rgba(59, 130, 246, 0.5)' : colors.border}`,
        borderRadius: 10,
        padding: '12px 16px',
      },
      menu: { borderRadius: 12, marginTop: 8 }
    },
    minimal: {
      trigger: {
        background: 'transparent',
        border: `1px solid ${isOpen ? 'rgba(59, 130, 246, 0.5)' : 'transparent'}`,
        borderRadius: 8,
        padding: '8px 12px',
      },
      menu: { borderRadius: 10, marginTop: 4 }
    },
    pill: {
      trigger: {
        background: isOpen ? 'rgba(59, 130, 246, 0.2)' : 'rgba(59, 130, 246, 0.1)',
        border: `1px solid ${isOpen ? 'rgba(59, 130, 246, 0.5)' : 'rgba(59, 130, 246, 0.3)'}`,
        borderRadius: 20,
        padding: '8px 16px',
      },
      menu: { borderRadius: 12, marginTop: 8 }
    }
  };

  const vstyle = variantStyles[variant];

  return (
    <div style={{ position: 'relative', width }} ref={dropdownRef}>
      {label && (
        <label style={{
          display: 'block', fontSize: 13, fontWeight: 500,
          color: colors.textSecondary, marginBottom: 6,
        }}>
          {label}
        </label>
      )}
      
      {/* Trigger */}
      <button
        onClick={() => setIsOpen(!isOpen)}
        style={{
          width: '100%',
          display: 'flex', alignItems: 'center', justifyContent: 'space-between',
          gap: 10,
          color: selectedOption ? colors.text : colors.textMuted,
          fontSize: 14,
          cursor: 'pointer',
          transition: 'all 0.15s',
          outline: 'none',
          ...vstyle.trigger,
        }}
      >
        <span style={{ display: 'flex', alignItems: 'center', gap: 8, flex: 1, minWidth: 0 }}>
          {icon && <span style={{ color: colors.textMuted, display: 'flex' }}>{icon}</span>}
          {selectedOption?.icon && <span style={{ display: 'flex' }}>{selectedOption.icon}</span>}
          <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
            {selectedOption?.label || placeholder}
          </span>
        </span>
        <span style={{ 
          color: colors.textMuted, 
          transform: isOpen ? 'rotate(180deg)' : 'rotate(0deg)',
          transition: 'transform 0.2s',
          display: 'flex',
          flexShrink: 0,
        }}>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </span>
      </button>

      {/* Menu */}
      {isOpen && (
        <div style={{
          position: 'absolute',
          top: '100%',
          left: 0,
          right: 0,
          background: colors.bgCard,
          border: `1px solid ${colors.border}`,
          boxShadow: '0 10px 40px rgba(0,0,0,0.4)',
          zIndex: 1000,
          overflow: 'hidden',
          ...vstyle.menu,
        }}>
          <div style={{ padding: 6, maxHeight: 280, overflowY: 'auto' }}>
            {options.map((option) => (
              <button
                key={option.value}
                onClick={() => { onChange(option.value); setIsOpen(false); }}
                onMouseEnter={(e) => {
                  if (value !== option.value) e.currentTarget.style.background = '#243352';
                }}
                onMouseLeave={(e) => {
                  if (value !== option.value) e.currentTarget.style.background = 'transparent';
                }}
                style={{
                  width: '100%',
                  display: 'flex', alignItems: 'center', gap: 10,
                  padding: '10px 12px',
                  background: value === option.value ? 'rgba(59, 130, 246, 0.15)' : 'transparent',
                  border: 'none',
                  borderRadius: 8,
                  color: value === option.value ? colors.blue : colors.text,
                  fontSize: 14,
                  cursor: 'pointer',
                  transition: 'background 0.1s',
                  textAlign: 'left',
                }}
              >
                {option.icon && <span style={{ display: 'flex' }}>{option.icon}</span>}
                <span style={{ flex: 1 }}>{option.label}</span>
                {option.count !== undefined && (
                  <span style={{
                    fontSize: 12, color: colors.textMuted,
                    background: 'rgba(71, 85, 105, 0.3)',
                    padding: '2px 8px', borderRadius: 10,
                  }}>
                    {option.count}
                  </span>
                )}
                {value === option.value && (
                  <span style={{ color: colors.blue, display: 'flex' }}>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                      <polyline points="20 6 9 17 4 12"/>
                    </svg>
                  </span>
                )}
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}


// ========== USAGE EXAMPLES ==========

/*
// Basic usage
const [status, setStatus] = useState('all');

const statusOptions = [
  { value: 'all', label: 'Alle Status' },
  { value: 'active', label: 'Aktiv', icon: <StatusDot color={colors.green} /> },
  { value: 'inactive', label: 'Inaktiv', icon: <StatusDot color={colors.red} /> },
];

<Dropdown
  label="Status"
  options={statusOptions}
  value={status}
  onChange={setStatus}
  width={180}
/>

// With category counts
const categoryOptions = [
  { value: '', label: 'Alle Kategorien' },
  { value: 'getraenke', label: 'Getränke', count: 8 },
  { value: 'snacks', label: 'Snacks', count: 3 },
];

<Dropdown
  options={categoryOptions}
  value={category}
  onChange={setCategory}
  width={200}
/>

// Pill variant for toolbar filters
<Dropdown
  options={statusOptions}
  value={status}
  onChange={setStatus}
  variant="pill"
  width={140}
/>

// With filter icon
<Dropdown
  icon={<FilterIcon />}
  options={statusOptions}
  value={status}
  onChange={setStatus}
  placeholder="Filter..."
  width={180}
/>
*/

export default Dropdown;
