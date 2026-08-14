/**
 * The rotation wizard: walking every stored IBAN from the old key to the new
 * one, 100 rows at a time (#394, ADR-0036).
 *
 * Activating a replacement key is what *starts* a rotation — from that moment
 * new IBANs are sealed under the new key while the existing rows are still on
 * the old one. This dialog is the rest of it, and it is a loop rather than a
 * button because the server cannot do it alone: only the old private key can
 * open what is being rotated away from, and that key lives offline. It is
 * supplied here, kept in this component for as long as the dialog is open, and
 * sent with each batch request. Nothing is persisted — not in a session, not in
 * storage — so closing the dialog ends the server's ability to read those rows
 * again.
 *
 * Every batch carries its own step-up credential, which is why the loop is
 * driven by the admin instead of running unattended. With 2FA enrolled that
 * means a fresh code per batch: codes are single-use per 30-second step
 * (#338), so the previous one will not be accepted again.
 *
 * Nothing is lost by stopping: batches are selected by "still on the old key",
 * so reopening the dialog resumes exactly where it left off.
 *
 * Test IDs: `key-rotation-progress`, `key-rotation-remaining`,
 * `key-rotation-drained`, plus the wrapped dialog's own.
 */

import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { PrivateKeyInput } from './PrivateKeyInput'
import { StepUpConfirmDialog, type StepUpCredentials } from './StepUpConfirmDialog'

export interface KeyRotationProgress {
  processed: number
  skipped: number
  remaining: number
  drained: boolean
}

export interface KeyRotationDialogProps {
  isOpen: boolean
  /** Identifier of the key being rotated away from, for the dialog copy. */
  keyIdentifier: string
  /** Rows still sealed under it when the dialog opened. */
  outstandingCount: number
  requiresTotp: boolean
  /** Result of the last batch, or null before the first one. */
  progress: KeyRotationProgress | null
  error?: string | null
  /** True while a batch or the final retirement is in flight. */
  busy?: boolean
  onRunBatch: (privateKeyBase64: string, credentials: StepUpCredentials) => void
  onComplete: (credentials: StepUpCredentials) => void
  onClose: () => void
}

export function KeyRotationDialog({
  isOpen,
  keyIdentifier,
  outstandingCount,
  requiresTotp,
  progress,
  error,
  busy = false,
  onRunBatch,
  onComplete,
  onClose,
}: KeyRotationDialogProps) {
  const { t } = useTranslation()
  const [privateKey, setPrivateKey] = useState('')

  // The key is collected once per rotation, not once per batch — but a fresh
  // open is a fresh rotation, and must not inherit the key material of the last
  // one.
  useEffect(() => {
    if (isOpen) setPrivateKey('')
  }, [isOpen])

  // Trust the server's re-count, not the last batch's: `remaining` was true at
  // the moment the batch ended, and the final retirement re-checks it anyway.
  const drained = progress?.drained ?? outstandingCount === 0
  const remaining = progress?.remaining ?? outstandingCount

  return (
    <StepUpConfirmDialog
      isOpen={isOpen}
      title={t('settings.credentials.rotation.title', { key: keyIdentifier })}
      message={
        drained
          ? t('settings.credentials.rotation.readyToRetire', { key: keyIdentifier })
          : t('settings.credentials.rotation.intro', { key: keyIdentifier, count: remaining })
      }
      confirmLabel={
        drained ? t('settings.credentials.rotation.retire') : t('settings.credentials.rotation.runBatch')
      }
      requiresTotp={requiresTotp}
      error={error}
      // Before the backlog is drained a batch needs the old private key; the
      // final retirement is a row count and needs none.
      confirmDisabled={busy || (!drained && privateKey.trim() === '')}
      extraFields={
        <>
          {progress && (
            <div
              data-testid="key-rotation-progress"
              style={{
                marginBottom: theme.spacing.md,
                padding: theme.spacing.md,
                borderRadius: theme.borderRadius.md,
                background: theme.colors.bg.card,
                border: `1px solid ${theme.colors.border.light}`,
                fontSize: theme.typography.fontSize.sm,
              }}
            >
              <div>{t('settings.credentials.rotation.batchDone', { count: progress.processed })}</div>
              {progress.skipped > 0 && (
                <div style={{ color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.xs }}>
                  {t('settings.credentials.rotation.skipped', { count: progress.skipped })}
                </div>
              )}
              <div
                data-testid="key-rotation-remaining"
                data-remaining={progress.remaining}
                style={{ marginTop: theme.spacing.xs, fontWeight: theme.typography.fontWeight.semibold }}
              >
                {progress.drained ? (
                  <span data-testid="key-rotation-drained">
                    {t('settings.credentials.rotation.allMoved')}
                  </span>
                ) : (
                  t('settings.credentials.rotation.remaining', { count: progress.remaining })
                )}
              </div>
            </div>
          )}

          {!drained && (
            <>
              <p
                style={{
                  margin: `0 0 ${theme.spacing.sm} 0`,
                  fontSize: theme.typography.fontSize.xs,
                  color: theme.colors.text.secondary,
                }}
              >
                {t('settings.credentials.rotation.keyHint')}
              </p>
              <PrivateKeyInput value={privateKey} onChange={setPrivateKey} />
            </>
          )}
        </>
      }
      onConfirm={(credentials) =>
        drained ? onComplete(credentials) : onRunBatch(privateKey, credentials)
      }
      onCancel={onClose}
    />
  )
}
