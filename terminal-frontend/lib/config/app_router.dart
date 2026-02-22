import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/screens/idle_waiting_screen.dart';
import 'package:ruderbar_terminal/screens/product_selection_screen.dart';
import 'package:ruderbar_terminal/screens/member_details_screen.dart';
import 'package:ruderbar_terminal/screens/shopping_cart_screen.dart';
import 'package:ruderbar_terminal/screens/checkout_confirmation_screen.dart';
import 'package:ruderbar_terminal/screens/setup_screen.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/services/config_service.dart';
import 'package:ruderbar_terminal/widgets/main_layout.dart';

// Create router with dynamic redirect based on member selection and config state
GoRouter createAppRouter(BuildContext context, {ConfigService? configService}) {
  return GoRouter(
    initialLocation: configService?.isConfigured == false ? '/setup' : '/idle',
    redirect: (context, state) {
      // If unconfigured, redirect to setup (unless already there)
      if (configService != null &&
          !configService.isConfigured &&
          !state.matchedLocation.startsWith('/setup')) {
        return '/setup';
      }

      // Don't apply member-based redirects on setup screen
      if (state.matchedLocation.startsWith('/setup')) {
        return null;
      }

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
      // Setup screen (outside ShellRoute — no MainLayout header)
      GoRoute(
        path: '/setup',
        pageBuilder: (context, state) => CustomTransitionPage(
          key: state.pageKey,
          child: SetupScreen(configService: configService ?? ConfigService()),
          transitionsBuilder: (context, animation, secondaryAnimation, child) {
            return FadeTransition(opacity: animation, child: child);
          },
        ),
      ),
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
