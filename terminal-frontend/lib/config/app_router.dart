import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/screens/idle_waiting_screen.dart';
import 'package:ruderbar_terminal/screens/product_selection_screen.dart';
import 'package:ruderbar_terminal/screens/member_details_screen.dart';
import 'package:ruderbar_terminal/screens/shopping_cart_screen.dart';
import 'package:ruderbar_terminal/screens/checkout_confirmation_screen.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/widgets/main_layout.dart';

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
      // Shell route with persistent header
      ShellRoute(
        builder: (context, state, child) => MainLayout(child: child),
        routes: [
          GoRoute(
            path: '/idle',
            pageBuilder: (context, state) => CustomTransitionPage(
              key: state.pageKey,
              child: const IdleWaitingScreen(),
              transitionsBuilder: (context, animation, secondaryAnimation, child) {
                return FadeTransition(opacity: animation, child: child);
              },
            ),
          ),
          GoRoute(
            path: '/products',
            pageBuilder: (context, state) => CustomTransitionPage(
              key: state.pageKey,
              child: const ProductSelectionScreen(),
              transitionsBuilder: (context, animation, secondaryAnimation, child) {
                return FadeTransition(opacity: animation, child: child);
              },
            ),
          ),
          GoRoute(
            path: '/member-details',
            pageBuilder: (context, state) => CustomTransitionPage(
              key: state.pageKey,
              child: const MemberDetailsScreen(),
              transitionsBuilder: (context, animation, secondaryAnimation, child) {
                return FadeTransition(opacity: animation, child: child);
              },
            ),
          ),
          GoRoute(
            path: '/cart',
            pageBuilder: (context, state) => CustomTransitionPage(
              key: state.pageKey,
              child: const ShoppingCartScreen(),
              transitionsBuilder: (context, animation, secondaryAnimation, child) {
                return FadeTransition(opacity: animation, child: child);
              },
            ),
          ),
          GoRoute(
            path: '/confirmation/:transactionId',
            pageBuilder: (context, state) {
              final transactionId = state.pathParameters['transactionId'] ?? '';
              return CustomTransitionPage(
                key: state.pageKey,
                child: CheckoutConfirmationScreen(transactionId: transactionId),
                transitionsBuilder: (context, animation, secondaryAnimation, child) {
                  return FadeTransition(opacity: animation, child: child);
                },
              );
            },
          ),
        ],
      ),
    ],
  );
}
