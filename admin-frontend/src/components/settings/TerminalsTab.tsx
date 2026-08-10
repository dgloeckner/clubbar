/**
 * TerminalsTab Component
 * Terminal devices list with toggle for active/inactive status and action buttons
 */

import { useTranslation } from 'react-i18next'
import { theme, formatDateTime } from '../../styles/design-system'
import { Toggle } from '../common/Toggle'
import { Badge } from '../common/Badge'
import { Tooltip } from '../common/Tooltip'
import { useBreakpoint } from '../../hooks/useBreakpoint'
import type { Terminal as GeneratedTerminal } from '../../api/generated'

// Required fields that are always present in the API response
type Terminal = GeneratedTerminal & { id: string; name: string; is_active: boolean }

export interface TerminalsTabProps {
  terminals: Terminal[]
  loading: boolean
  onCreateTerminal: () => void
  onEditTerminal: (terminal: Terminal) => void
  onRotateToken: (id: string) => void
  onRevokeAccess: (id: string) => void
  onDeactivateTerminal: (id: string) => void
  onReactivateTerminal: (id: string) => void
}

function EditIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
      <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
    </svg>
  )
}

function RotateTokenIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4" />
    </svg>
  )
}

function RevokeIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <circle cx="12" cy="12" r="10" />
      <line x1="4.93" y1="4.93" x2="19.07" y2="19.07" />
    </svg>
  )
}

/**
 * Terminal tokens expire (#106). A terminal whose token has run out stops
 * syncing until an admin rotates it, so the table has to say so before that
 * happens — an expiry date alone is something nobody reads until the bar is
 * already offline.
 */
const EXPIRY_WARNING_DAYS = 14

type TokenExpiryState = 'none' | 'expired' | 'expiringSoon' | 'valid'

function tokenExpiryState(tokenExpiresAt: string | null | undefined): TokenExpiryState {
  if (!tokenExpiresAt) return 'none'

  const daysLeft = (new Date(tokenExpiresAt).getTime() - Date.now()) / 86_400_000
  if (daysLeft <= 0) return 'expired'
  if (daysLeft <= EXPIRY_WARNING_DAYS) return 'expiringSoon'
  return 'valid'
}

const actionButtonStyle: React.CSSProperties = {
  width: '32px',
  height: '32px',
  borderRadius: '8px',
  border: 'none',
  background: 'transparent',
  color: theme.colors.text.secondary,
  cursor: 'pointer',
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
  transition: `all ${theme.transitions.default}`,
}

function TokenExpiry({ terminal }: { terminal: Terminal }) {
  const { t } = useTranslation()
  const state = tokenExpiryState(terminal.token_expires_at)
  const testId = `settings-terminal-token-expiry-${terminal.id}`

  // The state is published as an attribute as well as a badge: the badge text
  // is translated, so tests and tooling need something stable to read.
  if (state === 'none') {
    // No token outstanding — access was revoked, or none was ever issued.
    return (
      <span data-testid={testId} data-token-expiry-state={state} style={{ color: theme.colors.text.muted }}>
        {t('settings.tokenNone')}
      </span>
    )
  }

  if (state === 'valid') {
    return (
      <span data-testid={testId} data-token-expiry-state={state}>
        {formatDateTime(terminal.token_expires_at!)}
      </span>
    )
  }

  return (
    <span
      data-testid={testId}
      data-token-expiry-state={state}
      style={{ display: 'inline-flex', alignItems: 'center', gap: theme.spacing.sm }}
    >
      <Badge
        label={state === 'expired' ? t('settings.tokenExpired') : t('settings.tokenExpiringSoon')}
        variant={state === 'expired' ? 'danger' : 'warning'}
        showDot={false}
        testId={`settings-terminal-token-expiry-badge-${terminal.id}`}
      />
      {formatDateTime(terminal.token_expires_at!)}
    </span>
  )
}

