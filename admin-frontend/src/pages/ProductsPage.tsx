/**
 * Products Page
 * Product catalog management
 */

import React from 'react'
import { Card } from '../components/common/Card'
import { theme } from '../styles/design-system'

export function ProductsPage() {
  return (
    <div>
      <Card title="Products" subtitle="Manage product catalog">
        <div style={{ padding: theme.spacing.lg, color: theme.colors.text.secondary }}>
          <p>Products management page - Coming in Phase 2</p>
          <p style={{ fontSize: theme.typography.fontSize.sm }}>
            Features to implement:
            <ul>
              <li>Product list with categories</li>
              <li>Create new product</li>
              <li>Edit product details</li>
              <li>Toggle product activation</li>
              <li>Delete product</li>
            </ul>
          </p>
        </div>
      </Card>
    </div>
  )
}
