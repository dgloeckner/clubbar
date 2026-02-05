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
 * Follows /admin-frontend/patterns/table-implementation.md
 */

import { useEffect, useState } from 'react'
import { get, post, patch, del } from '../services/api'
import { theme } from '../styles/design-system'
import { EditIcon, TrashIcon, PlusIcon } from '../components/icons'
import { IconSelect } from '../components/forms/IconSelect'
import { StatusFilterPills } from '../components/forms/StatusFilterPills'
import { getCategoryIcon } from '../components/icons/IconRegistry'
import { IconCell } from '../components/tables/IconCell'
import { StatusToggleCell } from '../components/tables/StatusToggleCell'
import { SortableTableHeader } from '../components/tables/SortableTableHeader'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import {
  tableWrapperStyles,
  tableElementStyles,
  headerRowStyle,
  headerCellBaseStyle,
  tableColors,
  tableSpacing,
  getRowStyle,
} from '../styles/tableTokens'

interface Category {
  id: string
  names: { [lang: string]: string }
  display_order: number
  is_active: boolean
  product_count: number
  icon_name?: string | null
  created_at: string
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
  const [selectedIcon, setSelectedIcon] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [confirmDialog, setConfirmDialog] = useState<{
    type: 'delete' | 'status'
    categoryId: string
    message: string
  } | null>(null)

  // Filtering & sorting
  const [filterStatus, setFilterStatus] = useState<'all' | 'active' | 'inactive'>('all')
  const [sortKey, setSortKey] = useState<'name' | 'created_at'>('created_at')
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('desc')

  // Pagination
  const [page, setPage] = useState(1)
  const pageSize = 20
  const [totalItems, setTotalItems] = useState(0)

  // Load categories on mount and when filter/sort changes
  useEffect(() => {
    loadCategories()
  }, [filterStatus, sortKey, sortDirection, page])

