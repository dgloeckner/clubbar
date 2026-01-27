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
import { get, post, patch, del, onLoadingStateChange } from '../services/api'
import { EditIcon, TrashIcon } from '../components/icons'

interface Product {
  id: string
  names: { [lang: string]: string }
  descriptions?: { [lang: string]: string }
  price_cents: number
  category_id: string
  is_active: boolean
  created_at: string
  updated_at: string
}

interface ApiResponse {
  items: Product[]
  pagination?: {
    page: number
    per_page: number
    total: number
    total_pages: number
  }
}

interface Category {
  id: string
  names: { [lang: string]: string }
  is_active: boolean
  display_order: number
}

interface CategoriesResponse {
  categories: Category[]
}

export function ProductsPage() {
  const [products, setProducts] = useState<Product[]>([])
  const [categories, setCategories] = useState<Category[]>([])
  const [loading, setLoading] = useState(true)
  const [globalLoading, setGlobalLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [showModal, setShowModal] = useState(false)
  const [modalMode, setModalMode] = useState<'create' | 'edit'>('create')
  const [editingProduct, setEditingProduct] = useState<Product | null>(null)
  const [selectedCategory, setSelectedCategory] = useState<string>('')
  const [formData, setFormData] = useState({ name: '', price: '' })
  const [formError, setFormError] = useState<string | null>(null)
  const [confirmDialog, setConfirmDialog] = useState<{
    type: 'delete' | 'status'
    productId: string
    message: string
  } | null>(null)

  // Subscribe to global loading state on mount
  useEffect(() => {
    const unsubscribe = onLoadingStateChange((isLoading) => {
      setGlobalLoading(isLoading)
    })
    return () => unsubscribe()
  }, [])

  // Load products and categories on mount
  useEffect(() => {
    loadCategories()
    loadProducts()
  }, [])

  async function loadCategories() {
    try {
      const response = await get<CategoriesResponse>('/admin/categories')
      const categoriesArray = response.categories || []
      setCategories(categoriesArray)
    } catch (err: any) {
      // Silently fail - categories are optional
      setCategories([])
    }
  }

  async function loadProducts() {
    try {
      setLoading(true)
      setError(null)
      const response = await get<ApiResponse>('/admin/products', {
        params: {
          page: 1,
          per_page: 20,
        },
      })
      console.log('Products API response:', response)
      let items = response.items || []
      console.log(`Loaded ${items.length} products from API`)

      // Deduplicate products by ID (fix for duplicate rows issue)
      const seenIds = new Set<string>()
      const uniqueItems = items.filter(product => {
        if (seenIds.has(product.id)) {
          console.warn(`Duplicate product detected: ${product.id}`)
          return false
        }
        seenIds.add(product.id)
        return true
      })

      if (uniqueItems.length < items.length) {
        console.warn(`Filtered out ${items.length - uniqueItems.length} duplicate products`)
        items = uniqueItems
      }

      if (items.length > 0) {
        console.log('First product:', items[0])
        console.log('First product names:', items[0].names)
        console.log('First product category_id:', items[0].category_id)
        console.log('First 5 product names:', items.slice(0, 5).map(p => p.names?.de || 'NO NAME'))
      } else {
        console.log('No products returned from API')
      }

      setProducts(items)
    } catch (err: any) {
      setError(err.message || 'Failed to load products')
      setProducts([])
    } finally {
      setLoading(false)
    }
  }

  function openEditModal(product: Product) {
    setModalMode('edit')
    setEditingProduct(product)
    setFormData({
      name: product.names.de || product.names.en || '',
      price: (product.price_cents / 100).toFixed(2),
    })
    setSelectedCategory(product.category_id)
    setFormError(null)
    setShowModal(true)
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

    if (!selectedCategory) {
      setFormError('Category is required')
      return
    }

    try {
      const priceCents = Math.round(parseFloat(formData.price) * 100)
      const productData = {
        names: { de: formData.name.trim() },
        price_cents: priceCents,
        category_id: selectedCategory,
      }
      console.log('Creating product with:', productData)

      const response = await post('/admin/products', productData)
      console.log('Product created successfully:', response)

      setFormData({ name: '', price: '' })
      setSelectedCategory('')
      setModalMode('create')
      setEditingProduct(null)
      setShowModal(false)
      console.log('Form state reset, reloading products...')

      // Reload product list to show newly created product
      await loadProducts()
      console.log('Products reloaded after creation')
    } catch (err: any) {
      console.error('Product creation error:', err)
      const errorMsg = err.response?.data?.message || err.response?.data?.error || err.message || 'Failed to create product'
      setFormError(errorMsg)
    }
  }

  async function handleUpdateProduct(e: React.FormEvent) {
    e.preventDefault()
    setFormError(null)

    if (!formData.name.trim()) {
      setFormError('Product name is required')
      return
    }

    if (!formData.price || parseFloat(formData.price) <= 0) {
      setFormError('Valid price is required')
      return
    }

    if (!selectedCategory) {
      setFormError('Category is required')
      return
    }

    try {
      const priceCents = Math.round(parseFloat(formData.price) * 100)

      await patch(`/admin/products/${editingProduct!.id}`, {
        names: { de: formData.name.trim() },
        price_cents: priceCents,
        category_id: selectedCategory,
      })

      setFormData({ name: '', price: '' })
      setSelectedCategory('')
      setEditingProduct(null)
      setModalMode('create')
      setShowModal(false)
      // Reload product list to reflect updated product
      await loadProducts()
    } catch (err: any) {
      console.error('Product update error:', err)
      const errorMsg = err.response?.data?.message || err.response?.data?.error || err.message || 'Failed to update product'
      setFormError(errorMsg)
    }
  }

  async function handleDelete(product: Product) {
    setConfirmDialog({
      type: 'delete',
      productId: product.id,
      message: `Delete "${product.names.de || product.names.en || 'Product'}"? This will permanently remove the product from the database and cannot be undone.`,
    })
  }

  async function handleStatusToggle(product: Product) {
    if (product.is_active) {
      // Deactivating requires confirmation
      setConfirmDialog({
        type: 'status',
        productId: product.id,
        message: `Deactivate "${product.names.de || product.names.en || 'Product'}"? This will hide it from the terminal.`,
      })
    } else {
      // Activating is immediate (no confirmation)
      try {
        await patch(`/admin/products/${product.id}/status`, {
          is_active: true,
        })
        // Reload product list to reflect activated product
        await loadProducts()
      } catch (err: any) {
        setError(err.message || 'Failed to activate product')
      }
    }
  }

  async function confirmAction() {
    if (!confirmDialog) return

    try {
      if (confirmDialog.type === 'delete') {
        // Delete product (soft delete via DELETE endpoint)
        await del(`/admin/products/${confirmDialog.productId}`)
      } else if (confirmDialog.type === 'status') {
        // Deactivate product (toggle status via status endpoint)
        await patch(`/admin/products/${confirmDialog.productId}/status`, {
          is_active: false,
        })
      }

      setConfirmDialog(null)
      // Reload product list to reflect deleted/deactivated product
      await loadProducts()
    } catch (err: any) {
      setError(err.message || 'Failed to perform action')
      setConfirmDialog(null)
    }
  }

  function cancelConfirmation() {
    setConfirmDialog(null)
  }

  async function handleFormSubmit(e: React.FormEvent) {
    if (modalMode === 'create') {
      await handleCreateProduct(e)
    } else {
      await handleUpdateProduct(e)
    }
  }

  function handleCancel() {
    setShowModal(false)
    setFormData({ name: '', price: '' })
    setSelectedCategory('')
    setFormError(null)
    setEditingProduct(null)
    setModalMode('create')
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
      {/* Global Loading Indicator */}
      {globalLoading && (
        <div
          data-testid="products-global-loading"
          style={{
            position: 'fixed',
            top: 0,
            left: 0,
            right: 0,
            height: '4px',
            backgroundColor: '#3b82f6',
            animation: 'none',
            zIndex: 9999,
          }}
        />
      )}

      <h1>Products</h1>

      <div style={{ marginBottom: '20px', display: 'flex', gap: '10px', justifyContent: 'flex-end' }}>
        <button
          data-testid="products-create-button"
          onClick={() => {
            setModalMode('create')
            setEditingProduct(null)
            setFormData({ name: '', price: '' })
            setSelectedCategory('')
            setFormError(null)
            setShowModal(true)
          }}
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
                Status
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
                ID
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
                Actions
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
                <td style={{ border: '1px solid #2d3748', padding: '12px' }}>
                  <button
                    data-testid={`products-status-toggle-${product.id}`}
                    onClick={() => handleStatusToggle(product)}
                    style={{
                      padding: '4px 8px',
                      borderRadius: '4px',
                      backgroundColor: product.is_active ? '#dcfce7' : '#fee2e2',
                      color: product.is_active ? '#166534' : '#991b1b',
                      border: 'none',
                      fontSize: '12px',
                      fontWeight: '500',
                      cursor: 'pointer',
                      transition: 'all 150ms',
                    }}
                  >
                    <span data-testid={`products-table-cell-status-${product.id}`}>
                      {product.is_active ? '✓ Active' : '✗ Inactive'}
                    </span>
                  </button>
                </td>
                <td style={{ border: '1px solid #2d3748', padding: '12px', color: '#e2e8f0', fontFamily: 'monospace', fontSize: '11px' }}>
                  <span data-testid={`products-table-cell-id-${product.id}`}>
                    {product.id}
                  </span>
                </td>
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
                    {(() => {
                      const category = categories.find((c) => c.id === product.category_id)
                      return category ? category.names.de || category.names.en || 'Unknown' : 'Unknown'
                    })()}
                  </span>
                </td>
                <td
                  data-testid={`products-table-cell-actions-${product.id}`}
                  style={{
                    border: '1px solid #2d3748',
                    padding: '12px',
                    display: 'flex',
                    gap: '8px',
                  }}
                >
                  <button
                    data-testid={`products-edit-button-${product.id}`}
                    onClick={() => openEditModal(product)}
                    style={{
                      padding: '4px 8px',
                      backgroundColor: 'rgba(59, 130, 246, 0.1)',
                      border: '1px solid rgba(59, 130, 246, 0.3)',
                      borderRadius: '4px',
                      color: '#3b82f6',
                      cursor: 'pointer',
                      display: 'flex',
                      alignItems: 'center',
                      gap: '4px',
                      fontSize: '12px',
                      transition: 'all 150ms',
                    }}
                  >
                    <EditIcon size={14} />
                    Edit
                  </button>

                  <button
                    data-testid={`products-delete-button-${product.id}`}
                    onClick={() => handleDelete(product)}
                    style={{
                      padding: '4px 8px',
                      backgroundColor: 'rgba(239, 68, 68, 0.1)',
                      border: '1px solid rgba(239, 68, 68, 0.3)',
                      borderRadius: '4px',
                      color: '#ef4444',
                      cursor: 'pointer',
                      display: 'flex',
                      alignItems: 'center',
                      gap: '4px',
                      fontSize: '12px',
                      transition: 'all 150ms',
                    }}
                  >
                    <TrashIcon size={14} />
                    Delete
                  </button>
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
          onClick={handleCancel}
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
            <h2 data-testid="products-form-title" style={{ marginTop: 0, marginBottom: '16px', color: '#e2e8f0' }}>
              {modalMode === 'create' ? 'Create Product' : 'Edit Product'}
            </h2>

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

            <form onSubmit={handleFormSubmit}>
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
                  Category *
                </label>
                <select
                  data-testid="products-form-category-select"
                  value={selectedCategory}
                  onChange={(e) => setSelectedCategory(e.target.value)}
                  style={{
                    width: '100%',
                    padding: '8px',
                    border: '1px solid #2d3748',
                    borderRadius: '4px',
                    backgroundColor: '#0d1829',
                    color: '#e2e8f0',
                    boxSizing: 'border-box',
                    cursor: 'pointer',
                  }}
                  required
                >
                  <option value="">Select a category...</option>
                  {categories.map((cat) => (
                    <option key={cat.id} value={cat.id}>
                      {cat.names.de || cat.names.en || 'Unnamed'}
                    </option>
                  ))}
                </select>
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
                  onClick={handleCancel}
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
                  {modalMode === 'create' ? 'Create Product' : 'Save Changes'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Confirmation Dialog */}
      {confirmDialog && (
        <div
          data-testid="products-confirm-dialog"
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
            zIndex: 2000,
          }}
        >
          <div
            data-testid="products-confirm-dialog-content"
            style={{
              backgroundColor: '#1a2744',
              padding: '24px',
              borderRadius: '8px',
              maxWidth: '500px',
              width: '90%',
            }}
          >
            <div style={{ display: 'flex', gap: '12px', marginBottom: '20px', alignItems: 'flex-start' }}>
              {confirmDialog.type === 'delete' && (
                <div
                  style={{
                    fontSize: '24px',
                    color: '#fbbf24',
                    flexShrink: 0,
                  }}
                >
                  ⚠️
                </div>
              )}
              <p
                data-testid="products-confirm-message"
                style={{
                  color: '#e2e8f0',
                  marginBottom: '0',
                  fontSize: '16px',
                  margin: '0',
                }}
              >
                {confirmDialog.message}
              </p>
            </div>

            <div style={{ display: 'flex', gap: '12px', justifyContent: 'flex-end' }}>
              <button
                data-testid="products-confirm-cancel"
                onClick={cancelConfirmation}
                style={{
                  padding: '8px 16px',
                  backgroundColor: 'rgba(107, 114, 128, 0.1)',
                  border: '1px solid rgba(107, 114, 128, 0.3)',
                  borderRadius: '4px',
                  color: '#9ca3af',
                  cursor: 'pointer',
                }}
              >
                Cancel
              </button>

              <button
                data-testid="products-confirm-ok"
                onClick={confirmAction}
                style={{
                  padding: '8px 16px',
                  backgroundColor: 'rgba(239, 68, 68, 0.1)',
                  border: '1px solid rgba(239, 68, 68, 0.3)',
                  borderRadius: '4px',
                  color: '#ef4444',
                  cursor: 'pointer',
                }}
              >
                {confirmDialog.type === 'delete' ? 'Delete' : 'Deactivate'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
