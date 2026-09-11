/**
 * The terminal product tile's geometry and palette, mirrored for the panel.
 *
 * The product form previews the tile a member will see, and it does that by
 * drawing the terminal's own card rather than a panel-styled approximation of
 * it (ADR-0056; the widget is `ProductCard` in
 * `terminal-frontend/lib/widgets/styled_components/product_card.dart`).
 *
 * The numbers are `ProductTileMetrics`
 * (`terminal-frontend/lib/utils/product_grid_layout.dart`) at
 * `AppFontSizes.productNameFloor` — the one scale that is the same on every
 * installation, since a club raising `productNameMin` in `config.json` scales
 * the whole tile from there. The colours come from the panel's own tokens,
 * which already carry the terminal's values for the card, its border and its
 * text; the three tints and the sky price are stated here because only the tile
 * uses them.
 *
 * Dart and TypeScript share no module, so this is a copy, and a copy can drift.
 * `ProductPreview.test.tsx` pins the relationships the terminal's own widget
 * tests hold — one name line, a reserved volume row, a price derived from the
 * name — so a change made on one side and not the other fails the unit suite
 * rather than slowly making the preview a lie.
 */

import { theme, withAlpha } from './design-system'

export const TERMINAL_TILE = {
  /** `AppFontSizes.xxxl`, the default `productNameFloor`. */
  nameFontSize: 26,
  /** `AppSpacing.md` — the padding inside the card. */
  padding: 12,
  /** `AppSpacing.sm` — under the icon and under the volume row. */
  gap: 8,
  /** `metrics.lineHeight`, pinned so the text block is exactly this tall. */
  lineHeight: 1.2,
  /** `metrics.nameLines` — **one**, since the size moved out of the name. */
  nameLines: 1,
  /** `metrics.baseIconSize` at the floor. */
  iconSize: 52,
  /** `metrics.volumeRowScale` × the name size. */
  volumeRowScale: 0.67,
  /** `metrics.volumeTextScale` × the name size. */
  volumeTextScale: 0.4,
  /** `metrics.priceScale` × the name size, never below `priceFloor`. */
  priceScale: 0.9,
  /** `AppFontSizes.xxl` — the price's floor. */
  priceFloor: 24,
  /** `metrics.pricePillPadding` / `.pricePillBorder`. */
  pricePillPaddingY: 4,
  pricePillPaddingX: 12,
  pricePillBorder: 1,
  /** `AppBorderRadius.lg` and `.full`. */
  radius: 16,
  radiusFull: 9999,
  colors: {
    /** `AppColors.bgCard` — the same value the panel already calls `bg.card`. */
    card: theme.colors.bg.card,
    /** `AppColors.borderLight`. */
    border: theme.colors.border.light,
    /** `AppColors.textPrimary` / `.textSecondary`. */
    name: theme.colors.text.primary,
    volume: theme.colors.text.secondary,
    /** `AppColors.bgVolumeBadge` — `textSecondary` at 12% over the card. */
    volumeBadge: withAlpha(theme.colors.text.secondary, 0.12),
    /** `AppColors.bgPricePill` — `semantic.info` at 22%. */
    pricePill: withAlpha(theme.colors.semantic.info, 0.22),
    /** `AppColors.borderPricePill` — the price colour at 45%. */
    pricePillBorder: withAlpha(theme.colors.semantic.infoLight, 0.45),
    /** `AppColors.infoOnTint`. */
    price: theme.colors.semantic.infoLight,
  },
} as const

/** The fixed box the name sits in, bottom-aligned — `nameLines` of it. */
export const TILE_NAME_BOX_HEIGHT =
  TERMINAL_TILE.lineHeight * TERMINAL_TILE.nameLines * TERMINAL_TILE.nameFontSize

/** The volume badge's row, reserved whether or not the product has a size. */
export const TILE_VOLUME_ROW_HEIGHT = TERMINAL_TILE.volumeRowScale * TERMINAL_TILE.nameFontSize

/** The badge's own text — small on purpose; a member confirms the size last. */
export const TILE_VOLUME_FONT_SIZE = TERMINAL_TILE.volumeTextScale * TERMINAL_TILE.nameFontSize

/** The price — the loudest thing on the tile, derived from the name's size. */
export const TILE_PRICE_FONT_SIZE = Math.max(
  TERMINAL_TILE.priceFloor,
  TERMINAL_TILE.priceScale * TERMINAL_TILE.nameFontSize,
)
