/**
 * Members Page
 * Member management (list, create, edit, delete)
 */

import React from 'react'
import { Card } from '../components/common/Card'
import { theme } from '../styles/design-system'

export function MembersPage() {
  return (
    <div>
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
