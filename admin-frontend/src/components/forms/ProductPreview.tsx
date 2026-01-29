/**
 * Product Preview Component
 * Shows how a product will appear in the terminal
 * Updates in real-time as name, price, and icon change
 */

import { getProductIcon } from '../icons/IconRegistry'

interface ProductPreviewProps {
  name: string
  price: string
  iconName: string | null
}

export function ProductPreview({ name, price, iconName }: ProductPreviewProps) {
  // Format price like terminal: "3,50 €"
  const formatPrice = (priceStr: string) => {
    if (!priceStr) return '0,00 €'
    const num = parseFloat(priceStr)
    if (isNaN(num)) return '0,00 €'
    return num.toFixed(2).replace('.', ',') + ' €'
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
        border: '1px solid rgba(71, 85, 105, 0.4)',
        borderRadius: '12px',
        minHeight: '140px',
        textAlign: 'center',
      }}
    >
      {/* Icon */}
      <div
        style={{
          fontSize: '32px',
          marginBottom: '12px',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          height: '40px',
        }}
      >
        <IconComponent size={32} color="#e2e8f0" />
      </div>

      {/* Product Name */}
      <div
        style={{
          fontSize: '13px',
          fontWeight: '500',
          color: '#e2e8f0',
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
          color: '#14b8a6',
          marginTop: '4px',
        }}
      >
        {displayPrice}
      </div>
    </div>
  )
}
