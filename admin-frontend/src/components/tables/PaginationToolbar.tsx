/**
 * Pagination Toolbar Component
 * Combines pagination controls and page size selector
 * Based on prototypes/Pagination.jsx "KOMBINIERTE TOOLBAR"
 *
 * Props:
 * - currentPage: Current page number (1-indexed)
 * - totalPages: Total number of pages
 * - totalItems: Total number of items across all pages
 * - pageSize: Items per page
 * - pageSizeOptions: Array of page size options
 * - onPageChange: Callback when page changes
 * - onPageSizeChange: Callback when page size changes
 * - variant: 'default' | 'compact' | 'minimal'
 * - showPageSize: Show page size selector
 * - showInfo: Show item count info
 * - testId: Base test ID for elements
 */

interface PaginationToolbarProps {
  currentPage: number
  totalPages: number
  totalItems: number
  pageSize: number
  pageSizeOptions?: number[]
  onPageChange: (page: number) => void
  onPageSizeChange: (size: number) => void
  variant?: 'default' | 'compact' | 'minimal'
  showPageSize?: boolean
  showInfo?: boolean
  testId?: string
}

// Simple icon components as inline SVGs
function ChevronLeftIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <polyline points="15 18 9 12 15 6" />
    </svg>
  )
}

function ChevronRightIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <polyline points="9 18 15 12 9 6" />
    </svg>
  )
}

function ChevronsLeftIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <polyline points="11 17 6 12 11 7" />
      <polyline points="18 17 13 12 18 7" />
    </svg>
  )
}

function ChevronsRightIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <polyline points="13 17 18 12 13 7" />
      <polyline points="6 17 11 12 6 7" />
    </svg>
  )
}

export function PaginationToolbar({
  currentPage,
  totalPages,
  totalItems,
  pageSize,
  pageSizeOptions = [10, 25, 50, 100],
  onPageChange,
  onPageSizeChange,
  variant = 'default',
  showPageSize = true,
  showInfo = true,
  testId = 'pagination',
}: PaginationToolbarProps) {
  const startItem = (currentPage - 1) * pageSize + 1
  const endItem = Math.min(currentPage * pageSize, totalItems)

  const getPageNumbers = () => {
    const pages: (number | string)[] = []
    const maxVisible = variant === 'compact' ? 3 : 5
    let start = Math.max(1, currentPage - Math.floor(maxVisible / 2))
    let end = Math.min(totalPages, start + maxVisible - 1)
    if (end - start + 1 < maxVisible) start = Math.max(1, end - maxVisible + 1)

    if (start > 1) {
      pages.push(1)
      if (start > 2) pages.push('...')
    }
    for (let i = start; i <= end; i++) pages.push(i)
    if (end < totalPages) {
      if (end < totalPages - 1) pages.push('...')
      pages.push(totalPages)
    }
    return pages
  }

  const btnBase = {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    minWidth: 36,
    height: 36,
    padding: '0 12px',
    border: '1px solid #2d3748',
    borderRadius: 8,
    fontSize: 14,
    cursor: 'pointer',
    transition: 'all 0.15s',
    backgroundColor: 'transparent',
    color: '#e2e8f0',
  }

  const PageButton = ({ page, active }: { page: number; active: boolean }) => (
    <button
      data-testid={`${testId}-page-${page}`}
      onClick={() => onPageChange(page)}
      style={{
        ...btnBase,
        background: active ? '#3b82f6' : 'transparent',
        borderColor: active ? '#3b82f6' : '#2d3748',
        color: active ? 'white' : '#e2e8f0',
        fontWeight: active ? 600 : 400,
      }}
    >
      {page}
    </button>
  )

  const NavButton = ({
    onClick,
    disabled,
    children,
    title,
    testIdSuffix,
  }: {
    onClick: () => void
    disabled: boolean
    children: React.ReactNode
    title: string
    testIdSuffix: string
  }) => (
    <button
      data-testid={`${testId}-${testIdSuffix}`}
      onClick={onClick}
      disabled={disabled}
      title={title}
      style={{
        ...btnBase,
        minWidth: 36,
        padding: 0,
        color: disabled ? '#64748b' : '#e2e8f0',
        opacity: disabled ? 0.5 : 1,
        cursor: disabled ? 'not-allowed' : 'pointer',
      }}
    >
      {children}
    </button>
  )

  return (
    <div
      data-testid={`${testId}-toolbar`}
      style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        flexWrap: 'wrap',
        gap: 16,
        padding: '16px 0',
      }}
    >
      {/* Left: Info & Page size */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
        {showInfo && (
          <span data-testid={`${testId}-info`} style={{ color: '#cbd5e1', fontSize: 14 }}>
            Showing <strong style={{ color: '#e2e8f0' }}>{startItem}-{endItem}</strong> of{' '}
            <strong style={{ color: '#e2e8f0' }}>{totalItems}</strong>
          </span>
        )}
        {showPageSize && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <span style={{ color: '#94a3b8', fontSize: 13 }}>Items per page:</span>
            <select
              data-testid={`${testId}-page-size-select`}
              value={pageSize}
              onChange={(e) => onPageSizeChange(Number(e.target.value))}
              style={{
                background: '#0d1829',
                border: '1px solid #2d3748',
                borderRadius: 6,
                color: '#e2e8f0',
                fontSize: 13,
                padding: '6px 28px 6px 10px',
                cursor: 'pointer',
                outline: 'none',
                appearance: 'none',
                WebkitAppearance: 'none',
                backgroundImage: `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E")`,
                backgroundRepeat: 'no-repeat',
                backgroundPosition: 'right 8px center',
                backgroundSize: '12px',
              }}
            >
              {pageSizeOptions.map((size) => (
                <option key={size} value={size}>
                  {size}
                </option>
              ))}
            </select>
          </div>
        )}
      </div>

      {/* Right: Navigation */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
        {variant !== 'minimal' && (
          <NavButton
            onClick={() => onPageChange(1)}
            disabled={currentPage === 1}
            title="First page"
            testIdSuffix="first"
          >
            <ChevronsLeftIcon />
          </NavButton>
        )}
        <NavButton
          onClick={() => onPageChange(currentPage - 1)}
          disabled={currentPage === 1}
          title="Previous page"
          testIdSuffix="prev"
        >
          <ChevronLeftIcon />
        </NavButton>

        {variant !== 'minimal' &&
          getPageNumbers().map((page, idx) =>
            page === '...' ? (
              <span
                key={`dots-${idx}`}
                data-testid={`${testId}-ellipsis-${idx}`}
                style={{ padding: '0 8px', color: '#64748b' }}
              >
                …
              </span>
            ) : (
              <PageButton key={page} page={page as number} active={currentPage === page} />
            )
          )}

        {variant === 'minimal' && (
          <span data-testid={`${testId}-page-info`} style={{ padding: '0 12px', color: '#e2e8f0', fontSize: 14 }}>
            Page <strong>{currentPage}</strong> of <strong>{totalPages}</strong>
          </span>
        )}

        <NavButton
          onClick={() => onPageChange(currentPage + 1)}
          disabled={currentPage === totalPages}
          title="Next page"
          testIdSuffix="next"
        >
          <ChevronRightIcon />
        </NavButton>
        {variant !== 'minimal' && (
          <NavButton
            onClick={() => onPageChange(totalPages)}
            disabled={currentPage === totalPages}
            title="Last page"
            testIdSuffix="last"
          >
            <ChevronsRightIcon />
          </NavButton>
        )}
      </div>
    </div>
  )
}
