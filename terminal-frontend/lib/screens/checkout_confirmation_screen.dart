import 'package:flutter/material.dart';

class CheckoutConfirmationScreen extends StatelessWidget {
  final bool isSuccess;
  final String message;
  final VoidCallback onDismiss;

  const CheckoutConfirmationScreen({
    required this.isSuccess,
    required this.message,
    required this.onDismiss,
    Key? key,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              isSuccess ? Icons.check_circle : Icons.error,
              size: 100,
              color: isSuccess ? Colors.green : Colors.red,
            ),
            const SizedBox(height: 24),
            Text(
              isSuccess ? 'Success!' : 'Error',
              style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                    color: isSuccess ? Colors.green : Colors.red,
                    fontWeight: FontWeight.bold,
                  ),
            ),
            const SizedBox(height: 12),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 32.0),
              child: Text(
                message,
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.bodyLarge,
              ),
            ),
            const SizedBox(height: 40),
            ElevatedButton(
              onPressed: onDismiss,
              style: ElevatedButton.styleFrom(
                padding: const EdgeInsets.symmetric(horizontal: 48, vertical: 16),
                backgroundColor: isSuccess ? Colors.green : Colors.red,
              ),
              child: const Text('Continue'),
            ),
          ],
        ),
      ),
    );
  }
}
