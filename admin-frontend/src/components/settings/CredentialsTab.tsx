/**
 * Security & Credentials: the IBAN encryption keypairs and their lifecycle
 * (#394, ADR-0036).
 *
 * Member IBANs are sealed under the club's public key and the server holds no
 * private key, so the keypair is not an implementation detail an admin can
 * ignore — it is a thing the club owns, that expires, and that has to be
 * replaced before it does. This page is where that is visible: which key is in
 * force, how long it has left, whether a rotation is half-finished, and what to
 * press next.
 *
 * Nothing here is computed on a schedule. Shared hosting guarantees no cron
 * (ADR-0031), so every warning tier arrives with the response that was just
 * fetched.
 *
 * Every mutation carries a fresh step-up credential: in a flat admin model
 * (ADR-0015) that is what stands in for a key-management permission.
 *
 * Test IDs: `credentials-tab`, `credentials-key-<id>` (with `data-status` /
 * `data-lifecycle-state`), `credentials-register-button`,
 * `credentials-activate-<id>`, `credentials-rotate-<id>`,
 * `credentials-revoke-<id>`, `credentials-compromise-<id>`,
 * `credentials-expiry-banner`, `credentials-error`, `credentials-success`.
 */

import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { theme, formatDateTime } from '../../styles/design-system'
import { Alert } from '../common/Alert'
import { Badge } from '../common/Badge'
import { StepUpConfirmDialog, type StepUpCredentials } from '../modals/StepUpConfirmDialog'
import { KeyRotationDialog, type KeyRotationProgress } from '../modals/KeyRotationDialog'
import { modalInputStyle } from '../modals/ModalError'
import { useLatestRequest } from '../../hooks/useLatestRequest'
import { getSecurity } from '../../api/generated/security/security'
import { getApiErrorMessage } from '../../utils/apiErrors'
import type { EncryptionKey } from '../../api/generated'

export interface CredentialsTabProps {
  /**
   * Whether the *signed-in* admin has 2FA enrolled — decides whether the
   * step-up dialogs ask for a code alongside the password (#337).
   */
  callerTotpEnabled: boolean
}

/** Statuses whose rows can still be walked onto the active key. */
const ROTATABLE = ['retiring', 'revoked', 'compromised']

const STATUS_VARIANT: Record<string, 'success' | 'warning' | 'danger' | 'info' | 'neutral'> = {
  active: 'success',
  pending: 'info',
  retiring: 'warning',
  retired: 'neutral',
  revoked: 'neutral',
  compromised: 'danger',
}

const LIFECYCLE_COLOR: Record<string, string> = {
  ok: theme.colors.semantic.success,
  info: theme.colors.text.secondary,
  warning: theme.colors.semantic.warning,
  critical: theme.colors.semantic.danger,
  expired: theme.colors.semantic.danger,
}

type RevokeIntent = { id: string; compromised: boolean }

