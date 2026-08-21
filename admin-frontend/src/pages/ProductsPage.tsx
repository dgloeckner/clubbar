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
import axios from 'axios'
import { getProducts } from '../api/generated/products/products'
import { theme } from '../styles/design-system'
import type {
  Product,
  Category,
  ProductCreateRequest,
  ProductUpdateRequest,
  ListProductsParams,
  ListProductsSortBy,
  ListProductsStatus,
} from '../api/generated'
import { CategorySelect } from '../components/forms/CategorySelect'
import { FieldLabel } from '../components/forms/FieldLabel'
import { IconSelect } from '../components/forms/IconSelect'
import { LanguageTabsInput } from '../components/forms/LanguageTabsInput'
import { ProductPreview } from '../components/forms/ProductPreview'
import { getProductIcon } from '../components/icons/IconRegistry'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { SortableTableHeader } from '../components/tables/SortableTableHeader'
import { CategoryFilter } from '../components/tables/CategoryFilter'
import { PillFilter } from '../components/forms/PillFilter'
import { activeStatusOptions } from '../components/forms/filterOptions'
import { StatusToggleCell } from '../components/tables/StatusToggleCell'
import { Toggle } from '../components/common/Toggle'
import { PriceCell } from '../components/tables/PriceCell'
import { BadgeCell } from '../components/tables/BadgeCell'
import { ActionButtons } from '../components/tables/ActionButtons'
import { Badge } from '../components/common/Badge'
import { EditIcon, PlusIcon, TrashIcon } from '../components/icons'
import { PageActionButton } from '../components/common/PageActionButton'
import { PageHeader } from '../components/layout/PageHeader'
import { ProductsTabs } from '../components/products/ProductsTabs'
import { ListLoadingOverlay } from '../components/tables/ListLoadingOverlay'
import { MobileFilterRow } from '../components/tables/MobileFilterRow'
import { MobileToolbar } from '../components/layout/MobileToolbar'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { useListQuery } from '../hooks/useListQuery'
import {
  tableColors,
  tableWrapperStyles,
  tableElementStyles,
  headerCellBaseStyle,
  headerRowStyle,
  getRowStyle,
} from '../styles/tableTokens'
import { ageRestrictionOf } from '../utils/ageRestriction'
import { getLocalizedName, hasAnyName } from '../utils/i18n-helpers'
import { parsePriceToCents } from '../utils/price'
import { useFormatters } from '../hooks/useFormatters'
import { useLatestRequest } from '../hooks/useLatestRequest'
import { ConfirmDialog } from '../components/modals/ConfirmDialog'

// `requires_dispenser` and `min_age` are in the OAS spec and the generated
// type since #582 M3; the alias is kept because the page passes it around by
// name in a dozen places.
type ProductWithExtras = Product

/**
 * Read the Jugendschutz age out of the form field (ADR-0045).
 *
 * Three outcomes, and the middle one is the point: an **empty** field is not a
 * missing value to be dropped, it is the assertion that this drink carries no
 * legal age. It travels to the API as an explicit `null` so clearing one
 * actually clears the column.
 *
 * `'invalid'` covers what the number input cannot stop on its own — a `0`,
 * a `100`, a decimal — so the admin gets a sentence instead of the backend's
 * 422.
 */
function parseMinAge(value: string): number | null | 'invalid' {
  const trimmed = value.trim()
  if (trimmed === '') return null

  const parsed = Number(trimmed)
  if (!Number.isInteger(parsed) || parsed < 1 || parsed > 99) return 'invalid'

  return parsed
}

// Extend Category to ensure required fields are non-optional at runtime
type CategoryRuntime = Category & {
  id: string
  names: { [lang: string]: string }
  is_active: boolean
}

type ProductSortKey = 'name' | 'price' | 'category' | 'created_at'

interface ProductFilters {
  categoryId: string | null
  status: 'all' | 'active' | 'inactive'
}

