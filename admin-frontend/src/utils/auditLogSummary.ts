/**
 * Builds a translated, human-readable one-line summary for an audit log
 * entry — every AuditAction the backend can emit gets a specific sentence
 * here rather than falling through to a generic "no changes" message (#381).
 */
import type { AuditLogEntry } from '../api/generated'
import { formatPrice } from '../styles/design-system'
import { getLocalizedName } from './i18n-helpers'

type TFn = (key: string, options?: Record<string, unknown>) => string

type Values = Record<string, unknown> | null | undefined

function asString(value: unknown): string | undefined {
  return typeof value === 'string' && value.length > 0 ? value : undefined
}

function asNumber(value: unknown): number | undefined {
  return typeof value === 'number' ? value : undefined
}

/** Best-effort human label for the affected record, pulled from whichever values are present. */
function getSubjectLabel(values: Values, language: string): string | undefined {
  if (!values) return undefined

  const name = values['name']
  if (typeof name === 'string' && name.length > 0) return name
  if (name && typeof name === 'object') {
    const localized = getLocalizedName(name as Record<string, string>, language)
    if (localized) return localized
  }

  const firstName = asString(values['first_name'])
  const lastName = asString(values['last_name'])
  if (firstName || lastName) return [firstName, lastName].filter(Boolean).join(' ')

  return asString(values['email']) ?? asString(values['card_uid']) ?? asString(values['original_filename'])
}

function subjectOrEntityId(entry: AuditLogEntry, language: string): string {
  const subject = getSubjectLabel(entry.new_values, language) ?? getSubjectLabel(entry.old_values, language)
  if (subject) return subject
  return entry.entity_id ? entry.entity_id.slice(0, 8) : ''
}

function entityTypeLabel(entry: AuditLogEntry, t: TFn): string {
  const type = entry.entity_type ?? ''
  return t(`auditLog.entityTypes.${type}`, { defaultValue: type })
}

function actorLabel(entry: AuditLogEntry, t: TFn): string {
  return entry.admin_user_email ?? t('auditLog.summary.unknownActor')
}

/** Returns the translated summary sentence for a single audit log entry. */
export function getAuditLogSummary(entry: AuditLogEntry, t: TFn, language: string): string {
  const actor = actorLabel(entry, t)
  const entityType = entityTypeLabel(entry, t)
  const subject = subjectOrEntityId(entry, language)
  const newValues = entry.new_values as Values

  switch (entry.action) {
    case 'login':
      return t('auditLog.summary.login', { actor })
    case 'logout':
      return t('auditLog.summary.logout', { actor })
    case 'login_failed': {
      const email = asString(newValues?.['attempted_email']) ?? subject
      if (newValues?.['context'] === 'step_up_reauth') {
        return t('auditLog.summary.loginFailedStepUp', { actor })
      }
      return t('auditLog.summary.loginFailed', { email })
    }
    case 'totp_enrolled':
      return t('auditLog.summary.totpEnrolled', { actor })
    case 'totp_reset': {
      const target = asString(newValues?.['target_email']) ?? subject
      return t('auditLog.summary.totpReset', { actor, target })
    }
    case 'anonymize':
      return t('auditLog.summary.anonymize', { actor, subject })
    case 'export':
      return t('auditLog.summary.export', { actor })
    case 'transaction_storno':
      return t('auditLog.summary.transactionStorno', { actor })
    case 'transaction_price_divergence': {
      // Deliberately no {{actor}}: a terminal synced this and no admin acted,
      // so `actorLabel` would print "An admin" over something no admin did.
      const amountCents = asNumber(newValues?.['amount_cents'])
      const priceCents = asNumber(newValues?.['current_price_cents'])
      if (amountCents !== undefined && priceCents !== undefined) {
        return t('auditLog.summary.transactionPriceDivergence', {
          amount: formatPrice(amountCents),
          price: formatPrice(priceCents),
        })
      }
      return t('auditLog.summary.transactionPriceDivergenceGeneric')
    }
    case 'settlement_create': {
      const memberCount = asNumber(newValues?.['member_count'])
      const amountCents = asNumber(newValues?.['total_amount_cents'])
      if (memberCount !== undefined && amountCents !== undefined) {
        return t('auditLog.summary.settlementCreate', { actor, memberCount, amount: formatPrice(amountCents) })
      }
      return t('auditLog.summary.settlementCreateGeneric', { actor })
    }
    case 'settlement_cancel': {
      const reason = asString(newValues?.['reason'])
      return reason
        ? t('auditLog.summary.settlementCancelWithReason', { actor, reason })
        : t('auditLog.summary.settlementCancel', { actor })
    }
    case 'settlement_submit':
      return t('auditLog.summary.settlementSubmit', { actor })
    case 'settlement_export':
      return t('auditLog.summary.settlementExport', { actor })
    case 'settlement_reverse': {
      const memberCount = asNumber(newValues?.['member_count'])
      const amountCents = asNumber(newValues?.['amount_cents'])
      if (memberCount !== undefined && amountCents !== undefined) {
        return t('auditLog.summary.settlementReverse', { actor, memberCount, amount: formatPrice(amountCents) })
      }
      return t('auditLog.summary.settlementReverseGeneric', { actor })
    }
    case 'collection_hold_placed': {
      const reason = asString(newValues?.['reason'])
      return reason
        ? t('auditLog.summary.collectionHoldPlacedWithReason', { actor, reason })
        : t('auditLog.summary.collectionHoldPlaced', { actor })
    }
    case 'collection_hold_cleared':
      return t('auditLog.summary.collectionHoldCleared', { actor })
    case 'mandate_document_upload': {
      const filename = asString(newValues?.['original_filename'])
      return filename
        ? t('auditLog.summary.mandateDocumentUploadNamed', { actor, filename })
        : t('auditLog.summary.mandateDocumentUpload', { actor })
    }
    case 'mandate_document_delete':
      return t('auditLog.summary.mandateDocumentDelete', { actor })
    case 'create':
      return t('auditLog.summary.create', { actor, entityType, subject })
    case 'update':
      return t('auditLog.summary.update', { actor, entityType, subject })
    case 'delete':
      return t('auditLog.summary.delete', { actor, entityType, subject })
    case 'activate':
      return t('auditLog.summary.activate', { actor, entityType, subject })
    case 'deactivate':
      return t('auditLog.summary.deactivate', { actor, entityType, subject })
    default:
      return t('auditLog.summary.fallback', { actor, entityType, subject })
  }
}