  async function loadCategories() {
    try {
      setLoading(true)
      setError(null)
      // API returns { categories: [...] } directly (not wrapped in ApiResponse)
      const response = await get<any>('/admin/categories')
      // response IS the direct API response { categories: [...] }
      let categoriesArray = (response as any).categories || []
      if (Array.isArray(categoriesArray)) {
        // Apply filtering
        if (filterStatus === 'active') {
          categoriesArray = categoriesArray.filter((c) => c.is_active)
        } else if (filterStatus === 'inactive') {
          categoriesArray = categoriesArray.filter((c) => !c.is_active)
        }

        // Apply sorting
        categoriesArray.sort((a: Category, b: Category) => {
          let aVal: any = sortKey === 'name' ? a.names.de || a.names.en || '' : a.created_at
          let bVal: any = sortKey === 'name' ? b.names.de || b.names.en || '' : b.created_at

          if (typeof aVal === 'string') {
            aVal = aVal.toLowerCase()
            bVal = (bVal as string).toLowerCase()
          }

          if (aVal < bVal) return sortDirection === 'asc' ? -1 : 1
          if (aVal > bVal) return sortDirection === 'asc' ? 1 : -1
          return 0
        })

        // Update total items count
        setTotalItems(categoriesArray.length)

        // Apply pagination (client-side)
        const startIdx = (page - 1) * pageSize
        const endIdx = startIdx + pageSize
        const paginatedArray = categoriesArray.slice(startIdx, endIdx)

        setCategories(paginatedArray)
      } else {
        setCategories([])
        setTotalItems(0)
      }
    } catch (err: any) {
      setError(err.message || 'Failed to load categories')
      setCategories([])
      setTotalItems(0)
    } finally {
      setLoading(false)
    }
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
          icon_name: selectedIcon,
        })
        console.log('Category created:', result)
      } else if (modalMode === 'edit' && selectedCategory) {
        const result = await patch(`/admin/categories/${selectedCategory.id}`, {
          names: nonEmptyNames,
          icon_name: selectedIcon,
        })
        console.log('Category updated:', result)
      }

      // Close modal immediately
      setShowModal(false)
      setFormData({ de: '', en: '' })
      setSelectedIcon(null)

      // Then reload categories
      await loadCategories()
    } catch (err: any) {
      console.error(`Error ${modalMode} category:`, err)
      setFormError(err.message || `Failed to ${modalMode} category`)
    }
  }

  async function handleStatusToggle(category: Category) {
    const categoryName = Object.values(category.names)[0] || 'Category'
    const message = category.is_active
      ? `Deactivate "${categoryName}"? All ${category.product_count} products in this category will no longer be visible in the terminal.`
      : `Activate "${categoryName}"? All ${category.product_count} products in this category will become visible in the terminal again.`

    setConfirmDialog({
      type: 'status',
      categoryId: category.id,
      message,
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
      message: `Delete "${Object.values(category.names)[0] || 'Category'}"? This will permanently remove the category from the database and cannot be undone.`,
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

  return (
    <div data-testid="categories-page" style={{ padding: '20px' }}>
      <h1 style={{ margin: '0 0 20px 0' }}>Categories</h1>

      {error && (
        <div
          data-testid="categories-error-message"
          style={{
            padding: theme.spacing.lg,
            background: `${theme.colors.semantic.danger}20`,
            borderBottom: `1px solid ${theme.colors.semantic.danger}`,
            color: theme.colors.semantic.danger,
            marginBottom: theme.spacing.lg,
            borderRadius: theme.borderRadius.md,
          }}
        >
          {error}
        </div>
      )}

      {/* Search/Filter toolbar */}
      <div
        style={{
          display: 'flex',
          gap: theme.spacing.md,
          padding: `${theme.spacing.md} ${theme.spacing.lg}`,
          borderBottom: `1px solid ${tableColors.rowActiveBorder}`,
          alignItems: 'center',
          justifyContent: 'space-between',
        }}
      >
        {/* LEFT: Count summary */}
        <span data-testid="categories-count-summary" style={{ color: theme.colors.text.secondary, fontSize: '14px', whiteSpace: 'nowrap' }}>
          <strong style={{ color: theme.colors.text.primary }}>{totalItems}</strong> Categories gefunden
        </span>

        {/* RIGHT: Filter + Create button */}
        <div style={{ display: 'flex', gap: theme.spacing.md, alignItems: 'center' }}>
          <StatusFilterPills
            value={filterStatus}
            onChange={(status) => {
              setFilterStatus(status)
              setPage(1) // Reset to first page when filtering
            }}
            testId="categories-filter-status"
          />

          <button
            data-testid="categories-create-button"
            onClick={() => {
              setSelectedCategory(null)
              setShowModal(true)
            }}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: theme.spacing.sm,
              padding: `${tableSpacing.cellPaddingVertical} ${tableSpacing.cellPaddingHorizontal}`,
              background: theme.colors.semantic.primary,
              border: 'none',
              borderRadius: '6px',
              color: 'white',
              cursor: 'pointer',
              fontSize: '14px',
              fontWeight: '500',
              whiteSpace: 'nowrap',
            }}
          >
            <PlusIcon size={18} />
            <span>Erstellen</span>
          </button>
        </div>
      </div>

      {/* Loading State */}
      {loading ? (
        <div data-testid="categories-loading-indicator" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
          Loading categories...
        </div>
      ) : categories.length === 0 ? (
        <div
          data-testid="categories-empty-state"
          style={{
            padding: theme.spacing.xl,
            textAlign: 'center',
            color: theme.colors.text.secondary,
          }}
        >
          No categories found. Create one to get started.
        </div>
      ) : (
        <>
          <div data-testid="categories-table-wrapper" style={tableWrapperStyles}>
            <table data-testid="categories-table" style={tableElementStyles}>
            <thead>
              <tr style={headerRowStyle}>
                <th style={{ ...headerCellBaseStyle, width: '80px', textAlign: 'center' }}>Status</th>
                <th style={headerCellBaseStyle}>
                  <SortableTableHeader
                    label="Name"
                    sortKey="name"
                    currentSort={{ key: sortKey, direction: sortDirection }}
                    onSort={(key: string, direction: 'asc' | 'desc') => {
                      setSortKey(key as 'name' | 'created_at')
                      setSortDirection(direction)
                      setPage(1) // Reset to first page when sorting
                    }}
                    testId="categories-sort-name"
                  />
                </th>
                <th style={headerCellBaseStyle}>Products</th>
                <th style={headerCellBaseStyle}>Order</th>
                <th style={{ ...headerCellBaseStyle, width: '200px', textAlign: 'center' }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {categories.map((category) => (
                <tr
                  key={category.id}
                  data-testid={`categories-table-row-${category.id}`}
                  style={getRowStyle(category.is_active)}
                  onMouseEnter={(e: React.MouseEvent<HTMLTableRowElement>) => {
                    if (category.is_active) {
                      e.currentTarget.style.backgroundColor = tableColors.rowActiveHoverBg
                    }
                  }}
                  onMouseLeave={(e: React.MouseEvent<HTMLTableRowElement>) => {
                    e.currentTarget.style.backgroundColor = category.is_active
                      ? tableColors.rowActiveBg
                      : tableColors.rowInactiveBg
                  }}
                >
                  {/* Status Toggle */}
                  <StatusToggleCell
                    enabled={category.is_active}
                    onChange={() => handleStatusToggle(category)}
                    testId={`categories-status-toggle-${category.id}`}
                  />

                  {/* Name */}
                  <IconCell
                    icon={getCategoryIcon(category.icon_name)}
                    label={category.names.de || category.names.en || 'Unnamed'}
                    iconTestId={`categories-table-cell-icon-${category.id}`}
                    labelTestId={`categories-table-cell-name-${category.id}`}
                  />

                  {/* Products Count */}
                  <td style={{ padding: tableSpacing.cellPadding, color: tableColors.cellText }}>
                    <span data-testid={`categories-table-cell-product-count-${category.id}`}>
                      {category.product_count}
                    </span>
                  </td>

                  {/* Order */}
                  <td style={{ padding: tableSpacing.cellPadding, color: tableColors.cellText }}>
                    <span data-testid={`categories-table-cell-order-${category.id}`}>
                      {category.display_order}
                    </span>
                  </td>

                  {/* Actions */}
                  <td style={{ padding: tableSpacing.cellPadding, textAlign: 'center' }}>
                    <button
                      data-testid={`categories-table-action-edit-${category.id}`}
                      onClick={() => {
                        setModalMode('edit')
                        setSelectedCategory(category)
                        setFormData(category.names)
                        setSelectedIcon(category.icon_name || null)
                        setActiveLanguage('de')
                        setFormError(null)
                        setShowModal(true)
                      }}
                      style={{
                        background: 'transparent',
                        border: 'none',
                        color: theme.colors.semantic.primary,
                        cursor: 'pointer',
                        padding: theme.spacing.sm,
                      }}
                      title="Edit"
                    >
                      <EditIcon size={18} />
                    </button>
                    <button
                      data-testid={`categories-table-action-delete-${category.id}`}
                      onClick={() => handleDelete(category)}
                      disabled={category.product_count > 0}
                      style={{
                        background: 'transparent',
                        border: 'none',
                        color: category.product_count > 0 ? '#6b7280' : theme.colors.semantic.danger,
                        cursor: category.product_count > 0 ? 'not-allowed' : 'pointer',
                        padding: theme.spacing.sm,
                        marginLeft: theme.spacing.md,
                        opacity: category.product_count > 0 ? 0.5 : 1,
                      }}
                      title={category.product_count > 0 ? `Cannot delete (${category.product_count} products)` : 'Delete'}
                    >
                      <TrashIcon size={18} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
            </table>
          </div>

          {/* Pagination */}
          <PaginationToolbar
            currentPage={page}
            totalPages={Math.ceil(totalItems / pageSize)}
            totalItems={totalItems}
            pageSize={pageSize}
            onPageChange={setPage}
            onPageSizeChange={() => {}} // Not implemented - always use 20
            variant="default"
            showPageSize={false}
            showInfo={true}
            testId="categories-pagination"
          />
        </>
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
            background: 'rgba(0, 0, 0, 0.5)',
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
              background: theme.colors.bg.secondary,
              borderRadius: theme.borderRadius.lg,
              padding: theme.spacing.xl,
              maxWidth: '500px',
              width: '90%',
              boxShadow: '0 25px 50px rgba(0, 0, 0, 0.5)',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            <h2 data-testid="categories-form-title" style={{ margin: '0 0 20px 0' }}>
              {modalMode === 'create' ? 'Create Category' : 'Edit Category'}
            </h2>

            {formError && (
              <div
                data-testid="categories-form-error"
                style={{
                  marginBottom: theme.spacing.lg,
                  padding: theme.spacing.md,
                  background: `${theme.colors.semantic.danger}20`,
                  borderLeft: `3px solid ${theme.colors.semantic.danger}`,
                  borderRadius: theme.borderRadius.md,
                  color: theme.colors.semantic.danger,
                  fontSize: '14px',
                }}
              >
                {formError}
              </div>
            )}

            {/* Language Tabs */}
            <div style={{ marginBottom: theme.spacing.lg }}>
              <div style={{ display: 'flex', gap: theme.spacing.md, borderBottom: `1px solid ${theme.colors.border.light}` }}>
                {['de', 'en'].map((lang) => (
                  <button
                    key={lang}
                    data-testid={`categories-form-lang-tab-${lang}`}
                    onClick={() => setActiveLanguage(lang)}
                    style={{
                      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                      border: 'none',
                      background: 'transparent',
                      color: activeLanguage === lang ? theme.colors.semantic.primary : theme.colors.text.secondary,
                      borderBottom: activeLanguage === lang ? `2px solid ${theme.colors.semantic.primary}` : 'none',
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
                <div style={{ marginBottom: theme.spacing.lg }}>
                  <label
                    style={{
                      display: 'block',
                      marginBottom: theme.spacing.sm,
                      color: theme.colors.text.primary,
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
                      padding: theme.spacing.md,
                      border: `1px solid ${theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.md,
                      background: theme.colors.bg.primary,
                      color: theme.colors.text.primary,
                      boxSizing: 'border-box',
                      fontSize: '14px',
                      transition: 'all 150ms',
                    }}
                    onFocus={(e) => {
                      e.currentTarget.style.borderColor = theme.colors.semantic.primary
                    }}
                    onBlur={(e) => {
                      e.currentTarget.style.borderColor = theme.colors.border.light
                    }}
                  />
                </div>
              </div>
            ))}

            <IconSelect
              value={selectedIcon}
              onChange={setSelectedIcon}
              iconType="category"
              testId="categories-form-icon-select"
              label="Icon (optional)"
            />

            {/* Buttons */}
            <div style={{ display: 'flex', gap: theme.spacing.lg, justifyContent: 'flex-end', marginTop: theme.spacing.xl }}>
              <button
                data-testid="categories-form-cancel-button"
                type="button"
                onClick={() => setShowModal(false)}
                style={{
                  padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                  background: 'transparent',
                  border: `1px solid ${theme.colors.border.light}`,
                  borderRadius: theme.borderRadius.md,
                  color: theme.colors.text.primary,
                  cursor: 'pointer',
                  fontSize: '14px',
                  fontWeight: theme.typography.fontWeight.semibold,
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
                  padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                  background: theme.colors.semantic.primary,
                  border: 'none',
                  borderRadius: theme.borderRadius.md,
                  color: 'white',
                  cursor: 'pointer',
                  fontSize: '14px',
                  fontWeight: theme.typography.fontWeight.semibold,
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
            background: 'rgba(0, 0, 0, 0.5)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 1001,
          }}
          onClick={() => setConfirmDialog(null)}
        >
          <div
            style={{
              background: theme.colors.bg.secondary,
              borderRadius: theme.borderRadius.lg,
              padding: theme.spacing.xl,
              maxWidth: '400px',
              width: '90%',
              boxShadow: '0 25px 50px rgba(0, 0, 0, 0.5)',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            <h2 style={{ margin: 0, marginBottom: theme.spacing.lg, fontSize: theme.typography.fontSize.lg }}>
              {confirmDialog.type === 'delete' ? 'Delete Category' : 'Confirm Action'}
            </h2>
            <p
              data-testid="categories-confirm-message"
              style={{
                color: theme.colors.text.secondary,
                marginBottom: theme.spacing.lg,
                fontSize: '14px',
              }}
            >
              {confirmDialog.message}
            </p>

            <div style={{ display: 'flex', gap: theme.spacing.lg, justifyContent: 'flex-end' }}>
              <button
                data-testid="categories-confirm-cancel"
                onClick={() => setConfirmDialog(null)}
                style={{
                  padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                  background: 'transparent',
                  border: `1px solid ${theme.colors.border.light}`,
                  borderRadius: theme.borderRadius.md,
                  color: theme.colors.text.primary,
                  cursor: 'pointer',
                  fontSize: theme.typography.fontSize.sm,
                  fontWeight: theme.typography.fontWeight.semibold,
                }}
              >
                Cancel
              </button>
              <button
                data-testid="categories-confirm-ok"
                onClick={confirmAction}
                style={{
                  padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                  background: confirmDialog.type === 'delete' ? theme.colors.semantic.danger : theme.colors.semantic.primary,
                  border: 'none',
                  borderRadius: theme.borderRadius.md,
                  color: 'white',
                  cursor: 'pointer',
                  fontSize: theme.typography.fontSize.sm,
                  fontWeight: theme.typography.fontWeight.semibold,
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
