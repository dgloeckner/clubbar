/**
 * Members Page
 * Member management (list, create, edit, delete)
 */

import { useEffect, useState } from 'react'
import { StatCard } from '../components/common/StatCard'
import { TransactionModal } from '../components/modals/TransactionModal'
import { theme } from '../styles/design-system'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { useLoading } from '../context/LoadingContext'
import { UsersIcon, BankIcon, CalendarIcon, TrashIcon, EditIcon, PlusIcon, BookIcon } from '../components/icons'
import { formatPrice, formatDate } from '../styles/design-system'
import { getMembers, createMember, updateMember, deactivateMember, Member } from '../services/members'
import { getDashboardMetrics } from '../services/dashboard'
import { TableSearchToolbar } from '../components/tables/TableSearchToolbar'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { SortableTableHeader } from '../components/tables/SortableTableHeader'
import { StatusFilter } from '../components/tables/StatusFilter'
import { SortDropdown } from '../components/tables/SortDropdown'
import { StatusToggleCell } from '../components/tables/StatusToggleCell'
import { TableCell } from '../components/tables/TableCell'
import { LanguageSelector } from '../components/forms/LanguageSelector'
import {
  tableWrapperStyles,
  tableElementStyles,
  headerRowStyle,
  headerCellBaseStyle,
  tableColors,
  tableSpacing,
  getRowStyle,
} from '../styles/tableTokens'

