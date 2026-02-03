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
 *   const Icon = getCategoryIcon('PilsIcon')
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
 * All icon names - Universal set used for both products and categories.
 * Categories use the same product icons (e.g. a "Drinks" category shows a beer icon).
 */
export const ALL_ICON_NAMES = PRODUCT_ICON_NAMES

/**
 * Category icon names - Categories use the universal (product) icon set.
 * The old "CategoryIcon" variants are navigation UI elements, not category representations.
 */
export const CATEGORY_ICON_NAMES = PRODUCT_ICON_NAMES

export type CategoryIconName = ProductIconName

/**
 * Navigation icon names - Generic UI icons for category navigation bars
 * These are NOT for representing individual categories.
 */
export const NAVIGATION_ICON_NAMES = [
  'CategoryIcon',
  'CategoryTagsIcon',
  'CategoryLayersIcon',
  'CategoryFolderIcon',
  'CategoryListIcon',
] as const

export type NavigationIconName = (typeof NAVIGATION_ICON_NAMES)[number]

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
 * Category icon registry - Categories use the universal product icon set
 */
export const CategoryIconRegistry: Record<CategoryIconName, React.FC<IconProps>> = ProductIconRegistry

/**
 * Navigation icon registry - Generic UI icons for category navigation bars
 * These are NOT for representing individual categories.
 */
export const NavigationIconRegistry: Record<string, React.FC<IconProps>> = {
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
 * Get category icon component by name with fallback to PackageIcon
 * Categories use the same universal product icon set.
 *
 * @param iconName - Icon name from database (nullable)
 * @returns React component for the icon
 */
export function getCategoryIcon(iconName?: string | null): React.FC<IconProps> {
  if (!iconName) return PackageIcon
  const icon = ProductIconRegistry[iconName as ProductIconName]
  return icon || PackageIcon
}
