/**
 * Products Page
 * Product catalog management
 *
 * Implements:
 * - List products with pagination
 * - Search/filter products
 * - Create new product via modal
 * - Display product details (name, price, category, status, icon)
 *
 * Uses TDD with E2E tests in e2etests/tests/admin/products.spec.ts
 */

import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { get, post, patch, del, onLoadingStateChange } from '../services/api'
import { CategorySelect } from '../components/forms/CategorySelect'
import { IconSelect } from '../components/forms/IconSelect'
import { LanguageTabsInput } from '../components/forms/LanguageTabsInput'
import { ProductPreview } from '../components/forms/ProductPreview'
import { getProductIcon } from '../components/icons/IconRegistry'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { SortableTableHeader } from '../components/tables/SortableTableHeader'
import { CategoryFilter } from '../components/tables/CategoryFilter'
import { StatusFilterPills } from '../components/forms/StatusFilterPills'
import { StatusToggleCell } from '../components/tables/StatusToggleCell'
import { IconCell } from '../components/tables/IconCell'
import { PriceCell } from '../components/tables/PriceCell'
import { BadgeCell } from '../components/tables/BadgeCell'
import { ActionButtons } from '../components/tables/ActionButtons'
import {
  tableColors,
  tableWrapperStyles,
  tableElementStyles,
  headerCellBaseStyle,
  headerRowStyle,
  getRowStyle,
} from '../styles/tableTokens'
import { getLocalizedName, hasAnyName } from '../utils/i18n-helpers'
import { useFormatters } from '../hooks/useFormatters'

interface Product {
  id: string
  names: { [lang: string]: string }
  descriptions?: { [lang: string]: string }
  price_cents: number
  category_id: string
  is_active: boolean
  icon_name?: string | null
  created_at: string
  updated_at: string
}

interface Category {
  id: string
  names: { [lang: string]: string }
  is_active: boolean
  display_order: number
}


