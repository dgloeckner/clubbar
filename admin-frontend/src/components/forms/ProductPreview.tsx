/**
 * Product Preview Component
 * Shows how a product will appear in the terminal
 * Updates in real-time as name, price, and icon change
 */

import { getProductIcon } from '../icons/IconRegistry'
import { parsePriceToCents } from '../../utils/price'
import { theme } from '../../styles/design-system'
import { tableColors } from '../../styles/tableTokens'

interface ProductPreviewProps {
  name: string
  price: string
  iconName: string | null
}

export function ProductPreview({ name, price, iconName }: ProductPreviewProps) {
  // Format price like terminal: "3,50 €"
  const formatPrice = (priceStr: string) => {
    const cents = parsePriceToCents(priceStr)
    if (cents === null) return '0,00 €'
    return (cents / 100).toFixed(2).replace('.', ',') + ' €'
  }

  // Get icon component
  const IconComponent = getProductIcon(iconName)
  const displayName = name.trim() || '(Product name)'
  const displayPrice = formatPrice(price)

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
