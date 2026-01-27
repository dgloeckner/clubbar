/**
 * Icon Registry - Type-safe mapping of icon names to React components
 *
 * Central registry that maps icon name strings (stored in database) to React components.
 * Provides type-safe lookups with fallback defaults.
 *
 * Usage:
 *   const Icon = getProductIcon('PilsIcon')
 *   <Icon size={20} />
 *
 *   const Icon = getCategoryIcon('CategoryIcon')
 *   <Icon size={20} />
 */

import { IconProps } from './types'
import { PackageIcon } from './PackageIcon'

// Product icon imports
import * as ProductIcons from './product-icons'

// Category icon imports
import * as CategoryIcons from './category-icons'

/**
 * Product icon names - Type-safe list of available product icons
 */
export const PRODUCT_ICON_NAMES = [
  'PilsIcon',
  'WeizenIcon',
  'BeerAFIcon',
  'RadlerIcon',
  'LemonadeIcon',
  'AppleJuiceIcon',
  'ApplerIcon',
  'WaterLargeIcon',
  'WaterSmallIcon',
  'SaunaTokenIcon',
  'SaunaThermometerIcon',
  'SaunaTimeIcon',
  'SaunaCabinIcon',
] as const

export type ProductIconName = (typeof PRODUCT_ICON_NAMES)[number]

/**
 * Category icon names - Reuse product icons for categories
 * Categories can use the same product icons (beverages use drink icons, etc.)
 */
export const CATEGORY_ICON_NAMES = PRODUCT_ICON_NAMES

export type CategoryIconName = ProductIconName

/**
 * Product icon registry - Maps icon names to components
 */
export const ProductIconRegistry: Record<ProductIconName, React.FC<IconProps>> = {
  PilsIcon: ProductIcons.PilsIcon,
  WeizenIcon: ProductIcons.WeizenIcon,
  BeerAFIcon: ProductIcons.BeerAFIcon,
  RadlerIcon: ProductIcons.RadlerIcon,
  LemonadeIcon: ProductIcons.LemonadeIcon,
  AppleJuiceIcon: ProductIcons.AppleJuiceIcon,
  ApplerIcon: ProductIcons.ApplerIcon,
  WaterLargeIcon: ProductIcons.WaterLargeIcon,
  WaterSmallIcon: ProductIcons.WaterSmallIcon,
  SaunaTokenIcon: ProductIcons.SaunaTokenIcon,
  SaunaThermometerIcon: ProductIcons.SaunaThermometerIcon,
  SaunaTimeIcon: ProductIcons.SaunaTimeIcon,
  SaunaCabinIcon: ProductIcons.SaunaCabinIcon,
}

/**
 * Category icon registry - Reuses product icons
 * For category icon selection, use the same product icons
 */
export const CategoryIconRegistry: Record<CategoryIconName, React.FC<IconProps>> = ProductIconRegistry

/**
 * Navigation category icons - Generic category representations
 * Reserved for category navigation bars and UI elements
 */
export const NavigationCategoryIcons = {
  CategoryIcon: CategoryIcons.CategoryIcon,
  CategoryTagsIcon: CategoryIcons.CategoryTagsIcon,
  CategoryLayersIcon: CategoryIcons.CategoryLayersIcon,
  CategoryFolderIcon: CategoryIcons.CategoryFolderIcon,
  CategoryListIcon: CategoryIcons.CategoryListIcon,
}

/**
 * Get product icon component by name with fallback to PackageIcon
 *
 * @param iconName - Icon name from database (nullable)
 * @returns React component for the icon
 */
export function getProductIcon(iconName?: string | null): React.FC<IconProps> {
  if (!iconName) return PackageIcon
  const icon = ProductIconRegistry[iconName as ProductIconName]
  return icon || PackageIcon
}

/**
 * Get category icon component by name with fallback to CategoryIcon
 * Categories use product icons, with CategoryIcon (grid) as default
 *
 * @param iconName - Icon name from database (nullable)
 * @returns React component for the icon
 */
export function getCategoryIcon(iconName?: string | null): React.FC<IconProps> {
  if (!iconName) return CategoryIcons.CategoryIcon
  const icon = CategoryIconRegistry[iconName as CategoryIconName]
  return icon || CategoryIcons.CategoryIcon
}
