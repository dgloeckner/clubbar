import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:clubbar_terminal/screens/idle_waiting_screen.dart';
import 'package:clubbar_terminal/screens/product_selection_screen.dart';
import 'package:clubbar_terminal/screens/member_details_screen.dart';
import 'package:clubbar_terminal/screens/shopping_cart_screen.dart';
import 'package:clubbar_terminal/screens/checkout_confirmation_screen.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/widgets/main_layout.dart';
import 'package:clubbar_terminal/widgets/scan_capture.dart';

/// Creates the app's router with a redirect driven by member selection state.
///
/// Takes [membersProvider] directly rather than reading it off a
/// [BuildContext]: the router is created once for the app's lifetime (issue
/// #33), outside any build, so there is no context to read from at that point.
GoRouter createAppRouter({
  required MembersProvider membersProvider,
  configService,
}) {
  return GoRouter(
    initialLocation: '/idle',
    // Re-evaluate redirects when the session ends without a navigation event
    // (e.g. the inactivity timeout clears the member; ADR-0027 rule 6).
    refreshListenable: membersProvider,
    redirect: (context, state) {
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
      // Shell route with persistent header. Scan capture sits here so a card
      // tap is read on every route, not only on the idle screen (issue #26).
      ShellRoute(
        builder: (context, state, child) => ScanCapture(
          location: state.uri.path,
          child: MainLayout(child: child),
        ),
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
