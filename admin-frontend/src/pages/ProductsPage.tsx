/**
 * Products Page
 * Product catalog management
 *
 * Implements:
 * - List products with pagination
 * - Search/filter products
 * - Create new product via modal
 * - Display product details (name, price, category, status)
 *
 * Uses TDD with E2E tests in e2etests/tests/admin/products.spec.ts
 */

import { useEffect, useState } from 'react'
import { get, post } from '../services/api'

interface Product {
  id: string
  names: { [lang: string]: string }
  descriptions?: { [lang: string]: string }
  price_cents: number
  category_id: string
  is_active: boolean
  created_at: string
}

interface ApiResponse {
  data: Product[]
  pagination?: {
    page: number
    per_page: number
    total: number
    total_pages: number
  }
}

export function ProductsPage() {
  const [products, setProducts] = useState<Product[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [showModal, setShowModal] = useState(false)
  const [searchTerm, setSearchTerm] = useState('')
  const [formData, setFormData] = useState({ name: '', price: '' })
  const [formError, setFormError] = useState<string | null>(null)

  // Load products on mount
  useEffect(() => {
    loadProducts()
  }, [])

  async function loadProducts() {
    try {
      setLoading(true)
      setError(null)
      const response = await get<ApiResponse>('/admin/products', {
        params: {
          page: 1,
          per_page: 20,
          search: searchTerm || undefined,
        },
      })
      setProducts(response.data?.data || [])
    } catch (err: any) {
      setError(err.message || 'Failed to load products')
      setProducts([])
    } finally {
      setLoading(false)
    }
  }

  async function handleCreateProduct(e: React.FormEvent) {
    e.preventDefault()
    setFormError(null)

    if (!formData.name.trim()) {
      setFormError('Product name is required')
      return
    }

    if (!formData.price.trim()) {
      setFormError('Price is required')
      return
    }

    try {
      await post('/admin/products', {
        names: { de: formData.name },
        price_cents: Math.round(parseFloat(formData.price) * 100),
        category_id: '00000000-0000-0000-0000-000000000000',
      })
      setFormData({ name: '', price: '' })
      setShowModal(false)
      await loadProducts()
    } catch (err: any) {
      setFormError(err.message || 'Failed to create product')
    }
  }

  function handleSearch(value: string) {
    setSearchTerm(value)
    // Debounce search
    setTimeout(() => {
      loadProducts()
    }, 300)
  }

  if (loading && products.length === 0) {
    return (
      <div style={{ padding: '20px' }}>
        <div>Loading products...</div>
      </div>
    )
  }

  return (
    <div data-testid="products-page" style={{ padding: '20px' }}>
      <h1>Products</h1>

      <div style={{ marginBottom: '20px', display: 'flex', gap: '10px' }}>
        <input
          data-testid="products-search-input"
          type="text"
          placeholder="Search products..."
          value={searchTerm}
          onChange={(e) => handleSearch(e.target.value)}
          style={{
            flex: 1,
            padding: '8px',
            border: '1px solid #ccc',
            borderRadius: '4px',
          }}
        />
        <button
          data-testid="products-create-button"
          onClick={() => setShowModal(true)}
          style={{
            padding: '8px 16px',
            backgroundColor: '#3b82f6',
            color: 'white',
            border: 'none',
            borderRadius: '4px',
            cursor: 'pointer',
          }}
        >
          Create Product
        </button>
      </div>

      {error && (
        <div
          data-testid="products-error-message"
          style={{
            marginBottom: '10px',
            padding: '10px',
            backgroundColor: '#fee2e2',
            color: '#dc2626',
            borderRadius: '4px',
          }}
        >
          {error}
        </div>
      )}

      <div data-testid="products-table-wrapper" style={{ overflowX: 'auto' }}>
        <table
          data-testid="products-table"
          style={{
            width: '100%',
            borderCollapse: 'collapse',
            backgroundColor: '#1a2744',
            borderRadius: '4px',
            overflow: 'hidden',
          }}
        >
          <thead>
            <tr style={{ backgroundColor: '#0f1d32' }}>
              <th
                style={{
                  border: '1px solid #2d3748',
                  padding: '12px',
                  textAlign: 'left',
                  fontWeight: '600',
                  color: '#e2e8f0',
                }}
              >
                Name
              </th>
              <th
                style={{
                  border: '1px solid #2d3748',
                  padding: '12px',
                  textAlign: 'left',
                  fontWeight: '600',
                  color: '#e2e8f0',
                }}
              >
                Price
              </th>
              <th
                style={{
                  border: '1px solid #2d3748',
                  padding: '12px',
                  textAlign: 'left',
                  fontWeight: '600',
                  color: '#e2e8f0',
                }}
              >
                Category
              </th>
              <th
                style={{
                  border: '1px solid #2d3748',
                  padding: '12px',
                  textAlign: 'left',
                  fontWeight: '600',
                  color: '#e2e8f0',
                }}
              >
                Status
              </th>
            </tr>
          </thead>
          <tbody>
            {products.map((product) => (
              <tr
                key={product.id}
                data-testid={`products-table-row-${product.id}`}
                style={{
                  borderBottom: '1px solid #2d3748',
                }}
              >
                <td style={{ border: '1px solid #2d3748', padding: '12px', color: '#e2e8f0' }}>
                  <span data-testid={`products-table-cell-name-${product.id}`}>
                    {product.names.de || product.names.en || 'Unnamed Product'}
                  </span>
                </td>
                <td style={{ border: '1px solid #2d3748', padding: '12px', color: '#e2e8f0' }}>
                  <span data-testid={`products-table-cell-price-${product.id}`}>
                    €{(product.price_cents / 100).toFixed(2)}
                  </span>
                </td>
                <td style={{ border: '1px solid #2d3748', padding: '12px', color: '#e2e8f0' }}>
                  <span data-testid={`products-table-cell-category-${product.id}`}>
                    {product.category_id.substring(0, 8)}...
                  </span>
                </td>
                <td style={{ border: '1px solid #2d3748', padding: '12px', color: '#e2e8f0' }}>
                  <span
                    data-testid={`products-table-cell-status-${product.id}`}
                    style={{
                      padding: '4px 8px',
                      borderRadius: '4px',
                      backgroundColor: product.is_active ? '#dcfce7' : '#fee2e2',
                      color: product.is_active ? '#166534' : '#991b1b',
                      fontSize: '12px',
                    }}
                  >
                    {product.is_active ? '✓ Active' : '✗ Inactive'}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {products.length === 0 && !loading && (
        <div
          data-testid="products-empty-state"
          style={{
            textAlign: 'center',
            padding: '40px',
            color: '#94a3b8',
          }}
        >
          No products found
        </div>
      )}

      {showModal && (
        <div
          data-testid="products-form-modal"
          style={{
            position: 'fixed',
            top: 0,
            left: 0,
            right: 0,
            bottom: 0,
            backgroundColor: 'rgba(0, 0, 0, 0.5)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 1000,
          }}
          onClick={() => setShowModal(false)}
        >
          <div
            data-testid="products-form-modal-content"
            style={{
              backgroundColor: '#1a2744',
              padding: '24px',
              borderRadius: '8px',
              maxWidth: '500px',
              width: '90%',
              boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.5)',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            <h2 data-testid="products-form-title" style={{ marginTop: 0, marginBottom: '16px', color: '#e2e8f0' }}>Create Product</h2>

            {formError && (
              <div
                data-testid="products-form-error"
                style={{
                  marginBottom: '12px',
                  padding: '8px',
                  backgroundColor: '#fee2e2',
                  color: '#dc2626',
                  borderRadius: '4px',
                  fontSize: '14px',
                }}
              >
                {formError}
              </div>
            )}

            <form onSubmit={handleCreateProduct}>
              <div style={{ marginBottom: '16px' }}>
                <label
                  style={{
                    display: 'block',
                    marginBottom: '6px',
                    color: '#e2e8f0',
                    fontSize: '14px',
                    fontWeight: '500',
                  }}
                >
                  Product Name
                </label>
                <input
                  data-testid="products-form-name-input"
                  type="text"
                  placeholder="Product name"
                  value={formData.name}
                  onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                  style={{
                    width: '100%',
                    padding: '8px',
                    border: '1px solid #2d3748',
                    borderRadius: '4px',
                    backgroundColor: '#0d1829',
                    color: '#e2e8f0',
                    boxSizing: 'border-box',
                  }}
                  required
                />
              </div>

              <div style={{ marginBottom: '20px' }}>
                <label
                  style={{
                    display: 'block',
                    marginBottom: '6px',
                    color: '#e2e8f0',
                    fontSize: '14px',
                    fontWeight: '500',
                  }}
                >
                  Price (€)
                </label>
                <input
                  data-testid="products-form-price-input"
                  type="number"
                  step="0.01"
                  placeholder="10.50"
                  value={formData.price}
                  onChange={(e) => setFormData({ ...formData, price: e.target.value })}
                  style={{
                    width: '100%',
                    padding: '8px',
                    border: '1px solid #2d3748',
                    borderRadius: '4px',
                    backgroundColor: '#0d1829',
                    color: '#e2e8f0',
                    boxSizing: 'border-box',
                  }}
                  required
                />
              </div>

              <div style={{ display: 'flex', gap: '10px', justifyContent: 'flex-end' }}>
                <button
                  data-testid="products-form-cancel-button"
                  type="button"
                  onClick={() => setShowModal(false)}
                  style={{
                    padding: '8px 16px',
                    backgroundColor: '#2d3748',
                    color: '#e2e8f0',
                    border: 'none',
                    borderRadius: '4px',
                    cursor: 'pointer',
                  }}
                >
                  Cancel
                </button>
                <button
                  data-testid="products-form-submit-button"
                  type="submit"
                  style={{
                    padding: '8px 16px',
                    backgroundColor: '#3b82f6',
                    color: 'white',
                    border: 'none',
                    borderRadius: '4px',
                    cursor: 'pointer',
                  }}
                >
                  Create
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