export function ProductsPage() {
  const { t, i18n } = useTranslation()
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const { formatPrice: _formatPrice } = useFormatters()
  const [products, setProducts] = useState<Product[]>([])
  const [categories, setCategories] = useState<Category[]>([])
  const [loading, setLoading] = useState(true)
  const [globalLoading, setGlobalLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [showModal, setShowModal] = useState(false)
  const [modalMode, setModalMode] = useState<'create' | 'edit'>('create')
  const [editingProduct, setEditingProduct] = useState<Product | null>(null)
  const [selectedCategory, setSelectedCategory] = useState<string>('')
  const [selectedIcon, setSelectedIcon] = useState<string | null>(null)
  const [formData, setFormData] = useState({ names: { de: '', en: '' }, price: '' })
  const [formError, setFormError] = useState<string | null>(null)
  const [confirmDialog, setConfirmDialog] = useState<{
    type: 'delete' | 'status'
    productId: string
    message: string
  } | null>(null)

  // Pagination, sorting, and filtering state
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)
  const [sortKey, setSortKey] = useState<'name' | 'price' | 'category'>('name')
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('asc')
  const [filterCategory, setFilterCategory] = useState<string | null>(null) // Category filter: null = all
  const [filterStatus, setFilterStatus] = useState<'all' | 'active' | 'inactive'>('all')
  const [search, setSearch] = useState('')
  const [totalItems, setTotalItems] = useState(0) // From API

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
  }, [])

  // Load products when pagination/sorting/filtering/search state changes
  useEffect(() => {
    const timer = setTimeout(loadProducts, search ? 500 : 0)
    return () => clearTimeout(timer)
  }, [currentPage, pageSize, sortKey, sortDirection, filterCategory, filterStatus, search])

  async function loadCategories() {
    try {
      const response = await get<any>('/admin/categories')
      const categoriesArray = (response as any).categories || []
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
      const response = await get<any>('/admin/products', {
        params: {
          page: currentPage,
          per_page: pageSize,
          sort_by: `${sortKey}_${sortDirection}`,
          ...(filterCategory && { category_id: filterCategory }),
          ...(filterStatus !== 'all' && { status: filterStatus }),
          ...(search && { search }),
        },
      })
      console.log('Products API response:', response)
      // API response uses 'items' array with pagination metadata
      let items = (response as any).items || []
      console.log(`Loaded ${items.length} products from API`)
      console.log('Pagination info:', {
        total: (response as any).total,
        limit: (response as any).limit,
        offset: (response as any).offset,
        has_more: (response as any).has_more,
      })

      // Extract total from API response
      const apiTotal = (response as any).total || items.length
      setTotalItems(apiTotal)
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
      names: { de: product.names.de || '', en: product.names.en || '' },
      price: (product.price_cents / 100).toFixed(2),
    })
    setSelectedCategory(product.category_id)
    setSelectedIcon(product.icon_name || null)
    setFormError(null)
    setShowModal(true)
  }

  async function handleCreateProduct(e: React.FormEvent) {
    e.preventDefault()
    setFormError(null)

    if (!hasAnyName(formData.names)) {
      setFormError(t('validation.atLeastOneLanguage'))
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
      // Filter out empty language names - backend requires all values to be non-empty
      const nonEmptyNames = Object.entries(formData.names)
        .filter(([, name]) => name.trim())
        .reduce((acc, [lang, name]) => ({ ...acc, [lang]: name.trim() }), {})
      const productData = {
        names: nonEmptyNames,
        price_cents: priceCents,
        category_id: selectedCategory,
        icon_name: selectedIcon,
      }
      console.log('Creating product with:', productData)

      const response = await post('/admin/products', productData)
      console.log('Product created successfully:', response)

      setFormData({ names: { de: '', en: '' }, price: '' })
      setSelectedCategory('')
      setSelectedIcon(null)
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

    if (!hasAnyName(formData.names)) {
      setFormError(t('validation.atLeastOneLanguage'))
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
      // Filter out empty language names - backend requires all values to be non-empty
      const nonEmptyNames = Object.entries(formData.names)
        .filter(([, name]) => name.trim())
        .reduce((acc, [lang, name]) => ({ ...acc, [lang]: name.trim() }), {})

      await patch(`/admin/products/${editingProduct!.id}`, {
        names: nonEmptyNames,
        price_cents: priceCents,
        category_id: selectedCategory,
        icon_name: selectedIcon,
      })

      setFormData({ names: { de: '', en: '' }, price: '' })
      setSelectedCategory('')
      setSelectedIcon(null)
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
    const productName = getLocalizedName(product.names, i18n.language)
    setConfirmDialog({
      type: 'delete',
      productId: product.id,
      message: t('products.deleteConfirm', { name: productName }),
    })
  }

  async function handleStatusToggle(product: Product) {
    if (product.is_active) {
      // Deactivating requires confirmation
      const productName = getLocalizedName(product.names, i18n.language)
      setConfirmDialog({
        type: 'status',
        productId: product.id,
        message: t('products.deactivateConfirm', { name: productName }),
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
    setFormData({ names: { de: '', en: '' }, price: '' })
    setSelectedCategory('')
    setSelectedIcon(null)
    setFormError(null)
    setEditingProduct(null)
    setModalMode('create')
  }

  // Server-side pagination - products are already sorted/paginated from API
  function getTotalPages(): number {
    return Math.ceil(totalItems / pageSize)
  }

  function handleSort(key: string, direction: 'asc' | 'desc') {
    const validKeys = ['name', 'price', 'category']
    if (!validKeys.includes(key)) return

    // If clicking the same column that's already sorted, toggle the direction
    // Otherwise use the provided direction (which is 'asc' by default)
    let newDirection = direction
    if (sortKey === key) {
      // Clicking the same column - toggle between asc and desc
      newDirection = sortDirection === 'asc' ? 'desc' : 'asc'
    }

    setSortKey(key as 'name' | 'price' | 'category')
    setSortDirection(newDirection)

    // Reset to first page when sorting changes (useEffect will call loadProducts)
    setCurrentPage(1)
  }

  function handlePageChange(page: number) {
    const totalPages = Math.ceil(totalItems / pageSize)
    setCurrentPage(Math.max(1, Math.min(page, totalPages || 1)))
    // useEffect will call loadProducts with new page
  }

  function handlePageSizeChange(newSize: number) {
    setPageSize(newSize)
    // Reset to first page when page size changes (useEffect will call loadProducts)
    setCurrentPage(1)
  }


  function handleCategoryFilterChange(categoryId: string | null) {
    setFilterCategory(categoryId)
    // Reset to first page when category filter changes
    setCurrentPage(1)
  }

  if (loading && products.length === 0) {
    return (
      <div style={{ padding: '20px' }}>
        <div>{t('common.loading')}</div>
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

      <h1>{t('products.title')}</h1>

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

      {/* Search and Sort Toolbar - top with Create button */}
      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: 16,
          marginBottom: 16,
          flexWrap: 'wrap',
        }}
      >
        {/* Left: Summary + Search */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, flex: 1 }}>
          <span data-testid="products-count-summary" style={{ color: '#cbd5e1', fontSize: 14, whiteSpace: 'nowrap' }}>
            <strong style={{ color: '#e2e8f0' }}>{totalItems}</strong> {t('products.title')} {t('common.found')}
          </span>
          <input
            type="text"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setCurrentPage(1)
            }}
            placeholder={t('common.searchPlaceholder')}
            data-testid="products-search-input"
            style={{
              flex: 1,
              padding: '8px 12px',
              backgroundColor: '#0d1829',
              border: '1px solid #2d3748',
              borderRadius: 8,
              color: '#e2e8f0',
              fontSize: '14px',
              fontFamily: 'inherit',
              maxWidth: '400px',
              height: '40px',
              boxSizing: 'border-box',
              verticalAlign: 'middle',
              transition: 'all 0.15s',
            }}
            onFocus={(e) => {
              e.currentTarget.style.borderColor = 'rgba(59,130,246,0.5)'
            }}
            onBlur={(e) => {
              e.currentTarget.style.borderColor = '#2d3748'
            }}
          />
        </div>

        {/* Right: Status filter + Category filter + Create button */}
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <StatusFilterPills
            value={filterStatus}
            onChange={(status) => {
              setFilterStatus(status)
              setCurrentPage(1)
            }}
            testId="products-filter-status"
          />
          <CategoryFilter
            categories={categories}
            value={filterCategory}
            onChange={handleCategoryFilterChange}
            testId="products-search-sort-category"
          />

          {/* Create button */}
          <button
            data-testid="products-create-button"
            onClick={() => {
              setModalMode('create')
              setEditingProduct(null)
              setFormData({ names: { de: '', en: '' }, price: '' })
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
              fontSize: '14px',
              fontWeight: '500',
              whiteSpace: 'nowrap',
            }}
          >
            {t('products.createProduct')}
          </button>
        </div>
      </div>

      <div data-testid="products-table-wrapper" style={tableWrapperStyles}>
        <table
          data-testid="products-table"
          style={tableElementStyles}
        >
          <thead>
            <tr style={headerRowStyle}>
              <th
                style={{
                  ...headerCellBaseStyle,
                  textAlign: 'center',
                  width: '60px',
                }}
              >
                {t('common.active').toUpperCase()}
              </th>
              <th style={{ ...headerCellBaseStyle, textAlign: 'left' }}>
                <SortableTableHeader
                  label={t('journal.product')}
                  sortKey="name"
                  currentSort={{ key: sortKey, direction: sortDirection }}
                  onSort={handleSort}
                  testId="products-table-header-name"
                />
              </th>
              <th style={{ ...headerCellBaseStyle, textAlign: 'left' }}>
                <SortableTableHeader
                  label={t('common.price')}
                  sortKey="price"
                  currentSort={{ key: sortKey, direction: sortDirection }}
                  onSort={handleSort}
                  testId="products-table-header-price"
                />
              </th>
              <th style={{ ...headerCellBaseStyle, textAlign: 'left' }}>
                <SortableTableHeader
                  label={t('common.category')}
                  sortKey="category"
                  currentSort={{ key: sortKey, direction: sortDirection }}
                  onSort={handleSort}
                  testId="products-table-header-category"
                />
              </th>
              <th style={{ ...headerCellBaseStyle, textAlign: 'center' }}>
                {t('common.actions')}
              </th>
            </tr>
          </thead>
          <tbody>
            {products.map((product) => (
              <tr
                key={product.id}
                data-testid={product.id}
                style={getRowStyle(product.is_active)}
                onMouseEnter={(e: React.MouseEvent<HTMLTableRowElement>) => {
                  if (product.is_active) {
                    e.currentTarget.style.backgroundColor = tableColors.rowActiveHoverBg
                  }
                }}
                onMouseLeave={(e: React.MouseEvent<HTMLTableRowElement>) => {
                  e.currentTarget.style.backgroundColor = product.is_active
                    ? tableColors.rowActiveBg
                    : tableColors.rowInactiveBg
                }}
              >
                <StatusToggleCell
                  enabled={product.is_active}
                  onChange={() => handleStatusToggle(product)}
                  testId={`products-status-toggle-${product.id}`}
                />
                <IconCell
                  icon={getProductIcon(product.icon_name)}
                  label={getLocalizedName(product.names, i18n.language)}
                  iconTestId={`products-table-cell-icon-${product.id}`}
                  labelTestId={`products-table-cell-name-${product.id}`}
                />
                <PriceCell
                  priceCents={product.price_cents}
                  testId={`products-table-cell-price-${product.id}`}
                />
                <BadgeCell
                  label={(() => {
                    const category = categories.find((c) => c.id === product.category_id)
                    return category ? getLocalizedName(category.names, i18n.language) : ''
                  })()}
                  testId={`products-table-cell-category-${product.id}`}
                />
                <ActionButtons
                  onEdit={() => openEditModal(product)}
                  onDelete={() => handleDelete(product)}
                  editTestId={`products-edit-button-${product.id}`}
                  deleteTestId={`products-delete-button-${product.id}`}
                />
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Pagination toolbar - bottom */}
      {totalItems > 0 && (
        <PaginationToolbar
          currentPage={currentPage}
          totalPages={getTotalPages()}
          totalItems={totalItems}
          pageSize={pageSize}
          pageSizeOptions={[10, 25, 50, 100]}
          onPageChange={handlePageChange}
          onPageSizeChange={handlePageSizeChange}
          variant="default"
          showPageSize={true}
          showInfo={true}
          testId="products-pagination-bottom"
        />
      )}

      {totalItems === 0 && !loading && (
        <div
          data-testid="products-empty-state"
          style={{
            textAlign: 'center',
            padding: '40px',
            color: '#94a3b8',
          }}
        >
          {t('products.noProducts')}
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
              maxWidth: '700px',
              width: '90%',
              boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.5)',
              display: 'flex',
              gap: '24px',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            {/* Left Column: Form */}
            <div style={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
              <h2 data-testid="products-form-title" style={{ marginTop: 0, marginBottom: '16px', color: '#e2e8f0' }}>
                {modalMode === 'create' ? t('products.createProduct') : t('products.editProduct')}
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

              <form onSubmit={handleFormSubmit} style={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
              <div style={{ marginBottom: '16px' }}>
                <LanguageTabsInput
                  values={formData.names}
                  onChange={(names) => setFormData({ ...formData, names })}
                  label={t('products.productName')}
                  placeholder={t('products.productName')}
                  required
                  testIdPrefix="products-form-name"
                />
              </div>

              <CategorySelect
                categories={categories}
                value={selectedCategory}
                onChange={setSelectedCategory}
                testId="products-form-category-select"
                label={`${t('common.category')} *`}
                required
              />

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
                  {t('common.price')} (€)
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
                    padding: '10px 12px',
                    border: '1px solid #4b5563',
                    borderRadius: '6px',
                    backgroundColor: '#1e293b',
                    color: '#e2e8f0',
                    fontSize: '14px',
                    boxSizing: 'border-box',
                  }}
                  required
                />
              </div>

              <IconSelect
                value={selectedIcon}
                onChange={setSelectedIcon}
                iconType="product"
                testId="products-form-icon-select"
                label={`${t('products.icon')} (${t('common.optional')})`}
              />

              <div style={{ display: 'flex', gap: '10px', justifyContent: 'flex-end', marginTop: 'auto' }}>
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
                    fontSize: '14px',
                    fontWeight: '500',
                  }}
                >
                  {t('common.cancel')}
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
                    fontSize: '14px',
                    fontWeight: '500',
                  }}
                >
                  {modalMode === 'create' ? t('products.createProduct') : t('common.saveChanges')}
                </button>
              </div>
              </form>
            </div>

            {/* Right Column: Preview */}
            <div style={{ width: '160px', display: 'flex', flexDirection: 'column', justifyContent: 'center' }}>
              <div style={{ marginBottom: '12px', color: '#64748b', fontSize: '12px', fontWeight: '500', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                {t('common.terminalPreview')}
              </div>
              <ProductPreview
                name={getLocalizedName(formData.names, i18n.language)}
                price={formData.price}
                iconName={selectedIcon}
              />
            </div>
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
                  fontSize: '14px',
                  fontWeight: '500',
                }}
              >
                {t('common.cancel')}
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
                  fontSize: '14px',
                  fontWeight: '500',
                }}
              >
                {confirmDialog.type === 'delete' ? t('common.delete') : t('common.deactivate')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
