/**
 * Statistics Page
 * Dashboard with metrics and analytics
 */

import { Card } from '../components/common/Card'
import { theme } from '../styles/design-system'

export function StatisticsPage() {
  return (
    <div>
      <Card title="Statistik" subtitle="Dashboard and analytics">
        <div style={{ padding: theme.spacing.lg, color: theme.colors.text.secondary }}>
          <p>Statistics and analytics page - Coming in Phase 2</p>
          <p style={{ fontSize: theme.typography.fontSize.sm }}>
            Features to implement:
            <ul>
              <li>Key metrics (members, balance, transactions)</li>
              <li>Recent activity feed</li>
              <li>Terminal status</li>
              <li>Top spenders</li>
              <li>Charts and graphs</li>
            </ul>
          </p>
        </div>
      </Card>
    </div>
  )
}
