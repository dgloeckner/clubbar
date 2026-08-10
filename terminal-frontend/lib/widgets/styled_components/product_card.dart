import 'package:flutter/material.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/utils/formatters.dart';
import 'package:clubbar_terminal/utils/icon_registry.dart';

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

  const ProductCard({
    super.key,
    required this.product,
    required this.productName,
    required this.locale,
    required this.onTap,
    this.quantity = 0,
    this.onDecrement,
    this.enabled = true,
    this.unavailableNote,
  });

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
                  padding: const EdgeInsets.all(AppSpacing.lg),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(AppBorderRadius.lg),
                    boxShadow: const [],
                  ),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.center,
                    children: [
                      // Icon (larger for better visibility)
                      getProductIcon(widget.product.iconName, size: 72),
                      const SizedBox(height: AppSpacing.lg),

                      // Product name (larger font)
                      Text(
                        widget.productName,
                        textAlign: TextAlign.center,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          color: AppColors.textPrimary,
                          fontSize: AppFontSizes.xl,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: AppSpacing.sm),

                      // Price (cyan, bold, larger font)
                      Text(
                        formatPrice(widget.product.priceCents, widget.locale),
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          color: AppColors.semanticInfo,
                          fontSize: AppFontSizes.xxl,
                          fontWeight: FontWeight.bold,
                        ),
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
