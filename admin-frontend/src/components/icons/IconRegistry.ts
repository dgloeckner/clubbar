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
  'WeizenNewIcon',
  'BeerAFIcon',
  'RadlerIcon',
  'LemonadeIcon',
  'LimonadeIcon',
  'AppleJuiceIcon',
  'ApfelschorleIcon',
  'OrangensaftIcon',
  'ApplerIcon',
  'BembelIcon',
  'CoffeeMugIcon',
  'RotweinIcon',
  'WeissweinIcon',
  'WaterLargeIcon',
  'WaterSmallIcon',
  'SaunaTokenIcon',
  'SaunaThermometerIcon',
  'SaunaTimeIcon',
  'SaunaCabinIcon',
  'SaunaAufgussIcon',
  'SaunaIceIcon',
  'SaunaShowerIcon',
  'SaunaTowelIcon',
  'SaunaWellnessIcon',
  'SaunaWhiskIcon',
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
  WeizenNewIcon: ProductIcons.WeizenNewIcon,
  BeerAFIcon: ProductIcons.BeerAFIcon,
  RadlerIcon: ProductIcons.RadlerIcon,
  LemonadeIcon: ProductIcons.LemonadeIcon,
  LimonadeIcon: ProductIcons.LimonadeIcon,
  AppleJuiceIcon: ProductIcons.AppleJuiceIcon,
  ApfelschorleIcon: ProductIcons.ApfelschorleIcon,
  OrangensaftIcon: ProductIcons.OrangensaftIcon,
  ApplerIcon: ProductIcons.ApplerIcon,
  BembelIcon: ProductIcons.BembelIcon,
  CoffeeMugIcon: ProductIcons.CoffeeMugIcon,
  RotweinIcon: ProductIcons.RotweinIcon,
  WeissweinIcon: ProductIcons.WeissweinIcon,
  WaterLargeIcon: ProductIcons.WaterLargeIcon,
  WaterSmallIcon: ProductIcons.WaterSmallIcon,
  SaunaTokenIcon: ProductIcons.SaunaTokenIcon,
  SaunaThermometerIcon: ProductIcons.SaunaThermometerIcon,
  SaunaTimeIcon: ProductIcons.SaunaTimeIcon,
  SaunaCabinIcon: ProductIcons.SaunaCabinIcon,
  SaunaAufgussIcon: ProductIcons.SaunaAufgussIcon,
  SaunaIceIcon: ProductIcons.SaunaIceIcon,
  SaunaShowerIcon: ProductIcons.SaunaShowerIcon,
  SaunaTowelIcon: ProductIcons.SaunaTowelIcon,
  SaunaWellnessIcon: ProductIcons.SaunaWellnessIcon,
  SaunaWhiskIcon: ProductIcons.SaunaWhiskIcon,
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
 * Canonical icon name to legacy icon name mapping
 * Maps docs/icon-registry.md canonical names to legacy component names
 */
const CANONICAL_TO_LEGACY: Record<string, ProductIconName> = {
  // Beverages - Beer
  'beer-pils': 'PilsIcon',
  'beer-weizen': 'WeizenIcon',
  'beer-radler': 'RadlerIcon',
  'beer-alcohol-free': 'BeerAFIcon',

  // Beverages - Cider & Spritzers
  'cider-apfelwein': 'BembelIcon',
  'spritzer-apple': 'ApfelschorleIcon',

  // Beverages - Hot Drinks
  'coffee': 'CoffeeMugIcon',

  // Beverages - Water & Soft Drinks
  'water': 'WaterLargeIcon',
  'water-large': 'WaterLargeIcon',
  'soda': 'LemonadeIcon',

  // Services - Sauna
  'sauna-session': 'SaunaTimeIcon',
  'sauna-cabin': 'SaunaCabinIcon',
  'sauna-infusion': 'SaunaAufgussIcon',
  'sauna-towel': 'SaunaTowelIcon',
}

/**
 * Get product icon component by name with fallback to PackageIcon
 * Supports both canonical names (beer-pils) and legacy names (PilsIcon)
 *
 * @param iconName - Icon name from database (nullable)
 * @returns React component for the icon
 */
export function getProductIcon(iconName?: string | null): React.FC<IconProps> {
  if (!iconName) return PackageIcon

  // Try canonical name first, then legacy name
  const legacyName = CANONICAL_TO_LEGACY[iconName] || iconName
  const icon = ProductIconRegistry[legacyName as ProductIconName]
  return icon || PackageIcon
}

/**
 * Get category icon component by name with fallback to PackageIcon
 * Categories use the same universal product icon set.
 * Supports both canonical names (beer-pils) and legacy names (PilsIcon)
 *
 * @param iconName - Icon name from database (nullable)
 * @returns React component for the icon
 */
export function getCategoryIcon(iconName?: string | null): React.FC<IconProps> {
  if (!iconName) return PackageIcon

  // Try canonical name first, then legacy name (same as products)
  const legacyName = CANONICAL_TO_LEGACY[iconName] || iconName
  const icon = ProductIconRegistry[legacyName as ProductIconName]
  return icon || PackageIcon
}
