import 'package:flutter/material.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/formatters.dart';
import 'package:clubbar_terminal/utils/icon_registry.dart';
import 'package:clubbar_terminal/utils/product_grid_layout.dart';

class ProductCard extends StatefulWidget {
  final ProductsCacheData product;
  final String productName;
  final String locale;
  final VoidCallback onTap;
  final int quantity;
  final VoidCallback? onDecrement;

  /// Whether the product can be bought right now.
  ///
  /// Issue #31: a token whose dispenser is offline stays on the grid — hiding
  /// it reads as "discontinued" — but it must not reach the cart.
  final bool enabled;

  /// Short line telling the member why a disabled card cannot be bought.
  final String? unavailableNote;

  /// Size of the product name — the tile's headline.
  ///
  /// Member feedback: product names were too small. The name was `xl` under a
  /// price at `xxl`: the amount louder than the thing it is the amount for, on
  /// a 7" panel read standing up. A member picks by name and reads the price
  /// second, so the name is the larger of the two (#369).
  ///
  /// Chosen by `ProductSelectionScreen` for the whole category, not by the
  /// card: one size for every tile the member is looking at, the largest at
  /// which every name of the category fits its tile with every word whole,
  /// between [AppFontSizes.productNameFloor] and
  /// [AppFontSizes.productNameCeiling] (`fontSizes.productNameMin` / `Max` in
  /// config.json). See `ProductGridLayout`. Defaults to the floor so a card
  /// on its own still looks like a card.
  final double nameFontSize;

  /// Size of the price — the loudest number on the tile (#878).
  ///
  /// Derived from [nameFontSize] by [ProductTileMetrics.priceFontSize]: the
  /// larger of `xxl` and 0.9 x the name. The height the dropped second name
  /// line freed goes here, because a member picks by name and then checks the
  /// price. Passed in rather than recomputed, so the card draws at the number
  /// the grid solved the tile's height from.
  final double priceFontSize;

  /// Size of the volume's text in the price pill, likewise derived from
  /// [nameFontSize].
  final double volumeFontSize;

  /// Edge of the product icon — 52 at the floor, growing with whatever room
  /// the category's name size has over it, so the tile scales as one thing.
  final double iconSize;

  /// The geometry the card draws with and the grid sizes with, shared so the
  /// two cannot drift apart.
  static const ProductTileMetrics metrics = ProductTileMetrics();

  /// Line height of the name and the price, as a multiple of the font size.
  ///
  /// Pinned rather than left to the font, for the same reason the member bar
  /// pins its own: Roboto's natural line box is ~1.34 em, and at the scale a
  /// production terminal runs (`xxxl` 31) that is 12 px per tile the grid
  /// could not spare — the second row sat cut off behind the summary bar
  /// whenever the credit-limit banner was up. 1.2 is ordinary leading for a
  /// bold two-line headline, and it makes the tile's text block *exactly*
  /// `textLineHeight * (2 * name + price)`, which is what the grid sizes the
  /// tile from — no longer a measured figure that has to be re-measured when
  /// a font changes.
  static double get textLineHeight => metrics.lineHeight;

  /// Number of lines the name may take before it is ellipsised.
  static int get nameLines => metrics.nameLines;

  ProductCard({
    super.key,
    required this.product,
    required this.productName,
    required this.locale,
    required this.onTap,
    this.quantity = 0,
    this.onDecrement,
    this.enabled = true,
    this.unavailableNote,
    double? nameFontSize,
    double? priceFontSize,
    double? volumeFontSize,
    double? iconSize,
  })  : nameFontSize = nameFontSize ?? AppFontSizes.productNameFloor,
        priceFontSize = priceFontSize ??
            metrics.priceFontSize(
                nameFontSize ?? AppFontSizes.productNameFloor,
                AppFontSizes.xxl),
        volumeFontSize = volumeFontSize ??
            metrics.volumeFontSize(
                nameFontSize ?? AppFontSizes.productNameFloor),
        iconSize = iconSize ??
            metrics.iconSize(nameFontSize ?? AppFontSizes.productNameFloor,
                AppFontSizes.productNameFloor);

  @override
  State<ProductCard> createState() => _ProductCardState();
}

