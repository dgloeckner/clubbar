import React from 'react'
import { theme } from '../../styles/design-system'
import { IconProps } from '../icons'

interface StatCardProps {
  icon: React.ReactElement<IconProps>
  label: string
  value: string | number
  color?: 'blue' | 'green' | 'orange' | 'red'
}

export function StatCard({ icon, label, value, color = 'blue' }: StatCardProps) {
  const colorMap = {
    blue: theme.colors.semantic.primary,
    green: theme.colors.semantic.success,
    orange: theme.colors.semantic.warning,
    red: theme.colors.semantic.danger,
  }

  const bgColorMap = {
    blue: 'rgba(59, 130, 246, 0.15)',
    green: 'rgba(34, 197, 94, 0.15)',
    orange: 'rgba(249, 115, 22, 0.15)',
    red: 'rgba(239, 68, 68, 0.15)',
  }

  const selectedColor = colorMap[color]
  const selectedBgColor = bgColorMap[color]

  return (
    <div
      style={{
        background: theme.colors.bg.card,
        border: `1px solid ${theme.colors.border.light}`,
        borderRadius: theme.borderRadius.lg,
        padding: theme.spacing.xl,
        display: 'flex',
        alignItems: 'center',
        gap: theme.spacing.lg,
      }}
    >
      <div
        style={{
          width: '52px',
          height: '52px',
          borderRadius: '12px',
          background: selectedBgColor,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          color: selectedColor,
          flexShrink: 0,
        }}
      >
        {React.cloneElement(icon, { size: 24 })}
      </div>
      <div>
        <div
          style={{
            fontSize: theme.typography.fontSize.sm,
            color: theme.colors.text.secondary,
            marginBottom: '4px',
          }}
        >
          {label}
        </div>
        <div
          style={{
            fontSize: '28px',
            fontWeight: theme.typography.fontWeight.bold,
            color: theme.colors.text.primary,
          }}
        >
          {value}
        </div>
      </div>
    </div>
  )
}
