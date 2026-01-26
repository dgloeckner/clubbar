/**
 * Journal Page
 * Transaction journal / booking log
 */

import React from 'react'
import { Card } from '../components/common/Card'
import { theme } from '../styles/design-system'

export function JournalPage() {
  return (
    <div>
      <Card title="Buchungsjournal" subtitle="Transaction journal and booking log">
        <div style={{ padding: theme.spacing.lg, color: theme.colors.text.secondary }}>
          <p>Transaction journal page - Coming in Phase 2</p>
          <p style={{ fontSize: theme.typography.fontSize.sm }}>
            Features to implement:
            <ul>
              <li>Transaction list with filters</li>
              <li>Filter by member, date range, type</li>
              <li>View transaction details</li>
              <li>Export transactions to CSV</li>
              <li>Record corrections/reversals</li>
            </ul>
          </p>
        </div>
      </Card>
    </div>
  )
}
