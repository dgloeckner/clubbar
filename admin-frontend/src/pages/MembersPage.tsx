/**
 * Members Page
 * Member management (list, create, edit, delete)
 */

import { useEffect, useState } from 'react'
import { Card } from '../components/common/Card'
import { StatCard } from '../components/common/StatCard'
import { theme } from '../styles/design-system'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { useLoading } from '../context/LoadingContext'
import { UsersIcon, BankIcon, CalendarIcon, TrashIcon, EditIcon, PlusIcon } from '../components/icons'
import { formatPrice, formatDate } from '../styles/design-system'
import { getMembers, createMember, updateMember, deactivateMember, Member } from '../services/members'

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
  const [formData, setFormData] = useState({
    email: '',
    first_name: '',
    last_name: '',
    phone: '',
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
      setFormData({ email: '', first_name: '', last_name: '', phone: '' })
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save member')
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
      const response = await getMembers(page, 20)
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
      email: member.email,
      first_name: member.first_name,
      last_name: member.last_name,
      phone: member.phone || '',
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
    <div>
      {/* Stats Grid */}
      <div
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
        <div style={{ padding: theme.spacing.lg, borderBottom: `1px solid ${theme.colors.border.light}`, display: 'flex', gap: theme.spacing.md, alignItems: 'center' }}>
          <input
            type="text"
            placeholder="Search members..."
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
            style={{
              flex: 1,
              padding: `${theme.spacing.md} ${theme.spacing.lg}`,
              background: theme.colors.bg.input,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              color: theme.colors.text.primary,
              fontSize: theme.typography.fontSize.base,
              boxSizing: 'border-box',
            }}
          />
          <button
            onClick={() => {
              setEditingMember(null)
              setFormData({ email: '', first_name: '', last_name: '', phone: '' })
              setShowModal(true)
            }}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: theme.spacing.sm,
              padding: `${theme.spacing.md} ${theme.spacing.lg}`,
              background: theme.colors.semantic.primary,
              border: 'none',
              borderRadius: theme.borderRadius.md,
              color: 'white',
              cursor: 'pointer',
              fontSize: theme.typography.fontSize.sm,
              fontWeight: theme.typography.fontWeight.semibold,
              whiteSpace: 'nowrap',
            }}
          >
            <PlusIcon size={18} />
            <span>Hinzufügen</span>
          </button>
        </div>

        {/* Table */}
        {error && (
          <div
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
          <div style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
            Loading members...
          </div>
        ) : members.length === 0 ? (
          <div style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
            No members found
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table
              style={{
                width: '100%',
                borderCollapse: 'collapse',
                fontSize: theme.typography.fontSize.sm,
              }}
            >
              <thead>
                <tr
                  style={{
                    borderBottom: `1px solid ${theme.colors.border.light}`,
                    background: theme.colors.bg.input,
                  }}
                >
                  <th style={{ padding: theme.spacing.md, textAlign: 'left', fontWeight: 600 }}>Name</th>
                  <th style={{ padding: theme.spacing.md, textAlign: 'left', fontWeight: 600 }}>Email</th>
                  <th style={{ padding: theme.spacing.md, textAlign: 'right', fontWeight: 600 }}>Balance</th>
                  <th style={{ padding: theme.spacing.md, textAlign: 'center', fontWeight: 600 }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {members.map((member) => (
                  <tr
                    key={member.id}
                    style={{
                      borderBottom: `1px solid ${theme.colors.border.light}`,
                      background: member.is_active ? 'transparent' : `${theme.colors.semantic.danger}05`,
                    }}
                  >
                    <td style={{ padding: theme.spacing.md }}>
                      {member.first_name} {member.last_name}
                    </td>
                    <td style={{ padding: theme.spacing.md }}>{member.email}</td>
                    <td style={{ padding: theme.spacing.md, textAlign: 'right' }}>
                      {formatPrice(member.balance_cents)}
                    </td>
                    <td style={{ padding: theme.spacing.md, textAlign: 'center' }}>
                      <button
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
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {/* Create/Edit Modal */}
      {showModal && (
        <div
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
            <h2 style={{ margin: 0, marginBottom: theme.spacing.lg, fontSize: theme.typography.fontSize.xl }}>
              {editingMember ? 'Edit Member' : 'New Member'}
            </h2>

            <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.lg }}>
              <div>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  Email
                </label>
                <input
                  type="email"
                  required
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
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
                  First Name
                </label>
                <input
                  type="text"
                  required
                  value={formData.first_name}
                  onChange={(e) => setFormData({ ...formData, first_name: e.target.value })}
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
                  Last Name
                </label>
                <input
                  type="text"
                  required
                  value={formData.last_name}
                  onChange={(e) => setFormData({ ...formData, last_name: e.target.value })}
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
                  Phone (optional)
                </label>
                <input
                  type="tel"
                  value={formData.phone}
                  onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
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

              <div style={{ display: 'flex', gap: theme.spacing.lg, justifyContent: 'flex-end', marginTop: theme.spacing.lg }}>
                <button
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
            <h2 style={{ margin: 0, marginBottom: theme.spacing.lg, fontSize: theme.typography.fontSize.lg }}>
              Confirm Delete
            </h2>
            <p style={{ color: theme.colors.text.secondary, marginBottom: theme.spacing.lg }}>
              Are you sure you want to deactivate this member? This action cannot be undone.
            </p>

            <div style={{ display: 'flex', gap: theme.spacing.lg, justifyContent: 'flex-end' }}>
              <button
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
    </div>
  )
}
