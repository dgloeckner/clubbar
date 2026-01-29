// ============================================
// Pagination & Sorting Components for frgs-admin.html
// ============================================

// ========== ICONS (add to your icons section) ==========
/*
    const ChevronLeftIcon = () => (
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <polyline points="15 18 9 12 15 6"/>
      </svg>
    );

    const ChevronRightIcon = () => (
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <polyline points="9 18 15 12 9 6"/>
      </svg>
    );

    const ChevronsLeftIcon = () => (
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <polyline points="11 17 6 12 11 7"/>
        <polyline points="18 17 13 12 18 7"/>
      </svg>
    );

    const ChevronsRightIcon = () => (
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <polyline points="13 17 18 12 13 7"/>
        <polyline points="6 17 11 12 6 7"/>
      </svg>
    );

    const ArrowUpIcon = () => (
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <line x1="12" y1="19" x2="12" y2="5"/>
        <polyline points="5 12 12 5 19 12"/>
      </svg>
    );

    const ArrowDownIcon = () => (
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <line x1="12" y1="5" x2="12" y2="19"/>
        <polyline points="19 12 12 19 5 12"/>
      </svg>
    );

    const ArrowUpDownIcon = () => (
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M7 15l5 5 5-5"/>
        <path d="M7 9l5-5 5 5"/>
      </svg>
    );
*/

// ========== PAGINATION COMPONENT ==========
// Usage: <Pagination currentPage={page} totalPages={10} totalItems={100} pageSize={10} onPageChange={setPage} onPageSizeChange={setPageSize} />

function Pagination({
  currentPage,
  totalPages,
  onPageChange,
  pageSize = 25,
  onPageSizeChange,
  totalItems,
  pageSizeOptions = [10, 25, 50, 100],
  showPageSize = true,
  showInfo = true,
  variant = 'default', // 'default' | 'compact' | 'minimal'
}) {
  const startItem = (currentPage - 1) * pageSize + 1;
  const endItem = Math.min(currentPage * pageSize, totalItems);

  const getPageNumbers = () => {
    const pages = [];
    const maxVisible = variant === 'compact' ? 3 : 5;
    let start = Math.max(1, currentPage - Math.floor(maxVisible / 2));
    let end = Math.min(totalPages, start + maxVisible - 1);
    if (end - start + 1 < maxVisible) start = Math.max(1, end - maxVisible + 1);

    if (start > 1) { pages.push(1); if (start > 2) pages.push('...'); }
    for (let i = start; i <= end; i++) pages.push(i);
    if (end < totalPages) { if (end < totalPages - 1) pages.push('...'); pages.push(totalPages); }
    return pages;
  };

  const btnBase = {
    display: 'flex', alignItems: 'center', justifyContent: 'center',
    minWidth: 36, height: 36, padding: '0 12px',
    border: `1px solid ${colors.border}`, borderRadius: 8,
    fontSize: 14, cursor: 'pointer', transition: 'all 0.15s',
  };

  const PageButton = ({ page, active }) => (
    <button
      onClick={() => onPageChange(page)}
      style={{
        ...btnBase,
        background: active ? colors.blue : 'transparent',
        borderColor: active ? colors.blue : colors.border,
        color: active ? 'white' : colors.text,
        fontWeight: active ? 600 : 400,
      }}
    >{page}</button>
  );

  const NavButton = ({ onClick, disabled, children, title }) => (
    <button
      onClick={onClick}
      disabled={disabled}
      title={title}
      style={{
        ...btnBase, minWidth: 36, padding: 0,
        background: 'transparent',
        color: disabled ? colors.textMuted : colors.text,
        opacity: disabled ? 0.5 : 1,
        cursor: disabled ? 'not-allowed' : 'pointer',
      }}
    >{children}</button>
  );

  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 16 }}>
      {/* Left: Info & Page size */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
        {showInfo && (
          <span style={{ color: colors.textSecondary, fontSize: 14 }}>
            Zeige <strong style={{ color: colors.text }}>{startItem}-{endItem}</strong> von <strong style={{ color: colors.text }}>{totalItems}</strong>
          </span>
        )}
        {showPageSize && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <span style={{ color: colors.textMuted, fontSize: 13 }}>Einträge:</span>
            <select
              value={pageSize}
              onChange={(e) => onPageSizeChange(Number(e.target.value))}
              style={{
                background: colors.bgInput, border: `1px solid ${colors.border}`,
                borderRadius: 6, color: colors.text, fontSize: 13,
                padding: '6px 28px 6px 10px', cursor: 'pointer', outline: 'none',
              }}
            >
              {pageSizeOptions.map(size => <option key={size} value={size}>{size}</option>)}
            </select>
          </div>
        )}
      </div>

      {/* Right: Navigation */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
        {variant !== 'minimal' && (
          <NavButton onClick={() => onPageChange(1)} disabled={currentPage === 1} title="Erste Seite">
            <ChevronsLeftIcon />
          </NavButton>
        )}
        <NavButton onClick={() => onPageChange(currentPage - 1)} disabled={currentPage === 1} title="Vorherige">
          <ChevronLeftIcon />
        </NavButton>

        {variant !== 'minimal' && getPageNumbers().map((page, idx) => (
          page === '...' 
            ? <span key={`dots-${idx}`} style={{ padding: '0 8px', color: colors.textMuted }}>…</span>
            : <PageButton key={page} page={page} active={currentPage === page} />
        ))}

        {variant === 'minimal' && (
          <span style={{ padding: '0 12px', color: colors.text, fontSize: 14 }}>
            Seite <strong>{currentPage}</strong> von <strong>{totalPages}</strong>
          </span>
        )}

        <NavButton onClick={() => onPageChange(currentPage + 1)} disabled={currentPage === totalPages} title="Nächste">
          <ChevronRightIcon />
        </NavButton>
        {variant !== 'minimal' && (
          <NavButton onClick={() => onPageChange(totalPages)} disabled={currentPage === totalPages} title="Letzte Seite">
            <ChevronsRightIcon />
          </NavButton>
        )}
      </div>
    </div>
  );
}


