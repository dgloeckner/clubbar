import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:clubbar_terminal/screens/idle_waiting_screen.dart';
import 'package:clubbar_terminal/screens/product_selection_screen.dart';
import 'package:clubbar_terminal/screens/shopping_cart_screen.dart';
import 'package:clubbar_terminal/screens/checkout_confirmation_screen.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/widgets/main_layout.dart';
import 'package:clubbar_terminal/widgets/scan_capture.dart';

/// How long the screen being entered takes to fade in.
const _screenFadeDuration = Duration(milliseconds: 300);

/// Opacity of a screen that is being covered by another one: fully visible
/// while nothing covers it, gone from the first frame of the hand-over.
class _CoveredScreenOpacity extends Animatable<double> {
  const _CoveredScreenOpacity();

  @override
  double transform(double t) => t == 0.0 ? 1.0 : 0.0;
}

/// One page for every terminal route: the screen being left goes at once, the
/// screen being entered fades in over the terminal's own background.
///
/// Going from one screen to another replaces the navigator's page rather than
/// popping it, so the screen being left is **covered**, not dismissed: its own
/// `animation` stays at 1 and only its `secondaryAnimation` moves. A builder
/// that fades on `animation` alone therefore leaves it fully opaque underneath
/// for the whole transition — and none of these screens covers it, because the
/// welcome screen is a gradient ending in [Colors.transparent] and the product
/// screen is a bare [Column]. Both were on the glass at once, one of them
/// half-faded (#644).
///
/// Logout is where that showed. `SessionController.endSession` clears the
/// member, the cart and the session price freeze *before* the route changes,
/// so the product screen spent those 300 ms rebuilding at full opacity under
/// the welcome screen: the member bar goes and the grid jumps up by its
/// height, the cart summary bar goes, the frozen catalogue is swapped for the
/// live one and the labels fall back from the member's language to German.
/// Seen through the welcome screen fading in on top, that looks exactly like
/// the product screen coming back.
///
/// So the covered screen leaves the glass on the first frame of the hand-over
/// ([_CoveredScreenOpacity]) and only the incoming one animates. It can never
/// be caught mid-rebuild, and only one screen is ever painted.
/// [reverseTransitionDuration] says the same thing for the pop this app does
/// not currently make: the screen being left does not linger.
CustomTransitionPage<void> _fadeInPage(GoRouterState state, Widget child) {
  return CustomTransitionPage<void>(
    key: state.pageKey,
    child: child,
    transitionDuration: _screenFadeDuration,
    reverseTransitionDuration: Duration.zero,
    transitionsBuilder: (context, animation, secondaryAnimation, child) {
      return FadeTransition(
        opacity: animation,
        child: FadeTransition(
          opacity: secondaryAnimation.drive(const _CoveredScreenOpacity()),
          child: child,
        ),
      );
    },
  );
}

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
          !state.matchedLocation.startsWith('/confirmation')) {
        return '/products';
      }

      // If no member selected and on products/cart, return to idle
      if (selectedMember == null &&
          (state.matchedLocation.startsWith('/products') ||
              state.matchedLocation.startsWith('/cart'))) {
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
            pageBuilder: (context, state) =>
                _fadeInPage(state, const IdleWaitingScreen()),
          ),
          GoRoute(
            path: '/products',
            pageBuilder: (context, state) =>
                _fadeInPage(state, const ProductSelectionScreen()),
          ),
          GoRoute(
            path: '/cart',
            pageBuilder: (context, state) =>
                _fadeInPage(state, const ShoppingCartScreen()),
          ),
          GoRoute(
            path: '/confirmation/:sessionId',
            pageBuilder: (context, state) => _fadeInPage(
              state,
              CheckoutConfirmationScreen(
                sessionId: state.pathParameters['sessionId'] ?? '',
              ),
            ),
          ),
        ],
      ),
    ],
  );
}
