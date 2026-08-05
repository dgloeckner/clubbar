import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/screens/idle_waiting_screen.dart';
import 'package:clubbar_terminal/screens/product_selection_screen.dart';
import 'package:clubbar_terminal/screens/member_details_screen.dart';
import 'package:clubbar_terminal/screens/shopping_cart_screen.dart';
import 'package:clubbar_terminal/screens/checkout_confirmation_screen.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/widgets/main_layout.dart';

// Create router with dynamic redirect based on member selection state
GoRouter createAppRouter(BuildContext context, {configService}) {
  return GoRouter(
    initialLocation: '/idle',
    // Re-evaluate redirects when the session ends without a navigation event
    // (e.g. the inactivity timeout clears the member; ADR-0027 rule 6).
    refreshListenable: Provider.of<MembersProvider>(context, listen: false),
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
            path: '/confirmation/:sessionId',
            pageBuilder: (context, state) {
              final sessionId = state.pathParameters['sessionId'] ?? '';
              return CustomTransitionPage(
                key: state.pageKey,
                child: CheckoutConfirmationScreen(sessionId: sessionId),
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
