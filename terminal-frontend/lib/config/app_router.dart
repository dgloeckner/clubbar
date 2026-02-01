import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:ruderbar_terminal/screens/idle_waiting_screen.dart';
import 'package:ruderbar_terminal/screens/product_selection_screen.dart';
import 'package:ruderbar_terminal/screens/member_details_screen.dart';
import 'package:ruderbar_terminal/screens/shopping_cart_screen.dart';
import 'package:ruderbar_terminal/screens/checkout_confirmation_screen.dart';

final appRouter = GoRouter(
  initialLocation: '/idle',
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
