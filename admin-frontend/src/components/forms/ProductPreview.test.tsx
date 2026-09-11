// @vitest-environment jsdom

/**
 * The preview is a copy of the terminal's tile, and a copy is a thing that can
 * drift (ADR-0056; the layout is `ProductCard` in
 * `terminal-frontend/lib/widgets/styled_components/product_card.dart`).
 *
 * These tests pin what the layout *is* — one name line, the size in the price
 * pill, a price derived from the name — rather than a screenshot of it, because those
 * are the properties the terminal's own widget tests hold on the other side. A
 * change on one side that is not made on the other fails here.
 */

import { render, screen, cleanup } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { ProductPreview } from './ProductPreview'
import { TERMINAL_TILE } from '../../styles/terminalTile'

// `jest-dom`'s matchers are not installed in this project, so the assertions
// below read the DOM directly.

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
    i18n: { language: 'de' },
  }),
}))

afterEach(cleanup)

function renderPreview(props: Partial<Parameters<typeof ProductPreview>[0]> = {}) {
  render(
    <ProductPreview
      name={props.name ?? 'Weizenbier'}
      price={props.price ?? '4,20'}
      iconName={props.iconName ?? null}
      volumeMl={props.volumeMl ?? null}
    />,
  )
}

describe('ProductPreview — the terminal tile', () => {
  it('puts the name on one line and ellipsises it, as the tile does', () => {
    // The terminal has one name line since the size moved out of the name. A
    // preview that wrapped would show an admin a tile the terminal never draws.
    renderPreview({ name: 'Alkoholfreies Weizenbier vom Fass' })

    const name = screen.getByTestId('products-preview-name')
    expect(name.style.whiteSpace).toBe('nowrap')
    expect(name.style.textOverflow).toBe('ellipsis')
    expect(name.style.overflow).toBe('hidden')
    expect(name.textContent).toBe('Alkoholfreies Weizenbier vom Fass')
  })

  it('draws the name as the headline — larger than the price', () => {
    // #369: the price used to be louder than the thing it was the price for.
    renderPreview()

    const name = screen.getByTestId('products-preview-name')
    const price = screen.getByTestId('products-preview-price')
    expect(parseFloat(name.style.fontSize)).toBe(TERMINAL_TILE.nameFontSize)
    expect(parseFloat(price.style.fontSize)).toBeLessThan(TERMINAL_TILE.nameFontSize)
    expect(parseFloat(price.style.fontSize)).toBe(
      Math.max(TERMINAL_TILE.priceFloor, TERMINAL_TILE.priceScale * TERMINAL_TILE.nameFontSize),
    )
  })

  it('draws the price in a pill rather than as flat text', () => {
    renderPreview({ price: '4,20' })

    const pill = screen.getByTestId('products-preview-price-pill')
    const price = screen.getByTestId('products-preview-price')
    expect(price.style.backgroundColor).toBe(TERMINAL_TILE.colors.pricePill)
    expect(pill.style.borderRadius).toBe(`${TERMINAL_TILE.radiusFull}px`)
    expect(pill.style.borderWidth).toBe(`${TERMINAL_TILE.pricePillBorder}px`)
    expect(price.textContent).toContain('4,20')
  })

  it('puts the size in the price pill, before the amount, in litres', () => {
    // Picked as 500 ml, read as 0,5 l — the whole point of storing one
    // language-neutral number (ADR-0056). Read with the price, as one fact.
    renderPreview({ volumeMl: 500 })

    const badge = screen.getByTestId('products-preview-volume')
    expect(screen.getByTestId('products-preview-price-pill').firstElementChild).toBe(badge)
    expect(parseFloat(badge.style.fontSize)).toBeLessThan(
      parseFloat(screen.getByTestId('products-preview-price').style.fontSize),
    )
    expect(badge.textContent?.replace(/\u00a0/g, ' ')).toBe('0,5 l')
    expect(badge.style.backgroundColor).toBe(TERMINAL_TILE.colors.volumeBadge)
    expect(parseFloat(badge.style.fontSize)).toBe(
      TERMINAL_TILE.volumeTextScale * TERMINAL_TILE.nameFontSize,
    )
  })

  it('draws just the price for a product with no size', () => {
    renderPreview({ volumeMl: null })

    expect(screen.queryByTestId('products-preview-volume')).toBeNull()
    expect(screen.getByTestId('products-preview-price-pill').children).toHaveLength(1)
  })

  it('keeps the name box the same height whether or not there is a size', () => {
    renderPreview({ volumeMl: 500 })
    const withSize = screen.getByTestId('products-preview-name').parentElement!.style.height
    cleanup()

    renderPreview({ volumeMl: null })
    const withoutSize = screen.getByTestId('products-preview-name').parentElement!.style.height

    expect(withSize).toBe(withoutSize)
    expect(withSize).toBe(
      `${TERMINAL_TILE.lineHeight * TERMINAL_TILE.nameLines * TERMINAL_TILE.nameFontSize}px`,
    )
  })

  it('falls back to a placeholder name rather than drawing an empty tile', () => {
    renderPreview({ name: '   ' })

    expect(screen.getByTestId('products-preview-name').textContent).toBe(
      'products.previewNamePlaceholder',
    )
  })
})

describe('TERMINAL_TILE', () => {
  it('carries the terminal tile metrics it says it does', () => {
    // Copied from `ProductTileMetrics` and `AppColors`/`AppFontSizes` in the
    // terminal. Stated here so a change on the Dart side that is not mirrored
    // is a failing test rather than a screen that slowly stops matching.
    expect(TERMINAL_TILE.nameFontSize).toBe(26) // AppFontSizes.xxxl
    expect(TERMINAL_TILE.nameLines).toBe(1)
    expect(TERMINAL_TILE.lineHeight).toBe(1.2)
    expect(TERMINAL_TILE.iconSize).toBe(52) // metrics.baseIconSize
    expect(TERMINAL_TILE.padding).toBe(12) // AppSpacing.md
    expect(TERMINAL_TILE.gap).toBe(8) // AppSpacing.sm
    expect(TERMINAL_TILE.volumeTextScale).toBe(0.6)
    expect(TERMINAL_TILE.pillOuterPadding).toBe(12)
    expect(TERMINAL_TILE.pillInnerPadding).toBe(8)
    expect(TERMINAL_TILE.pillDivider).toBe(1)
    expect(TERMINAL_TILE.priceScale).toBe(0.9)
    expect(TERMINAL_TILE.priceFloor).toBe(24) // AppFontSizes.xxl
  })
})