export function ProductsPage() {
  const { t, i18n } = useTranslation()
  const { formatPrice } = useFormatters()
  const breakpoint = useBreakpoint()
  // The category list is a second, independent stream, so it gets its own abort
  // slot — the product list's lives inside useListQuery (#96).
  const categoriesRequest = useLatestRequest()
  const [categories, setCategories] = useState<CategoryRuntime[]>([])
  // Categories are optional for *display*, but the product form requires one.
  // Failing quietly rendered an empty select and a "Category is required" the
  // admin had no way to act on (#132).
  const [categoriesFailed, setCategoriesFailed] = useState(false)
  const [showModal, setShowModal] = useState(false)
  const [modalMode, setModalMode] = useState<'create' | 'edit'>('create')
  const [editingProduct, setEditingProduct] = useState<ProductWithExtras | null>(null)
  const [selectedCategory, setSelectedCategory] = useState<string>('')
  const [selectedIcon, setSelectedIcon] = useState<string | null>(null)
  const [formData, setFormData] = useState({ names: { de: '', en: '' }, price: '', requiresDispenser: false, minAge: '' })
  const [formError, setFormError] = useState<string | null>(null)
  const [confirmDialog, setConfirmDialog] = useState<{
    type: 'delete'
    productId: string
    message: string
  } | null>(null)

  // Pagination, sorting, filtering and search all live in the shared list-query
  // state, which owns the debounce, the abort guard and the page resets (#121).
  // Default to created_at descending so newest products appear first.
  const list = useListQuery<ProductWithExtras, ProductFilters, ProductSortKey>({
    initialFilters: { categoryId: null, status: 'all' },
    initialSortKey: 'created_at',
    initialSortDirection: 'desc',
    initialPageSize: 25,
    fetcher: async ({ page, pageSize, sortKey, sortDirection, search, filters, signal }) => {
      const params: ListProductsParams = {
        page,
        per_page: pageSize,
        sort_by: `${sortKey}_${sortDirection}` as ListProductsSortBy,
      }
      if (filters.categoryId) params.category_id = filters.categoryId
      if (filters.status !== 'all') params.status = filters.status as ListProductsStatus
      if (search) params.search = search

      const response = await getProducts().listProducts(params, { signal })
      const items = (response.data ?? []) as ProductWithExtras[]
      return { items, total: response.pagination?.total ?? items.length }
    },
    parseError: (err) =>
      axios.isAxiosError(err)
        ? err.response?.data?.message || err.message || t('products.errors.load')
        : err instanceof Error
          ? err.message
          : t('products.errors.load'),
  })

  const { items: products, total: totalItems, totalPages, loading, hasLoaded, error, setError } = list
  const { categoryId: filterCategory, status: filterStatus } = list.filters
  const search = list.search
  const sortKey = list.sortKey
  const sortDirection = list.sortDirection

  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'
  const [showMobileFilters, setShowMobileFilters] = useState(false)

  const mobileFilterCount = [
    filterStatus !== 'all' ? 1 : 0,
    filterCategory ? 1 : 0,
  ].reduce((a, b) => a + b, 0)

  const mobileSortOptions = [
    { value: 'name_asc', label: t('products.sortName'), direction: 'asc' as const },
    { value: 'name_desc', label: t('products.sortNameDesc'), direction: 'desc' as const },
    { value: 'price_asc', label: t('products.sortPriceLow'), direction: 'asc' as const },
    { value: 'price_desc', label: t('products.sortPriceHigh'), direction: 'desc' as const },
    { value: 'category_asc', label: t('products.sortCategory'), direction: 'asc' as const },
    { value: 'created_at_desc', label: t('products.sortNewest'), direction: 'desc' as const },
  ]

  const mobileSortValue = list.sortValue

  // Load products and categories on mount
  useEffect(() => {
    loadCategories(categoriesRequest.next())
    return () => categoriesRequest.abort()
  }, [categoriesRequest]) // eslint-disable-line react-hooks/exhaustive-deps

  async function loadCategories(signal: AbortSignal = categoriesRequest.next()) {
    try {
      const response = await getProducts().listCategories({ signal })
      if (signal.aborted) return
      setCategories((response.data ?? []) as CategoryRuntime[])
      setCategoriesFailed(false)
    } catch {
      if (signal.aborted) return
      setCategories([])
      setCategoriesFailed(true)
    }
  }

  /**
   * One place to open an empty form.
   *
   * The desktop and mobile create buttons each reset the modal themselves, and
   * both forgot the icon — so "Create product" opened carrying the icon of the
   * product edited before it (#131).
   */
  function openCreateModal() {
    setModalMode('create')
    setEditingProduct(null)
    setFormData({ names: { de: '', en: '' }, price: '', requiresDispenser: false, minAge: '' })
    setSelectedCategory('')
    setSelectedIcon(null)
    setFormError(null)
    setShowModal(true)
  }

  function openEditModal(product: ProductWithExtras) {
    setModalMode('edit')
    setEditingProduct(product)
    setFormData({
      names: { de: product.names?.de || '', en: product.names?.en || '' },
      price: ((product.price_cents ?? 0) / 100).toFixed(2),
      requiresDispenser: product.requires_dispenser || false,
      // Held as a string like `price`, so the input round-trips an empty field
      // as "unrestricted" rather than as a 0 nobody can be old enough for.
      minAge: product.min_age == null ? '' : String(product.min_age),
    })
    setSelectedCategory(product.category_id || '')
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

    const priceCents = parsePriceToCents(formData.price)
    if (priceCents === null) {
      setFormError(t('products.validation.priceRequired'))
      return
    }

    if (!selectedCategory) {
      setFormError(t('products.validation.categoryRequired'))
      return
    }

    const minAge = parseMinAge(formData.minAge)
    if (minAge === 'invalid') {
      setFormError(t('products.validation.minAgeRange'))
      return
    }

    try {
      // Filter out empty language names - backend requires all values to be non-empty
      const nonEmptyNames = Object.entries(formData.names)
        .filter(([, name]) => name.trim())
        .reduce((acc, [lang, name]) => ({ ...acc, [lang]: name.trim() }), {} as Record<string, string>)

      const productData: ProductCreateRequest & { requires_dispenser?: boolean } = {
        names: nonEmptyNames,
        price_cents: priceCents,
        category_id: selectedCategory,
        icon_name: selectedIcon,
        requires_dispenser: formData.requiresDispenser,
        min_age: minAge,
      }

      await getProducts().createProduct(productData as ProductCreateRequest)

      setFormData({ names: { de: '', en: '' }, price: '', requiresDispenser: false, minAge: '' })
      setSelectedCategory('')
      setSelectedIcon(null)
      setModalMode('create')
      setEditingProduct(null)
      setShowModal(false)

      // Reload product list to show newly created product
      await list.reload()
    } catch (err: unknown) {
      if (axios.isAxiosError(err)) {
        const errorMsg = err.response?.data?.message || err.response?.data?.error || err.message || t('products.errors.create')
        setFormError(errorMsg)
      } else {
        setFormError(err instanceof Error ? err.message : t('products.errors.create'))
      }
    }
  }

  async function handleUpdateProduct(e: React.FormEvent) {
    e.preventDefault()
    setFormError(null)

    if (!hasAnyName(formData.names)) {
      setFormError(t('validation.atLeastOneLanguage'))
      return
    }

    const priceCents = parsePriceToCents(formData.price)
    if (priceCents === null || priceCents <= 0) {
      setFormError(t('products.validation.priceRequired'))
      return
    }

    if (!selectedCategory) {
      setFormError(t('products.validation.categoryRequired'))
      return
    }

    const minAge = parseMinAge(formData.minAge)
    if (minAge === 'invalid') {
      setFormError(t('products.validation.minAgeRange'))
      return
    }

    try {
      // Filter out empty language names - backend requires all values to be non-empty
      const nonEmptyNames = Object.entries(formData.names)
        .filter(([, name]) => name.trim())
        .reduce((acc, [lang, name]) => ({ ...acc, [lang]: name.trim() }), {} as Record<string, string>)

      const updateData: ProductUpdateRequest & { requires_dispenser?: boolean } = {
        names: nonEmptyNames,
        price_cents: priceCents,
        category_id: selectedCategory,
        icon_name: selectedIcon,
        requires_dispenser: formData.requiresDispenser,
        // Always sent, never omitted: an empty field means "unrestricted", and
        // un-restricting a drink is the write that makes it *more* available.
        // A dropped key would leave the old age on the row with the form
        // claiming it had been cleared (ADR-0045; the backend reads an explicit
        // null via `array_key_exists`).
        min_age: minAge,
      }

      await getProducts().updateProduct(editingProduct!.id!, updateData as ProductUpdateRequest)

      setFormData({ names: { de: '', en: '' }, price: '', requiresDispenser: false, minAge: '' })
      setSelectedCategory('')
      setSelectedIcon(null)
      setEditingProduct(null)
      setModalMode('create')
      setShowModal(false)
      // Reload product list to reflect updated product
      await list.reload()
    } catch (err: unknown) {
      if (axios.isAxiosError(err)) {
        const errorMsg = err.response?.data?.message || err.response?.data?.error || err.message || t('products.errors.update')
        setFormError(errorMsg)
      } else {
        setFormError(err instanceof Error ? err.message : t('products.errors.update'))
      }
    }
  }

  async function handleDelete(product: ProductWithExtras) {
    const productName = getLocalizedName(product.names as Record<string, string>, i18n.language)
    setConfirmDialog({
      type: 'delete',
      productId: product.id!,
      message: t('products.deleteConfirm', { name: productName }),
    })
  }

  async function handleStatusToggle(product: ProductWithExtras) {
    try {
      await getProducts().updateProduct(product.id!, { is_active: !product.is_active })
      await list.reload()
    } catch (err: unknown) {
      if (axios.isAxiosError(err)) {
        setError(err.response?.data?.message || err.message || t('products.errors.updateStatus'))
      } else {
        setError(err instanceof Error ? err.message : t('products.errors.updateStatus'))
      }
    }
  }

  async function confirmAction() {
    if (!confirmDialog) return

    try {
      await getProducts().deleteProduct(confirmDialog.productId)
      setConfirmDialog(null)
      await list.reload()
    } catch (err: unknown) {
      if (axios.isAxiosError(err)) {
        setError(err.response?.data?.message || err.message || t('products.errors.delete'))
      } else {
        setError(err instanceof Error ? err.message : t('products.errors.delete'))
      }
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
    setFormData({ names: { de: '', en: '' }, price: '', requiresDispenser: false, minAge: '' })
    setSelectedCategory('')
    setSelectedIcon(null)
    setFormError(null)
    setEditingProduct(null)
    setModalMode('create')
  }

  function handleSort(key: string, direction: 'asc' | 'desc') {
    const validKeys: ProductSortKey[] = ['name', 'price', 'category']
    if (!validKeys.includes(key as ProductSortKey)) return
    // Clicking the already-sorted column flips the direction; a new column takes
    // the direction the header offers. Either way the page resets, because the
    // hook resets it for desktop and mobile sorting alike.
    list.toggleSort(key as ProductSortKey, direction)
  }

  function handlePageChange(page: number) {
    list.setPage(Math.min(page, totalPages || 1))
  }

  function handleCategoryFilterChange(categoryId: string | null) {
    list.setFilter('categoryId', categoryId)
  }

  // Only the very first load may take over the page. `loading && !products.length`
  // looked equivalent and was not: a search that comes back empty leaves the list
  // empty, so the next keystroke's request replaced the whole page — toolbar,
  // focused search box and all — and the rest of the word was typed into nothing
  // (#137). Every later fetch dims the results region instead, below.
  if (!hasLoaded) {
    return (
      <div data-testid="products-loading" style={{ padding: '20px' }}>
        <div>{t('common.loading')}</div>
      </div>
    )
  }

  return (
    <div data-testid="products-page">
      <PageHeader
        title={t('products.title')}
        actions={
          /* One position for the primary action, on every tab (#375). This
             one used to live inside the desktop toolbar and, on mobile, inside
             MobileToolbar — two places, neither of them where Members and
             Settlements keep theirs. */
          <PageActionButton
            data-testid="products-create-button"
            onClick={openCreateModal}
            iconOnly={isMobile}
            icon={<PlusIcon size={18} />}
            title={t('products.createProduct')}
          >
            {t('products.createProduct')}
          </PageActionButton>
        }
      />

      <ProductsTabs />

      {error && (
        <div
          data-testid="products-error-message"
          style={{
            marginBottom: '10px',
            padding: '10px',
            backgroundColor: theme.colors.alert.dangerBg,
            color: theme.colors.semantic.dangerHover,
            borderRadius: '4px',
          }}
        >
          {error}
        </div>
      )}

      {categoriesFailed && (
        <div
          data-testid="products-categories-error"
          style={{
            marginBottom: '10px',
            padding: '10px',
            backgroundColor: 'rgba(249,115,22,0.12)',
            border: '1px solid rgba(249,115,22,0.5)',
            color: theme.colors.semantic.warningLight,
            borderRadius: '4px',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: '12px',
            flexWrap: 'wrap',
          }}
        >
          <span>{t('products.errors.loadCategories')}</span>
          <button
            data-testid="products-categories-retry"
            onClick={() => loadCategories()}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: '1px solid rgba(249,115,22,0.6)',
              background: 'transparent',
              color: theme.colors.semantic.warningLight,
              fontSize: '13px',
              fontWeight: 600,
              cursor: 'pointer',
            }}
          >
            {t('common.retry')}
          </button>
        </div>
      )}

      {isMobile ? (
        <>
          <MobileToolbar
            testId="products-mobile-toolbar"
            search={{
              value: search,
              onChange: list.setSearch,
              testId: 'products-search-input',
            }}
            sort={{
              options: mobileSortOptions,
              value: mobileSortValue,
              onChange: list.setSortValue,
            }}
            filterCount={mobileFilterCount}
            onFilterToggle={() => setShowMobileFilters(!showMobileFilters)}
            showFilters={showMobileFilters}
            filterContent={
              <>
                <MobileFilterRow
                  label={t('common.status')}
                  options={[
                    { value: 'all', label: t('common.all') },
                    { value: 'active', label: t('common.active') },
                    { value: 'inactive', label: t('common.inactive') },
                  ]}
                  value={filterStatus}
                  onChange={(v) => list.setFilter('status', v as ProductFilters['status'])}
                  testId="products-mobile-filter-status"
                />
                <MobileFilterRow
                  label={t('common.category')}
                  options={[
                    { value: '', label: t('common.all') },
                    ...categories.map((c) => ({
                      value: c.id,
                      label: getLocalizedName(c.names as Record<string, string>, i18n.language),
                    })),
                  ]}
                  value={filterCategory || ''}
                  onChange={(v) => handleCategoryFilterChange(v || null)}
                  testId="products-mobile-filter-category"
                />
              </>
            }
          />

          {/* Mobile card list — kept mounted while a refresh runs, same as the
              desktop table, so the results do not vanish and reappear on every
              debounced keystroke. */}
          <ListLoadingOverlay loading={loading} label={t('common.loading')} testId="products-list-loading">
          {products.length === 0 ? (
            !loading && (
              <div data-testid="products-empty-state" style={{ padding: '40px', textAlign: 'center', color: theme.colors.text.secondary }}>
                {t('products.noProducts')}
              </div>
            )
          ) : (
            <div data-testid="products-mobile-cards" style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
              {products.map((product) => {
                const category = categories.find((c) => c.id === product.category_id)
                const categoryName = category ? getLocalizedName(category.names as Record<string, string>, i18n.language) : ''
                const minAge = ageRestrictionOf(product.min_age)
                return (
                  <div
                    key={product.id}
                    data-testid={`product-card-${product.id}`}
                    style={{
                      background: theme.mobileCard.bg,
                      border: `1px solid ${theme.mobileCard.border}`,
                      borderRadius: '10px',
                      padding: '14px 16px',
                    }}
                  >
                    {/* Row 1: toggle + name + price */}
                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '8px' }}>
                      <Toggle
                        isEnabled={product.is_active ?? false}
                        onChange={() => handleStatusToggle(product)}
                        size="small"
                        testId={`products-status-toggle-${product.id}`}
                      />
                      <span style={{ flex: 1, fontWeight: 600, color: tableColors.cellText, fontSize: '14px' }}>
                        {getLocalizedName(product.names as Record<string, string>, i18n.language)}
                      </span>
                      <span style={{ fontWeight: 600, color: tableColors.cellText, fontSize: '14px', whiteSpace: 'nowrap' }}>
                        {formatPrice(product.price_cents ?? 0)}
                      </span>
                    </div>
                    {/* Row 2: category, and the Jugendschutz age beside it —
                        the card is the whole overview on a phone, so the badge
                        cannot live only in the desktop table (ADR-0045). */}
                    {(categoryName || minAge !== null) && (
                      <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '10px', paddingLeft: '46px' }}>
                        {categoryName && (
                          <span style={{ fontSize: '12px', color: theme.colors.text.secondary }}>
                            {categoryName}
                          </span>
                        )}
                        {minAge !== null && (
                          <Badge
                            label={t('products.minAgeBadge', { age: minAge })}
                            variant="warning"
                            showDot={false}
                            testId={`products-list-min-age-badge-${product.id}`}
                          />
                        )}
                      </div>
                    )}
                    {/* Row 3: actions */}
                    <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '8px' }}>
                      <button
                        data-testid={`products-edit-button-${product.id}`}
                        onClick={() => openEditModal(product)}
                        style={{
                          display: 'flex', alignItems: 'center', gap: '4px',
                          padding: '6px 12px', borderRadius: '6px', border: 'none',
                          background: theme.badges.info.bg, color: theme.colors.semantic.primary,
                          fontSize: '12px', cursor: 'pointer',
                        }}
                      >
                        <EditIcon size={14} /> {t('common.edit')}
                      </button>
                      <button
                        data-testid={`products-delete-button-${product.id}`}
                        onClick={() => handleDelete(product)}
                        style={{
                          display: 'flex', alignItems: 'center', gap: '4px',
                          padding: '6px 12px', borderRadius: '6px', border: 'none',
                          background: theme.badges.danger.bg, color: theme.colors.semantic.danger,
                          fontSize: '12px', cursor: 'pointer',
                        }}
                      >
                        <TrashIcon size={14} /> {t('common.delete')}
                      </button>
                    </div>
                  </div>
                )
              })}
            </div>
          )}
          </ListLoadingOverlay>

          {/* Pagination (mobile) */}
          {!loading && products.length > 0 && (
            <PaginationToolbar
              currentPage={list.page}
              totalPages={totalPages}
              totalItems={totalItems}
              pageSize={list.pageSize}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={handlePageChange}
              onPageSizeChange={list.setPageSize}
              variant="default"
              showPageSize={false}
              showInfo={true}
              testId="products-pagination-bottom"
            />
          )}
        </>
      ) : (
        <>
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
          <span data-testid="products-count-summary" style={{ color: tableColors.headerText, fontSize: 14, whiteSpace: 'nowrap' }}>
            {t('products.countFound', { count: totalItems })}
          </span>
          <input
            type="text"
            value={search}
            onChange={(e) => {
              list.setSearch(e.target.value)
            }}
            placeholder={t('common.searchPlaceholder')}
            data-testid="products-search-input"
            style={{
              flex: 1,
              padding: '8px 12px',
              backgroundColor: theme.colors.bg.input,
              border: `1px solid ${theme.colors.border.input}`,
              borderRadius: 8,
              color: tableColors.cellText,
              fontSize: '14px',
              fontFamily: 'inherit',
              maxWidth: '400px',
              height: '40px',
              boxSizing: 'border-box',
              verticalAlign: 'middle',
              transition: 'all 0.15s',
            }}
            onFocus={(e) => {
              e.currentTarget.style.borderColor = theme.activeTint.primaryBorder
            }}
            onBlur={(e) => {
              e.currentTarget.style.borderColor = theme.colors.border.input
            }}
          />
        </div>

        {/* Right: Status filter + Category filter + Create button */}
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <PillFilter
            value={filterStatus}
            onChange={(status) => {
              list.setFilter('status', status)
            }}
            options={activeStatusOptions(t)}
            variant="solid"
            testId="products-filter-status"
          />
          <CategoryFilter
            categories={categories}
            value={filterCategory}
            onChange={handleCategoryFilterChange}
            testId="products-search-sort-category"
          />
        </div>
      </div>

      {/* The table stays mounted while a refresh runs — only the results are
          dimmed. The toolbar above must survive a re-fetch (#137). */}
      <ListLoadingOverlay loading={loading} label={t('common.loading')} testId="products-list-loading">
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
                style={getRowStyle(product.is_active ?? false)}
                onMouseEnter={(e: React.MouseEvent<HTMLTableRowElement>) => {
                  if (product.is_active) {
                    e.currentTarget.style.backgroundColor = tableColors.rowActiveHoverBg
                  }
                }}
                onMouseLeave={(e: React.MouseEvent<HTMLTableRowElement>) => {
                  e.currentTarget.style.backgroundColor = (product.is_active ?? false)
                    ? tableColors.rowActiveBg
                    : tableColors.rowInactiveBg
                }}
              >
                <StatusToggleCell
                  enabled={product.is_active ?? false}
                  onChange={() => handleStatusToggle(product)}
                  testId={`products-status-toggle-${product.id}`}
                />
                <td
                  style={{
                    padding: '12px 16px',
                    color: tableColors.cellText,
                    display: 'flex',
                    alignItems: 'center',
                    gap: '12px',
                  }}
                >
                  {(() => {
                    const Icon = getProductIcon(product.icon_name ?? null)
                    return <Icon size={20} data-testid={`products-table-cell-icon-${product.id}`} />
                  })()}
                  <span data-testid={`products-table-cell-name-${product.id}`} style={{ fontWeight: '500' }}>
                    {getLocalizedName(product.names as Record<string, string>, i18n.language)}
                  </span>
                  {product.requires_dispenser && (
                    <Badge
                      label={t('products.dispenserBadge')}
                      variant="info"
                      showDot={false}
                      testId={`products-list-dispenser-badge-${product.id}`}
                    />
                  )}
                  {/* Jugendschutz (ADR-0045): the age lives on the row, not
                      only inside the edit form, so a Getränkewart can see at a
                      glance which drinks the terminal will refuse. */}
                  {(() => {
                    const minAge = ageRestrictionOf(product.min_age)
                    return minAge === null ? null : (
                      <Badge
                        label={t('products.minAgeBadge', { age: minAge })}
                        variant="warning"
                        showDot={false}
                        testId={`products-list-min-age-badge-${product.id}`}
                      />
                    )
                  })()}
                </td>
                <PriceCell
                  priceCents={product.price_cents ?? 0}
                  testId={`products-table-cell-price-${product.id}`}
                />
                <BadgeCell
                  label={(() => {
                    const category = categories.find((c) => c.id === product.category_id)
                    return category ? getLocalizedName(category.names as Record<string, string>, i18n.language) : ''
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

      {totalItems === 0 && !loading && (
        <div
          data-testid="products-empty-state"
          style={{
            textAlign: 'center',
            padding: '40px',
            color: theme.colors.text.secondary,
          }}
        >
          {t('products.noProducts')}
        </div>
      )}
      </ListLoadingOverlay>

      {/* Pagination toolbar - bottom */}
      {totalItems > 0 && (
        <PaginationToolbar
          currentPage={list.page}
          totalPages={totalPages}
          totalItems={totalItems}
          pageSize={list.pageSize}
          pageSizeOptions={[10, 25, 50, 100]}
          onPageChange={handlePageChange}
          onPageSizeChange={list.setPageSize}
          variant="default"
          showPageSize={true}
          showInfo={true}
          testId="products-pagination-bottom"
        />
      )}

        </>
      )}

      {/* The backdrop deliberately carries no close handler: a stray click
          beside the dialog used to discard everything typed into it (#131).
          It closes through Cancel or a successful save. */}
      {showModal && (
        <div
          data-testid="products-form-modal"
          style={{
            position: 'fixed',
            top: 0,
            left: 0,
            right: 0,
            bottom: 0,
            backgroundColor: theme.overlay.backdrop,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 1100,
          }}
        >
          <div
            data-testid="products-form-modal-content"
            style={{
              backgroundColor: theme.colors.bg.card,
              padding: isMobile ? '16px' : '24px',
              borderRadius: isMobile ? 0 : '8px',
              maxWidth: isMobile ? '100%' : '700px',
              width: isMobile ? '100%' : '90%',
              height: isMobile ? '100%' : 'auto',
              maxHeight: isMobile ? '100%' : '90vh',
              overflowY: 'auto' as const,
              boxShadow: isMobile ? 'none' : '0 20px 25px -5px rgba(0, 0, 0, 0.5)',
              display: 'flex',
              flexDirection: isMobile ? 'column' as const : 'row' as const,
              gap: isMobile ? '16px' : '24px',
            }}
          >
            {/* Left Column: Form */}
            <div style={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
              <h2 data-testid="products-form-title" style={{ marginTop: 0, marginBottom: '16px', color: tableColors.cellText }}>
                {modalMode === 'create' ? t('products.createProduct') : t('products.editProduct')}
              </h2>

              {formError && (
                <div
                  data-testid="products-form-error"
                  style={{
                    marginBottom: '12px',
                    padding: '8px',
                    backgroundColor: theme.colors.alert.dangerBg,
                    color: theme.colors.semantic.dangerHover,
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
                  requirement="required"
                  satisfied={hasAnyName(formData.names)}
                  testIdPrefix="products-form-name"
                />
              </div>

              <CategorySelect
                categories={categories}
                value={selectedCategory}
                onChange={setSelectedCategory}
                testId="products-form-category-select"
                label={t('common.category')}
                requirement="required"
                satisfied={Boolean(selectedCategory)}
              />

              {/* The page banner is behind this overlay, and the select the
                  admin is staring at is the one that came up empty. */}
              {categoriesFailed && (
                <div
                  data-testid="products-form-categories-error"
                  style={{
                    marginTop: '-8px',
                    marginBottom: '16px',
                    color: theme.colors.semantic.warningLight,
                    fontSize: '13px',
                  }}
                >
                  {t('products.errors.loadCategories')}
                </div>
              )}

              <div style={{ marginBottom: '20px' }}>
                <FieldLabel
                  htmlFor="products-form-price-input"
                  label={`${t('common.price')} (€)`}
                  requirement="required"
                  satisfied={parsePriceToCents(formData.price) !== null}
                  testId="products-form-price-label"
                />
                <input
                  id="products-form-price-input"
                  data-testid="products-form-price-input"
                  type="number"
                  step="0.01"
                  placeholder="10.50"
                  value={formData.price}
                  onChange={(e) => setFormData({ ...formData, price: e.target.value })}
                  style={{
                    width: '100%',
                    padding: '10px 12px',
                    border: `1px solid ${theme.colors.border.muted}`,
                    borderRadius: '6px',
                    backgroundColor: theme.colors.bg.inputAlt,
                    color: tableColors.cellText,
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
                label={t('products.icon')}
                requirement="optional"
              />

              {/* Jugendschutz (ADR-0045): the legal age this drink requires.
                  The terminal compares it against the member's own age at
                  checkout, offline. Empty is the ordinary state of a drinks
                  list, and the hint has to say so — a Getränkewart should not
                  have to guess whether a blank field means "unrestricted" or
                  "not filled in yet". */}
              <div style={{ marginBottom: isMobile ? '12px' : '20px' }}>
                <FieldLabel
                  htmlFor="products-form-min-age-input"
                  label={t('products.minAge')}
                  requirement="optional"
                  testId="products-form-min-age-label"
                />
                <input
                  id="products-form-min-age-input"
                  data-testid="products-form-min-age-input"
                  type="number"
                  min={1}
                  max={99}
                  step={1}
                  inputMode="numeric"
                  placeholder={t('products.minAgePlaceholder')}
                  value={formData.minAge}
                  onChange={(e) => setFormData({ ...formData, minAge: e.target.value })}
                  style={{
                    width: '100%',
                    padding: '10px 12px',
                    border: `1px solid ${theme.colors.border.muted}`,
                    borderRadius: '6px',
                    backgroundColor: theme.colors.bg.inputAlt,
                    color: tableColors.cellText,
                    fontSize: '14px',
                    boxSizing: 'border-box',
                  }}
                />
                {!isMobile && (
                  <p
                    style={{
                      marginTop: '6px',
                      color: theme.colors.text.secondary,
                      fontSize: '12px',
                      lineHeight: '1.5',
                    }}
                  >
                    {t('products.minAgeHelp')}
                  </p>
                )}
              </div>

              <div style={{ marginBottom: isMobile ? '12px' : '20px' }}>
                <label
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: '8px',
                    color: tableColors.cellText,
                    fontSize: '14px',
                    fontWeight: '500',
                    cursor: 'pointer',
                  }}
                >
                  <input
                    data-testid="products-form-requires-dispenser-checkbox"
                    type="checkbox"
                    checked={formData.requiresDispenser}
                    onChange={(e) => setFormData({ ...formData, requiresDispenser: e.target.checked })}
                    style={{
                      width: '18px',
                      height: '18px',
                      cursor: 'pointer',
                    }}
                  />
                  {t('products.requiresDispenser')}
                </label>
                {!isMobile && (
                  <p
                    style={{
                      marginTop: '6px',
                      marginLeft: '26px',
                      color: theme.colors.text.secondary,
                      fontSize: '12px',
                      lineHeight: '1.5',
                    }}
                  >
                    {t('products.requiresDispenserHelp')}
                  </p>
                )}
              </div>

              <div style={{ display: 'flex', gap: '10px', justifyContent: 'flex-end', marginTop: isMobile ? '16px' : 'auto' }}>
                <button
                  data-testid="products-form-cancel-button"
                  type="button"
                  onClick={handleCancel}
                  style={{
                    padding: '8px 16px',
                    backgroundColor: theme.colors.border.input,
                    color: tableColors.cellText,
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
                    backgroundColor: theme.colors.semantic.primary,
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

            {/* Right Column: Preview - hidden on mobile */}
            {!isMobile && (
              <div style={{ width: '160px', display: 'flex', flexDirection: 'column', justifyContent: 'center' }}>
                <div style={{ marginBottom: '12px', color: theme.colors.text.muted, fontSize: '12px', fontWeight: '500', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                  {t('common.terminalPreview')}
                </div>
                <ProductPreview
                  name={getLocalizedName(formData.names, i18n.language)}
                  price={formData.price}
                  iconName={selectedIcon}
                />
              </div>
            )}
          </div>
        </div>
      )}

      {/* Confirmation Dialog */}
      <ConfirmDialog
        isOpen={!!confirmDialog}
        message={confirmDialog?.message ?? ''}
        confirmLabel={t('common.delete')}
        variant="danger"
        onConfirm={confirmAction}
        onCancel={cancelConfirmation}
      />
    </div>
  )
}
