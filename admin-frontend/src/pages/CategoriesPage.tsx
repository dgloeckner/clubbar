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
import { useTranslation } from 'react-i18next'
import axios from 'axios'
import { getProducts } from '../api/generated/products/products'
import type {
  Category,
  CategoryCreateRequest,
  CategoryUpdateRequest,
} from '../api/generated'
import { theme } from '../styles/design-system'
import { getLocalizedName, hasAnyName } from '../utils/i18n-helpers'
import { EditIcon, TrashIcon, PlusIcon } from '../components/icons'
import { IconSelect } from '../components/forms/IconSelect'
import { LanguageTabsInput } from '../components/forms/LanguageTabsInput'
import { PillFilter } from '../components/forms/PillFilter'
import { activeStatusOptions } from '../components/forms/filterOptions'
import { getCategoryIcon } from '../components/icons/IconRegistry'
import { IconCell } from '../components/tables/IconCell'
import { StatusToggleCell } from '../components/tables/StatusToggleCell'
import { SortableTableHeader } from '../components/tables/SortableTableHeader'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { Toggle } from '../components/common/Toggle'
import { MobileFilterRow } from '../components/tables/MobileFilterRow'
import { MobileToolbar } from '../components/layout/MobileToolbar'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { useListQuery } from '../hooks/useListQuery'
import {
  tableWrapperStyles,
  tableElementStyles,
  headerRowStyle,
  headerCellBaseStyle,
  tableColors,
  tableSpacing,
  getRowStyle,
} from '../styles/tableTokens'
import { ConfirmDialog } from '../components/modals/ConfirmDialog'

// Runtime type with required fields
type CategoryRuntime = Category & {
  id: string
  names: { [lang: string]: string }
  is_active: boolean
  product_count: number
  created_at: string
}

type CategorySortKey = 'name' | 'created_at'

interface CategoryFilters {
  status: 'all' | 'active' | 'inactive'
  /**
   * The active UI language is part of the query because the name sort depends
   * on it: sorting by "name" in the English UI must order by the English names.
   * It used to read `names.de || names.en` unconditionally, so the English UI
   * was ordered by German names (#121).
   */
  language: string
}

const PAGE_SIZE = 20

