/**
 * Members Page
 * Member management (list, create, edit, delete)
 */

import { useEffect, useState } from 'react'
import { Card } from '../components/common/Card'
import { StatCard } from '../components/common/StatCard'
import { TransactionModal } from '../components/modals/TransactionModal'
import { theme } from '../styles/design-system'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { useLoading } from '../context/LoadingContext'
import { UsersIcon, BankIcon, CalendarIcon, TrashIcon, EditIcon, PlusIcon } from '../components/icons'
import { formatPrice, formatDate } from '../styles/design-system'
import { getMembers, createMember, updateMember, deactivateMember, Member } from '../services/members'
import { TableSearchToolbar } from '../components/tables/TableSearchToolbar'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { Toggle } from '../components/forms/Toggle'
import {
  tableWrapperStyles,
  tableElementStyles,
  headerRowStyle,
  headerCellBaseStyle,
  tableColors,
  tableSpacing,
} from '../styles/tableTokens'

export function MembersPage() {
  const breakpoint = useBreakpoint()
  const { setIsLoading } = useLoading()
  const [members, setMembers] = useState<Member[]>([])
  const [totalMembers, setTotalMembers] = useState(0)
  const [totalBalance, setTotalBalance] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [showModal, setShowModal] = useState(false)
  const [editingMember, setEditingMember] = useState<Member | null>(null)
  const [deleteConfirm, setDeleteConfirm] = useState<string | null>(null)
  const [selectedMemberForTransactions, setSelectedMemberForTransactions] = useState<Member | null>(null)
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
        const response = await getMembers(page, 20, search || undefined)

        setMembers(response.items)
        setTotalMembers(response.total)

        // Calculate total balance
        const total = response.items.reduce((sum, m) => sum + m.balance_cents, 0)
        setTotalBalance(total)

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
  }, [page, search, setIsLoading])

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

      // Reload members
      const response = await getMembers(1, 20)
      setMembers(response.items)
      setPage(1)

      // Reset form
      setShowModal(false)
      setEditingMember(null)
      setFormData({ first_name: '', last_name: '', email: '', iban: '', mandate_signed_at: '', preferred_language: 'de' })
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

      // Reload members
      const response = await getMembers(page, 20, search || undefined)
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

      // Reload members
      const response = await getMembers(page, 20, search || undefined)
      setMembers(response.items)
      setTotalMembers(response.total)

      setDeleteConfirm(null)
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
    <div data-testid="members-page">
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
          value={totalMembers}
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
          value={formatDate(new Date().toISOString().split('T')[0])}
          color="blue"
        />
      </div>

      {/* Members Table */}
      <Card title="Mitglieder" subtitle="Manage club members and their accounts">
        {/* Search bar and create button */}
        <TableSearchToolbar
          value={search}
          onChange={(value) => {
            setSearch(value)
            setPage(1)
          }}
          placeholder="Search members..."
          testId="members-search-toolbar"
          actions={
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
          }
        />

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
                  <th style={headerCellBaseStyle}>Name</th>
                  <th style={{ ...headerCellBaseStyle, width: '120px', textAlign: 'right' }}>Balance</th>
                  <th style={{ ...headerCellBaseStyle, width: '200px', textAlign: 'center' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {members.map((member) => (
                  <tr
                    key={member.id}
                    data-testid={`members-table-row-${member.id}`}
                    style={{
                      borderBottom: `1px solid ${tableColors.rowActiveBorder}`,
                      background: member.is_active ? 'transparent' : `${theme.colors.semantic.danger}05`,
                      opacity: member.is_active ? 1 : 0.6,
                    }}
                  >
                    <td style={{ padding: tableSpacing.cellPadding, color: tableColors.cellText, textAlign: 'center', width: '80px' }}>
                      <Toggle
                        enabled={member.is_active}
                        onChange={() => handleStatusToggle(member)}
                        size="small"
                        testId={`members-status-toggle-${member.id}`}
                      />
                    </td>
                    <td style={{ padding: tableSpacing.cellPadding, color: tableColors.cellText, overflow: 'hidden', textOverflow: 'ellipsis' }}>
                      <span data-testid={`members-table-cell-name-${member.id}`}>
                        {member.first_name} {member.last_name}
                      </span>
                    </td>
                    <td style={{ padding: tableSpacing.cellPadding, textAlign: 'right', color: tableColors.cellText, width: '120px' }}>
                      <span data-testid={`members-table-cell-balance-${member.id}`}>
                        {formatPrice(member.balance_cents)}
                      </span>
                    </td>
                    <td style={{ padding: tableSpacing.cellPadding, textAlign: 'center', width: '200px' }}>
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
                        📊
                      </button>
                    </td>
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
      </Card>

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
                <select
                  data-testid="members-form-language-select"
                  required
                  value={formData.preferred_language}
                  onChange={(e) => setFormData({ ...formData, preferred_language: e.target.value })}
                  style={{
                    width: '100%',
                    padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                    background: theme.colors.bg.input,
                    border: `1px solid ${theme.colors.border.light}`,
                    borderRadius: theme.borderRadius.md,
                    color: theme.colors.text.primary,
                    boxSizing: 'border-box',
                  }}
                >
                  <option value="de">Deutsch</option>
                  <option value="en">English</option>
                </select>
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
