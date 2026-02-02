import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/screens/idle_waiting_screen.dart';
import 'package:ruderbar_terminal/screens/product_selection_screen.dart';
import 'package:ruderbar_terminal/screens/member_details_screen.dart';
import 'package:ruderbar_terminal/screens/shopping_cart_screen.dart';
import 'package:ruderbar_terminal/screens/checkout_confirmation_screen.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';

// Create router with dynamic redirect based on member selection
GoRouter createAppRouter(BuildContext context) {
  return GoRouter(
    initialLocation: '/idle',
    redirect: (context, state) {
      // Watch MembersProvider for member selection changes
      final membersProvider = context.read<MembersProvider>();
      final selectedMember = membersProvider.selectedMember;

      // If member selected and not on products/cart/confirmation, navigate to products
      if (selectedMember != null &&
          !state.matchedLocation.startsWith('/products') &&
          !state.matchedLocation.startsWith('/cart') &&
          !state.matchedLocation.startsWith('/member-details') &&
          !state.matchedLocation.startsWith('/confirmation')) {
        return '/products';
      }

      // If no member selected and on products/cart, return to idle
      if (selectedMember == null &&
          (state.matchedLocation.startsWith('/products') ||
              state.matchedLocation.startsWith('/cart') ||
              state.matchedLocation.startsWith('/member-details'))) {
        return '/idle';
      }

      return null; // No redirect needed
    },
    routes: [
    GoRoute(
      path: '/idle',
      builder: (context, state) => const IdleWaitingScreen(),
    ),
    GoRoute(
      path: '/products',
      builder: (context, state) => const ProductSelectionScreen(),
    ),
    GoRoute(
      path: '/member-details',
      builder: (context, state) => const MemberDetailsScreen(),
    ),
    GoRoute(
      path: '/cart',
      builder: (context, state) => const ShoppingCartScreen(),
    ),
    GoRoute(
      path: '/confirmation/:transactionId',
      builder: (context, state) {
        final transactionId = state.pathParameters['transactionId'] ?? '';
        return CheckoutConfirmationScreen(
          transactionId: transactionId,
        );
      },
    ),
  ],
  );
}
