/**
 * Search and Sort Toolbar
 * Top toolbar with summary info, category filter, and sort options
 * Placed above the paginated table
 *
 * Props:
 * - totalItems: Total number of items
 * - sortOptions: Array of sort options for dropdown
 * - currentSort: Currently selected sort value
 * - onSortChange: Callback when sort selection changes
 * - categories: Array of categories for filter
 * - selectedCategory: Currently selected category ID (null for all)
 * - onCategoryChange: Callback when category selection changes
 * - testId: Base test ID
 */

import { CategoryFilter } from './CategoryFilter'
import { SortDropdown } from './SortDropdown'

interface SortOption {
  value: string
  label: string
  direction: 'asc' | 'desc'
}

interface Category {
  id: string
  names: { [lang: string]: string }
  is_active: boolean
}

interface SearchAndSortToolbarProps {
  totalItems: number
  sortOptions: SortOption[]
  currentSort: string
  onSortChange: (value: string) => void
  categories: Category[]
  selectedCategory: string | null
  onCategoryChange: (categoryId: string | null) => void
  testId?: string
}

export function SearchAndSortToolbar({
  totalItems,
  sortOptions,
  currentSort,
  onSortChange,
  categories,
  selectedCategory,
  onCategoryChange,
  testId = 'search-sort-toolbar',
}: SearchAndSortToolbarProps) {
  return (
    <div
      data-testid={`${testId}-container`}
      style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        gap: 16,
        marginBottom: 16,
        flexWrap: 'wrap',
      }}
    >
      {/* Left: Summary */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <span data-testid={`${testId}-summary`} style={{ color: '#cbd5e1', fontSize: 14 }}>
          <strong style={{ color: '#e2e8f0' }}>{totalItems}</strong> Produkte gefunden
        </span>
      </div>

      {/* Right: Category filter + Sort dropdown */}
      <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
        <CategoryFilter
          categories={categories}
          value={selectedCategory}
          onChange={onCategoryChange}
          testId={`${testId}-category`}
        />
        <SortDropdown
          options={sortOptions}
          value={currentSort}
          onChange={onSortChange}
          label="Sortieren"
          testId={`${testId}-sort`}
        />
      </div>
    </div>
  )
}
