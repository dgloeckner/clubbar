/**
 * Product Preview Component
 * Shows how a product will appear in the terminal
 * Updates in real-time as name, price, and icon change
 */

import { useTranslation } from 'react-i18next'
import { getProductIcon } from '../icons/IconRegistry'
import { parseMoneyToCents } from '../../utils/money'
import { useFormatters } from '../../hooks/useFormatters'
import { theme } from '../../styles/design-system'
import { tableColors } from '../../styles/tableTokens'

interface ProductPreviewProps {
  name: string
  price: string
  iconName: string | null
  /** Whole millilitres, or `null` when the product has no size (ADR-0056). */
  volumeMl?: number | null
}

export function ProductPreview({ name, price, iconName, volumeMl = null }: ProductPreviewProps) {
  const { t } = useTranslation()
  // The terminal shows a price in the *member's* language, so the preview of
  // it shows one in the admin's — through `Intl`, like every other amount in
  // the panel. It used to hardcode the German comma, which was right for the
  // default language and wrong for the other one.
  const { formatPrice, formatVolume } = useFormatters()

  const previewPrice = (priceStr: string) => {
    const cents = parseMoneyToCents(priceStr)
    return formatPrice(cents ?? 0)
  }

  // Get icon component
  const IconComponent = getProductIcon(iconName)
  const displayName = name.trim() || t('products.previewNamePlaceholder')
  const displayPrice = previewPrice(price)

  return (
    <div
      style={{
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '20px',
        backgroundColor: 'rgba(30, 41, 59, 0.8)',
        border: `1px solid ${theme.colors.border.slate}`,
        borderRadius: '12px',
        minHeight: '140px',
        textAlign: 'center',
      }}
    >
      {/* Icon */}
      <div
        style={{
          fontSize: '48px',
          marginBottom: '12px',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          height: '56px',
        }}
      >
        <IconComponent size={48} color={tableColors.cellText} />
      </div>

      {/* Product Name */}
      <div
        style={{
          fontSize: '13px',
          fontWeight: '500',
          color: tableColors.cellText,
          lineHeight: '1.2',
          marginBottom: '8px',
          maxWidth: '140px',
          wordBreak: 'break-word',
        }}
      >
        {displayName}
      </div>

      {/*
        The volume badge, drawn the way the terminal draws it: under a one-line
        name, in secondary text on a faint fill (ADR-0056).

        The row keeps its height when the product has no size. That is not
        cosmetic — on the terminal it is what holds every price on a grid row at
        the same height, and a preview that collapsed it would show an admin a
        tile the terminal will never draw.
      */}
      <div
        data-testid="products-preview-volume-row"
        style={{
          height: '20px',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          marginBottom: '4px',
        }}
      >
        {volumeMl != null && (
          <span
            data-testid="products-preview-volume"
            style={{
              padding: '1px 8px',
              borderRadius: '999px',
              backgroundColor: 'rgba(148, 163, 184, 0.16)',
              color: theme.colors.text.secondary,
              fontSize: '11px',
              fontWeight: 600,
              whiteSpace: 'nowrap',
            }}
          >
            {formatVolume(volumeMl)}
          </span>
        )}
      </div>

      {/* Price */}
      <div
        style={{
          fontSize: '16px',
          fontWeight: '700',
          fontFamily: 'JetBrains Mono, monospace',
          color: theme.colors.semantic.teal,
          marginTop: '4px',
        }}
      >
        {displayPrice}
      </div>
    </div>
  )
}
