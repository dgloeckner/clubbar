/**
 * Members Page
 * Member management (list, create, edit, delete)
 */

import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { StatCard } from '../components/common/StatCard'
import { theme } from '../styles/design-system'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { useLoading } from '../context/LoadingContext'
import { useFormatters } from '../hooks/useFormatters'
import { UsersIcon, BankIcon, CalendarIcon, TrashIcon, EditIcon, PlusIcon } from '../components/icons'
import { getMembers, createMember, updateMember, deactivateMember, Member } from '../services/members'
import { getDashboardMetrics } from '../services/dashboard'
// TableSearchToolbar is available but not currently used
// import { TableSearchToolbar } from '../components/tables/TableSearchToolbar'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { SortableTableHeader } from '../components/tables/SortableTableHeader'
import { StatusFilterPills } from '../components/forms/StatusFilterPills'
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
  const { t } = useTranslation()
  const formatters = useFormatters()
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
  const [sortKey, setSortKey] = useState<'first_name' | 'last_name' | 'created_at'>('created_at')
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('desc')
  const [filterIsActive, setFilterIsActive] = useState<'all' | 'active' | 'inactive'>('all')
  const [formData, setFormData] = useState({
    first_name: '',
    last_name: '',
    email: '',
    iban: '',
    account_holder_name: '',
    mandate_reference: '',
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

      // Reset form
      setShowModal(false)
      setEditingMember(null)
      setFormData({ first_name: '', last_name: '', email: '', iban: '', account_holder_name: '', mandate_reference: '', mandate_signed_at: '', preferred_language: 'de' })

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

      setError(null)
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
      // Only send the field that needs to be updated
      await updateMember(member.id, { is_active: !member.is_active })

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
      account_holder_name: member.account_holder_name || '',
      mandate_reference: member.mandate_reference || '',
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
          label={t('members.title')}
          value={activeMembersCount}
          color="green"
        />
        <StatCard
          icon={<BankIcon />}
          label={t('common.balance')}
          value={formatters.formatPrice(totalBalance)}
          color="blue"
        />
        <StatCard
          icon={<CalendarIcon />}
          label={t('settlements.settlementDate')}
          value={lastSettlementDate ? formatters.formatDate(lastSettlementDate.split('T')[0]) : '—'}
          color="blue"
        />
      </div>

      <h1 style={{ margin: '0 0 20px 0' }}>{t('members.title')}</h1>

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
          <strong style={{ color: theme.colors.text.primary }}>{totalMembers}</strong> {t('members.title')} {t('common.found')}
        </span>

        {/* Search input */}
        <input
          type="text"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value)
            setPage(1)
          }}
          placeholder={t('common.searchPlaceholder')}
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
          <StatusFilterPills
            value={filterIsActive}
            onChange={(status) => {
              setFilterIsActive(status)
              setPage(1)
            }}
            testId="members-filter-status"
          />

          {/* Create button */}
          <button
            data-testid="members-create-button"
            onClick={() => {
              setEditingMember(null)
              setFormData({ first_name: '', last_name: '', email: '', iban: '', account_holder_name: '', mandate_reference: '', mandate_signed_at: '', preferred_language: 'de' })
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
            <span>{t('common.add')}</span>
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
            {t('common.loading')}
          </div>
        ) : members.length === 0 ? (
          <div data-testid="members-empty-state" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
            {t('members.noMembers')}
          </div>
        ) : (
          <div data-testid="members-table-wrapper" style={tableWrapperStyles}>
            <table
              data-testid="members-table"
              style={tableElementStyles}
            >
              <thead>
                <tr style={headerRowStyle}>
                  <th style={{ ...headerCellBaseStyle, width: '80px', textAlign: 'center' }}>{t('common.status')}</th>
                  <th style={{ ...headerCellBaseStyle, width: '100px', textAlign: 'center' }} data-testid="members-table-header-sepa">
                    SEPA
                  </th>
                  <th style={headerCellBaseStyle}>
                    <SortableTableHeader
                      label={t('common.name')}
                      sortKey="first_name"
                      currentSort={{ key: sortKey, direction: sortDirection }}
                      onSort={(key: string, direction: 'asc' | 'desc') => {
                        setSortKey(key as 'first_name' | 'last_name' | 'created_at')
                        setSortDirection(direction)
                        setPage(1)
                      }}
                    />
                  </th>
                  <th style={{ ...headerCellBaseStyle, width: '120px' }}>
                    <SortableTableHeader
                      label={t('members.memberSince')}
                      sortKey="created_at"
                      currentSort={{ key: sortKey, direction: sortDirection }}
                      onSort={(key: string, direction: 'asc' | 'desc') => {
                        setSortKey(key as 'first_name' | 'last_name' | 'created_at')
                        setSortDirection(direction)
                        setPage(1)
                      }}
                    />
                  </th>
                  <th style={{ ...headerCellBaseStyle, width: '200px', textAlign: 'center' }}>{t('common.actions')}</th>
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
                    <td style={{ textAlign: 'center' }} data-testid={`members-table-cell-sepa-${member.id}`}>
                      <span
                        style={{
                          padding: '4px 8px',
                          borderRadius: 4,
                          fontSize: 12,
                          fontWeight: 500,
                          backgroundColor: member.is_sepa_valid ? theme.colors.semantic.success : theme.colors.semantic.danger,
                          color: '#ffffff',
                          display: 'inline-block',
                        }}
                      >
                        {member.is_sepa_valid ? t('members.valid') : t('members.missing')}
                      </span>
                    </td>
                    <TableCell testId={`members-table-cell-name-${member.id}`}>
                      {member.first_name} {member.last_name}
                    </TableCell>
                    <TableCell testId={`members-table-cell-created-${member.id}`}>
                      {formatters.formatDate(member.created_at.split('T')[0])}
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
              maxWidth: '900px',
              width: '90%',
              boxShadow: '0 25px 50px rgba(0, 0, 0, 0.5)',
              maxHeight: '90vh',
              overflowY: 'auto',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            <h2 data-testid="members-form-title" style={{ margin: 0, marginBottom: theme.spacing.lg, fontSize: theme.typography.fontSize.xl }}>
              {editingMember ? t('members.editMember') : t('members.createMember')}
            </h2>

            {/* SEPA Status Indicator */}
            {editingMember && (
              <div
                style={{
                  padding: '12px 16px',
                  borderRadius: 6,
                  marginBottom: theme.spacing.md,
                  backgroundColor: editingMember.is_sepa_valid
                    ? 'rgba(34, 197, 94, 0.1)'
                    : 'rgba(239, 68, 68, 0.1)',
                  border: `1px solid ${editingMember.is_sepa_valid ? theme.colors.semantic.success : theme.colors.semantic.danger}`,
                  display: 'flex',
                  alignItems: 'center',
                  gap: theme.spacing.sm,
                }}
                data-testid="members-form-sepa-status"
              >
                <span style={{ fontSize: 18 }}>
                  {editingMember.is_sepa_valid ? '✓' : '⚠'}
                </span>
                <div>
                  <div style={{ fontWeight: 600, fontSize: 14, color: theme.colors.text.primary }}>
                    {editingMember.is_sepa_valid ? t('members.sepaValid') : t('members.sepaMissing')}
                  </div>
                  {!editingMember.is_sepa_valid && (
                    <div style={{ fontSize: 12, color: theme.colors.text.secondary, marginTop: 4 }}>
                      {t('members.sepaMissingHint')}
                    </div>
                  )}
                </div>
              </div>
            )}

            <form onSubmit={handleSubmit} style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: theme.spacing.lg, columnGap: theme.spacing.xl }}>
              <div>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  {t('members.firstName')} *
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
                  {t('members.lastName')} *
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
                  {t('members.email')}
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
                  {t('members.iban')} <span style={{ color: theme.colors.semantic.danger }}>*</span>
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
                  {t('members.accountHolderName')}
                </label>
                <input
                  data-testid="members-form-account-holder-name-input"
                  type="text"
                  value={formData.account_holder_name}
                  onChange={(e) => setFormData({ ...formData, account_holder_name: e.target.value })}
                  placeholder="Only if different from member"
                  maxLength={70}
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
                <span style={{ fontSize: '12px', color: theme.colors.text.secondary, marginTop: '4px', display: 'block' }}>
                  {t('members.accountHolderHint')}
                </span>
              </div>

              <div>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  {t('members.mandateReference')} <span style={{ color: theme.colors.semantic.danger }}>*</span>
                </label>
                <input
                  data-testid="members-form-mandate-reference-input"
                  type="text"
                  required
                  value={formData.mandate_reference}
                  onChange={(e) => setFormData({ ...formData, mandate_reference: e.target.value.toUpperCase() })}
                  placeholder="Unique mandate identifier"
                  minLength={1}
                  maxLength={35}
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
                  {t('members.mandateSignedAt')} <span style={{ color: theme.colors.semantic.danger }}>*</span>
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

              <div style={{ gridColumn: '1 / -1' }}>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  {t('members.preferredLanguage')} *
                </label>
                <LanguageSelector
                  value={formData.preferred_language as 'de' | 'en'}
                  onChange={(language) => setFormData({ ...formData, preferred_language: language })}
                  testId="members-form-language-select"
                  required
                />
              </div>

              <div style={{ gridColumn: '1 / -1', display: 'flex', gap: theme.spacing.lg, justifyContent: 'flex-end', marginTop: theme.spacing.lg }}>
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
                  {t('common.cancel')}
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
                  {t('common.save')}
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
              {t('members.deleteMember')}
            </h2>
            <p data-testid="members-delete-confirm-message" style={{ color: theme.colors.text.secondary, marginBottom: theme.spacing.lg }}>
              {t('members.deleteConfirm')}
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
                {t('common.cancel')}
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
                {t('common.delete')}
              </button>
            </div>
          </div>
        </div>
      )}

    </div>
  )
}
