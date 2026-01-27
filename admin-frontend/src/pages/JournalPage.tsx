/**
 * Journal Page (Buchungsjournal)
 * Global transaction journal - Coming in Phase 3
 *
 * BLOCKED: Requires backend API for filtered global transactions
 *
 * For now, member transactions are available via:
 * - UC-A20: View Transactions modal (in Members page)
 * - See: e2etests/tests/admin/members-uc-a20-transactions.spec.ts
 *
 * TODO (Phase 3+):
 * - Backend must implement: GET /api/admin/transactions
 *   Required parameters: date_from, date_to, type, member_id (optional), page, per_page
 *   See: plans/PHASE2_JOURNAL_REDESIGN.md
 */

import { Card } from '../components/common/Card'
import { theme } from '../styles/design-system'

export function JournalPage() {
  return (
    <div data-testid="journal-page">
      <Card title="Buchungsjournal" subtitle="Transaction journal and booking log">
        <div style={{ padding: theme.spacing.lg, color: theme.colors.text.secondary }}>
          <p>Global transaction journal page - Coming in Phase 3</p>
          <p style={{ fontSize: theme.typography.fontSize.sm }}>
            <strong>Current Status:</strong> Awaiting backend implementation
          </p>
          <p style={{ fontSize: theme.typography.fontSize.sm }}>
            <strong>In the meantime:</strong> View member transactions via the "View Transactions" button in the Members
            page (implements UC-A20).
          </p>
          <p style={{ fontSize: theme.typography.fontSize.sm }}>
            <strong>Backend Needed:</strong>
            <ul>
              <li>New API endpoint: GET /api/admin/transactions</li>
              <li>Filtering: date_from, date_to, type, member_id (optional)</li>
              <li>Pagination: page, per_page parameters</li>
              <li>Response: Array of transactions with member details, product info, amounts</li>
            </ul>
          </p>
          <p style={{ fontSize: theme.typography.fontSize.sm }}>
            <strong>Reference:</strong> See plans/PHASE2_JOURNAL_REDESIGN.md for full spec
          </p>
        </div>
      </Card>
    </div>
  )
}