export function TerminalsTab({
  terminals,
  loading,
  onCreateTerminal,
  onEditTerminal,
  onRotateToken,
  onRevokeAccess,
  onDeactivateTerminal,
  onReactivateTerminal,
}: TerminalsTabProps) {
  const { t } = useTranslation()
  const breakpoint = useBreakpoint()
  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'

  if (loading) {
    return (
      <div style={{ textAlign: 'center', padding: theme.spacing.xl }}>
        {t('common.loading')}
      </div>
    )
  }

  return (
    <div>
      {/* Section Header with Count Badge */}
      <div
        style={{
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          marginBottom: theme.spacing.lg,
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: theme.spacing.md }}>
          <h2 style={{ margin: 0, fontSize: theme.typography.fontSize.lg }}>{t('settings.terminals')}</h2>
          <Badge label={`${terminals.length}`} variant="info" showDot={false} testId="settings-terminals-count-badge" />
        </div>

        {/* Create Terminal Button */}
        <button
          data-testid="settings-terminal-create-button"
          onClick={onCreateTerminal}
          style={{
            padding: `${theme.spacing.md} ${theme.spacing.lg}`,
            background: theme.colors.semantic.primary,
            color: 'white',
            border: 'none',
            borderRadius: theme.borderRadius.md,
            fontSize: theme.typography.fontSize.sm,
            fontWeight: theme.typography.fontWeight.semibold,
            cursor: 'pointer',
            transition: `all ${theme.transitions.default}`,
          }}
          onMouseEnter={(e) => {
            e.currentTarget.style.background = 'rgb(37, 99, 235)'
          }}
          onMouseLeave={(e) => {
            e.currentTarget.style.background = theme.colors.semantic.primary
          }}
        >
          + {t('settings.createTerminal')}
        </button>
      </div>

      {terminals.length === 0 ? (
        <div style={{ textAlign: 'center', padding: theme.spacing.xl, color: theme.colors.text.secondary }}>
          {t('settings.noTerminals')}
        </div>
      ) : isMobile ? (
        /* Mobile Card View */
        <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
          {terminals.map((terminal) => (
            <div
              key={terminal.id}
              data-testid={`settings-terminal-row-${terminal.id}`}
              style={{
                background: theme.mobileCard.bg,
                border: `1px solid ${theme.mobileCard.border}`,
                borderRadius: '10px',
                padding: '14px',
                opacity: terminal.is_active ? 1 : 0.5,
                transition: `opacity ${theme.transitions.default}`,
              }}
            >
              {/* Top row: Toggle + Name + Device ID */}
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '10px' }}>
                <Toggle
                  isEnabled={terminal.is_active}
                  onChange={(enabled) => {
                    if (enabled) {
                      onReactivateTerminal(terminal.id)
                    } else {
                      onDeactivateTerminal(terminal.id)
                    }
                  }}
                  testId={`settings-terminal-toggle-${terminal.id}`}
                />
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div
                    data-testid={`settings-terminal-name-${terminal.id}`}
                    style={{ fontWeight: 600, fontSize: '14px', color: theme.colors.text.primary }}
                  >
                    {terminal.name}
                  </div>
                  <code
                    data-testid={`settings-terminal-device-id-${terminal.id}`}
                    style={{
                      fontSize: '11px',
                      color: theme.colors.text.muted,
                      background: theme.colors.bg.tertiary,
                      padding: '1px 6px',
                      borderRadius: '4px',
                    }}
                  >
                    {terminal.device_id}
                  </code>
                </div>
              </div>

              {/* Info row: Last Sync + Last Transaction */}
              <div style={{ display: 'flex', gap: '16px', marginBottom: '10px', fontSize: '12px', color: theme.colors.text.secondary }}>
                <div>
                  <span style={{ color: theme.colors.text.muted }}>{t('settings.lastSync')}: </span>
                  <span data-testid={`settings-terminal-last-sync-${terminal.id}`}>
                    {terminal.last_sync_at ? formatDateTime(terminal.last_sync_at) : t('dates.never')}
                  </span>
                </div>
                <div>
                  <span style={{ color: theme.colors.text.muted }}>{t('settings.lastTransaction')}: </span>
                  <span data-testid={`settings-terminal-last-transaction-${terminal.id}`}>
                    {terminal.last_transaction_at ? formatDateTime(terminal.last_transaction_at) : t('dates.never')}
                  </span>
                </div>
              </div>

              {/* Token expiry (#106) */}
              <div style={{ marginBottom: '10px', fontSize: '12px', color: theme.colors.text.secondary }}>
                <span style={{ color: theme.colors.text.muted }}>{t('settings.tokenExpires')}: </span>
                <TokenExpiry terminal={terminal} />
              </div>

              {/* Actions row */}
              <div style={{ display: 'flex', gap: '6px', justifyContent: 'flex-end' }}>
                <button
                  data-testid={`settings-terminal-edit-button-${terminal.id}`}
                  onClick={() => onEditTerminal(terminal)}
                  style={{
                    ...actionButtonStyle,
                    background: theme.badges.info.bg,
                    color: theme.colors.semantic.primary,
                  }}
                >
                  <EditIcon />
                </button>
                <button
                  data-testid={`settings-terminal-rotate-token-button-${terminal.id}`}
                  onClick={() => onRotateToken(terminal.id)}
                  style={{
                    ...actionButtonStyle,
                    background: 'rgba(249, 115, 22, 0.1)',
                    color: 'rgb(249, 115, 22)',
                  }}
                >
                  <RotateTokenIcon />
                </button>
                <button
                  data-testid={`settings-terminal-revoke-button-${terminal.id}`}
                  onClick={() => onRevokeAccess(terminal.id)}
                  style={{
                    ...actionButtonStyle,
                    background: theme.badges.danger.bg,
                    color: 'rgb(239, 68, 68)',
                  }}
                >
                  <RevokeIcon />
                </button>
              </div>
            </div>
          ))}
        </div>
      ) : (
        /* Desktop Table View */
        <div
          style={{
            overflowX: 'auto',
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.md,
          }}
        >
          <table
            data-testid="settings-terminals-table"
            style={{
              width: '100%',
              borderCollapse: 'collapse',
              fontSize: theme.typography.fontSize.sm,
            }}
          >
            <thead>
              <tr style={{ background: theme.colors.bg.tertiary }}>
                <th
                  style={{
                    padding: theme.spacing.md,
                    textAlign: 'left',
                    borderBottom: `1px solid ${theme.colors.border.light}`,
                    fontWeight: theme.typography.fontWeight.semibold,
                  }}
                >
                  {t('settings.terminalName')}
                </th>
                <th
                  style={{
                    padding: theme.spacing.md,
                    textAlign: 'left',
                    borderBottom: `1px solid ${theme.colors.border.light}`,
                    fontWeight: theme.typography.fontWeight.semibold,
                  }}
                >
                  {t('settings.deviceId')}
                </th>
                <th
                  style={{
                    padding: theme.spacing.md,
                    textAlign: 'left',
                    borderBottom: `1px solid ${theme.colors.border.light}`,
                    fontWeight: theme.typography.fontWeight.semibold,
                  }}
                >
                  {t('settings.lastSync')}
                </th>
                <th
                  style={{
                    padding: theme.spacing.md,
                    textAlign: 'left',
                    borderBottom: `1px solid ${theme.colors.border.light}`,
                    fontWeight: theme.typography.fontWeight.semibold,
                  }}
                >
                  {t('settings.lastTransaction')}
                </th>
                <th
                  style={{
                    padding: theme.spacing.md,
                    textAlign: 'left',
                    borderBottom: `1px solid ${theme.colors.border.light}`,
                    fontWeight: theme.typography.fontWeight.semibold,
                  }}
                >
                  {t('settings.tokenExpires')}
                </th>
                <th
                  style={{
                    padding: theme.spacing.md,
                    textAlign: 'center',
                    borderBottom: `1px solid ${theme.colors.border.light}`,
                    fontWeight: theme.typography.fontWeight.semibold,
                  }}
                >
                  {t('common.actions')}
                </th>
              </tr>
            </thead>
            <tbody>
              {terminals.map((terminal) => (
                <tr
                  key={terminal.id}
                  data-testid={`settings-terminal-row-${terminal.id}`}
                  style={{
                    borderBottom: `1px solid ${theme.colors.border.light}`,
                    opacity: terminal.is_active ? 1 : 0.5,
                    transition: `opacity ${theme.transitions.default}`,
                  }}
                >
                  {/* Terminal Toggle + Name */}
                  <td
                    style={{
                      padding: theme.spacing.md,
                      display: 'flex',
                      alignItems: 'center',
                      gap: theme.spacing.md,
                    }}
                  >
                    <Toggle
                      isEnabled={terminal.is_active}
                      onChange={(enabled) => {
                        if (enabled) {
                          onReactivateTerminal(terminal.id)
                        } else {
                          onDeactivateTerminal(terminal.id)
                        }
                      }}
                      testId={`settings-terminal-toggle-${terminal.id}`}
                    />
                    <span data-testid={`settings-terminal-name-${terminal.id}`}>{terminal.name}</span>
                  </td>

                  {/* Device ID */}
                  <td style={{ padding: theme.spacing.md }} data-testid={`settings-terminal-device-id-${terminal.id}`}>
                    <code
                      style={{
                        padding: `2px ${theme.spacing.sm}`,
                        background: theme.colors.bg.tertiary,
                        borderRadius: theme.borderRadius.sm,
                        fontSize: theme.typography.fontSize.xs,
                      }}
                    >
                      {terminal.device_id}
                    </code>
                  </td>

                  {/* Last Sync */}
                  <td
                    style={{
                      padding: theme.spacing.md,
                      color: theme.colors.text.secondary,
                      fontSize: theme.typography.fontSize.xs,
                    }}
                    data-testid={`settings-terminal-last-sync-${terminal.id}`}
                  >
                    {terminal.last_sync_at ? formatDateTime(terminal.last_sync_at) : t('dates.never')}
                  </td>

                  {/* Last Transaction */}
                  <td
                    style={{
                      padding: theme.spacing.md,
                      color: theme.colors.text.secondary,
                      fontSize: theme.typography.fontSize.xs,
                    }}
                    data-testid={`settings-terminal-last-transaction-${terminal.id}`}
                  >
                    {terminal.last_transaction_at ? formatDateTime(terminal.last_transaction_at) : t('dates.never')}
                  </td>

                  {/* Token expiry (#106) */}
                  <td
                    style={{
                      padding: theme.spacing.md,
                      color: theme.colors.text.secondary,
                      fontSize: theme.typography.fontSize.xs,
                    }}
                  >
                    <TokenExpiry terminal={terminal} />
                  </td>

                  {/* Actions */}
                  <td style={{ padding: theme.spacing.md, textAlign: 'center' }}>
                    <div style={{ display: 'flex', gap: theme.spacing.sm, justifyContent: 'center', alignItems: 'center' }}>
                      {/* Edit Button */}
                      <Tooltip content={t('common.edit')} position="top">
                        <button
                          data-testid={`settings-terminal-edit-button-${terminal.id}`}
                          onClick={() => onEditTerminal(terminal)}
                          style={actionButtonStyle}
                          onMouseEnter={(e) => {
                            e.currentTarget.style.background = 'rgba(59, 130, 246, 0.2)'
                            e.currentTarget.style.color = theme.colors.semantic.primary
                          }}
                          onMouseLeave={(e) => {
                            e.currentTarget.style.background = 'transparent'
                            e.currentTarget.style.color = theme.colors.text.secondary
                          }}
                        >
                          <EditIcon />
                        </button>
                      </Tooltip>

                      {/* Rotate Token Button */}
                      <Tooltip content={t('settings.rotateToken')} position="top">
                        <button
                          data-testid={`settings-terminal-rotate-token-button-${terminal.id}`}
                          onClick={() => onRotateToken(terminal.id)}
                          style={actionButtonStyle}
                          onMouseEnter={(e) => {
                            e.currentTarget.style.background = 'rgba(249, 115, 22, 0.2)'
                            e.currentTarget.style.color = 'rgb(249, 115, 22)'
                          }}
                          onMouseLeave={(e) => {
                            e.currentTarget.style.background = 'transparent'
                            e.currentTarget.style.color = theme.colors.text.secondary
                          }}
                        >
                          <RotateTokenIcon />
                        </button>
                      </Tooltip>

                      {/* Revoke Access Button */}
                      <Tooltip content={t('settings.revokeAccess')} position="top">
                        <button
                          data-testid={`settings-terminal-revoke-button-${terminal.id}`}
                          onClick={() => onRevokeAccess(terminal.id)}
                          style={actionButtonStyle}
                          onMouseEnter={(e) => {
                            e.currentTarget.style.background = 'rgba(239, 68, 68, 0.2)'
                            e.currentTarget.style.color = 'rgb(239, 68, 68)'
                          }}
                          onMouseLeave={(e) => {
                            e.currentTarget.style.background = 'transparent'
                            e.currentTarget.style.color = theme.colors.text.secondary
                          }}
                        >
                          <RevokeIcon />
                        </button>
                      </Tooltip>

                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
