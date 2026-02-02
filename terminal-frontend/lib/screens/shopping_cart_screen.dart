import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/utils/design_tokens.dart';
import 'package:ruderbar_terminal/utils/icon_registry.dart';
import 'package:ruderbar_terminal/widgets/styled_components/action_button.dart';
import 'package:ruderbar_terminal/widgets/styled_components/price_display.dart';

class ShoppingCartScreen extends StatelessWidget {
  const ShoppingCartScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xff0a1628),
      appBar: AppBar(
        backgroundColor: const Color(0xff0f1d32),
        title: const Text(
          'Cart',
          style: TextStyle(
            color: Color(0xfff1f5f9),
            fontSize: AppFontSizes.xl,
            fontWeight: FontWeight.w600,
          ),
        ),
        leading: IconButton(
          icon: const Icon(Icons.arrow_back, color: Color(0xfff1f5f9)),
          onPressed: () => context.go('/products'),
        ),
      ),
      body: Consumer<CartProvider>(
        builder: (context, cartProvider, child) {
          if (cartProvider.items.isEmpty) {
            return const Center(
              child: Text(
                'Your cart is empty',
                style: TextStyle(
                  color: Color(0xff94a3b8),
                  fontSize: AppFontSizes.lg,
                ),
              ),
            );
          }

          final totalCents = cartProvider.items.fold<int>(
            0,
            (sum, item) => sum + (item.priceCents * item.quantity),
          );

          return Column(
            children: [
              // Item list
              Expanded(
                child: ListView.builder(
                  padding: const EdgeInsets.all(AppSpacing.lg),
                  itemCount: cartProvider.items.length,
                  itemBuilder: (context, index) {
                    final item = cartProvider.items[index];

                    return Container(
                      margin: const EdgeInsets.only(bottom: AppSpacing.md),
                      padding: const EdgeInsets.all(AppSpacing.md),
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
                            size: 40,
                          ),
                          const SizedBox(width: AppSpacing.md),

                          // Name and quantity
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  item.productName,
                                  style: const TextStyle(
                                    color: Color(0xfff1f5f9),
                                    fontSize: AppFontSizes.base,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                const SizedBox(height: AppSpacing.xs),
                                Text(
                                  'Qty: ${item.quantity}',
                                  style: const TextStyle(
                                    color: Color(0xff94a3b8),
                                    fontSize: AppFontSizes.sm,
                                  ),
                                ),
                              ],
                            ),
                          ),

                          // Price
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              PriceDisplay(
                                priceCents: item.priceCents * item.quantity,
                                fontSize: PriceFontSize.medium,
                              ),
                              const SizedBox(height: AppSpacing.xs),
                              GestureDetector(
                                onTap: () =>
                                    cartProvider.removeItem(item.productId),
                                child: const Text(
                                  'Remove',
                                  style: TextStyle(
                                    color: Color(0xffef4444),
                                    fontSize: AppFontSizes.sm,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    );
                  },
                ),
              ),

              // Total section (sticky bottom)
              Container(
                decoration: const BoxDecoration(
                  border: Border(
                    top: BorderSide(
                      color: Color(0xff334155),
                      width: 1,
                    ),
                  ),
                ),
                padding: const EdgeInsets.all(AppSpacing.lg),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text(
                          'Total:',
                          style: TextStyle(
                            color: Color(0xfff1f5f9),
                            fontSize: AppFontSizes.lg,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                        PriceDisplay(
                          priceCents: totalCents,
                          fontSize: PriceFontSize.large,
                        ),
                      ],
                    ),
                    const SizedBox(height: AppSpacing.md),

                    // Checkout button
                    ActionButton(
                      label: 'Proceed to Checkout',
                      onPressed: () async {
                        final membersProvider = context.read<MembersProvider>();
                        final selectedMember = membersProvider.selectedMember;

                        if (selectedMember == null) {
                          // This shouldn't happen due to router redirects, but handle it
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(
                              content: Text('Member not selected'),
                              backgroundColor: Color(0xffef4444),
                            ),
                          );
                          return;
                        }

                        // Perform checkout
                        await cartProvider.checkout(selectedMember);

                        // Check for errors
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

                        // Navigate to confirmation screen
                        if (cartProvider.lastTransactionId != null && context.mounted) {
                          context.go('/confirmation/${cartProvider.lastTransactionId}');
                        }
                      },
                      buttonStyle: ActionButtonStyle.primary,
                      fullWidth: true,
                    ),
                    const SizedBox(height: AppSpacing.sm),

                    // Back to products button
                    ActionButton(
                      label: 'Back to Products',
                      onPressed: () => context.go('/products'),
                      buttonStyle: ActionButtonStyle.secondary,
                      fullWidth: true,
                    ),
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
