import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';
import 'package:ruderbar_terminal/utils/icon_registry.dart';
import 'package:ruderbar_terminal/widgets/member_bar.dart';

class ShoppingCartScreen extends StatelessWidget {
  const ShoppingCartScreen({super.key});

  String _formatPrice(int cents) {
    final euros = cents / 100.0;
    return euros.toStringAsFixed(2);
  }

  static const double _horizontalPadding = 16.0;
  static const double _verticalSpacing = 12.0;

  @override
  Widget build(BuildContext context) {
    return Consumer2<CartProvider, MembersProvider>(
      builder: (context, cartProvider, membersProvider, child) {
        final selectedMember = membersProvider.selectedMember;

        if (cartProvider.items.isEmpty) {
          return Column(
            children: [
              // Member bar with back button
              if (selectedMember != null)
                Padding(
                  padding: const EdgeInsets.symmetric(
                    horizontal: _horizontalPadding,
                    vertical: _verticalSpacing,
                  ),
                  child: MemberBar(
                    member: selectedMember,
                    itemCount: cartProvider.itemCount,
                    deckelCents: membersProvider.memberDeckel,
                    showBackButton: true,
                    onBackPressed: () => context.go('/products'),
                    onLogoutPressed: () {
                      membersProvider.clearSelectedMember();
                      context.go('/idle');
                    },
                  ),
                ),
              const Expanded(
                child: Center(
                  child: Text(
                    'Your cart is empty',
                    style: TextStyle(
                      color: Color(0xff94a3b8),
                      fontSize: AppFontSizes.lg,
                    ),
                  ),
                ),
              ),
            ],
          );
        }

        final totalCents = cartProvider.items.fold<int>(
          0,
          (sum, item) => sum + (item.priceCents * item.quantity),
        );

        // Calculate new balance (Deckel + cart total)
        final currentDeckel = membersProvider.memberDeckel ?? 0;
        final newBalanceCents = currentDeckel + totalCents;

        return Column(
          children: [
            // Member bar with back button
            if (selectedMember != null)
              Padding(
                padding: const EdgeInsets.symmetric(
                  horizontal: _horizontalPadding,
                  vertical: _verticalSpacing,
                ),
                child: MemberBar(
                  member: selectedMember,
                  itemCount: cartProvider.itemCount,
                  deckelCents: membersProvider.memberDeckel,
                  showBackButton: true,
                  onBackPressed: () => context.go('/products'),
                  onLogoutPressed: () {
                    membersProvider.clearSelectedMember();
                    context.go('/idle');
                  },
                ),
              ),

            // Item list
            Expanded(
              child: ListView.builder(
                padding: const EdgeInsets.all(AppSpacing.lg),
                itemCount: cartProvider.items.length,
                itemBuilder: (context, index) {
                  final item = cartProvider.items[index];
                  final unitPriceFormatted = _formatPrice(item.priceCents);
                  final lineTotalFormatted = _formatPrice(item.priceCents * item.quantity);

                  return Container(
                    margin: const EdgeInsets.only(bottom: AppSpacing.md),
                    padding: const EdgeInsets.symmetric(
                      horizontal: AppSpacing.lg,
                      vertical: AppSpacing.md,
                    ),
                    decoration: BoxDecoration(
                      color: const Color(0xff1a2744),
                      borderRadius: BorderRadius.circular(AppBorderRadius.lg),
                      border: Border.all(
                        color: const Color(0xff334155),
                        width: 1,
                      ),
                    ),
                    child: Row(
                      children: [
                        // Icon
                        getProductIcon(
                          item.iconName,
                          size: 48,
                        ),
                        const SizedBox(width: AppSpacing.md),

                        // Name and price per unit
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                item.productName,
                                style: const TextStyle(
                                  color: Color(0xfff1f5f9),
                                  fontSize: AppFontSizes.lg,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                              const SizedBox(height: 2),
                              Text(
                                '€$unitPriceFormatted each',
                                style: const TextStyle(
                                  color: Color(0xff0ea5e9),
                                  fontSize: AppFontSizes.base,
                                  fontWeight: FontWeight.w500,
                                ),
                              ),
                            ],
                          ),
                        ),

                        // Quantity controls
                        Row(
                          children: [
                            // Minus button
                            GestureDetector(
                              onTap: () {
                                if (item.quantity > 1) {
                                  cartProvider.updateQuantity(
                                    item.productId,
                                    item.quantity - 1,
                                  );
                                }
                              },
                              child: Container(
                                width: 44,
                                height: 44,
                                decoration: BoxDecoration(
                                  color: const Color(0xff7f1d1d),
                                  borderRadius: BorderRadius.circular(8),
                                ),
                                child: const Center(
                                  child: Text(
                                    '−',
                                    style: TextStyle(
                                      color: Color(0xffef4444),
                                      fontSize: 24,
                                      fontWeight: FontWeight.bold,
                                    ),
                                  ),
                                ),
                              ),
                            ),

                            // Quantity
                            SizedBox(
                              width: 48,
                              child: Text(
                                '${item.quantity}',
                                textAlign: TextAlign.center,
                                style: const TextStyle(
                                  color: Colors.white,
                                  fontSize: AppFontSizes.xl,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ),

                            // Plus button
                            GestureDetector(
                              onTap: () {
                                cartProvider.updateQuantity(
                                  item.productId,
                                  item.quantity + 1,
                                );
                              },
                              child: Container(
                                width: 44,
                                height: 44,
                                decoration: BoxDecoration(
                                  color: const Color(0xff166534),
                                  borderRadius: BorderRadius.circular(8),
                                ),
                                child: const Center(
                                  child: Text(
                                    '+',
                                    style: TextStyle(
                                      color: Color(0xff22c55e),
                                      fontSize: 24,
                                      fontWeight: FontWeight.bold,
                                    ),
                                  ),
                                ),
                              ),
                            ),
                          ],
                        ),

                        const SizedBox(width: AppSpacing.lg),

                        // Line total
                        SizedBox(
                          width: 90,
                          child: Text(
                            '€$lineTotalFormatted',
                            textAlign: TextAlign.right,
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: AppFontSizes.xl,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),

                        const SizedBox(width: AppSpacing.md),

                        // Delete button
                        GestureDetector(
                          onTap: () => cartProvider.removeItem(item.productId),
                          child: Container(
                            width: 44,
                            height: 44,
                            decoration: BoxDecoration(
                              color: const Color(0x807f1d1d),
                              borderRadius: BorderRadius.circular(8),
                              border: Border.all(
                                color: const Color(0xffef4444),
                                width: 1,
                              ),
                            ),
                            child: const Center(
                              child: Icon(
                                Icons.delete_outline,
                                color: Color(0xffef4444),
                                size: 24,
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                  );
                },
              ),
            ),

            // Total section (sticky bottom)
            Container(
              decoration: BoxDecoration(
                color: const Color(0xff1a2744),
                borderRadius: const BorderRadius.only(
                  topLeft: Radius.circular(AppBorderRadius.lg),
                  topRight: Radius.circular(AppBorderRadius.lg),
                ),
                border: Border.all(
                  color: const Color(0xff334155),
                  width: 1,
                ),
              ),
              padding: const EdgeInsets.all(AppSpacing.lg),
              child: Column(
                children: [
                  // Summe row
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'Total',
                            style: TextStyle(
                              color: Color(0xff94a3b8),
                              fontSize: AppFontSizes.xxl,
                              fontWeight: FontWeight.w500,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            'New Balance: €${_formatPrice(newBalanceCents)}',
                            style: const TextStyle(
                              color: Color(0xff22c55e),
                              fontSize: AppFontSizes.xl,
                              fontWeight: FontWeight.w500,
                            ),
                          ),
                        ],
                      ),
                      Text(
                        '€${_formatPrice(totalCents)}',
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 48,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: AppSpacing.lg),

                  // Checkout button
                  GestureDetector(
                          onTap: () async {
                            final selectedMember = membersProvider.selectedMember;

                            if (selectedMember == null) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                const SnackBar(
                                  content: Text('Member not selected'),
                                  backgroundColor: Color(0xffef4444),
                                ),
                              );
                              return;
                            }

                            await cartProvider.checkout(selectedMember);

                            if (cartProvider.lastError != null) {
                              if (context.mounted) {
                                ScaffoldMessenger.of(context).showSnackBar(
                                  SnackBar(
                                    content: Text(cartProvider.lastError!),
                                    backgroundColor: const Color(0xffef4444),
                                  ),
                                );
                              }
                              return;
                            }

                            // Recompute Deckel from database (now includes
                            // the new unsynced transaction)
                            await membersProvider.refreshDeckel();

                            if (cartProvider.lastTransactionId != null && context.mounted) {
                              context.go('/confirmation/${cartProvider.lastTransactionId}');
                            }
                          },
                    child: Container(
                      height: 56,
                      decoration: BoxDecoration(
                        color: const Color(0xff22c55e),
                        borderRadius: BorderRadius.circular(AppBorderRadius.lg),
                      ),
                      child: const Center(
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(
                              Icons.check,
                              color: Colors.black,
                              size: 24,
                            ),
                            SizedBox(width: 8),
                            Text(
                              'Checkout',
                              style: TextStyle(
                                color: Colors.black,
                                fontSize: AppFontSizes.xl,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        );
      },
    );
  }
}
