/**
 * PasswordDisplayModal Component
 * Modal for displaying a generated password (one-time view)
 */

import { useTranslation } from 'react-i18next'
import { SecretDisplayModal } from './SecretDisplayModal'

export interface PasswordDisplayModalProps {
  isOpen: boolean
  password: string | null
  onClose: () => void
}

export function PasswordDisplayModal({ isOpen, password, onClose }: PasswordDisplayModalProps) {
  const { t } = useTranslation()

  return (
    <SecretDisplayModal
      isOpen={isOpen}
      secret={password}
      title={t('settings.passwordGenerated')}
      warning={t('settings.passwordWarning')}
      testIdPrefix="settings-admin-password"
      onClose={onClose}
    />
  )
}
