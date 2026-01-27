/**
 * Categories Page
 * Category management (CRUD operations)
 *
 * Implements:
 * - List categories with status and product count
 * - Create new category via modal with language tabs
 * - Edit category translations
 * - Activate/Deactivate category with confirmation
 * - Delete category (validation for non-empty categories)
 * - Reorder categories via drag & drop
 *
 * Uses TDD with E2E tests in e2etests/tests/admin/categories.spec.ts
 */

import { useEffect, useState } from 'react'
import { get, post, patch, del } from '../services/api'
import { theme } from '../styles/design-system'
import { EditIcon, TrashIcon, PlusIcon } from '../components/icons'

interface Category {
  id: string
  names: { [lang: string]: string }
  display_order: number
  is_active: boolean
  product_count: number
  created_at: string
}

interface ApiResponse {
  data: Category[]
  pagination?: {
    page: number
    per_page: number
    total: number
    total_pages: number
  }
}

export function CategoriesPage() {
  const [categories, setCategories] = useState<Category[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [showModal, setShowModal] = useState(false)
  const [modalMode, setModalMode] = useState<'create' | 'edit'>('create')
  const [selectedCategory, setSelectedCategory] = useState<Category | null>(null)
  const [activeLanguage, setActiveLanguage] = useState('de')
  const [formData, setFormData] = useState<{ [lang: string]: string }>({ de: '', en: '' })
  const [formError, setFormError] = useState<string | null>(null)
  const [confirmDialog, setConfirmDialog] = useState<{
    type: 'delete' | 'status'
    categoryId: string
    message: string
  } | null>(null)

  // Load categories on mount
  useEffect(() => {
    loadCategories()
  }, [])

  async function loadCategories() {
    try {
      setLoading(true)
      setError(null)
      // API returns { categories: [...] } directly (not wrapped in ApiResponse)
      const response = await get<any>('/admin/categories')
      // response IS the direct API response { categories: [...] }
      const categoriesArray = response.categories
      if (Array.isArray(categoriesArray)) {
        setCategories(categoriesArray)
      } else {
        setCategories([])
      }
    } catch (err: any) {
      setError(err.message || 'Failed to load categories')
      setCategories([])
    } finally {
      setLoading(false)
    }
  }

  function openCreateModal() {
    setModalMode('create')
    setSelectedCategory(null)
    setFormData({ de: '', en: '' })
    setActiveLanguage('de')
    setFormError(null)
    setShowModal(true)
  }

  function openEditModal(category: Category) {
    setModalMode('edit')
    setSelectedCategory(category)
    setFormData(category.names)
    setActiveLanguage('de')
    setFormError(null)
    setShowModal(true)
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setFormError(null)

    // Validation: at least one language name required
    const hasAtLeastOneName = Object.values(formData).some((name) => name.trim())
    if (!hasAtLeastOneName) {
      setFormError('At least one language name is required')
      return
    }

    try {
      // Filter out empty language names - backend requires all values to be non-empty
      const nonEmptyNames = Object.entries(formData)
        .filter(([, name]) => name.trim())
        .reduce((acc, [lang, name]) => ({ ...acc, [lang]: name }), {})

      if (modalMode === 'create') {
        const result = await post('/admin/categories', {
          names: nonEmptyNames,
        })
        console.log('Category created:', result)
      } else if (modalMode === 'edit' && selectedCategory) {
        const result = await patch(`/admin/categories/${selectedCategory.id}`, {
          names: nonEmptyNames,
        })
        console.log('Category updated:', result)
      }

      // Close modal immediately
      setShowModal(false)
      setFormData({ de: '', en: '' })

      // Then reload categories
      await loadCategories()
    } catch (err: any) {
      console.error(`Error ${modalMode} category:`, err)
      setFormError(err.message || `Failed to ${modalMode} category`)
    }
  }

  async function handleStatusToggle(category: Category) {
    setConfirmDialog({
      type: 'status',
      categoryId: category.id,
      message: `${category.is_active ? 'Deactivate' : 'Activate'} "${Object.values(category.names)[0] || 'Category'}"?`,
    })
  }

  async function handleDelete(category: Category) {
    if (category.product_count > 0) {
      setError(`Cannot delete category with ${category.product_count} products. Deactivate instead.`)
      return
    }

    setConfirmDialog({
      type: 'delete',
      categoryId: category.id,
      message: `Delete "${Object.values(category.names)[0] || 'Category'}"? This cannot be undone.`,
    })
  }

  async function confirmAction() {
    if (!confirmDialog) return

    try {
      if (confirmDialog.type === 'delete') {
        await del(`/admin/categories/${confirmDialog.categoryId}`)
      } else if (confirmDialog.type === 'status') {
        const category = categories.find((c) => c.id === confirmDialog.categoryId)
        if (category) {
          await patch(`/admin/categories/${confirmDialog.categoryId}/status`, {
            is_active: !category.is_active,
          })
        }
      }

      setConfirmDialog(null)
      await loadCategories()
    } catch (err: any) {
      setError(err.message || 'Failed to perform action')
      setConfirmDialog(null)
    }
  }

  if (loading && categories.length === 0) {
    return (
      <div style={{ padding: '20px' }}>
        <div>Loading categories...</div>
      </div>
    )
  }

  return (
    <div data-testid="categories-page" style={{ padding: '20px' }}>
      <div style={{ marginBottom: '24px' }}>
        <h1 style={{ margin: 0, marginBottom: '8px' }}>Categories</h1>
        <p style={{ margin: 0, color: theme.colors.text.secondary }}>Manage product categories</p>
      </div>

      {error && (
        <div
          data-testid="categories-error-message"
          style={{
            marginBottom: '20px',
            padding: '12px',
            backgroundColor: '#fee2e2',
            color: '#dc2626',
            borderRadius: '4px',
            fontSize: '14px',
          }}
        >
          {error}
        </div>
      )}

      <button
        data-testid="categories-create-button"
        onClick={openCreateModal}
        style={{
          marginBottom: '20px',
          padding: '10px 16px',
          backgroundColor: theme.colors.semantic.primary,
          color: 'white',
          border: 'none',
          borderRadius: '4px',
          cursor: 'pointer',
          display: 'flex',
          alignItems: 'center',
          gap: '8px',
          fontSize: '14px',
          fontWeight: '500',
        }}
      >
        <PlusIcon size={18} />
        Create Category
      </button>

      {/* Categories Table */}
      <div data-testid="categories-table-wrapper" style={{ overflowX: 'auto' }}>
        <table
          data-testid="categories-table"
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
                role="columnheader"
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
                role="columnheader"
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
                role="columnheader"
                style={{
                  border: '1px solid #2d3748',
                  padding: '12px',
                  textAlign: 'left',
                  fontWeight: '600',
                  color: '#e2e8f0',
                }}
              >
                Products
              </th>
              <th
                role="columnheader"
                style={{
                  border: '1px solid #2d3748',
                  padding: '12px',
                  textAlign: 'left',
                  fontWeight: '600',
                  color: '#e2e8f0',
                }}
              >
                Order
              </th>
              <th
                role="columnheader"
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
            {categories.map((category) => (
              <tr
                key={category.id}
                data-testid={`categories-table-row-${category.id}`}
                style={{
                  borderBottom: '1px solid #2d3748',
                }}
              >
                <td style={{ border: '1px solid #2d3748', padding: '12px', color: '#e2e8f0' }}>
                  <button
                    data-testid={`categories-status-toggle-${category.id}`}
                    onClick={() => handleStatusToggle(category)}
                    style={{
                      padding: '4px 8px',
                      borderRadius: '4px',
                      backgroundColor: category.is_active ? '#dcfce7' : '#fee2e2',
                      color: category.is_active ? '#166534' : '#991b1b',
                      border: 'none',
                      fontSize: '12px',
                      fontWeight: '500',
                      cursor: 'pointer',
                      transition: 'all 150ms',
                    }}
                  >
                    <span data-testid={`categories-table-cell-status-${category.id}`}>
                      {category.is_active ? '✓ Active' : '✗ Inactive'}
                    </span>
                  </button>
                </td>
                <td style={{ border: '1px solid #2d3748', padding: '12px', color: '#e2e8f0' }}>
                  <span data-testid={`categories-table-cell-name-${category.id}`}>
                    {category.names.de || category.names.en || 'Unnamed'}
                  </span>
                </td>
                <td style={{ border: '1px solid #2d3748', padding: '12px', color: '#e2e8f0' }}>
                  <span data-testid={`categories-table-cell-product-count-${category.id}`}>
                    {category.product_count}
                  </span>
                </td>
                <td style={{ border: '1px solid #2d3748', padding: '12px', color: '#e2e8f0' }}>
                  <span data-testid={`categories-table-cell-order-${category.id}`}>
                    {category.display_order}
                  </span>
                </td>
                <td style={{ border: '1px solid #2d3748', padding: '12px', color: '#e2e8f0' }}>
                  <div style={{ display: 'flex', gap: '8px' }}>
                    <button
                      data-testid={`categories-edit-button-${category.id}`}
                      onClick={() => openEditModal(category)}
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
                      data-testid={`categories-delete-button-${category.id}`}
                      onClick={() => handleDelete(category)}
                      disabled={category.product_count > 0}
                      style={{
                        padding: '4px 8px',
                        backgroundColor: category.product_count > 0 ? 'rgba(107, 114, 128, 0.1)' : 'rgba(239, 68, 68, 0.1)',
                        border: category.product_count > 0
                          ? '1px solid rgba(107, 114, 128, 0.3)'
                          : '1px solid rgba(239, 68, 68, 0.3)',
                        borderRadius: '4px',
                        color: category.product_count > 0 ? '#6b7280' : '#ef4444',
                        cursor: category.product_count > 0 ? 'not-allowed' : 'pointer',
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
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {categories.length === 0 && !loading && (
        <div
          data-testid="categories-empty-state"
          style={{
            textAlign: 'center',
            padding: '40px',
            color: '#94a3b8',
          }}
        >
          No categories found. Create one to get started.
        </div>
      )}

      {/* Create/Edit Modal */}
      {showModal && (
        <div
          data-testid="categories-form-modal"
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
            data-testid="categories-form-modal-content"
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
            <h2 data-testid="categories-form-title" style={{ marginTop: 0, marginBottom: '16px', color: '#e2e8f0' }}>
              {modalMode === 'create' ? 'Create Category' : 'Edit Category'}
            </h2>

            {formError && (
              <div
                data-testid="categories-form-error"
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

            {/* Language Tabs */}
            <div style={{ marginBottom: '16px' }}>
              <div style={{ display: 'flex', gap: '8px', borderBottom: '1px solid #2d3748' }}>
                {['de', 'en'].map((lang) => (
                  <button
                    key={lang}
                    data-testid={`categories-form-lang-tab-${lang}`}
                    onClick={() => setActiveLanguage(lang)}
                    style={{
                      padding: '8px 12px',
                      border: 'none',
                      background: activeLanguage === lang ? 'transparent' : 'transparent',
                      color: activeLanguage === lang ? '#3b82f6' : '#94a3b8',
                      borderBottom: activeLanguage === lang ? '2px solid #3b82f6' : 'none',
                      cursor: 'pointer',
                      fontSize: '14px',
                      fontWeight: activeLanguage === lang ? '600' : '400',
                      transition: 'all 150ms',
                    }}
                  >
                    {lang.toUpperCase()}
                  </button>
                ))}
              </div>
            </div>

            {/* Language Content */}
            {['de', 'en'].map((lang) => (
              <div
                key={lang}
                data-testid={`categories-form-lang-content-${lang}`}
                style={{ display: activeLanguage === lang ? 'block' : 'none' }}
              >
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
                    Category Name ({lang.toUpperCase()})
                  </label>
                  <input
                    data-testid={`categories-form-name-input-${lang}`}
                    type="text"
                    placeholder={`Enter category name in ${lang === 'de' ? 'German' : 'English'}`}
                    value={formData[lang] || ''}
                    onChange={(e) => setFormData({ ...formData, [lang]: e.target.value })}
                    style={{
                      width: '100%',
                      padding: '8px',
                      border: '1px solid #2d3748',
                      borderRadius: '4px',
                      backgroundColor: '#0d1829',
                      color: '#e2e8f0',
                      boxSizing: 'border-box',
                      fontSize: '14px',
                    }}
                  />
                </div>
              </div>
            ))}

            {/* Buttons */}
            <div style={{ display: 'flex', gap: '10px', justifyContent: 'flex-end' }}>
              <button
                data-testid="categories-form-cancel-button"
                type="button"
                onClick={() => setShowModal(false)}
                style={{
                  padding: '8px 16px',
                  backgroundColor: '#2d3748',
                  color: '#e2e8f0',
                  border: 'none',
                  borderRadius: '4px',
                  cursor: 'pointer',
                  fontSize: '14px',
                  fontWeight: '500',
                  transition: 'all 150ms',
                }}
              >
                Cancel
              </button>
              <button
                data-testid="categories-form-submit-button"
                type="submit"
                onClick={handleSubmit}
                style={{
                  padding: '8px 16px',
                  backgroundColor: '#3b82f6',
                  color: 'white',
                  border: 'none',
                  borderRadius: '4px',
                  cursor: 'pointer',
                  fontSize: '14px',
                  fontWeight: '500',
                  transition: 'all 150ms',
                }}
              >
                {modalMode === 'create' ? 'Create' : 'Save'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Confirmation Dialog */}
      {confirmDialog && (
        <div
          data-testid="categories-confirm-dialog"
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
            zIndex: 1001,
          }}
          onClick={() => setConfirmDialog(null)}
        >
          <div
            style={{
              backgroundColor: '#1a2744',
              padding: '24px',
              borderRadius: '8px',
              maxWidth: '400px',
              width: '90%',
              boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.5)',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            <h3 style={{ marginTop: 0, marginBottom: '16px', color: '#e2e8f0' }}>Confirm Action</h3>
            <p
              data-testid="categories-confirm-message"
              style={{
                marginTop: 0,
                marginBottom: '20px',
                color: '#cbd5e1',
                fontSize: '14px',
              }}
            >
              {confirmDialog.message}
            </p>
            <div style={{ display: 'flex', gap: '10px', justifyContent: 'flex-end' }}>
              <button
                data-testid="categories-confirm-cancel"
                onClick={() => setConfirmDialog(null)}
                style={{
                  padding: '8px 16px',
                  backgroundColor: '#2d3748',
                  color: '#e2e8f0',
                  border: 'none',
                  borderRadius: '4px',
                  cursor: 'pointer',
                  fontSize: '14px',
                  fontWeight: '500',
                }}
              >
                Cancel
              </button>
              <button
                data-testid="categories-confirm-ok"
                onClick={confirmAction}
                style={{
                  padding: '8px 16px',
                  backgroundColor: confirmDialog.type === 'delete' ? '#ef4444' : '#3b82f6',
                  color: 'white',
                  border: 'none',
                  borderRadius: '4px',
                  cursor: 'pointer',
                  fontSize: '14px',
                  fontWeight: '500',
                }}
              >
                {confirmDialog.type === 'delete' ? 'Delete' : 'Confirm'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
