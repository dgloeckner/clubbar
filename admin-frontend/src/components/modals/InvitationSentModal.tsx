/**
 * InvitationSentModal Component
 *
 * What an admin sees after creating an account, or after sending a colleague a
 * replacement link (migration 058). It replaces `PasswordDisplayModal` on the
 * create path — that modal showed a live password and asked the admin to carry
 * it to their colleague by whatever means they had.
 *
 * The link is shown as well as mailed, on the same reasoning
 * `AdminInvitationDto` sets out: an installation whose mail is not configured
 * yet, or whose host silently drops outbound SMTP, would otherwise be left with
 * an account nobody can reach and nothing to hand over. It is strictly weaker
 * than the password it replaces — seven days, one use, and it sets a secret the
 * admin looking at this screen never learns.
 */

import { useTranslation } from 'react-i18next'
import { SecretDisplayModal } from './SecretDisplayModal'
import { useFormatters } from '../../hooks/useFormatters'

export interface InvitationSentModalProps {
  isOpen: boolean
  /** The absolute link, or null when there is nothing to show. */
  url: string | null
  /** The address it was mailed to. */
  email: string | null
  /** ISO timestamp after which the link stops working. */
  expiresAt: string | null
  onClose: () => void
}

export function InvitationSentModal({ isOpen, url, email, expiresAt, onClose }: InvitationSentModalProps) {
  const { t } = useTranslation()
  const { formatDateTime } = useFormatters()

  return (
    <SecretDisplayModal
      isOpen={isOpen}
      secret={url}
      title={t('settings.invitationSent')}
      warning={t('settings.invitationWarning', {
        email: email ?? '',
        expires: expiresAt ? formatDateTime(expiresAt) : '',
      })}
      testIdPrefix="settings-admin-invitation"
      onClose={onClose}
    />
  )
}
