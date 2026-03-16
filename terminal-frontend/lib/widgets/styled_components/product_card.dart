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

  const ProductCard({
    super.key,
    required this.product,
    required this.productName,
    required this.locale,
    required this.onTap,
    this.quantity = 0,
    this.onDecrement,
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
    _animationController.forward();
  }

  void _handleTapUp(TapUpDetails details) {
    _animationController.reverse();
    widget.onTap();
  }

  void _handleTapCancel() {
    _animationController.reverse();
  }

  @override
  Widget build(BuildContext context) {
    final isInCart = widget.quantity > 0;

    return GestureDetector(
      onTapDown: _handleTapDown,
      onTapUp: _handleTapUp,
      onTapCancel: _handleTapCancel,
      child: ScaleTransition(
        scale: _scaleAnimation,
        child: Stack(
          fit: StackFit.expand,
          children: [
            Card(
              color: Color(int.parse('0xff${AppColors.bgCard.replaceFirst('#', '')}')),
              elevation: 0,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(AppBorderRadius.lg),
                side: BorderSide(
                  color: isInCart ? const Color(0xff3b82f6) : const Color(0xff334155),
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
                    getProductIcon(
                      widget.product.iconName,
                      size: 72,
                    ),
                    const SizedBox(height: AppSpacing.lg),

                    // Product name (larger font)
                    Text(
                      widget.productName,
                      textAlign: TextAlign.center,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: Color(0xfff1f5f9),
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
                        color: Color(0xff0ea5e9),
                        fontSize: AppFontSizes.xxl,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ],
                ),
              ),
            ),
            // Counter badge and minus button — vertically centered in a row
            if (isInCart)
              Positioned(
                top: 10,
                right: 10,
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.center,
                  children: [
                    if (widget.onDecrement != null)
                      GestureDetector(
                        onTap: widget.onDecrement,
                        child: Container(
                          width: 32,
                          height: 32,
                          decoration: BoxDecoration(
                            color: const Color(0xff1e293b),
                            borderRadius: BorderRadius.circular(16),
                            border: Border.all(color: const Color(0xff3b82f6), width: 1),
                          ),
                          child: const Icon(
                            Icons.remove,
                            color: Color(0xff94a3b8),
                            size: 16,
                          ),
                        ),
                      ),
                    if (widget.onDecrement != null)
                      const SizedBox(width: 6),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                      decoration: BoxDecoration(
                        color: const Color(0xff3b82f6),
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
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}
