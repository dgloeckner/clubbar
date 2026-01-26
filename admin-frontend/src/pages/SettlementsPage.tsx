/**
 * Settlements Page
 * Settlement / billing management
 */

import React from 'react'
import { Card } from '../components/common/Card'
import { theme } from '../styles/design-system'

export function SettlementsPage() {
  return (
    <div>
      <Card title="Abrechnungen" subtitle="Settlement and billing management">
        <div style={{ padding: theme.spacing.lg, color: theme.colors.text.secondary }}>
          <p>Settlements management page - Coming in Phase 2</p>
          <p style={{ fontSize: theme.typography.fontSize.sm }}>
            Features to implement:
            <ul>
              <li>Settlement list with status</li>
              <li>Create new settlement</li>
              <li>Preview settlement details</li>
              <li>Export to SEPA XML</li>
              <li>Export to CSV</li>
              <li>View settlement history</li>
            </ul>
          </p>
        </div>
      </Card>
    </div>
  )
}