export function MembersPage() {
  const breakpoint = useBreakpoint()
  const { setIsLoading } = useLoading()
  const [members, setMembers] = useState<Member[]>([])
  const [totalMembers, setTotalMembers] = useState(0)
  const [activeMembersCount, setActiveMembersCount] = useState(0)
  const [totalBalance, setTotalBalance] = useState(0)
  const [lastSettlementDate, setLastSettlementDate] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [showModal, setShowModal] = useState(false)
  const [editingMember, setEditingMember] = useState<Member | null>(null)
  const [deleteConfirm, setDeleteConfirm] = useState<string | null>(null)
  const [selectedMemberForTransactions, setSelectedMemberForTransactions] = useState<Member | null>(null)
  const [sortKey, setSortKey] = useState<'first_name' | 'last_name' | 'created_at'>('created_at')
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('desc')
  const [sortByValue, setSortByValue] = useState('created_at-desc') // For sort dropdown
  const [filterIsActive, setFilterIsActive] = useState<'all' | 'active' | 'inactive'>('all')
  const [formData, setFormData] = useState({
    first_name: '',
    last_name: '',
    email: '',
    iban: '',
    mandate_signed_at: '',
    preferred_language: 'de',
  })

  // Load members
  useEffect(() => {
    const loadMembers = async () => {
      try {
        setLoading(true)
        setIsLoading(true)

        // Build filter object
        const filter: { is_active?: boolean } = {}
        if (filterIsActive === 'active') {
          filter.is_active = true
        } else if (filterIsActive === 'inactive') {
          filter.is_active = false
        }

        const response = await getMembers(page, 20, search || undefined, filter, sortKey, sortDirection)

        setMembers(response.items)
        setTotalMembers(response.total)

        // Note: balance_cents is not available in members API response (would need separate transaction calculation)
        setTotalBalance(0)

        setError(null)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load members')
      } finally {
        setLoading(false)
        setIsLoading(false)
      }
    }

    const timer = setTimeout(loadMembers, search ? 500 : 0) // Debounce search
    return () => clearTimeout(timer)
  }, [page, search, filterIsActive, sortKey, sortDirection, setIsLoading])

  // Load dashboard metrics (active members count, outstanding balance, last settlement date)
  useEffect(() => {
    const loadDashboardMetrics = async () => {
      try {
        const dashboard = await getDashboardMetrics()
        setActiveMembersCount(dashboard.metrics.active_members)
        setTotalBalance(dashboard.metrics.outstanding_balance_cents)
        setLastSettlementDate(dashboard.system_status.last_settlement_date)
      } catch (err) {
        // Silently fail - stats are not critical
        console.warn('Failed to load dashboard metrics:', err)
      }
    }

    loadDashboardMetrics()
  }, [])

  // Handle form submission
  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    try {
      setIsLoading(true)
      if (editingMember) {
        await updateMember(editingMember.id, formData)
      } else {
        await createMember(formData)
      }

      // Reset form and page to trigger reload via useEffect
      setShowModal(false)
      setEditingMember(null)
      setFormData({ first_name: '', last_name: '', email: '', iban: '', mandate_signed_at: '', preferred_language: 'de' })
      setPage(1)  // This triggers useEffect which will reload members with current filter/sort
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save member')
    } finally {
      setIsLoading(false)
    }
  }

  // Handle status toggle (activate/deactivate)
  const handleStatusToggle = async (member: Member) => {
    try {
      setIsLoading(true)
      const updatedData = {
        ...member,
        is_active: !member.is_active,
      }
      await updateMember(member.id, updatedData)

      // Directly reload members (don't rely on setPage which may not trigger if page is already 1)
      const filter: { is_active?: boolean } = {}
      if (filterIsActive === 'active') {
        filter.is_active = true
      } else if (filterIsActive === 'inactive') {
        filter.is_active = false
      }

      const response = await getMembers(page, 20, search || undefined, filter, sortKey, sortDirection)
      setMembers(response.items)
      setTotalMembers(response.total)
      setTotalBalance(0)

      setError(null)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update member status')
    } finally {
      setIsLoading(false)
    }
  }

  // Handle delete confirmation
  const handleDelete = async (memberId: string) => {
    try {
      setIsLoading(true)
      await deactivateMember(memberId)

      // Directly reload members (don't rely on setPage which may not trigger if page is already 1)
      const filter: { is_active?: boolean } = {}
      if (filterIsActive === 'active') {
        filter.is_active = true
      } else if (filterIsActive === 'inactive') {
        filter.is_active = false
      }

      const response = await getMembers(page, 20, search || undefined, filter, sortKey, sortDirection)
      setMembers(response.items)
      setTotalMembers(response.total)
      setTotalBalance(0)

      setDeleteConfirm(null)
      setError(null)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to delete member')
    } finally {
      setIsLoading(false)
    }
  }

  // Handle edit member
  const handleEdit = (member: Member) => {
    setEditingMember(member)
    setFormData({
      first_name: member.first_name,
      last_name: member.last_name,
      email: member.email || '',
      iban: member.iban || '',
      mandate_signed_at: member.mandate_signed_at || '',
      preferred_language: member.preferred_language || 'de',
    })
    setShowModal(true)
  }

  // Grid columns based on breakpoint
  const gridColumns =
    breakpoint === 'desktop' || breakpoint === 'tablet'
      ? 'repeat(3, 1fr)'
      : breakpoint === 'mobile'
        ? 'repeat(2, 1fr)'
        : '1fr'

  return (
    <div data-testid="members-page" style={{ padding: '20px' }}>
      {/* Stats Grid */}
      <div
        data-testid="members-stats-grid"
        style={{
          display: 'grid',
          gridTemplateColumns: gridColumns,
          gap: theme.spacing.xl,
          marginBottom: theme.spacing['2xl'],
        }}
      >
        <StatCard
          icon={<UsersIcon />}
          label="Mitglieder"
          value={activeMembersCount}
          color="green"
        />
        <StatCard
          icon={<BankIcon />}
          label="Offene Posten"
          value={formatPrice(totalBalance)}
          color="blue"
        />
        <StatCard
          icon={<CalendarIcon />}
          label="Letzte Abrechnung"
          value={lastSettlementDate ? formatDate(lastSettlementDate.split('T')[0]) : '—'}
          color="blue"
        />
      </div>

      <h1 style={{ margin: '0 0 20px 0' }}>Mitglieder</h1>

      {/* Search bar + filters + create button (compact row) */}
      <div
        style={{
          display: 'flex',
          gap: theme.spacing.md,
          padding: `${theme.spacing.md} ${theme.spacing.lg}`,
          borderBottom: `1px solid ${tableColors.rowActiveBorder}`,
          alignItems: 'center',
          justifyContent: 'space-between',
        }}
      >
        {/* Members count summary - LEFT */}
        <span data-testid="members-count-summary" style={{ color: theme.colors.text.secondary, fontSize: '14px', whiteSpace: 'nowrap' }}>
          <strong style={{ color: theme.colors.text.primary }}>{totalMembers}</strong> Mitglieder gefunden
        </span>

        {/* Search input */}
        <input
          type="text"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value)
            setPage(1)
          }}
          placeholder="Search members..."
          data-testid="members-search-input"
          style={{
            flex: 1,
            padding: '8px 12px',
            backgroundColor: '#0d1829',
            border: '1px solid #2d3748',
            borderRadius: 8,
            color: '#e2e8f0',
            fontSize: '14px',
            fontFamily: 'inherit',
            maxWidth: '400px',
            height: '40px',
            boxSizing: 'border-box',
            verticalAlign: 'middle',
            transition: 'all 0.15s',
          }}
          onFocus={(e) => {
            e.currentTarget.style.borderColor = 'rgba(59,130,246,0.5)'
          }}
          onBlur={(e) => {
            e.currentTarget.style.borderColor = '#2d3748'
          }}
        />

        {/* Status Filter + Sort (right side) */}
        <div
          style={{
            display: 'flex',
            gap: theme.spacing.md,
            alignItems: 'center',
          }}
        >
          {/* Status Filter Component */}
          <StatusFilter
            value={filterIsActive}
            onChange={(status) => {
              setFilterIsActive(status)
              setPage(1)
            }}
            testId="members-filter-status"
          />

          {/* Sort Dropdown Component */}
          <SortDropdown
            options={[
              { value: 'created_at-desc', label: 'Newest first', direction: 'desc' },
              { value: 'created_at-asc', label: 'Oldest first', direction: 'asc' },
              { value: 'first_name-asc', label: 'First Name (A-Z)', direction: 'asc' },
              { value: 'first_name-desc', label: 'First Name (Z-A)', direction: 'desc' },
              { value: 'last_name-asc', label: 'Last Name (A-Z)', direction: 'asc' },
              { value: 'last_name-desc', label: 'Last Name (Z-A)', direction: 'desc' },
            ]}
            value={sortByValue}
            onChange={(value) => {
              const [key, direction] = value.split('-')
              setSortKey(key as 'first_name' | 'last_name' | 'created_at')
              setSortDirection(direction as 'asc' | 'desc')
              setSortByValue(value)
              setPage(1)
            }}
            testId="members-sort"
          />

          {/* Create button */}
          <button
            data-testid="members-create-button"
            onClick={() => {
              setEditingMember(null)
              setFormData({ first_name: '', last_name: '', email: '', iban: '', mandate_signed_at: '', preferred_language: 'de' })
              setShowModal(true)
            }}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: theme.spacing.sm,
              padding: `${tableSpacing.cellPaddingVertical} ${tableSpacing.cellPaddingHorizontal}`,
              background: theme.colors.semantic.primary,
              border: 'none',
              borderRadius: '6px',
              color: 'white',
              cursor: 'pointer',
              fontSize: '14px',
              fontWeight: '500',
              whiteSpace: 'nowrap',
            }}
          >
            <PlusIcon size={18} />
            <span>Hinzufügen</span>
          </button>
        </div>
      </div>

        {/* Table */}
        {error && (
          <div
            data-testid="members-error-message"
            style={{
              padding: theme.spacing.lg,
              background: `${theme.colors.semantic.danger}20`,
              borderBottom: `1px solid ${theme.colors.semantic.danger}`,
              color: theme.colors.semantic.danger,
              fontSize: theme.typography.fontSize.sm,
            }}
          >
            {error}
          </div>
        )}

        {loading ? (
          <div data-testid="members-loading" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
            Loading members...
          </div>
        ) : members.length === 0 ? (
          <div data-testid="members-empty-state" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
            No members found
          </div>
        ) : (
          <div data-testid="members-table-wrapper" style={tableWrapperStyles}>
            <table
              data-testid="members-table"
              style={tableElementStyles}
            >
              <thead>
                <tr style={headerRowStyle}>
                  <th style={{ ...headerCellBaseStyle, width: '80px', textAlign: 'center' }}>Status</th>
                  <th style={headerCellBaseStyle}>
                    <SortableTableHeader
                      label="Name"
                      sortKey="first_name"
                      currentSort={{ key: sortKey, direction: sortDirection }}
                      onSort={(key: string, direction: 'asc' | 'desc') => {
                        setSortKey(key as 'first_name' | 'last_name' | 'created_at')
                        setSortDirection(direction)
                        setSortByValue(`${key}-${direction}`)
                        setPage(1)
                      }}
                    />
                  </th>
                  <th style={{ ...headerCellBaseStyle, width: '200px', textAlign: 'center' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {members.map((member) => (
                  <tr
                    key={member.id}
                    data-testid={`members-table-row-${member.id}`}
                    style={getRowStyle(member.is_active)}
                    onMouseEnter={(e: React.MouseEvent<HTMLTableRowElement>) => {
                      if (member.is_active) {
                        e.currentTarget.style.backgroundColor = tableColors.rowActiveHoverBg
                      }
                    }}
                    onMouseLeave={(e: React.MouseEvent<HTMLTableRowElement>) => {
                      e.currentTarget.style.backgroundColor = member.is_active
                        ? tableColors.rowActiveBg
                        : tableColors.rowInactiveBg
                    }}
                  >
                    <StatusToggleCell
                      enabled={member.is_active}
                      onChange={() => handleStatusToggle(member)}
                      size="small"
                      testId={`members-status-toggle-${member.id}`}
                      cellTestId={`members-table-cell-status-${member.id}`}
                    />
                    <TableCell testId={`members-table-cell-name-${member.id}`}>
                      {member.first_name} {member.last_name}
                    </TableCell>
                    <TableCell align="center">
                      <button
                        data-testid={`members-table-action-edit-${member.id}`}
                        onClick={() => handleEdit(member)}
                        style={{
                          background: 'transparent',
                          border: 'none',
                          color: theme.colors.semantic.primary,
                          cursor: 'pointer',
                          padding: theme.spacing.sm,
                        }}
                        title="Edit"
                      >
                        <EditIcon size={18} />
                      </button>
                      <button
                        data-testid={`members-table-action-delete-${member.id}`}
                        onClick={() => setDeleteConfirm(member.id)}
                        style={{
                          background: 'transparent',
                          border: 'none',
                          color: theme.colors.semantic.danger,
                          cursor: 'pointer',
                          padding: theme.spacing.sm,
                          marginLeft: theme.spacing.md,
                        }}
                        title="Delete"
                      >
                        <TrashIcon size={18} />
                      </button>
                      <button
                        data-testid={`view-transactions-button-${member.id}`}
                        onClick={() => setSelectedMemberForTransactions(member)}
                        style={{
                          background: 'transparent',
                          border: 'none',
                          color: theme.colors.semantic.primary,
                          cursor: 'pointer',
                          padding: theme.spacing.sm,
                          marginLeft: theme.spacing.md,
                        }}
                        title="View Transactions"
                      >
                        <BookIcon size={18} />
                      </button>
                    </TableCell>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* Pagination */}
        {!loading && members.length > 0 && (
          <PaginationToolbar
            currentPage={page}
            totalPages={Math.ceil(totalMembers / 20)}
            totalItems={totalMembers}
            pageSize={20}
            onPageChange={setPage}
            onPageSizeChange={() => {}} // Not implemented - always use 20
            variant="default"
            showPageSize={false}
            showInfo={true}
            testId="members-pagination"
          />
        )}

      {/* Create/Edit Modal */}
      {showModal && (
        <div
          data-testid="members-form-modal"
          style={{
            position: 'fixed',
            top: 0,
            left: 0,
            right: 0,
            bottom: 0,
            background: 'rgba(0, 0, 0, 0.5)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 1000,
          }}
          onClick={() => setShowModal(false)}
        >
          <div
            data-testid="members-form-modal-content"
            style={{
              background: theme.colors.bg.secondary,
              borderRadius: theme.borderRadius.lg,
              padding: theme.spacing.xl,
              maxWidth: '500px',
              width: '90%',
              boxShadow: '0 25px 50px rgba(0, 0, 0, 0.5)',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            <h2 data-testid="members-form-title" style={{ margin: 0, marginBottom: theme.spacing.lg, fontSize: theme.typography.fontSize.xl }}>
              {editingMember ? 'Edit Member' : 'New Member'}
            </h2>

            <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.lg }}>
              <div>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  First Name *
                </label>
                <input
                  data-testid="members-form-first-name-input"
                  type="text"
                  required
                  value={formData.first_name}
                  onChange={(e) => setFormData({ ...formData, first_name: e.target.value })}
                  placeholder="Max"
                  maxLength={100}
                  style={{
                    width: '100%',
                    padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                    background: theme.colors.bg.input,
                    border: `1px solid ${theme.colors.border.light}`,
                    borderRadius: theme.borderRadius.md,
                    color: theme.colors.text.primary,
                    boxSizing: 'border-box',
                  }}
                />
              </div>

              <div>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  Last Name *
                </label>
                <input
                  data-testid="members-form-last-name-input"
                  type="text"
                  required
                  value={formData.last_name}
                  onChange={(e) => setFormData({ ...formData, last_name: e.target.value })}
                  placeholder="Mustermann"
                  maxLength={100}
                  style={{
                    width: '100%',
                    padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                    background: theme.colors.bg.input,
                    border: `1px solid ${theme.colors.border.light}`,
                    borderRadius: theme.borderRadius.md,
                    color: theme.colors.text.primary,
                    boxSizing: 'border-box',
                  }}
                />
              </div>

              <div>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  Email
                </label>
                <input
                  data-testid="members-form-email-input"
                  type="email"
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                  placeholder="max@example.com"
                  style={{
                    width: '100%',
                    padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                    background: theme.colors.bg.input,
                    border: `1px solid ${theme.colors.border.light}`,
                    borderRadius: theme.borderRadius.md,
                    color: theme.colors.text.primary,
                    boxSizing: 'border-box',
                  }}
                />
              </div>

              <div>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  IBAN *
                </label>
                <input
                  data-testid="members-form-iban-input"
                  type="text"
                  required
                  value={formData.iban}
                  onChange={(e) => setFormData({ ...formData, iban: e.target.value.toUpperCase() })}
                  placeholder="DE89370400440532013000"
                  minLength={15}
                  maxLength={34}
                  style={{
                    width: '100%',
                    padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                    background: theme.colors.bg.input,
                    border: `1px solid ${theme.colors.border.light}`,
                    borderRadius: theme.borderRadius.md,
                    color: theme.colors.text.primary,
                    boxSizing: 'border-box',
                    fontFamily: 'monospace',
                  }}
                />
              </div>

              <div>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  Mandate Date (SEPA) *
                </label>
                <input
                  data-testid="members-form-mandate-date-input"
                  type="date"
                  required
                  value={formData.mandate_signed_at}
                  onChange={(e) => setFormData({ ...formData, mandate_signed_at: e.target.value })}
                  max={new Date().toISOString().split('T')[0]}
                  style={{
                    width: '100%',
                    padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                    background: theme.colors.bg.input,
                    border: `1px solid ${theme.colors.border.light}`,
                    borderRadius: theme.borderRadius.md,
                    color: theme.colors.text.primary,
                    boxSizing: 'border-box',
                  }}
                />
              </div>

              <div>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  Language *
                </label>
                <LanguageSelector
                  value={formData.preferred_language as 'de' | 'en'}
                  onChange={(language) => setFormData({ ...formData, preferred_language: language })}
                  testId="members-form-language-select"
                  required
                />
              </div>

              <div style={{ display: 'flex', gap: theme.spacing.lg, justifyContent: 'flex-end', marginTop: theme.spacing.lg }}>
                <button
                  data-testid="members-form-cancel-button"
                  type="button"
                  onClick={() => setShowModal(false)}
                  style={{
                    padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                    background: 'transparent',
                    border: `1px solid ${theme.colors.border.light}`,
                    borderRadius: theme.borderRadius.md,
                    color: theme.colors.text.primary,
                    cursor: 'pointer',
                    fontSize: theme.typography.fontSize.sm,
                    fontWeight: theme.typography.fontWeight.semibold,
                  }}
                >
                  Cancel
                </button>
                <button
                  data-testid="members-form-submit-button"
                  type="submit"
                  style={{
                    padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                    background: theme.colors.semantic.primary,
                    border: 'none',
                    borderRadius: theme.borderRadius.md,
                    color: 'white',
                    cursor: 'pointer',
                    fontSize: theme.typography.fontSize.sm,
                    fontWeight: theme.typography.fontWeight.semibold,
                  }}
                >
                  Save
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Delete Confirmation Dialog */}
      {deleteConfirm && (
        <div
          data-testid="members-delete-confirm-modal"
          style={{
            position: 'fixed',
            top: 0,
            left: 0,
            right: 0,
            bottom: 0,
            background: 'rgba(0, 0, 0, 0.5)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 1001,
          }}
          onClick={() => setDeleteConfirm(null)}
        >
          <div
            data-testid="members-delete-confirm-content"
            style={{
              background: theme.colors.bg.secondary,
              borderRadius: theme.borderRadius.lg,
              padding: theme.spacing.xl,
              maxWidth: '400px',
              width: '90%',
              boxShadow: '0 25px 50px rgba(0, 0, 0, 0.5)',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            <h2 data-testid="members-delete-confirm-title" style={{ margin: 0, marginBottom: theme.spacing.lg, fontSize: theme.typography.fontSize.lg }}>
              Confirm Delete
            </h2>
            <p data-testid="members-delete-confirm-message" style={{ color: theme.colors.text.secondary, marginBottom: theme.spacing.lg }}>
              Are you sure you want to deactivate this member? This action cannot be undone.
            </p>

            <div style={{ display: 'flex', gap: theme.spacing.lg, justifyContent: 'flex-end' }}>
              <button
                data-testid="members-delete-confirm-cancel"
                onClick={() => setDeleteConfirm(null)}
                style={{
                  padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                  background: 'transparent',
                  border: `1px solid ${theme.colors.border.light}`,
                  borderRadius: theme.borderRadius.md,
                  color: theme.colors.text.primary,
                  cursor: 'pointer',
                  fontSize: theme.typography.fontSize.sm,
                  fontWeight: theme.typography.fontWeight.semibold,
                }}
              >
                Cancel
              </button>
              <button
                data-testid="members-delete-confirm-ok"
                onClick={() => handleDelete(deleteConfirm)}
                style={{
                  padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                  background: theme.colors.semantic.danger,
                  border: 'none',
                  borderRadius: theme.borderRadius.md,
                  color: 'white',
                  cursor: 'pointer',
                  fontSize: theme.typography.fontSize.sm,
                  fontWeight: theme.typography.fontWeight.semibold,
                }}
              >
                Delete
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Transaction Modal (UC-A20) */}
      {selectedMemberForTransactions && (
        <TransactionModal
          isOpen={!!selectedMemberForTransactions}
          memberId={selectedMemberForTransactions.id}
          memberName={`${selectedMemberForTransactions.first_name} ${selectedMemberForTransactions.last_name}`}
          currentBalance={selectedMemberForTransactions.balance_cents}
          onClose={() => setSelectedMemberForTransactions(null)}
        />
      )}
    </div>
  )
}