export function CategoriesPage() {
  const { t, i18n } = useTranslation()
  const breakpoint = useBreakpoint()
  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'
  const [showModal, setShowModal] = useState(false)
  const [modalMode, setModalMode] = useState<'create' | 'edit'>('create')
  const [selectedCategory, setSelectedCategory] = useState<CategoryRuntime | null>(null)
  const [formData, setFormData] = useState<{ de: string; en: string }>({ de: '', en: '' })
  const [selectedIcon, setSelectedIcon] = useState<string | null>(null)
  const [formError, setFormError] = useState<string | null>(null)
  const [confirmDialog, setConfirmDialog] = useState<{
    type: 'delete' | 'status'
    categoryId: string
    message: string
  } | null>(null)

  // The categories endpoint returns the whole collection with no query
  // parameters, so filtering, sorting and paging happen here — but they still
  // run through the shared list-query state, so page resets, abort handling and
  // the post-delete page clamp behave exactly as on the server-paged pages.
  const list = useListQuery<CategoryRuntime, CategoryFilters, CategorySortKey>({
    initialFilters: { status: 'all', language: i18n.language },
    initialSortKey: 'created_at',
    initialSortDirection: 'desc',
    initialPageSize: PAGE_SIZE,
    fetcher: async ({ page, pageSize, sortKey, sortDirection, filters, signal }) => {
      const response = await getProducts().listCategories({ signal })
      const all = (response.data ?? []) as CategoryRuntime[]
      if (!Array.isArray(all)) return { items: [], total: 0 }

      const filtered =
        filters.status === 'all'
          ? all
          : all.filter((c) => (filters.status === 'active' ? c.is_active : !c.is_active))

      const direction = sortDirection === 'asc' ? 1 : -1
      const sorted = [...filtered].sort((a, b) => {
        if (sortKey === 'name') {
          const aName = getLocalizedName(a.names as Record<string, string>, filters.language)
          const bName = getLocalizedName(b.names as Record<string, string>, filters.language)
          return aName.localeCompare(bName, filters.language) * direction
        }
        return (a.created_at || '').localeCompare(b.created_at || '') * direction
      })

      const start = (page - 1) * pageSize
      return { items: sorted.slice(start, start + pageSize), total: sorted.length }
    },
    parseError: (err) =>
      axios.isAxiosError(err)
        ? err.response?.data?.message || err.message || 'Failed to load categories'
        : err instanceof Error
          ? err.message
          : 'Failed to load categories',
  })

  const { items: categories, total: totalItems, totalPages, loading, error, setError } = list
  const filterStatus = list.filters.status

  // Switching the UI language must re-sort a name-sorted list, not just relabel it.
  useEffect(() => {
    if (list.filters.language !== i18n.language) list.setFilters({ language: i18n.language })
  }, [i18n.language, list])

  // Mobile state
  const [showMobileFilters, setShowMobileFilters] = useState(false)

  const mobileFilterCount = filterStatus !== 'all' ? 1 : 0

  const mobileSortOptions = [
    { value: 'name_asc', label: t('categories.sortName', 'Name A\u2013Z'), direction: 'asc' as const },
    { value: 'name_desc', label: t('categories.sortNameDesc', 'Name Z\u2013A'), direction: 'desc' as const },
    { value: 'created_at_desc', label: t('categories.sortNewest', 'Newest first'), direction: 'desc' as const },
  ]

  const mobileSortValue = list.sortValue

  /**
   * Every path into and out of the modal goes through these three helpers, so
   * that the five pieces of modal state (mode, selection, names, icon, error)
   * can never drift apart. The desktop create button used to reset only
   * `selectedCategory`, which left the modal in "edit" mode with no category
   * selected — `handleSubmit` then matched neither branch and closed silently
   * without creating anything (#88).
   */
  function openCreateModal() {
    setModalMode('create')
    setSelectedCategory(null)
    setFormData({ de: '', en: '' })
    setSelectedIcon(null)
    setFormError(null)
    setShowModal(true)
  }

  function openEditModal(category: CategoryRuntime) {
    setModalMode('edit')
    setSelectedCategory(category)
    setFormData({ de: category.names?.de || '', en: category.names?.en || '' })
    setSelectedIcon(category.icon_name || null)
    setFormError(null)
    setShowModal(true)
  }

  function closeModal() {
    setShowModal(false)
    setModalMode('create')
    setSelectedCategory(null)
    setFormData({ de: '', en: '' })
    setSelectedIcon(null)
    setFormError(null)
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setFormError(null)

    // Validation: at least one language name required
    if (!hasAnyName(formData)) {
      setFormError(t('validation.atLeastOneLanguage'))
      return
    }

    try {
      // Filter out empty language names - backend requires all values to be non-empty
      const nonEmptyNames = Object.entries(formData)
        .filter(([, name]) => name.trim())
        .reduce((acc, [lang, name]) => ({ ...acc, [lang]: name.trim() }), {} as Record<string, string>)

      if (modalMode === 'create') {
        const createData: CategoryCreateRequest = {
          names: nonEmptyNames,
          icon_name: selectedIcon,
        }
        await getProducts().createCategory(createData)
      } else if (modalMode === 'edit' && selectedCategory) {
        const updateData: CategoryUpdateRequest = {
          names: nonEmptyNames,
          icon_name: selectedIcon,
        }
        await getProducts().updateCategory(selectedCategory.id, updateData)
      } else {
        // Edit mode with no selected category: nothing can be written. Keep the
        // modal open and say so rather than closing as if the save had worked.
        setFormError(t('categories.formStateInvalid'))
        return
      }

      // Close modal immediately
      closeModal()

      // Then reload categories
      await list.reload()
    } catch (err: unknown) {
      if (axios.isAxiosError(err)) {
        setFormError(err.response?.data?.message || err.message || `Failed to ${modalMode} category`)
      } else {
        setFormError(err instanceof Error ? err.message : `Failed to ${modalMode} category`)
      }
    }
  }

  async function handleStatusToggle(category: CategoryRuntime) {
    if (category.is_active) {
      // Deactivating is immediate (no confirmation)
      try {
        await getProducts().updateCategory(category.id, { is_active: false })
        await list.reload()
      } catch (err: unknown) {
        if (axios.isAxiosError(err)) {
          setError(err.response?.data?.message || err.message || 'Failed to deactivate category')
        } else {
          setError(err instanceof Error ? err.message : 'Failed to deactivate category')
        }
      }
    } else {
      // Activating requires confirmation
      const categoryName = getLocalizedName(category.names as Record<string, string>, i18n.language)
      setConfirmDialog({
        type: 'status',
        categoryId: category.id,
        message: t('categories.activateConfirm', { name: categoryName, count: category.product_count }),
      })
    }
  }

  async function handleDelete(category: CategoryRuntime) {
    if (category.product_count > 0) {
      setError(t('categories.cannotDeleteWithProducts', { count: category.product_count }))
      return
    }

    const categoryName = getLocalizedName(category.names as Record<string, string>, i18n.language)
    setConfirmDialog({
      type: 'delete',
      categoryId: category.id,
      message: t('categories.deleteConfirm', { name: categoryName }),
    })
  }

  async function confirmAction() {
    if (!confirmDialog) return

    try {
      if (confirmDialog.type === 'delete') {
        await getProducts().deleteCategory(confirmDialog.categoryId)
      } else if (confirmDialog.type === 'status') {
        const category = categories.find((c) => c.id === confirmDialog.categoryId)
        if (category) {
          await getProducts().updateCategory(confirmDialog.categoryId, {
            is_active: !category.is_active,
          })
        }
      }

      setConfirmDialog(null)
      await list.reload()
    } catch (err: unknown) {
      if (axios.isAxiosError(err)) {
        setError(err.response?.data?.message || err.message || 'Failed to perform action')
      } else {
        setError(err instanceof Error ? err.message : 'Failed to perform action')
      }
      setConfirmDialog(null)
    }
  }

  return (
    <div data-testid="categories-page" style={{ padding: '20px' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', margin: '0 0 20px 0' }}>
        <h1 style={{ margin: 0 }}>{t('categories.title')}</h1>
        {isMobile && (
          <button
            data-testid="categories-create-button"
            onClick={openCreateModal}
            style={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              width: '36px',
              height: '36px',
              background: theme.colors.semantic.primary,
              border: 'none',
              borderRadius: '8px',
              color: 'white',
              cursor: 'pointer',
            }}
          >
            <PlusIcon size={20} />
          </button>
        )}
      </div>

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

      {isMobile ? (
        <>
          <MobileToolbar
            testId="categories-mobile-toolbar"
            sort={{
              options: mobileSortOptions,
              value: mobileSortValue,
              onChange: list.setSortValue,
            }}
            filterCount={mobileFilterCount}
            onFilterToggle={() => setShowMobileFilters(!showMobileFilters)}
            showFilters={showMobileFilters}
            filterContent={
              <MobileFilterRow
                label={t('common.status', 'Status')}
                options={[
                  { value: 'all', label: t('common.all') },
                  { value: 'active', label: t('common.active') },
                  { value: 'inactive', label: t('common.inactive') },
                ]}
                value={filterStatus}
                onChange={(v) => list.setFilter('status', v as CategoryFilters['status'])}
                testId="categories-mobile-filter-status"
              />
            }
          />

          {/* Mobile card list */}
          {loading ? (
            <div data-testid="categories-loading-indicator" style={{ padding: '40px', textAlign: 'center', color: '#94a3b8' }}>
              {t('common.loading')}
            </div>
          ) : categories.length === 0 ? (
            <div data-testid="categories-empty-state" style={{ padding: '40px', textAlign: 'center', color: '#94a3b8' }}>
              {t('categories.noCategories')}
            </div>
          ) : (
            <div data-testid="categories-mobile-cards" style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
              {categories.map((category) => {
                const IconComponent = getCategoryIcon(category.icon_name ?? null)
                return (
                  <div
                    key={category.id}
                    data-testid={`category-card-${category.id}`}
                    style={{
                      background: 'rgba(255,255,255,0.03)',
                      border: '1px solid rgba(255,255,255,0.06)',
                      borderRadius: '10px',
                      padding: '14px 16px',
                    }}
                  >
                    {/* Row 1: toggle + icon + category name */}
                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '8px' }}>
                      <Toggle
                        isEnabled={category.is_active}
                        onChange={() => handleStatusToggle(category)}
                        size="small"
                        testId={`categories-status-toggle-${category.id}`}
                      />
                      {IconComponent && (
                        <span style={{ display: 'flex', alignItems: 'center', color: '#94a3b8' }}>
                          <IconComponent size={16} />
                        </span>
                      )}
                      <span
                        data-testid={`categories-table-cell-name-${category.id}`}
                        style={{ flex: 1, fontWeight: 600, color: '#e2e8f0', fontSize: '14px' }}
                      >
                        {getLocalizedName(category.names as Record<string, string>, i18n.language)}
                      </span>
                    </div>
                    {/* Row 2: product count + actions */}
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', paddingLeft: '46px' }}>
                      <span
                        data-testid={`categories-table-cell-product-count-${category.id}`}
                        style={{ fontSize: '12px', color: '#94a3b8' }}
                      >
                        {category.product_count} {t('categories.productCount', 'Products')}
                      </span>
                      <div style={{ display: 'flex', gap: '8px' }}>
                        <button
                          data-testid={`categories-table-action-edit-${category.id}`}
                          onClick={() => openEditModal(category)}
                          aria-label={t('categories.editCategoryNamed', {
                            name: getLocalizedName(category.names as Record<string, string>, i18n.language),
                          })}
                          style={{
                            display: 'flex', alignItems: 'center', gap: '4px',
                            padding: '6px 12px', borderRadius: '6px', border: 'none',
                            background: 'rgba(59,130,246,0.1)', color: '#3b82f6',
                            fontSize: '12px', cursor: 'pointer',
                          }}
                        >
                          <EditIcon size={14} /> {t('common.edit')}
                        </button>
                        <button
                          data-testid={`categories-table-action-delete-${category.id}`}
                          onClick={() => handleDelete(category)}
                          disabled={category.product_count > 0}
                          title={
                            category.product_count > 0
                              ? t('categories.cannotDeleteWithProducts', { count: category.product_count })
                              : undefined
                          }
                          aria-label={t('categories.deleteCategoryNamed', {
                            name: getLocalizedName(category.names as Record<string, string>, i18n.language),
                          })}
                          style={{
                            display: 'flex', alignItems: 'center', gap: '4px',
                            padding: '6px 12px', borderRadius: '6px', border: 'none',
                            background: category.product_count > 0 ? 'rgba(107,114,128,0.1)' : 'rgba(239,68,68,0.1)',
                            color: category.product_count > 0 ? '#6b7280' : '#ef4444',
                            fontSize: '12px',
                            cursor: category.product_count > 0 ? 'not-allowed' : 'pointer',
                            opacity: category.product_count > 0 ? 0.5 : 1,
                          }}
                        >
                          <TrashIcon size={14} /> {t('common.delete')}
                        </button>
                      </div>
                    </div>
                  </div>
                )
              })}
            </div>
          )}

          {/* Mobile pagination */}
          <PaginationToolbar
            currentPage={list.page}
            totalPages={totalPages}
            totalItems={totalItems}
            pageSize={list.pageSize}
            onPageChange={list.setPage}
            onPageSizeChange={() => {}}
            variant="default"
            showPageSize={false}
            showInfo={true}
            testId="categories-pagination"
          />
        </>
      ) : (
        <>
          {/* Desktop: Search/Filter toolbar */}
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
              <strong style={{ color: theme.colors.text.primary }}>{totalItems}</strong> {t('categories.title')} {t('common.found')}
            </span>

            {/* RIGHT: Filter + Create button */}
            <div style={{ display: 'flex', gap: theme.spacing.md, alignItems: 'center' }}>
              <PillFilter
                value={filterStatus}
                onChange={(status) => list.setFilter('status', status)}
                options={activeStatusOptions(t)}
                variant="solid"
                testId="categories-filter-status"
              />

              <button
                data-testid="categories-create-button"
                onClick={openCreateModal}
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
                <span>{t('common.create')}</span>
              </button>
            </div>
          </div>

          {/* Loading State */}
          {loading ? (
            <div data-testid="categories-loading-indicator" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
              {t('common.loading')}
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
              {t('categories.noCategories')}
            </div>
          ) : (
            <>
              <div data-testid="categories-table-wrapper" style={tableWrapperStyles}>
                <table data-testid="categories-table" style={tableElementStyles}>
                <thead>
                  <tr style={headerRowStyle}>
                    <th style={{ ...headerCellBaseStyle, width: '80px', textAlign: 'center' }}>{t('common.status')}</th>
                    <th style={headerCellBaseStyle}>
                      <SortableTableHeader
                        label={t('common.name')}
                        sortKey="name"
                        currentSort={{ key: list.sortKey, direction: list.sortDirection }}
                        onSort={(key: string, direction: 'asc' | 'desc') => list.setSort(key as CategorySortKey, direction)}
                        testId="categories-sort-name"
                      />
                    </th>
                    <th style={headerCellBaseStyle}>{t('categories.productCount')}</th>
                    <th style={{ ...headerCellBaseStyle, width: '200px', textAlign: 'center' }}>{t('common.actions')}</th>
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
                        icon={getCategoryIcon(category.icon_name ?? null)}
                        label={getLocalizedName(category.names as Record<string, string>, i18n.language)}
                        iconTestId={`categories-table-cell-icon-${category.id}`}
                        labelTestId={`categories-table-cell-name-${category.id}`}
                      />

                      {/* Products Count */}
                      <td style={{ padding: tableSpacing.cellPadding, color: tableColors.cellText }}>
                        <span data-testid={`categories-table-cell-product-count-${category.id}`}>
                          {category.product_count}
                        </span>
                      </td>

                      {/* Actions */}
                      <td style={{ padding: tableSpacing.cellPadding, textAlign: 'center' }}>
                        <button
                          data-testid={`categories-table-action-edit-${category.id}`}
                          onClick={() => openEditModal(category)}
                          style={{
                            background: 'transparent',
                            border: 'none',
                            color: theme.colors.semantic.primary,
                            cursor: 'pointer',
                            padding: theme.spacing.sm,
                          }}
                          title={t('common.edit')}
                          aria-label={t('categories.editCategoryNamed', {
                            name: getLocalizedName(category.names as Record<string, string>, i18n.language),
                          })}
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
                          title={
                            category.product_count > 0
                              ? t('categories.cannotDeleteWithProducts', { count: category.product_count })
                              : t('common.delete')
                          }
                          aria-label={t('categories.deleteCategoryNamed', {
                            name: getLocalizedName(category.names as Record<string, string>, i18n.language),
                          })}
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
                currentPage={list.page}
                totalPages={totalPages}
                totalItems={totalItems}
                pageSize={list.pageSize}
                onPageChange={list.setPage}
                onPageSizeChange={() => {}}
                variant="default"
                showPageSize={false}
                showInfo={true}
                testId="categories-pagination"
              />
            </>
          )}
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
            zIndex: 1100,
          }}
          onClick={closeModal}
        >
          <div
            data-testid="categories-form-modal-content"
            style={{
              background: theme.colors.bg.secondary,
              borderRadius: isMobile ? 0 : theme.borderRadius.lg,
              padding: isMobile ? theme.spacing.lg : theme.spacing.xl,
              maxWidth: isMobile ? '100%' : '500px',
              width: isMobile ? '100%' : '90%',
              height: isMobile ? '100%' : 'auto',
              maxHeight: isMobile ? '100%' : '90vh',
              overflowY: 'auto' as const,
              boxShadow: isMobile ? 'none' : '0 25px 50px rgba(0, 0, 0, 0.5)',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            <h2 data-testid="categories-form-title" style={{ margin: '0 0 20px 0' }}>
              {modalMode === 'create' ? t('categories.createCategory') : t('categories.editCategory')}
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

            {/* Category Name with Language Tabs */}
            <div style={{ marginBottom: theme.spacing.lg }}>
              <LanguageTabsInput
                values={formData}
                onChange={setFormData}
                label={t('categories.categoryName')}
                placeholder={t('categories.categoryName')}
                required
                testIdPrefix="categories-form-name"
              />
            </div>

            <IconSelect
              value={selectedIcon}
              onChange={setSelectedIcon}
              iconType="category"
              testId="categories-form-icon-select"
              label={`${t('products.icon')} (${t('common.optional')})`}
            />

            {/* Buttons */}
            <div style={{ display: 'flex', gap: theme.spacing.lg, justifyContent: 'flex-end', marginTop: theme.spacing.xl }}>
              <button
                data-testid="categories-form-cancel-button"
                type="button"
                onClick={closeModal}
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
                {t('common.cancel')}
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
                {modalMode === 'create' ? t('common.create') : t('common.save')}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Confirmation Dialog */}
      <ConfirmDialog
        isOpen={!!confirmDialog}
        title={confirmDialog?.type === 'delete' ? t('categories.deleteCategory') : undefined}
        message={confirmDialog?.message ?? ''}
        confirmLabel={confirmDialog?.type === 'delete' ? t('common.delete') : t('common.confirm')}
        variant={confirmDialog?.type === 'delete' ? 'danger' : 'primary'}
        onConfirm={confirmAction}
        onCancel={() => setConfirmDialog(null)}
      />
    </div>
  )
}