export function CredentialsTab({ callerTotpEnabled }: CredentialsTabProps) {
  const { t } = useTranslation()
  const request = useLatestRequest()

  const [keys, setKeys] = useState<EncryptionKey[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)

  // Every dialog reports its own failure inside itself and stays open, so the
  // admin can correct a wrong password or a wrong key file without losing the
  // key they were acting on.
  const [dialogError, setDialogError] = useState<string | null>(null)

  const [registerOpen, setRegisterOpen] = useState(false)
  const [newIdentifier, setNewIdentifier] = useState('')
  const [newPublicKey, setNewPublicKey] = useState('')
  const [activateId, setActivateId] = useState<string | null>(null)
  const [revokeIntent, setRevokeIntent] = useState<RevokeIntent | null>(null)
  const [rotateKey, setRotateKey] = useState<EncryptionKey | null>(null)
  const [rotationProgress, setRotationProgress] = useState<KeyRotationProgress | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    const signal = request.next()
    try {
      setLoading(true)
      const result = await getSecurity().listEncryptionKeys({ signal })
      if (signal.aborted) return
      setKeys(result.keys ?? [])
      setError(null)
    } catch (err: unknown) {
      if (signal.aborted) return
      setError(getApiErrorMessage(err, t('settings.credentials.errors.load')))
    } finally {
      // A superseded request must not clear the spinner a newer one raised.
      if (!signal.aborted) setLoading(false)
    }
  }, [request, t])

  useEffect(() => {
    void load()
    return () => request.abort()
  }, [load, request])

  const activeKey = keys.find((key) => key.status === 'active') ?? null

  const closeDialogs = () => {
    setRegisterOpen(false)
    setActivateId(null)
    setRevokeIntent(null)
    setRotateKey(null)
    setRotationProgress(null)
    setDialogError(null)
  }

  const afterMutation = async (messageKey: string) => {
    closeDialogs()
    setError(null)
    setSuccess(t(messageKey))
    await load()
  }

  const handleRegister = async (credentials: StepUpCredentials) => {
    setBusy(true)
    try {
      await getSecurity().registerEncryptionKey({
        public_key: newPublicKey.trim(),
        key_identifier: newIdentifier.trim(),
        ...credentials,
      })
      setNewIdentifier('')
      setNewPublicKey('')
      await afterMutation('settings.credentials.registered')
    } catch (err: unknown) {
      setDialogError(getApiErrorMessage(err, t('settings.credentials.errors.register')))
    } finally {
      setBusy(false)
    }
  }

  const handleActivate = async (credentials: StepUpCredentials) => {
    if (!activateId) return
    setBusy(true)
    try {
      await getSecurity().activateEncryptionKey(activateId, credentials)
      await afterMutation('settings.credentials.activated')
    } catch (err: unknown) {
      setDialogError(getApiErrorMessage(err, t('settings.credentials.errors.activate')))
    } finally {
      setBusy(false)
    }
  }

  const handleRevoke = async (credentials: StepUpCredentials) => {
    if (!revokeIntent) return
    setBusy(true)
    try {
      await getSecurity().revokeEncryptionKey(revokeIntent.id, {
        compromised: revokeIntent.compromised,
        ...credentials,
      })
      await afterMutation(
        revokeIntent.compromised ? 'settings.credentials.compromised' : 'settings.credentials.revoked',
      )
    } catch (err: unknown) {
      setDialogError(getApiErrorMessage(err, t('settings.credentials.errors.revoke')))
    } finally {
      setBusy(false)
    }
  }

  const handleRotateBatch = async (privateKey: string, credentials: StepUpCredentials) => {
    if (!rotateKey?.id) return
    setBusy(true)
    try {
      const result = await getSecurity().rotateEncryptionKeyBatch(rotateKey.id, {
        private_key: privateKey,
        ...credentials,
      })
      const rotation = result.rotation
      setRotationProgress({
        processed: rotation?.processed ?? 0,
        skipped: rotation?.skipped ?? 0,
        remaining: rotation?.remaining ?? 0,
        drained: rotation?.drained ?? false,
      })
      setDialogError(null)
      // The list behind the dialog carries the backlog count, so it has to
      // follow along — a rotation the admin walks away from mid-way must show
      // where it stopped.
      await load()
    } catch (err: unknown) {
      setDialogError(getApiErrorMessage(err, t('settings.credentials.errors.rotate')))
    } finally {
      setBusy(false)
    }
  }

  const handleCompleteRotation = async (credentials: StepUpCredentials) => {
    if (!rotateKey?.id) return
    setBusy(true)
    try {
      await getSecurity().completeEncryptionKeyRotation(rotateKey.id, credentials)
      await afterMutation('settings.credentials.rotation.completed')
    } catch (err: unknown) {
      setDialogError(getApiErrorMessage(err, t('settings.credentials.errors.completeRotation')))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div data-testid="credentials-tab">
      <div
        style={{
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'flex-start',
          gap: theme.spacing.lg,
          marginBottom: theme.spacing.lg,
          flexWrap: 'wrap',
        }}
      >
        <div style={{ maxWidth: '640px' }}>
          <h2 style={{ margin: 0, fontSize: theme.typography.fontSize.lg }}>
            {t('settings.credentials.title')}
          </h2>
          <p
            style={{
              margin: `${theme.spacing.sm} 0 0`,
              color: theme.colors.text.secondary,
              fontSize: theme.typography.fontSize.sm,
            }}
          >
            {t('settings.credentials.intro')}
          </p>
        </div>
        <button
          data-testid="credentials-register-button"
          onClick={() => {
            setDialogError(null)
            setRegisterOpen(true)
          }}
          style={{
            padding: `${theme.spacing.md} ${theme.spacing.lg}`,
            background: theme.colors.semantic.primary,
            color: 'white',
            border: 'none',
            borderRadius: theme.borderRadius.md,
            fontSize: theme.typography.fontSize.sm,
            fontWeight: theme.typography.fontWeight.semibold,
            cursor: 'pointer',
          }}
        >
          + {t('settings.credentials.register')}
        </button>
      </div>

      <ExpiryBanner activeKey={activeKey} />

      {error && <Alert variant="danger" message={error} testId="credentials-error" />}
      {success && <Alert variant="success" message={success} dismissible testId="credentials-success" />}

      {loading && keys.length === 0 && (
        <div data-testid="credentials-loading" style={{ color: theme.colors.text.secondary }}>
          {t('common.loading')}
        </div>
      )}

      {!loading && keys.length === 0 && (
        <div
          data-testid="credentials-empty"
          style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}
        >
          {t('settings.credentials.empty')}
        </div>
      )}

      <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.md }}>
        {keys.map((key) => (
          <KeyCard
            key={key.id}
            encryptionKey={key}
            onActivate={() => {
              setDialogError(null)
              setActivateId(key.id!)
            }}
            onRotate={() => {
              setDialogError(null)
              setRotationProgress(null)
              setRotateKey(key)
            }}
            onRevoke={(compromised) => {
              setDialogError(null)
              setRevokeIntent({ id: key.id!, compromised })
            }}
          />
        ))}
      </div>

      <StepUpConfirmDialog
        isOpen={registerOpen}
        title={t('settings.credentials.register')}
        message={t('settings.credentials.registerHint')}
        confirmLabel={t('settings.credentials.register')}
        requiresTotp={callerTotpEnabled}
        error={dialogError}
        confirmDisabled={busy || newIdentifier.trim() === '' || newPublicKey.trim() === ''}
        extraFields={
          <>
            <input
              data-testid="credentials-key-identifier"
              type="text"
              placeholder={t('settings.credentials.identifierLabel')}
              value={newIdentifier}
              onChange={(e) => setNewIdentifier(e.target.value)}
              style={modalInputStyle(false)}
            />
            <input
              data-testid="credentials-public-key"
              type="text"
              autoComplete="off"
              spellCheck={false}
              placeholder={t('settings.credentials.publicKeyLabel')}
              value={newPublicKey}
              onChange={(e) => setNewPublicKey(e.target.value.trim())}
              style={{ ...modalInputStyle(false), fontFamily: 'monospace' }}
            />
          </>
        }
        onConfirm={handleRegister}
        onCancel={closeDialogs}
      />

      <StepUpConfirmDialog
        isOpen={activateId !== null}
        title={t('settings.credentials.activate')}
        message={t('settings.credentials.activateHint')}
        confirmLabel={t('settings.credentials.activate')}
        requiresTotp={callerTotpEnabled}
        error={dialogError}
        confirmDisabled={busy}
        onConfirm={handleActivate}
        onCancel={closeDialogs}
      />

      <StepUpConfirmDialog
        isOpen={revokeIntent !== null}
        title={
          revokeIntent?.compromised
            ? t('settings.credentials.compromise')
            : t('settings.credentials.revoke')
        }
        message={
          revokeIntent?.compromised
            ? t('settings.credentials.compromiseHint')
            : t('settings.credentials.revokeHint')
        }
        confirmLabel={t('common.confirm')}
        requiresTotp={callerTotpEnabled}
        error={dialogError}
        confirmDisabled={busy}
        onConfirm={handleRevoke}
        onCancel={closeDialogs}
      />

      <KeyRotationDialog
        isOpen={rotateKey !== null}
        keyIdentifier={rotateKey?.key_identifier ?? ''}
        outstandingCount={rotateKey?.sealed_record_count ?? 0}
        requiresTotp={callerTotpEnabled}
        progress={rotationProgress}
        error={dialogError}
        busy={busy}
        onRunBatch={handleRotateBatch}
        onComplete={handleCompleteRotation}
        onClose={closeDialogs}
      />
    </div>
  )
}