// ========== SORT DROPDOWN COMPONENT ==========
// Usage: <SortDropdown options={[{value: 'name-asc', label: 'Name (A-Z)', direction: 'asc'}]} value={sortBy} onChange={setSortBy} />

function SortDropdown({ options, value, onChange, label = 'Sortieren' }) {
  const [isOpen, setIsOpen] = useState(false);
  const dropdownRef = React.useRef(null);

  React.useEffect(() => {
    const handleClickOutside = (e) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target)) setIsOpen(false);
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const selectedOption = options.find(opt => opt.value === value);

  return (
    <div style={{ position: 'relative' }} ref={dropdownRef}>
      <button
        onClick={() => setIsOpen(!isOpen)}
        style={{
          display: 'flex', alignItems: 'center', gap: 8,
          padding: '8px 12px', background: colors.bgInput,
          border: `1px solid ${isOpen ? 'rgba(59,130,246,0.5)' : colors.border}`,
          borderRadius: 8, color: colors.text, fontSize: 14, cursor: 'pointer',
        }}
      >
        {selectedOption?.direction === 'asc' ? <ArrowUpIcon /> : <ArrowDownIcon />}
        <span>{selectedOption?.label || label}</span>
        <span style={{ color: colors.textMuted, transform: isOpen ? 'rotate(180deg)' : '', transition: 'transform 0.2s', display: 'flex' }}>
          <ChevronDownIcon />
        </span>
      </button>

      {isOpen && (
        <div style={{
          position: 'absolute', top: '100%', right: 0, marginTop: 8, minWidth: 200,
          background: colors.bgCard, border: `1px solid ${colors.border}`,
          borderRadius: 12, boxShadow: '0 10px 40px rgba(0,0,0,0.4)', zIndex: 1000,
        }}>
          <div style={{ padding: 6 }}>
            {options.map((option) => (
              <button
                key={option.value}
                onClick={() => { onChange(option.value); setIsOpen(false); }}
                style={{
                  width: '100%', display: 'flex', alignItems: 'center', gap: 10,
                  padding: '10px 12px', background: value === option.value ? 'rgba(59,130,246,0.15)' : 'transparent',
                  border: 'none', borderRadius: 8,
                  color: value === option.value ? colors.blue : colors.text,
                  fontSize: 14, cursor: 'pointer', textAlign: 'left',
                }}
              >
                <span style={{ color: value === option.value ? colors.blue : colors.textMuted }}>
                  {option.direction === 'asc' ? <ArrowUpIcon /> : <ArrowDownIcon />}
                </span>
                <span style={{ flex: 1 }}>{option.label}</span>
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}


// ========== SORTABLE TABLE HEADER ==========
// Usage: <SortableHeader label="Name" sortKey="name" currentSort={{key: 'name', direction: 'asc'}} onSort={(key, dir) => setSort({key, direction: dir})} />

function SortableHeader({ label, sortKey, currentSort, onSort }) {
  const isActive = currentSort.key === sortKey;
  const direction = isActive ? currentSort.direction : null;

  return (
    <button
      onClick={() => onSort(sortKey, isActive && direction === 'asc' ? 'desc' : 'asc')}
      style={{
        display: 'flex', alignItems: 'center', gap: 6,
        background: 'transparent', border: 'none',
        color: isActive ? colors.blue : colors.textSecondary,
        fontSize: 12, fontWeight: 600, textTransform: 'uppercase',
        letterSpacing: '0.05em', cursor: 'pointer', padding: '8px 0',
      }}
    >
      {label}
      <span style={{ opacity: isActive ? 1 : 0.4 }}>
        {direction === 'asc' ? <ArrowUpIcon /> : direction === 'desc' ? <ArrowDownIcon /> : <ArrowUpDownIcon />}
      </span>
    </button>
  );
}


// ========== USAGE EXAMPLES ==========
/*
// State
const [currentPage, setCurrentPage] = useState(1);
const [pageSize, setPageSize] = useState(25);
const [sortBy, setSortBy] = useState('name-asc');
const [tableSort, setTableSort] = useState({ key: 'name', direction: 'asc' });

// Sort options
const sortOptions = [
  { value: 'name-asc', label: 'Name (A-Z)', direction: 'asc' },
  { value: 'name-desc', label: 'Name (Z-A)', direction: 'desc' },
  { value: 'price-asc', label: 'Preis (aufsteigend)', direction: 'asc' },
  { value: 'price-desc', label: 'Preis (absteigend)', direction: 'desc' },
  { value: 'date-desc', label: 'Datum (neueste)', direction: 'desc' },
  { value: 'date-asc', label: 'Datum (älteste)', direction: 'asc' },
];

// Standard pagination
<Pagination
  currentPage={currentPage}
  totalPages={12}
  totalItems={287}
  pageSize={pageSize}
  onPageChange={setCurrentPage}
  onPageSizeChange={setPageSize}
/>

// Compact pagination (no page size selector)
<Pagination
  currentPage={currentPage}
  totalPages={8}
  totalItems={187}
  pageSize={25}
  onPageChange={setCurrentPage}
  variant="compact"
  showPageSize={false}
/>

// Minimal pagination (just prev/next)
<Pagination
  currentPage={currentPage}
  totalPages={5}
  totalItems={48}
  pageSize={10}
  onPageChange={setCurrentPage}
  variant="minimal"
  showPageSize={false}
  showInfo={false}
/>

// Sort dropdown
<SortDropdown
  options={sortOptions}
  value={sortBy}
  onChange={setSortBy}
/>

// Sortable table headers
<thead>
  <tr>
    <th><SortableHeader label="Name" sortKey="name" currentSort={tableSort} onSort={(k, d) => setTableSort({key: k, direction: d})} /></th>
    <th><SortableHeader label="Preis" sortKey="price" currentSort={tableSort} onSort={(k, d) => setTableSort({key: k, direction: d})} /></th>
    <th><SortableHeader label="Datum" sortKey="date" currentSort={tableSort} onSort={(k, d) => setTableSort({key: k, direction: d})} /></th>
  </tr>
</thead>
*/

export { Pagination, SortDropdown, SortableHeader };
