/**
 * Icon Registry - Type-safe mapping of icon names to React components
 *
 * Central registry that maps canonical icon name strings (stored in database) to React components.
 * Uses kebab-case naming convention for consistency with backend and Flutter.
 * Provides type-safe lookups with fallback defaults.
 *
 * Usage:
 *   const Icon = getProductIcon('beer-pils')
 *   <Icon size={20} />
 *
 *   const Icon = getCategoryIcon('coffee')
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
 * Uses canonical kebab-case naming convention
 */
export const PRODUCT_ICON_NAMES = [
  // Beverages - Beer
  'beer-pils',
  'beer-weizen',
  'beer-weizen-new',
  'beer-alcohol-free',
  'beer-radler',

  // Beverages - Cider & Spritzers
  'cider-apfelwein',
  'cider-appler',
  'spritzer-apple',

  // Beverages - Soft Drinks
  'soda-lemonade',
  'soda-limonade',
  'juice-apple',
  'juice-orange',

  // Beverages - Hot Drinks
  'coffee',

  // Beverages - Wine
  'wine-red',
  'wine-white',

  // Beverages - Water
  'water',
  'water-large',
  'water-small',

  // Services - Sauna
  'sauna-token',
  'sauna-thermometer',
  'sauna-session',
  'sauna-cabin',
  'sauna-infusion',
  'sauna-ice',
  'sauna-shower',
  'sauna-towel',
  'sauna-wellness',
  'sauna-whisk',

  // Food
  'food-bratwurst',
  'food-hamburger',
  'food-fish-sandwich',
  'food-crisps',
  'food-fries',
  'food-bretzel',
  'food-crackers',
  'food-steak',
  'food-salad',
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
 * Product icon registry - Maps canonical icon names to React components
 */
export const ProductIconRegistry: Record<ProductIconName, React.FC<IconProps>> = {
  // Beverages - Beer
  'beer-pils': ProductIcons.PilsIcon,
  'beer-weizen': ProductIcons.WeizenIcon,
  'beer-weizen-new': ProductIcons.WeizenNewIcon,
  'beer-alcohol-free': ProductIcons.BeerAFIcon,
  'beer-radler': ProductIcons.RadlerIcon,

  // Beverages - Cider & Spritzers
  'cider-apfelwein': ProductIcons.BembelIcon,
  'cider-appler': ProductIcons.ApplerIcon,
  'spritzer-apple': ProductIcons.ApfelschorleIcon,

  // Beverages - Soft Drinks
  'soda-lemonade': ProductIcons.LemonadeIcon,
  'soda-limonade': ProductIcons.LimonadeIcon,
  'juice-apple': ProductIcons.AppleJuiceIcon,
  'juice-orange': ProductIcons.OrangensaftIcon,

  // Beverages - Hot Drinks
  'coffee': ProductIcons.CoffeeMugIcon,

  // Beverages - Wine
  'wine-red': ProductIcons.RotweinIcon,
  'wine-white': ProductIcons.WeissweinIcon,

  // Beverages - Water
  'water': ProductIcons.WaterLargeIcon,
  'water-large': ProductIcons.WaterLargeIcon,
  'water-small': ProductIcons.WaterSmallIcon,

  // Services - Sauna
  'sauna-token': ProductIcons.SaunaTokenIcon,
  'sauna-thermometer': ProductIcons.SaunaThermometerIcon,
  'sauna-session': ProductIcons.SaunaTimeIcon,
  'sauna-cabin': ProductIcons.SaunaCabinIcon,
  'sauna-infusion': ProductIcons.SaunaAufgussIcon,
  'sauna-ice': ProductIcons.SaunaIceIcon,
  'sauna-shower': ProductIcons.SaunaShowerIcon,
  'sauna-towel': ProductIcons.SaunaTowelIcon,
  'sauna-wellness': ProductIcons.SaunaWellnessIcon,
  'sauna-whisk': ProductIcons.SaunaWhiskIcon,

  // Food
  'food-bratwurst': ProductIcons.BratwurstIcon,
  'food-hamburger': ProductIcons.HamburgerIcon,
  'food-fish-sandwich': ProductIcons.FishSandwichIcon,
  'food-crisps': ProductIcons.CrispsIcon,
  'food-fries': ProductIcons.FriesIcon,
  'food-bretzel': ProductIcons.BretzelIcon,
  'food-crackers': ProductIcons.CrackersIcon,
  'food-steak': ProductIcons.SteakIcon,
  'food-salad': ProductIcons.SaladIcon,
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
 * Get product icon component by canonical name with fallback to PackageIcon
 *
 * @param iconName - Canonical icon name from database (nullable)
 * @returns React component for the icon
 */
export function getProductIcon(iconName?: string | null): React.FC<IconProps> {
  if (!iconName) return PackageIcon

  const icon = ProductIconRegistry[iconName as ProductIconName]
  return icon || PackageIcon
}

/**
 * Get category icon component by canonical name with fallback to PackageIcon
 * Categories use the same universal product icon set.
 *
 * @param iconName - Canonical icon name from database (nullable)
 * @returns React component for the icon
 */
export function getCategoryIcon(iconName?: string | null): React.FC<IconProps> {
  if (!iconName) return PackageIcon

  const icon = ProductIconRegistry[iconName as ProductIconName]
  return icon || PackageIcon
}
