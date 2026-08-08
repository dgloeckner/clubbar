/**
 * TokenDisplayModal Component
 * Modal for displaying a generated API token (one-time view)
 */

import { useTranslation } from 'react-i18next'
import { SecretDisplayModal } from './SecretDisplayModal'

export interface TokenDisplayModalProps {
  isOpen: boolean
  token: string | null
  onClose: () => void
}

export function TokenDisplayModal({ isOpen, token, onClose }: TokenDisplayModalProps) {
  const { t } = useTranslation()

  return (
    <SecretDisplayModal
      isOpen={isOpen}
      secret={token}
      title={t('settings.tokenGenerated')}
      warning={t('settings.tokenWarning')}
      testIdPrefix="settings-terminal-token"
      onClose={onClose}
    />
  )
}