class _ProductCardState extends State<ProductCard>
    with SingleTickerProviderStateMixin {
  late AnimationController _animationController;
  late Animation<double> _scaleAnimation;

  @override
  void initState() {
    super.initState();
    _animationController = AnimationController(
      duration: AppAnimations.normal,
      vsync: this,
    );
    _scaleAnimation = Tween<double>(begin: 1.0, end: 1.05).animate(
      CurvedAnimation(parent: _animationController, curve: Curves.easeOut),
    );
  }

  @override
  void dispose() {
    _animationController.dispose();
    super.dispose();
  }

  void _handleTapDown(TapDownDetails details) {
    if (!widget.enabled) return;
    _animationController.forward();
  }

  void _handleTapUp(TapUpDetails details) {
    if (!widget.enabled) return;
    _animationController.reverse();
    widget.onTap();
  }

  void _handleTapCancel() {
    _animationController.reverse();
  }

  /// The pill: an optional volume segment on a slate wash, a hairline, and
  /// the price on the sky tint. Its widths are [ProductTileMetrics.pillWidth],
  /// which is what the grid sized the tile against.
  Widget _pricePill() {
    const metrics = ProductCard.metrics;
    final volumeMl = widget.product.volumeMl;

    return Container(
      key: const ValueKey('product-card-price-pill'),
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(AppBorderRadius.full),
        border: Border.all(
          color: AppColors.borderPricePill,
          width: metrics.pricePillBorder,
        ),
      ),
      child: IntrinsicHeight(
        child: Row(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (volumeMl != null) ...[
              Container(
                // A faint slate wash — opacity-only, not a swept colour (#302).
                color: AppColors.bgVolumeBadge,
                alignment: Alignment.center,
                padding: EdgeInsets.only(
                  left: metrics.pillOuterPadding,
                  right: metrics.pillInnerPadding,
                ),
                child: Text(
                  formatVolume(volumeMl, widget.locale),
                  maxLines: 1,
                  style: TextStyle(
                    color: AppColors.textSecondary,
                    fontSize: widget.volumeFontSize,
                    fontWeight: FontWeight.w700,
                    height: 1.0,
                  ),
                ),
              ),
              Container(
                width: metrics.pillDivider,
                color: AppColors.borderPricePill,
              ),
            ],
            Container(
              color: AppColors.bgPricePill,
              padding: EdgeInsets.only(
                left: volumeMl != null
                    ? metrics.pillInnerPadding
                    : metrics.pillOuterPadding,
                right: metrics.pillOuterPadding,
                top: metrics.pricePillPadding,
                bottom: metrics.pricePillPadding,
              ),
              child: Text(
                formatPrice(widget.product.priceCents, widget.locale),
                textAlign: TextAlign.center,
                maxLines: 1,
                style: TextStyle(
                  color: AppColors.infoOnTint,
                  fontSize: widget.priceFontSize,
                  fontWeight: FontWeight.w900,
                  height: ProductCard.textLineHeight,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isInCart = widget.quantity > 0;
    final enabled = widget.enabled;

    return GestureDetector(
      onTapDown: _handleTapDown,
      onTapUp: _handleTapUp,
      onTapCancel: _handleTapCancel,
      child: ScaleTransition(
        scale: _scaleAnimation,
        child: Stack(
          fit: StackFit.expand,
          children: [
            // Dimmed rather than removed: the member should still see what the
            // club sells, just not be able to order it (issue #31).
            Opacity(
              opacity: enabled ? 1.0 : 0.45,
              child: Card(
                color: AppColors.bgCard,
                elevation: 0,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(AppBorderRadius.lg),
                  side: BorderSide(
                    color: isInCart
                        ? AppColors.semanticPrimary
                        : AppColors.borderLight,
                    width: isInCart ? 2 : 1,
                  ),
                ),
                child: Container(
                  // `md`, not `lg` (#369). Together with the smaller icon
                  // below this takes 24 px off every tile, which is what buys
                  // the grid a whole second row on a 1280x800 kiosk. The grid
                  // sizes the tile from [metrics], which carries the same
                  // number.
                  padding: EdgeInsets.all(ProductCard.metrics.padding),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(AppBorderRadius.lg),
                    boxShadow: const [],
                  ),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.center,
                    children: [
                      // Icon — 52 at the floor, and `sm` under it: the 12 px
                      // this gave back is what paid for the larger name.
                      getProductIcon(widget.product.iconName,
                          size: widget.iconSize),
                      SizedBox(height: ProductCard.metrics.gap),

                      // Product name — the headline; see [nameFontSize].
                      //
                      // One line since #878, and the box is still fixed and
                      // bottom-aligned. Both matter for the same reason they
                      // did when it held two: the name box is what anchors
                      // everything below it to the same height across a row.
                      // The ellipsis is now only the fallback for a name still
                      // wider than the tile at the solver's minimum.
                      SizedBox(
                        height: ProductCard.textLineHeight *
                            ProductCard.nameLines *
                            widget.nameFontSize,
                        child: Align(
                          alignment: Alignment.bottomCenter,
                          child: Text(
                            widget.productName,
                            textAlign: TextAlign.center,
                            maxLines: ProductCard.nameLines,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              color: AppColors.textPrimary,
                              fontSize: widget.nameFontSize,
                              fontWeight: FontWeight.w700,
                              height: ProductCard.textLineHeight,
                            ),
                          ),
                        ),
                      ),

                      SizedBox(height: ProductCard.metrics.gap),

                      // Size and price — one pill, `0,5 l │ 2,00 €`, and the
                      // loudest thing on the tile.
                      //
                      // The member picks by name and then checks the price;
                      // the size (ADR-0056) is read with it, as "this much,
                      // for this price". The pill is one line tall with or
                      // without a volume, so a Sauna-Token's price sits level
                      // with its neighbours' — the invariant commit 2b4d50b5
                      // pinned the name box for. `semanticInfo` on a fill of
                      // its own with a 1 px border: the contrast of that
                      // pairing is asserted in `contrast_test.dart`.
                      //
                      // The grid sizes the type so the pill fits the tile;
                      // `scaleDown` is only the fallback for a price floor too
                      // large for the tile (`pillsOverflow`), which would
                      // otherwise clip the amount.
                      FittedBox(
                        fit: BoxFit.scaleDown,
                        child: _pricePill(),
                      ),
                    ],
                  ),
                ),
              ),
            ),
            // Unavailability note — a banner across the foot of the card, so
            // it costs the fixed-height tile no layout room.
            if (!enabled && widget.unavailableNote != null)
              Positioned(
                left: 0,
                right: 0,
                bottom: 0,
                child: Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: AppSpacing.sm,
                    vertical: 6,
                  ),
                  decoration: BoxDecoration(
                    // rgba tint of borderDark — opacity-only, not swept (#302).
                    color: const Color(0xcc1e293b),
                    borderRadius: BorderRadius.only(
                      bottomLeft: Radius.circular(AppBorderRadius.lg),
                      bottomRight: Radius.circular(AppBorderRadius.lg),
                    ),
                  ),
                  child: Text(
                    widget.unavailableNote!,
                    textAlign: TextAlign.center,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: AppColors.semanticWarningLight,
                      fontSize: AppFontSizes.sm,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ),
            // Minus button — top-left, large touch target
            if (isInCart && widget.onDecrement != null)
              Positioned(
                top: 8,
                left: 8,
                child: GestureDetector(
                  onTap: widget.onDecrement,
                  child: Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(
                      color: AppColors.borderDark,
                      borderRadius: BorderRadius.circular(AppBorderRadius.full),
                      border: Border.all(
                        color: AppColors.semanticPrimary,
                        width: 1.5,
                      ),
                    ),
                    child: const Icon(
                      Icons.remove,
                      color: AppColors.textSecondary,
                      size: 24,
                    ),
                  ),
                ),
              ),
            // Count badge — top-right
            if (isInCart)
              Positioned(
                top: 8,
                right: 8,
                child: Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 10,
                    vertical: 5,
                  ),
                  decoration: BoxDecoration(
                    // Strong blue: white on #3b82f6 is 3.7:1 (#41).
                    color: AppColors.semanticPrimaryStrong,
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: Text(
                    '${widget.quantity}x',
                    style: TextStyle(
                      color: Colors.white,
                      fontSize: AppFontSizes.lg,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
