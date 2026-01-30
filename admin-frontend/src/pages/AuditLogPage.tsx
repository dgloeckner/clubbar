/**
 * Audit Log Page
 * Displays audit trail of all administrative actions in the system
 */

import { useEffect, useState } from 'react'
import { theme } from '../styles/design-system'
import { useLoading } from '../context/LoadingContext'
import { getAuditLogs, getAvailableActions, getAvailableEntityTypes, AuditLogEntry } from '../services/audit-log'
import {
  tableWrapperStyles,
  tableElementStyles,
  headerRowStyle,
  headerCellBaseStyle,
  tableColors,
  getRowStyle,
} from '../styles/tableTokens'
import { SortableTableHeader } from '../components/tables/SortableTableHeader'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { formatDateTime } from '../styles/design-system'

export function AuditLogPage() {
  const { setIsLoading } = useLoading()

  // State management
  const [entries, setEntries] = useState<AuditLogEntry[]>([])
  const [totalEntries, setTotalEntries] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(50)
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('desc')

  // Filters
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [selectedAdmin, setSelectedAdmin] = useState('')
  const [selectedAction, setSelectedAction] = useState('')
  const [selectedEntityType, setSelectedEntityType] = useState('')
  const [searchText, setSearchText] = useState('')
  const [admins, setAdmins] = useState<Array<{ id: string; email: string }>>([])
  const [expandedRowId, setExpandedRowId] = useState<number | null>(null)

  // Load audit logs
  useEffect(() => {
    const loadAuditLogs = async () => {
      try {
        setLoading(true)
        setIsLoading(true)

        const response = await getAuditLogs({
          page,
          per_page: perPage,
          date_from: dateFrom || undefined,
          date_to: dateTo || undefined,
          admin_user_id: selectedAdmin || undefined,
          action: selectedAction || undefined,
          entity_type: selectedEntityType || undefined,
          search: searchText || undefined,
        })

        setEntries(response.items)
        setTotalEntries(response.total)
        setError(null)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load audit log')
        console.error('Failed to load audit logs:', err)
      } finally {
        setLoading(false)
        setIsLoading(false)
      }
    }

    const timer = setTimeout(loadAuditLogs, searchText ? 500 : 0) // Debounce search
    return () => clearTimeout(timer)
  }, [page, perPage, dateFrom, dateTo, selectedAdmin, selectedAction, selectedEntityType, searchText, setIsLoading])

  // Load admin users for filter dropdown
  useEffect(() => {
    // For now, extract from loaded entries
    const adminSet = new Map<string, string>()
    entries.forEach(entry => {
      if (entry.admin_user_id && entry.admin_user_email) {
        adminSet.set(entry.admin_user_id, entry.admin_user_email)
      }
    })
    setAdmins(Array.from(adminSet.entries()).map(([id, email]) => ({ id, email })))
  }, [entries])

  return (
    <div data-testid="audit-log-page" style={{ padding: '20px' }}>
      {/* Page Header */}
      <div style={{ marginBottom: theme.spacing.xl }}>
        <h1 style={{
          margin: 0,
          fontSize: theme.typography.fontSize['2xl'],
          fontWeight: theme.typography.fontWeight.bold,
          color: theme.colors.text.primary,
        }}>
          Audit-Log
        </h1>
        <p style={{
          margin: `${theme.spacing.sm} 0 0 0`,
          fontSize: theme.typography.fontSize.sm,
          color: theme.colors.text.secondary,
        }}>
          System audit trail - all administrative actions and data changes
        </p>
      </div>

      {/* Filters Row */}
      <div style={{
        display: 'flex',
        gap: theme.spacing.md,
        padding: `${theme.spacing.md} ${theme.spacing.lg}`,
        borderBottom: `1px solid ${tableColors.border}`,
        alignItems: 'flex-start',
        flexWrap: 'wrap',
      }}>
        {/* Date Range Filters */}
        <div style={{ display: 'flex', gap: theme.spacing.sm, alignItems: 'flex-end' }}>
          <div>
            <label style={{ display: 'block', fontSize: theme.typography.fontSize.xs, color: theme.colors.text.secondary, marginBottom: theme.spacing.xs }}>
              From
            </label>
            <input
              type="date"
              value={dateFrom}
              onChange={(e) => {
                setDateFrom(e.target.value)
                setPage(1)
              }}
              data-testid="audit-log-filter-date-from"
              style={{
                padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                background: theme.colors.bg.input,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.md,
                color: theme.colors.text.primary,
                fontSize: theme.typography.fontSize.sm,
              }}
            />
          </div>
          <div>
            <label style={{ display: 'block', fontSize: theme.typography.fontSize.xs, color: theme.colors.text.secondary, marginBottom: theme.spacing.xs }}>
              To
            </label>
            <input
              type="date"
              value={dateTo}
              onChange={(e) => {
                setDateTo(e.target.value)
                setPage(1)
              }}
              data-testid="audit-log-filter-date-to"
              style={{
                padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                background: theme.colors.bg.input,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.md,
                color: theme.colors.text.primary,
                fontSize: theme.typography.fontSize.sm,
              }}
            />
          </div>
        </div>

        {/* Admin Filter */}
        <div>
          <label style={{ display: 'block', fontSize: theme.typography.fontSize.xs, color: theme.colors.text.secondary, marginBottom: theme.spacing.xs }}>
            Admin
          </label>
          <select
            value={selectedAdmin}
            onChange={(e) => {
              setSelectedAdmin(e.target.value)
              setPage(1)
            }}
            data-testid="audit-log-filter-admin"
            style={{
              padding: `${theme.spacing.sm} ${theme.spacing.md}`,
              background: theme.colors.bg.input,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              color: theme.colors.text.primary,
              fontSize: theme.typography.fontSize.sm,
            }}
          >
            <option value="">All Admins</option>
            {admins.map(admin => (
              <option key={admin.id} value={admin.id}>{admin.email}</option>
            ))}
          </select>
        </div>

        {/* Action Filter */}
        <div>
          <label style={{ display: 'block', fontSize: theme.typography.fontSize.xs, color: theme.colors.text.secondary, marginBottom: theme.spacing.xs }}>
            Action
          </label>
          <select
            value={selectedAction}
            onChange={(e) => {
              setSelectedAction(e.target.value)
              setPage(1)
            }}
            data-testid="audit-log-filter-action"
            style={{
              padding: `${theme.spacing.sm} ${theme.spacing.md}`,
              background: theme.colors.bg.input,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              color: theme.colors.text.primary,
              fontSize: theme.typography.fontSize.sm,
            }}
          >
            <option value="">All Actions</option>
            {getAvailableActions().map(action => (
              <option key={action} value={action}>{action}</option>
            ))}
          </select>
        </div>

        {/* Entity Type Filter */}
        <div>
          <label style={{ display: 'block', fontSize: theme.typography.fontSize.xs, color: theme.colors.text.secondary, marginBottom: theme.spacing.xs }}>
            Entity Type
          </label>
          <select
            value={selectedEntityType}
            onChange={(e) => {
              setSelectedEntityType(e.target.value)
              setPage(1)
            }}
            data-testid="audit-log-filter-entity-type"
            style={{
              padding: `${theme.spacing.sm} ${theme.spacing.md}`,
              background: theme.colors.bg.input,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              color: theme.colors.text.primary,
              fontSize: theme.typography.fontSize.sm,
            }}
          >
            <option value="">All Entity Types</option>
            {getAvailableEntityTypes().map(type => (
              <option key={type} value={type}>{type}</option>
            ))}
          </select>
        </div>

        {/* Search Input */}
        <div style={{ flex: 1, minWidth: '200px' }}>
          <label style={{ display: 'block', fontSize: theme.typography.fontSize.xs, color: theme.colors.text.secondary, marginBottom: theme.spacing.xs }}>
            Search
          </label>
          <input
            type="text"
            value={searchText}
            onChange={(e) => {
              setSearchText(e.target.value)
              setPage(1)
            }}
            placeholder="Search entity ID, email, IP..."
            data-testid="audit-log-search-input"
            style={{
              width: '100%',
              padding: `${theme.spacing.sm} ${theme.spacing.md}`,
              background: theme.colors.bg.input,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              color: theme.colors.text.primary,
              fontSize: theme.typography.fontSize.sm,
              boxSizing: 'border-box',
            }}
          />
        </div>
      </div>

      {/* Results Info */}
      <div style={{
        padding: `${theme.spacing.md} ${theme.spacing.lg}`,
        borderBottom: `1px solid ${tableColors.border}`,
        fontSize: theme.typography.fontSize.sm,
        color: theme.colors.text.secondary,
      }}>
        <span data-testid="audit-log-results-count">
          <strong style={{ color: theme.colors.text.primary }}>{totalEntries}</strong> entries found
        </span>
      </div>

      {/* Error Message */}
      {error && (
        <div
          data-testid="audit-log-error-message"
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

      {/* Loading State */}
      {loading ? (
        <div data-testid="audit-log-loading" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
          Loading audit log...
        </div>
      ) : entries.length === 0 ? (
        <div data-testid="audit-log-empty-state" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
          No audit log entries found
        </div>
      ) : (
        <>
          <div data-testid="audit-log-table-wrapper" style={tableWrapperStyles}>
            <table data-testid="audit-log-table" style={tableElementStyles}>
              <thead>
                <tr style={headerRowStyle}>
                  <th style={{ ...headerCellBaseStyle, width: '200px' }}>
                    <SortableTableHeader
                      label="Timestamp"
                      sortKey="created_at"
                      currentSort={{ key: 'created_at', direction: sortDirection }}
                      onSort={() => {
                        setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc')
                      }}
                    />
                  </th>
                  <th style={{ ...headerCellBaseStyle, width: '150px' }}>Admin</th>
                  <th style={{ ...headerCellBaseStyle, width: '120px' }}>Action</th>
                  <th style={{ ...headerCellBaseStyle, width: '150px' }}>Entity Type</th>
                  <th style={{ ...headerCellBaseStyle, width: '200px' }}>Entity ID</th>
                  <th style={{ ...headerCellBaseStyle, width: '120px' }}>IP Address</th>
                  <th style={{ ...headerCellBaseStyle, width: '80px', textAlign: 'center' }}>Details</th>
                </tr>
              </thead>
              <tbody>
                {entries.map((entry) => (
                  <tr key={`row-${entry.id}`} data-testid={`audit-log-table-row-${entry.id}`} style={getRowStyle(true)}>
                    <td style={{ padding: '12px 16px' }} data-testid={`audit-log-timestamp-${entry.id}`}>
                      {formatDateTime(entry.created_at)}
                    </td>
                    <td style={{ padding: '12px 16px' }} data-testid={`audit-log-admin-${entry.id}`}>
                      {entry.admin_user_email || '(Failed Login)'}
                    </td>
                    <td style={{ padding: '12px 16px' }} data-testid={`audit-log-action-${entry.id}`}>
                      <span style={{
                        padding: '4px 8px',
                        borderRadius: 4,
                        fontSize: 12,
                        fontWeight: 500,
                        backgroundColor: 'rgba(59, 130, 246, 0.2)',
                        color: theme.colors.semantic.primary,
                        display: 'inline-block',
                      }}>
                        {entry.action}
                      </span>
                    </td>
                    <td style={{ padding: '12px 16px' }} data-testid={`audit-log-entity-type-${entry.id}`}>
                      {entry.entity_type || '—'}
                    </td>
                    <td style={{ padding: '12px 16px', fontFamily: 'monospace', fontSize: '12px' }} data-testid={`audit-log-entity-id-${entry.id}`}>
                      {entry.entity_id ? entry.entity_id.substring(0, 8) : '—'}
                    </td>
                    <td style={{ padding: '12px 16px', fontSize: '12px' }} data-testid={`audit-log-ip-${entry.id}`}>
                      {entry.ip_address || '—'}
                    </td>
                    <td style={{ padding: '12px 16px', textAlign: 'center' }}>
                      <button
                        data-testid={`audit-log-expand-button-${entry.id}`}
                        onClick={() => setExpandedRowId(expandedRowId === entry.id ? null : entry.id)}
                        style={{
                          background: 'transparent',
                          border: 'none',
                          color: theme.colors.semantic.primary,
                          cursor: 'pointer',
                          padding: theme.spacing.sm,
                        }}
                        title={expandedRowId === entry.id ? 'Hide details' : 'Show details'}
                      >
                        {expandedRowId === entry.id ? '▼' : '▶'}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Expandable Details Row */}
          {expandedRowId !== null && entries.find(e => e.id === expandedRowId) && (
            <div
              data-testid={`audit-log-details-row-${expandedRowId}`}
              style={{
                background: tableColors.rowInactiveBg,
                padding: theme.spacing.lg,
                borderTop: `1px solid ${tableColors.border}`,
              }}
            >
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: theme.spacing.lg }}>
                {entries.find(e => e.id === expandedRowId)?.old_values && (
                  <div>
                    <h4 style={{ margin: `0 0 ${theme.spacing.sm} 0`, color: theme.colors.text.primary }}>Before</h4>
                    <pre style={{
                      background: theme.colors.bg.input,
                      padding: theme.spacing.md,
                      borderRadius: theme.borderRadius.md,
                      overflow: 'auto',
                      maxHeight: '300px',
                      fontSize: '12px',
                      color: theme.colors.text.secondary,
                    }}>
                      {JSON.stringify(entries.find(e => e.id === expandedRowId)?.old_values, null, 2)}
                    </pre>
                  </div>
                )}
                {entries.find(e => e.id === expandedRowId)?.new_values && (
                  <div>
                    <h4 style={{ margin: `0 0 ${theme.spacing.sm} 0`, color: theme.colors.text.primary }}>After</h4>
                    <pre style={{
                      background: theme.colors.bg.input,
                      padding: theme.spacing.md,
                      borderRadius: theme.borderRadius.md,
                      overflow: 'auto',
                      maxHeight: '300px',
                      fontSize: '12px',
                      color: theme.colors.text.secondary,
                    }}>
                      {JSON.stringify(entries.find(e => e.id === expandedRowId)?.new_values, null, 2)}
                    </pre>
                  </div>
                )}
                {!entries.find(e => e.id === expandedRowId)?.old_values && !entries.find(e => e.id === expandedRowId)?.new_values && (
                  <div style={{ gridColumn: '1 / -1', color: theme.colors.text.secondary }}>
                    No value changes recorded
                  </div>
                )}
              </div>
            </div>
          )}
        </>
      )}

      {/* Pagination */}
      {!loading && entries.length > 0 && (
        <PaginationToolbar
          currentPage={page}
          totalPages={Math.ceil(totalEntries / perPage)}
          totalItems={totalEntries}
          pageSize={perPage}
          onPageChange={setPage}
          onPageSizeChange={setPerPage}
          variant="default"
          showPageSize={true}
          showInfo={true}
          testId="audit-log-pagination"
        />
      )}
    </div>
  )
}