/**
 * The warning an admin should not have to go looking for: the active key's
 * remaining lifetime, at the top of the page that can do something about it.
 * A missing key is the loudest case — until one is activated, no member's bank
 * details can be stored at all.
 */
function ExpiryBanner({ activeKey }: { activeKey: EncryptionKey | null }) {
  const { t } = useTranslation()

  if (activeKey === null) {
    return (
      <Alert
        variant="danger"
        title={t('settings.credentials.banner.missingTitle')}
        message={t('settings.credentials.banner.missing')}
        testId="credentials-expiry-banner"
      />
    )
  }

  const state = activeKey.lifecycle_state ?? 'ok'
  if (state === 'ok') return null

  return (
    <Alert
      variant={state === 'info' ? 'info' : state === 'warning' ? 'warning' : 'danger'}
      title={t(`settings.credentials.banner.${state}Title`)}
      message={
        state === 'expired'
          ? t('settings.credentials.banner.expired', { key: activeKey.key_identifier })
          : t('settings.credentials.banner.expiring', {
              key: activeKey.key_identifier,
              count: activeKey.days_until_expiry ?? 0,
            })
      }
      testId="credentials-expiry-banner"
    />
  )
}

function KeyCard({
  encryptionKey,
  onActivate,
  onRotate,
  onRevoke,
}: {
  encryptionKey: EncryptionKey
  onActivate: () => void
  onRotate: () => void
  onRevoke: (compromised: boolean) => void
}) {
  const { t } = useTranslation()
  const status = encryptionKey.status
  const lifecycle = encryptionKey.lifecycle_state ?? 'ok'
  const backlog = encryptionKey.sealed_record_count ?? 0
  const withdrawable = status === 'active' || status === 'retiring' || status === 'pending'

  return (
    <div
      data-testid={`credentials-key-${encryptionKey.id}`}
      data-status={status}
      data-lifecycle-state={lifecycle}
      data-sealed-record-count={backlog}
      style={{
        padding: theme.spacing.lg,
        background: theme.colors.bg.card,
        border: `1px solid ${theme.colors.border.light}`,
        borderLeft: `3px solid ${LIFECYCLE_COLOR[lifecycle] ?? theme.colors.border.light}`,
        borderRadius: theme.borderRadius.md,
        display: 'flex',
        flexWrap: 'wrap',
        gap: theme.spacing.lg,
        justifyContent: 'space-between',
      }}
    >
      <div style={{ minWidth: 0, flex: 1 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: theme.spacing.md, flexWrap: 'wrap' }}>
          <strong style={{ fontSize: theme.typography.fontSize.base }}>{encryptionKey.key_identifier}</strong>
          <Badge
            label={t(`settings.credentials.status.${status}`)}
            variant={STATUS_VARIANT[status] ?? 'neutral'}
            showDot={false}
            testId={`credentials-key-status-${encryptionKey.id}`}
          />
        </div>

        <div
          style={{
            marginTop: theme.spacing.sm,
            fontFamily: theme.typography.fontFamily.mono,
            fontSize: theme.typography.fontSize.xs,
            color: theme.colors.text.muted,
            wordBreak: 'break-all',
          }}
        >
          {/* The fingerprint is how a key is named out loud — it is what the
              admin compares against the sheet in the safe before supplying a
              private key. The first 16 characters are enough to tell the
              club's keys apart. */}
          {encryptionKey.fingerprint_sha256?.slice(0, 16)}…
        </div>

        <div
          style={{
            marginTop: theme.spacing.sm,
            fontSize: theme.typography.fontSize.xs,
            color: theme.colors.text.secondary,
            display: 'flex',
            gap: theme.spacing.lg,
            flexWrap: 'wrap',
          }}
        >
          {encryptionKey.activated_at && (
            <span>
              {t('settings.credentials.activatedAt')}: {formatDateTime(encryptionKey.activated_at)}
            </span>
          )}
          {encryptionKey.expires_at && (
            <span data-testid={`credentials-key-expiry-${encryptionKey.id}`}>
              {t('settings.credentials.expiresAt')}: {formatDateTime(encryptionKey.expires_at)}
              {encryptionKey.days_until_expiry !== null && encryptionKey.days_until_expiry !== undefined && (
                <span style={{ color: LIFECYCLE_COLOR[lifecycle], marginLeft: theme.spacing.sm }}>
                  ({t('settings.credentials.daysLeft', { count: encryptionKey.days_until_expiry })})
                </span>
              )}
            </span>
          )}
          <span data-testid={`credentials-key-backlog-${encryptionKey.id}`}>
            {t('settings.credentials.sealedRecords', { count: backlog })}
          </span>
        </div>
      </div>

      <div style={{ display: 'flex', gap: theme.spacing.sm, alignItems: 'flex-start', flexWrap: 'wrap' }}>
        {status === 'pending' && (
          <ActionButton testId={`credentials-activate-${encryptionKey.id}`} onClick={onActivate} primary>
            {t('settings.credentials.activate')}
          </ActionButton>
        )}
        {/* Re-encryption is offered only where it can do something: a key with
            no rows left needs the retirement step, which the same dialog
            handles once the backlog is drained. */}
        {ROTATABLE.includes(status) && (
          <ActionButton testId={`credentials-rotate-${encryptionKey.id}`} onClick={onRotate} primary={backlog > 0}>
            {backlog > 0
              ? t('settings.credentials.rotation.runBatch')
              : t('settings.credentials.rotation.retire')}
          </ActionButton>
        )}
        {withdrawable && (
          <>
            <ActionButton testId={`credentials-revoke-${encryptionKey.id}`} onClick={() => onRevoke(false)}>
              {t('settings.credentials.revoke')}
            </ActionButton>
            <ActionButton
              testId={`credentials-compromise-${encryptionKey.id}`}
              onClick={() => onRevoke(true)}
              danger
            >
              {t('settings.credentials.compromise')}
            </ActionButton>
          </>
        )}
      </div>
    </div>
  )
}

function ActionButton({
  testId,
  onClick,
  children,
  primary = false,
  danger = false,
}: {
  testId: string
  onClick: () => void
  children: React.ReactNode
  primary?: boolean
  danger?: boolean
}) {
  return (
    <button
      data-testid={testId}
      onClick={onClick}
      style={{
        padding: `${theme.spacing.sm} ${theme.spacing.md}`,
        borderRadius: theme.borderRadius.md,
        border: `1px solid ${danger ? theme.colors.semantic.danger : theme.colors.border.light}`,
        background: primary ? theme.colors.semantic.primary : 'transparent',
        color: primary ? 'white' : danger ? theme.colors.semantic.danger : theme.colors.text.primary,
        fontSize: theme.typography.fontSize.sm,
        cursor: 'pointer',
        whiteSpace: 'nowrap',
      }}
    >
      {children}
    </button>
  )
}
