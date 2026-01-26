/**
 * Members Page
 * Member management (list, create, edit, delete)
 */

import { Card } from '../components/common/Card'
import { StatCard } from '../components/common/StatCard'
import { theme } from '../styles/design-system'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { UsersIcon, BankIcon, CalendarIcon } from '../components/icons'
import { formatPrice, formatDate } from '../styles/design-system'

export function MembersPage() {
  const breakpoint = useBreakpoint()

  // Mock data - TODO: Replace with API calls
  const totalMembers = 128
  const totalBalance = 4567 // in cents
  const lastSettlement = '31.12.2024'

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
        <StatCard icon={<BankIcon />} label="Offene Posten" value={formatPrice(totalBalance)} color="blue" />
        <StatCard
          icon={<CalendarIcon />}
          label="Letzte Abrechnung"
          value={formatDate(lastSettlement)}
          color="blue"
        />
      </div>

      {/* Members Table */}
      <Card title="Members" subtitle="Manage club members and their accounts">
        <div style={{ padding: theme.spacing.lg, color: theme.colors.text.secondary }}>
          <p>Members management page - Coming in Phase 2</p>
          <p style={{ fontSize: theme.typography.fontSize.sm }}>
            Features to implement:
            <ul>
              <li>Member list with search and filter</li>
              <li>Create new member</li>
              <li>Edit member details</li>
              <li>View member balance and transactions</li>
              <li>Delete/deactivate member</li>
            </ul>
          </p>
        </div>
      </Card>
    </div>
  )
}
