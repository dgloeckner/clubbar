/**
 * The tile the terminal will draw, drawn in the product form.
 *
 * This is not "a card that resembles the terminal's": it is the terminal's
 * `ProductCard` (`terminal-frontend/lib/widgets/styled_components/product_card.dart`)
 * laid out the same way, from the same numbers, at the smallest scale the
 * terminal is configured for. An admin setting a name, a size and a price
 * should see what a member will see, including what the tile does with a name
 * that is too long for it.
 *
 * The layout, top to bottom (ADR-0056 and the prototype in
 * `docs/reviews/2026-09-10-product-card/`):
 *
 * 1. the icon;
 * 2. the **name on one line**, bottom-aligned inside a fixed box — the box is
 *    what anchors everything below it to the same height across a row of tiles;
 * 3. the **volume badge**, in a row whose height is reserved whether or not the
 *    product has a size. That is the invariant, not the badge: a row that
 *    collapsed on a Sauna-Token would lift that tile's price above its
 *    neighbours';
 * 4. the **price, in a pill** — the loudest thing on the tile, which is what the
 *    dropped second name line paid for.
 *
 * The numbers and colours are `TERMINAL_TILE` in `src/styles/terminalTile.ts`,
 * which mirrors `ProductTileMetrics` and `AppColors` at the terminal's name
 * floor. They are a copy — Dart and TypeScript share no module — so
 * `ProductPreview.test.tsx` pins the relationships (one name line, a reserved
 * row, a price derived from the name) rather than the pixels alone.
 */

import { useTranslation } from 'react-i18next'
import { getProductIcon } from '../icons/IconRegistry'
import { parseMoneyToCents } from '../../utils/money'
import { useFormatters } from '../../hooks/useFormatters'
import {
  TERMINAL_TILE,
  TILE_NAME_BOX_HEIGHT,
  TILE_PRICE_FONT_SIZE,
  TILE_VOLUME_FONT_SIZE,
  TILE_VOLUME_ROW_HEIGHT,
} from '../../styles/terminalTile'

interface ProductPreviewProps {
  name: string
  price: string
  iconName: string | null
  /** Whole millilitres, or `null` when the product has no size (ADR-0056). */
  volumeMl?: number | null
}

export function ProductPreview({ name, price, iconName, volumeMl = null }: ProductPreviewProps) {
  const { t } = useTranslation()
  // The terminal shows a price and a size in the *member's* language, so the
  // preview of them shows the admin's — through `Intl` and through the volume
  // rule shared with the backend and the terminal, never by hand.
  const { formatPrice, formatVolume } = useFormatters()

  const IconComponent = getProductIcon(iconName)
  const displayName = name.trim() || t('products.previewNamePlaceholder')
  const displayPrice = formatPrice(parseMoneyToCents(price) ?? 0)

  return (
    <div
      data-testid="products-preview-card"
      style={{
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        padding: `${TERMINAL_TILE.padding}px`,
        backgroundColor: TERMINAL_TILE.colors.card,
        border: `1px solid ${TERMINAL_TILE.colors.border}`,
        borderRadius: `${TERMINAL_TILE.radius}px`,
        textAlign: 'center',
      }}
    >
      {/* Icon — 52 px at the floor, with `sm` under it. */}
      <div
        style={{
          height: `${TERMINAL_TILE.iconSize}px`,
          marginBottom: `${TERMINAL_TILE.gap}px`,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
        }}
      >
        <IconComponent size={TERMINAL_TILE.iconSize} color={TERMINAL_TILE.colors.name} />
      </div>

      {/*
        The name — the headline, on one line, bottom-aligned in a fixed box.

        It ellipsises here rather than wrapping, as it does on the terminal:
        the tile has one line for it. The terminal has one move left that the
        preview cannot make — its grid solver shrinks the type for a whole
        category until every name fits whole — so a name clipped here is a name
        that is long for its tile, not necessarily one a member will see
        clipped.
      */}
      <div
        style={{
          height: `${TILE_NAME_BOX_HEIGHT}px`,
          width: '100%',
          display: 'flex',
          alignItems: 'flex-end',
          justifyContent: 'center',
        }}
      >
        <div
          data-testid="products-preview-name"
          style={{
            width: '100%',
            fontSize: `${TERMINAL_TILE.nameFontSize}px`,
            fontWeight: 700,
            lineHeight: TERMINAL_TILE.lineHeight,
            color: TERMINAL_TILE.colors.name,
            whiteSpace: 'nowrap',
            overflow: 'hidden',
            textOverflow: 'ellipsis',
          }}
        >
          {displayName}
        </div>
      </div>

      {/*
        The volume badge's row, reserved whether or not this product has a size
        — see the header: it is what holds every price on a grid row at the same
        height.
      */}
      <div
        data-testid="products-preview-volume-row"
        style={{
          height: `${TILE_VOLUME_ROW_HEIGHT}px`,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          marginBottom: `${TERMINAL_TILE.gap}px`,
        }}
      >
        {volumeMl != null && (
          <span
            data-testid="products-preview-volume"
            style={{
              padding: '2px 8px',
              borderRadius: `${TERMINAL_TILE.radiusFull}px`,
              backgroundColor: TERMINAL_TILE.colors.volumeBadge,
              color: TERMINAL_TILE.colors.volume,
              fontSize: `${TILE_VOLUME_FONT_SIZE}px`,
              fontWeight: 700,
              letterSpacing: '0.4px',
              lineHeight: 1,
              whiteSpace: 'nowrap',
            }}
          >
            {formatVolume(volumeMl)}
          </span>
        )}
      </div>

      {/* The price, in its pill — the loudest thing on the tile. */}
      <div
        data-testid="products-preview-price"
        style={{
          padding: `${TERMINAL_TILE.pricePillPaddingY}px ${TERMINAL_TILE.pricePillPaddingX}px`,
          borderRadius: `${TERMINAL_TILE.radiusFull}px`,
          backgroundColor: TERMINAL_TILE.colors.pricePill,
          border: `${TERMINAL_TILE.pricePillBorder}px solid ${TERMINAL_TILE.colors.pricePillBorder}`,
          color: TERMINAL_TILE.colors.price,
          fontSize: `${TILE_PRICE_FONT_SIZE}px`,
          fontWeight: 900,
          lineHeight: TERMINAL_TILE.lineHeight,
          whiteSpace: 'nowrap',
          maxWidth: '100%',
          overflow: 'hidden',
          textOverflow: 'ellipsis',
        }}
      >
        {displayPrice}
      </div>
    </div>
  )
}
