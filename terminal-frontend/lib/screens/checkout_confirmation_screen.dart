import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:provider/provider.dart';
import 'dart:async';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';

class CheckoutConfirmationScreen extends StatefulWidget {
  final String transactionId;

  const CheckoutConfirmationScreen({
    required this.transactionId,
    Key? key,
  }) : super(key: key);

  @override
  State<CheckoutConfirmationScreen> createState() =>
      _CheckoutConfirmationScreenState();
}

class _CheckoutConfirmationScreenState extends State<CheckoutConfirmationScreen> {
  Timer? _autoLoopTimer;

  @override
  void initState() {
    super.initState();
    // Auto-loop back to idle after 3 seconds
    _autoLoopTimer = Timer(const Duration(seconds: 3), () {
      if (mounted) {
        // Clear cart and selected member
        context.read<CartProvider>().clearCart();
        context.read<MembersProvider>().clearSelectedMember();
        // Navigate back to idle
        context.go('/idle');
      }
    });
  }

  @override
  void dispose() {
    _autoLoopTimer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              Icons.check_circle,
              size: 100,
              color: Colors.green,
            ),
            const SizedBox(height: 24),
            Text(
              'Success!',
              style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                    color: Colors.green,
                    fontWeight: FontWeight.bold,
                  ),
            ),
            const SizedBox(height: 12),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 32.0),
              child: Text(
                'Transaction ${widget.transactionId} completed',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.bodyLarge,
              ),
            ),
            const SizedBox(height: 12),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 32.0),
              child: Text(
                'Returning to idle in 3 seconds...',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.bodyMedium,
              ),
            ),
            const SizedBox(height: 40),
            ElevatedButton(
              onPressed: () {
                context.read<CartProvider>().clearCart();
                context.read<MembersProvider>().clearSelectedMember();
                context.go('/idle');
              },
              style: ElevatedButton.styleFrom(
                padding: const EdgeInsets.symmetric(horizontal: 48, vertical: 16),
                backgroundColor: Colors.green,
              ),
              child: const Text('Return to Idle Now'),
            ),
          ],
        ),
      ),
    );
  }
}
