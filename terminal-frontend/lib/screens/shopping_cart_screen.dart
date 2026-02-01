import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/widgets/index.dart';

class ShoppingCartScreen extends StatelessWidget {
  const ShoppingCartScreen({Key? key}) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return Consumer2<CartProvider, MembersProvider>(
      builder: (context, cartProvider, membersProvider, child) {
        final items = cartProvider.items;
        final totalCents = cartProvider.total;
        final totalEuros = totalCents / 100.0;

        return Column(
          children: [
            // Error banner if present
            if (cartProvider.lastError != null)
              ErrorBanner(message: cartProvider.lastError!),

            // Loading overlay
            if (cartProvider.isLoading)
              LoadingOverlay(
                isLoading: true,
                message: 'Processing checkout...',
                child: const SizedBox.shrink(),
              ),

            // Cart items or empty state
            Expanded(
              child: items.isEmpty
                  ? Center(
                      child: Text(
                        'Your cart is empty',
                        style: Theme.of(context).textTheme.headlineSmall,
                      ),
                    )
                  : ListView.builder(
                      itemCount: items.length,
                      itemBuilder: (context, index) {
                        final item = items[index];
                        return CartItemRow(
                          item: item,
                          onRemovePressed: () {
                            cartProvider.removeItem(item.productId);
                          },
                          onQuantityChanged: (newQuantity) {
                            if (newQuantity > 0) {
                              cartProvider.updateQuantity(
                                item.productId,
                                newQuantity,
                              );
                            }
                          },
                        );
                      },
                    ),
            ),

            // Total and checkout button
            if (items.isNotEmpty)
              Padding(
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(
                          'Total:',
                          style: Theme.of(context).textTheme.titleLarge,
                        ),
                        Text(
                          '€${totalEuros.toStringAsFixed(2)}',
                          style: Theme.of(context).textTheme.titleLarge?.copyWith(
                                color: Colors.green,
                                fontWeight: FontWeight.bold,
                              ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 16),
                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton(
                        onPressed: cartProvider.isLoading
                            ? null
                            : () async {
                                final member = membersProvider.selectedMember;
                                if (member != null) {
                                  await cartProvider.checkout(member);
                                  if (context.mounted) {
                                    Navigator.of(context).pop();
                                  }
                                }
                              },
                        style: ElevatedButton.styleFrom(
                          padding: const EdgeInsets.symmetric(vertical: 16),
                          backgroundColor: Colors.green,
                        ),
                        child: const Text('Proceed to Checkout'),
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
