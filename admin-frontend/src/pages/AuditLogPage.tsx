/**
 * Audit Log Page
 * Displays audit trail of all administrative actions in the system
 */

import React, { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { PageHeader } from '../components/layout/PageHeader'
import { theme } from '../styles/design-system'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { getAuditLog } from '../api/generated/audit-log/audit-log'
import type { AuditLogEntry, ListAuditLogParams, ListAuditLogSortBy } from '../api/generated'
import { ListAuditLogAction, ListAuditLogEntityType } from '../api/generated'
import {
  tableWrapperStyles,
  tableElementStyles,
  headerRowStyle,
  headerCellBaseStyle,
  tableColors,
  getRowStyle,
} from '../styles/tableTokens'
import { SortableTableHeader } from '../components/tables/SortableTableHeader'
import { useListQuery } from '../hooks/useListQuery'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { formatDateTime } from '../styles/design-system'
import { getAuditLogSummary } from '../utils/auditLogSummary'

/** Return a color pair for action badges */
function getActionBadgeStyle(action: string): { backgroundColor: string; color: string } {
  if (action.startsWith('create') || action === 'login') {
    return { backgroundColor: 'rgba(34, 197, 94, 0.2)', color: theme.colors.semantic.success }
  }
  if (action.startsWith('update')) {
    return { backgroundColor: theme.activeTint.primaryStrong, color: theme.colors.semantic.primary }
  }
  if (action.startsWith('delete') || action === 'login_failed') {
    return { backgroundColor: theme.badges.danger.strong, color: theme.colors.semantic.danger }
  }
  // default blue
  return { backgroundColor: theme.activeTint.primaryStrong, color: theme.colors.semantic.primary }
}

/** Shared select style for filter dropdowns */
const selectStyle: React.CSSProperties = {
  width: '100%',
  padding: `${theme.spacing.sm} 28px ${theme.spacing.sm} ${theme.spacing.md}`,
  background: theme.colors.bg.input,
  border: `1px solid ${theme.colors.border.light}`,
  borderRadius: theme.borderRadius.md,
  color: theme.colors.text.primary,
  fontSize: theme.typography.fontSize.sm,
  cursor: 'pointer',
  outline: 'none',
  appearance: 'none' as const,
  WebkitAppearance: 'none' as const,
  backgroundImage: `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E")`,
  backgroundRepeat: 'no-repeat',
  backgroundPosition: 'right 8px center',
  backgroundSize: '12px',
  boxSizing: 'border-box' as const,
}

const inputStyle: React.CSSProperties = {
  width: '100%',
  padding: `${theme.spacing.sm} ${theme.spacing.md}`,
  background: theme.colors.bg.input,
  border: `1px solid ${theme.colors.border.light}`,
  borderRadius: theme.borderRadius.md,
  color: theme.colors.text.primary,
  fontSize: theme.typography.fontSize.sm,
  boxSizing: 'border-box' as const,
}

const labelStyle: React.CSSProperties = {
  display: 'block',
  fontSize: theme.typography.fontSize.xs,
  color: theme.colors.text.secondary,
  marginBottom: theme.spacing.xs,
}

interface AuditLogFilters {
  dateFrom: string
  dateTo: string
  adminId: string
  action: string
  entityType: string
}

export function AuditLogPage() {
  const { t, i18n } = useTranslation()
  const breakpoint = useBreakpoint()

  const isMobile = breakpoint === 'mobile' || breakpoint === 'smallMobile'

  /** Translated badge text for an action; falls back to the raw slug for anything not yet mapped */
  const actionLabel = (action: string) => t(`auditLog.actionLabel.${action}`, { defaultValue: action })

  // Paging, filters and the debounced search all live in the shared list-query
  // state (#121).
  //
  // The sort direction is sent as `sort_by=created_at_<direction>`: the header
  // arrow and the mobile dropdown used to toggle a value the request never
  // carried, so the list showed an ascending arrow over newest-first rows
  // (#125). Timestamp is the only orderable column the endpoint offers.
  const list = useListQuery<AuditLogEntry, AuditLogFilters, 'created_at'>({
    initialFilters: { dateFrom: '', dateTo: '', adminId: '', action: '', entityType: '' },
    initialSortKey: 'created_at',
    initialSortDirection: 'desc',
    initialPageSize: 50,
    fetcher: async ({ page, pageSize, sortDirection, search, filters, signal }) => {
      const params: ListAuditLogParams = {
        page,
        per_page: pageSize,
        sort_by: `created_at_${sortDirection}` as ListAuditLogSortBy,
        date_from: filters.dateFrom || undefined,
        date_to: filters.dateTo || undefined,
        admin_user_id: filters.adminId || undefined,
        action: (filters.action as ListAuditLogParams['action']) || undefined,
        entity_type: (filters.entityType as ListAuditLogParams['entity_type']) || undefined,
        search: search || undefined,
      }
      const response = await getAuditLog().listAuditLog(params, { signal })
      return { items: response.data ?? [], total: response.pagination?.total ?? 0 }
    },
    parseError: (err) => (err instanceof Error ? err.message : t('auditLog.errors.load')),
  })

  const { items: entries, total: totalEntries, totalPages, loading, error } = list
  const { dateFrom, dateTo, adminId: selectedAdmin, action: selectedAction, entityType: selectedEntityType } = list.filters
  const searchText = list.search
  const sortDirection = list.sortDirection

  const [admins, setAdmins] = useState<Array<{ id: string; email: string }>>([])
  const [expandedRowId, setExpandedRowId] = useState<number | null>(null)

  // Mobile state
  const [showMobileFilters, setShowMobileFilters] = useState(false)
  const [expandedCardId, setExpandedCardId] = useState<number | null>(null)

  // Active filter count for mobile badge
  const activeFilterCount = [dateFrom, dateTo, selectedAdmin, selectedAction, selectedEntityType, searchText].filter(Boolean).length

  // Sort options for mobile toolbar
  const sortOptions = [
    { label: t('auditLog.timestamp') + ' (' + t('common.newest') + ')', value: 'desc' },
    { label: t('auditLog.timestamp') + ' (' + t('common.oldest') + ')', value: 'asc' },
  ]

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

  /** Render the filter controls (shared between desktop toolbar and mobile filter panel) */
  const renderFilterControls = (stacked: boolean) => {
    const wrapperStyle: React.CSSProperties = stacked
      ? { display: 'flex', flexDirection: 'column', gap: theme.spacing.md }
      : {
          display: 'flex',
          gap: theme.spacing.md,
          padding: `${theme.spacing.md} ${theme.spacing.lg}`,
          borderBottom: `1px solid ${tableColors.border}`,
          alignItems: 'flex-start',
          flexWrap: 'wrap',
        }

    return (
      <div style={wrapperStyle}>
        {/* Date Range Filters */}
        <div style={stacked ? { display: 'flex', gap: theme.spacing.sm } : { display: 'flex', gap: theme.spacing.sm, alignItems: 'flex-end' }}>
          <div style={stacked ? { flex: 1 } : undefined}>
            <label style={labelStyle}>{t('auditLog.from')}</label>
            <input
              type="date"
              value={dateFrom}
              onChange={(e) => list.setFilter('dateFrom', e.target.value)}
              data-testid="audit-log-filter-date-from"
              style={inputStyle}
            />
          </div>
          <div style={stacked ? { flex: 1 } : undefined}>
            <label style={labelStyle}>{t('auditLog.to')}</label>
            <input
              type="date"
              value={dateTo}
              onChange={(e) => list.setFilter('dateTo', e.target.value)}
              data-testid="audit-log-filter-date-to"
              style={inputStyle}
            />
          </div>
        </div>

        {/* Admin Filter */}
        <div>
          <label style={labelStyle}>{t('auditLog.admin')}</label>
          <select
            value={selectedAdmin}
            onChange={(e) => list.setFilter('adminId', e.target.value)}
            data-testid="audit-log-filter-admin"
            style={selectStyle}
          >
            <option value="">{t('auditLog.allAdmins')}</option>
            {admins.map(admin => (
              <option key={admin.id} value={admin.id}>{admin.email}</option>
            ))}
          </select>
        </div>

        {/* Action Filter */}
        <div>
          <label style={labelStyle}>{t('auditLog.action')}</label>
          <select
            value={selectedAction}
            onChange={(e) => list.setFilter('action', e.target.value)}
            data-testid="audit-log-filter-action"
            style={selectStyle}
          >
            <option value="">{t('auditLog.allActions')}</option>
            {Object.values(ListAuditLogAction).map(action => (
              <option key={action} value={action}>{action}</option>
            ))}
          </select>
        </div>

        {/* Entity Type Filter */}
        <div>
          <label style={labelStyle}>{t('auditLog.entityType')}</label>
          <select
            value={selectedEntityType}
            onChange={(e) => list.setFilter('entityType', e.target.value)}
            data-testid="audit-log-filter-entity-type"
            style={selectStyle}
          >
            <option value="">{t('auditLog.allEntityTypes')}</option>
            {Object.values(ListAuditLogEntityType).map(type => (
              <option key={type} value={type}>{type}</option>
            ))}
          </select>
        </div>

        {/* Search Input */}
        <div style={stacked ? {} : { flex: 1, minWidth: '200px' }}>
          <label style={labelStyle}>{t('common.search')}</label>
          <input
            type="text"
            value={searchText}
            onChange={(e) => list.setSearch(e.target.value)}
            placeholder={t('auditLog.searchPlaceholder')}
            data-testid="audit-log-search-input"
            style={inputStyle}
          />
        </div>
      </div>
    )
  }

  /** Render the expanded details for a card or row */
  const renderEntryDetails = (entry: AuditLogEntry) => (
    <div style={{ display: 'grid', gridTemplateColumns: isMobile ? '1fr' : '1fr 1fr', gap: theme.spacing.md, marginTop: theme.spacing.md }}>
      {entry.old_values && (
        <div>
          <h4 style={{ margin: `0 0 ${theme.spacing.sm} 0`, color: theme.colors.text.primary, fontSize: '13px' }}>{t('common.before')}</h4>
          <pre data-testid={`audit-log-old-values-${entry.id}`} style={{
            background: theme.colors.bg.input,
            padding: theme.spacing.md,
            borderRadius: theme.borderRadius.md,
            overflow: 'auto',
            maxHeight: '200px',
            fontSize: '11px',
            color: theme.colors.text.secondary,
            margin: 0,
          }}>
            {JSON.stringify(entry.old_values, null, 2)}
          </pre>
        </div>
      )}
      {entry.new_values && (
        <div>
          <h4 style={{ margin: `0 0 ${theme.spacing.sm} 0`, color: theme.colors.text.primary, fontSize: '13px' }}>{t('common.after')}</h4>
          <pre data-testid={`audit-log-new-values-${entry.id}`} style={{
            background: theme.colors.bg.input,
            padding: theme.spacing.md,
            borderRadius: theme.borderRadius.md,
            overflow: 'auto',
            maxHeight: '200px',
            fontSize: '11px',
            color: theme.colors.text.secondary,
            margin: 0,
          }}>
            {JSON.stringify(entry.new_values, null, 2)}
          </pre>
        </div>
      )}
      {!entry.old_values && !entry.new_values && (
        <div style={{ color: theme.colors.text.secondary, fontSize: '13px' }}>
          {t('common.noChanges')}
        </div>
      )}
    </div>
  )

  /** Render mobile card list */
  const renderMobileCards = () => (
    <div data-testid="audit-log-mobile-cards" style={{ display: 'flex', flexDirection: 'column', gap: '10px', padding: '0 4px' }}>
      {entries.map((entry) => {
        const badgeStyle = getActionBadgeStyle(entry.action ?? '')
        const isExpanded = expandedCardId === entry.id
        const hasDetails = !!(entry.old_values || entry.new_values)

        return (
          <div
            key={entry.id}
            data-testid={`audit-log-card-${entry.id}`}
            style={{
              background: theme.mobileCard.bg,
              border: `1px solid ${theme.mobileCard.border}`,
              borderRadius: '10px',
              padding: '14px 16px',
            }}
          >
            {/* Row 1: Timestamp (left) + Action badge (right) */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
              <span style={{ fontSize: '13px', color: theme.colors.text.secondary }}>
                {formatDateTime(entry.created_at ?? '')}
              </span>
              <span data-action={entry.action} style={{
                padding: '3px 8px',
                borderRadius: 4,
                fontSize: 11,
                fontWeight: 600,
                backgroundColor: badgeStyle.backgroundColor,
                color: badgeStyle.color,
              }}>
                {actionLabel(entry.action ?? '')}
              </span>
            </div>

            {/* Row 1.5: Human-readable summary (#381) */}
            <div
              data-testid={`audit-log-summary-${entry.id}`}
              style={{ fontSize: '13px', color: theme.colors.text.primary, marginBottom: '6px' }}
            >
              {getAuditLogSummary(entry, t, i18n.language)}
            </div>

            {/* Row 2: Admin user name + Entity type */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' }}>
              <span style={{ fontSize: '14px', fontWeight: 500, color: theme.colors.text.primary }}>
                {entry.admin_user_email || (entry.action === 'login_failed' ? t('auditLog.failedLogin') : '\u2014')}
              </span>
              <span style={{ fontSize: '12px', color: theme.colors.text.secondary }}>
                {entry.entity_type || '\u2014'}
              </span>
            </div>

            {/* Row 3: Entity ID (truncated/monospace) + expand button */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <span style={{
                fontFamily: 'monospace',
                fontSize: '11px',
                color: theme.colors.text.secondary,
                overflow: 'hidden',
                textOverflow: 'ellipsis',
                whiteSpace: 'nowrap',
                maxWidth: hasDetails ? 'calc(100% - 40px)' : '100%',
              }}>
                {entry.entity_id || '\u2014'}
              </span>
              {hasDetails && (
                <button
                  data-testid={`audit-log-expand-button-${entry.id}`}
                  onClick={() => setExpandedCardId(isExpanded ? null : (entry.id ?? null))}
                  style={{
                    background: 'transparent',
                    border: 'none',
                    color: theme.colors.semantic.primary,
                    cursor: 'pointer',
                    padding: '4px 8px',
                    fontSize: '12px',
                    flexShrink: 0,
                  }}
                >
                  {isExpanded ? '\u25BC' : '\u25B6'}
                </button>
              )}
            </div>

            {/* Expanded details */}
            {isExpanded && renderEntryDetails(entry)}
          </div>
        )
      })}
    </div>
  )

  /** Render desktop table */
  const renderDesktopTable = () => (
    <div data-testid="audit-log-table-wrapper" style={tableWrapperStyles}>
      <table data-testid="audit-log-table" style={tableElementStyles}>
        <thead>
          <tr style={headerRowStyle}>
            <th style={{ ...headerCellBaseStyle, width: '180px' }}>
              <SortableTableHeader
                label={t('auditLog.timestamp')}
                sortKey="created_at"
                currentSort={{ key: 'created_at', direction: sortDirection }}
                onSort={() => list.toggleSort('created_at')}
              />
            </th>
            <th style={{ ...headerCellBaseStyle, width: '150px' }}>{t('auditLog.admin')}</th>
            <th style={{ ...headerCellBaseStyle, width: '120px' }}>{t('auditLog.action')}</th>
            <th style={{ ...headerCellBaseStyle, width: '140px' }}>{t('auditLog.entityType')}</th>
            <th style={{ ...headerCellBaseStyle }}>{t('auditLog.entityId')}</th>
            <th style={{ ...headerCellBaseStyle, width: '80px', textAlign: 'center' }}>{t('auditLog.details')}</th>
          </tr>
        </thead>
        <tbody>
          {entries.map((entry) => (
            <React.Fragment key={`row-${entry.id}`}>
              <tr data-testid={`audit-log-table-row-${entry.id}`} style={getRowStyle(true)}>
                <td style={{ padding: '12px 16px' }} data-testid={`audit-log-timestamp-${entry.id}`}>
                  {formatDateTime(entry.created_at ?? '')}
                </td>
                <td style={{ padding: '12px 16px' }} data-testid={`audit-log-admin-${entry.id}`}>
                  {entry.admin_user_email || (entry.action === 'login_failed' ? t('auditLog.failedLogin') : '\u2014')}
                </td>
                <td style={{ padding: '12px 16px' }} data-testid={`audit-log-action-${entry.id}`} data-action={entry.action}>
                  <span style={{
                    padding: '4px 8px',
                    borderRadius: 4,
                    fontSize: 12,
                    fontWeight: 500,
                    backgroundColor: theme.activeTint.primaryStrong,
                    color: theme.colors.semantic.primary,
                    display: 'inline-block',
                  }}>
                    {actionLabel(entry.action ?? '')}
                  </span>
                </td>
                <td style={{ padding: '12px 16px' }} data-testid={`audit-log-entity-type-${entry.id}`}>
                  {entry.entity_type || '\u2014'}
                </td>
                <td style={{ padding: '12px 16px', fontFamily: 'monospace', fontSize: '12px' }} data-testid={`audit-log-entity-id-${entry.id}`}>
                  {entry.entity_id || '\u2014'}
                </td>
                <td style={{ padding: '12px 16px', textAlign: 'center' }}>
                  <button
                    data-testid={`audit-log-expand-button-${entry.id}`}
                    onClick={() => setExpandedRowId(expandedRowId === entry.id ? null : (entry.id ?? null))}
                    style={{
                      background: 'transparent',
                      border: 'none',
                      color: theme.colors.semantic.primary,
                      cursor: 'pointer',
                      padding: theme.spacing.sm,
                    }}
                    title={expandedRowId === entry.id ? t('auditLog.hideDetails') : t('auditLog.showDetails')}
                  >
                    {expandedRowId === entry.id ? '\u25BC' : '\u25B6'}
                  </button>
                </td>
              </tr>
              {expandedRowId === entry.id && (
                <tr data-testid={`audit-log-details-row-${entry.id}`}>
                  <td colSpan={6} style={{
                    background: tableColors.rowInactiveBg,
                    padding: theme.spacing.lg,
                    borderTop: `1px solid ${tableColors.border}`,
                  }}>
                    {/* Human-readable summary (#381) */}
                    <div data-testid={`audit-log-summary-${entry.id}`} style={{ marginBottom: theme.spacing.md, fontSize: '14px', color: theme.colors.text.primary }}>
                      {getAuditLogSummary(entry, t, i18n.language)}
                    </div>
                    {/* IP Address */}
                    <div style={{ marginBottom: theme.spacing.md, fontSize: '13px' }}>
                      <span style={{ color: theme.colors.text.secondary }}>{t('auditLog.ipAddress')}: </span>
                      <span data-testid={`audit-log-ip-${entry.id}`} style={{ color: theme.colors.text.primary, fontFamily: 'monospace' }}>
                        {entry.ip_address || '\u2014'}
                      </span>
                    </div>
                    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: theme.spacing.lg }}>
                      {entry.old_values && (
                        <div>
                          <h4 style={{ margin: `0 0 ${theme.spacing.sm} 0`, color: theme.colors.text.primary }}>{t('common.before')}</h4>
                          <pre data-testid={`audit-log-old-values-${entry.id}`} style={{
                            background: theme.colors.bg.input,
                            padding: theme.spacing.md,
                            borderRadius: theme.borderRadius.md,
                            overflow: 'auto',
                            maxHeight: '300px',
                            fontSize: '12px',
                            color: theme.colors.text.secondary,
                          }}>
                            {JSON.stringify(entry.old_values, null, 2)}
                          </pre>
                        </div>
                      )}
                      {entry.new_values && (
                        <div>
                          <h4 style={{ margin: `0 0 ${theme.spacing.sm} 0`, color: theme.colors.text.primary }}>{t('common.after')}</h4>
                          <pre data-testid={`audit-log-new-values-${entry.id}`} style={{
                            background: theme.colors.bg.input,
                            padding: theme.spacing.md,
                            borderRadius: theme.borderRadius.md,
                            overflow: 'auto',
                            maxHeight: '300px',
                            fontSize: '12px',
                            color: theme.colors.text.secondary,
                          }}>
                            {JSON.stringify(entry.new_values, null, 2)}
                          </pre>
                        </div>
                      )}
                      {!entry.old_values && !entry.new_values && (
                        <div style={{ gridColumn: '1 / -1', color: theme.colors.text.secondary }}>
                          {t('common.noChanges')}
                        </div>
                      )}
                    </div>
                  </td>
                </tr>
              )}
            </React.Fragment>
          ))}
        </tbody>
      </table>
    </div>
  )

  return (
    <div data-testid="audit-log-page">
      <PageHeader title={t('auditLog.title')} />

      {isMobile ? (
        <>
          {/* Mobile Toolbar: Sort + Filter toggle */}
          <div
            data-testid="audit-log-mobile-toolbar"
            style={{
              display: 'flex',
              gap: '10px',
              marginBottom: '12px',
              alignItems: 'center',
            }}
          >
            {/* Sort dropdown */}
            <select
              data-testid="audit-log-mobile-sort"
              value={sortDirection}
              onChange={(e) => list.setSort('created_at', e.target.value as 'asc' | 'desc')}
              style={{
                ...selectStyle,
                flex: 1,
              }}
            >
              {sortOptions.map(opt => (
                <option key={opt.value} value={opt.value}>{opt.label}</option>
              ))}
            </select>

            {/* Filter toggle button */}
            <button
              data-testid="audit-log-mobile-filter-toggle"
              onClick={() => setShowMobileFilters(!showMobileFilters)}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: '6px',
                padding: '8px 14px',
                borderRadius: theme.borderRadius.md,
                border: `1px solid ${theme.colors.border.light}`,
                background: showMobileFilters ? theme.activeTint.primary : theme.colors.bg.input,
                color: showMobileFilters ? theme.colors.semantic.primary : theme.colors.text.primary,
                fontSize: '13px',
                fontWeight: 500,
                cursor: 'pointer',
                whiteSpace: 'nowrap',
              }}
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3" />
              </svg>
              {t('common.filters')}
              {activeFilterCount > 0 && (
                <span style={{
                  background: theme.colors.semantic.primary,
                  color: 'white',
                  borderRadius: '10px',
                  padding: '1px 6px',
                  fontSize: '11px',
                  fontWeight: 600,
                  minWidth: '18px',
                  textAlign: 'center',
                }}>
                  {activeFilterCount}
                </span>
              )}
            </button>
          </div>

          {/* Mobile Filter Panel (collapsible) */}
          {showMobileFilters && (
            <div
              data-testid="audit-log-mobile-filter-panel"
              style={{
                background: theme.mobileCard.bg,
                border: `1px solid ${theme.mobileCard.border}`,
                borderRadius: '10px',
                padding: '14px 16px',
                marginBottom: '12px',
              }}
            >
              {renderFilterControls(true)}
            </div>
          )}

          {/* Results Info */}
          <div style={{
            padding: `${theme.spacing.sm} 0`,
            marginBottom: '10px',
            fontSize: theme.typography.fontSize.sm,
            color: theme.colors.text.secondary,
          }}>
            <span data-testid="audit-log-results-count">
              {t('auditLog.countFound', { count: totalEntries })}
            </span>
          </div>

          {/* Error */}
          {error && (
            <div data-testid="audit-log-error-message" style={{
              padding: theme.spacing.md,
              background: `${theme.colors.semantic.danger}20`,
              border: `1px solid ${theme.colors.semantic.danger}`,
              borderRadius: '8px',
              color: theme.colors.semantic.danger,
              fontSize: theme.typography.fontSize.sm,
              marginBottom: '10px',
            }}>
              {error}
            </div>
          )}

          {/* Content */}
          {loading ? (
            <div data-testid="audit-log-loading" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
              {t('auditLog.loading')}
            </div>
          ) : entries.length === 0 ? (
            <div data-testid="audit-log-empty-state" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
              {t('auditLog.noEntries')}
            </div>
          ) : (
            renderMobileCards()
          )}
        </>
      ) : (
        <>
          {/* Desktop: Filters Row */}
          {renderFilterControls(false)}

          {/* Results Info */}
          <div style={{
            padding: `${theme.spacing.md} ${theme.spacing.lg}`,
            borderBottom: `1px solid ${tableColors.border}`,
            fontSize: theme.typography.fontSize.sm,
            color: theme.colors.text.secondary,
          }}>
            <span data-testid="audit-log-results-count">
              {t('auditLog.countFound', { count: totalEntries })}
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
              {t('auditLog.loading')}
            </div>
          ) : entries.length === 0 ? (
            <div data-testid="audit-log-empty-state" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
              {t('auditLog.noEntries')}
            </div>
          ) : (
            renderDesktopTable()
          )}
        </>
      )}

      {/* Pagination */}
      {!loading && entries.length > 0 && (
        <PaginationToolbar
          currentPage={list.page}
          totalPages={totalPages}
          totalItems={totalEntries}
          pageSize={list.pageSize}
          onPageChange={list.setPage}
          onPageSizeChange={list.setPageSize}
          variant="default"
          showPageSize={true}
          showInfo={true}
          testId="audit-log-pagination"
        />
      )}
    </div>
  )
}
