/**
 * Product Icons Registry
 *
 * Maps canonical icon names from docs/icon-registry.md to React icon components.
 * This is the single source of truth for admin frontend icon display.
 *
 * IMPORTANT: Icon names must match docs/icon-registry.md exactly.
 * Use kebab-case format (e.g., "beer-pils", "sauna-session").
 */

import React from 'react'
import {
  PilsIcon,
  WeizenIcon,
  BeerAFIcon,
  RadlerIcon,
  ApfelschorleIcon,
  BembelIcon,
  CoffeeMugIcon,
  WaterLargeIcon,
  SaunaTimeIcon,
  SaunaCabinIcon,
  SaunaAufgussIcon,
  SaunaTowelIcon,
} from '../components/icons/product-icons'
import { PackageIcon } from '../components/icons/PackageIcon'

/**
 * Icon component type
 */
export type ProductIconComponent = React.FC<{ size?: number }>

/**
 * Get icon component for a canonical product icon name
 *
 * @param iconName - Canonical icon name from docs/icon-registry.md
 * @returns React icon component or fallback PackageIcon
 *
 * @example
 * const BeerIcon = getProductIcon('beer-pils');
 * <BeerIcon size={20} />
 */
export function getProductIcon(iconName: string | null | undefined): ProductIconComponent {
  if (!iconName) return PackageIcon

  const normalized = iconName.toLowerCase()
  return PRODUCT_ICONS[normalized] ?? PackageIcon
}

/**
 * Canonical icon name to React component mapping
 *
 * Keys match docs/icon-registry.md exactly (kebab-case).
 * Values are React icon components from components/icons/product-icons/
 */
const PRODUCT_ICONS: Record<string, ProductIconComponent> = {
  // === CANONICAL NAMES (from docs/icon-registry.md) ===

  // Beverages - Beer
  'beer-pils': PilsIcon,
  'beer-weizen': WeizenIcon,
  'beer-radler': RadlerIcon,
  'beer-alcohol-free': BeerAFIcon,

  // Beverages - Cider & Spritzers
  'cider-apfelwein': BembelIcon,
  'spritzer-apple': ApfelschorleIcon,

  // Beverages - Hot Drinks
  coffee: CoffeeMugIcon,

  // Beverages - Water & Soft Drinks
  'water-large': WaterLargeIcon,

  // Services - Sauna
  'sauna-session': SaunaTimeIcon,
  'sauna-cabin': SaunaCabinIcon,
  'sauna-infusion': SaunaAufgussIcon,
  'sauna-towel': SaunaTowelIcon,
}

/**
 * Get all canonical icon names
 *
 * @returns Array of canonical icon names (kebab-case)
 */
export function getCanonicalIconNames(): string[] {
  return Object.keys(PRODUCT_ICONS).filter((name) => !name.includes('icon'))
}

/**
 * Validate if an icon name is canonical (not legacy)
 *
 * @param iconName - Icon name to validate
 * @returns true if canonical, false if legacy or unknown
 */
export function isCanonicalIconName(iconName: string): boolean {
  const normalized = iconName.toLowerCase()
  return !normalized.includes('icon') && normalized in PRODUCT_ICONS
}
