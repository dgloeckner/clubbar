/**
 * ConfirmDialog Component
 * Shared reusable confirmation dialog for destructive or irreversible actions.
 *
 * Replaces all native confirm() and window.confirm() calls across the admin frontend.
 * Uses design system theme tokens exclusively — no hardcoded colors.
 *
 * Test IDs:
 *   confirm-dialog          — backdrop overlay
 *   confirm-dialog-content  — inner content box
 *   confirm-dialog-message  — message text
 *   confirm-dialog-cancel   — cancel button
 *   confirm-dialog-ok       — confirm button (absent when showConfirm is false)
 */

import { useEffect, useRef, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'

export interface ConfirmDialogProps {
  isOpen: boolean
  title?: string
  /** Plain text for most callers; a node when the question needs figures (#127). */
  message: ReactNode
  confirmLabel?: string
  cancelLabel?: string
  variant?: 'danger' | 'primary'
  /** The question is asked but cannot be answered yet — e.g. an unticked acknowledgement. */
  confirmDisabled?: boolean
  /**
   * Drop the confirm button entirely. For a dialog that only explains why an
   * action is unavailable, a permanently disabled button is the dead control
   * the dialog exists to replace.
   */
  showConfirm?: boolean
  onConfirm: () => void
  onCancel: () => void
}

export function ConfirmDialog({
  isOpen,
  title,
  message,
  confirmLabel,
  cancelLabel,
  variant = 'danger',
  confirmDisabled = false,
  showConfirm = true,
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  const { t } = useTranslation()
  const contentRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (isOpen) {
      contentRef.current?.focus()
    }
  }, [isOpen])

  if (!isOpen) return null

  const resolvedConfirmLabel = confirmLabel ?? t('common.confirm')
  const resolvedCancelLabel = cancelLabel ?? t('common.cancel')

  const confirmBg = variant === 'danger' ? theme.colors.semantic.danger : theme.colors.semantic.primary

  return (
    <div
      data-testid="confirm-dialog"
      tabIndex={-1}
      style={{
        position: 'fixed',
        inset: 0,
        background: 'rgba(0, 0, 0, 0.5)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 2000,
      }}
      onClick={onCancel}
      onKeyDown={(e) => { if (e.key === 'Escape') onCancel() }}
    >
      <div
        ref={contentRef}
        data-testid="confirm-dialog-content"
        role="dialog"
        aria-modal="true"
        aria-labelledby={title ? 'confirm-dialog-title' : undefined}
        aria-label={!title ? confirmLabel ?? t('common.confirm') : undefined}
        aria-describedby="confirm-dialog-message"
        tabIndex={-1}
        style={{
          background: theme.colors.bg.secondary,
          borderRadius: theme.borderRadius.lg,
          padding: theme.spacing.xl,
          maxWidth: '420px',
          width: '90%',
          boxShadow: '0 25px 50px rgba(0, 0, 0, 0.5)',
        }}
        onClick={(e) => e.stopPropagation()}
      >
        {title && (
          <h2
            id="confirm-dialog-title"
            style={{
              margin: 0,
              marginBottom: theme.spacing.md,
              fontSize: theme.typography.fontSize.xl,
              fontWeight: theme.typography.fontWeight.semibold,
              color: theme.colors.text.primary,
            }}
          >
            {title}
          </h2>
        )}

        {/* A div, not a p: the message may carry blocks of its own (#127). */}
        <div
          id="confirm-dialog-message"
          data-testid="confirm-dialog-message"
          style={{
            marginBottom: theme.spacing.xl,
            color: theme.colors.text.secondary,
            fontSize: theme.typography.fontSize.base,
            lineHeight: theme.typography.lineHeight.normal,
          }}
        >
          {message}
        </div>

        <div style={{ display: 'flex', gap: theme.spacing.md, justifyContent: 'flex-end' }}>
          <button
            data-testid="confirm-dialog-cancel"
            onClick={onCancel}
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
            {resolvedCancelLabel}
          </button>

          {showConfirm && (
            <button
              data-testid="confirm-dialog-ok"
              onClick={onConfirm}
              disabled={confirmDisabled}
              style={{
                padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                background: confirmDisabled ? theme.colors.bg.tertiary : confirmBg,
                border: 'none',
                borderRadius: theme.borderRadius.md,
                color: confirmDisabled ? theme.colors.text.muted : 'white',
                cursor: confirmDisabled ? 'not-allowed' : 'pointer',
                fontSize: theme.typography.fontSize.sm,
                fontWeight: theme.typography.fontWeight.semibold,
              }}
            >
              {resolvedConfirmLabel}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}
