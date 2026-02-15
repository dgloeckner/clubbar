import 'package:flutter/material.dart';

class ErrorBanner extends StatelessWidget {
  final String? message;
  final VoidCallback? onDismiss;

  const ErrorBanner({
    this.message,
    this.onDismiss,
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    if (message == null || message!.isEmpty) {
      return const SizedBox.shrink();
    }

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16.0),
      color: Colors.red[100],
      child: Row(
        children: [
          Icon(
            Icons.error,
            color: Colors.red[700],
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              message!,
              style: TextStyle(
                color: Colors.red[900],
                fontSize: 14,
              ),
            ),
          ),
          if (onDismiss != null)
            IconButton(
              icon: const Icon(Icons.close),
              onPressed: onDismiss,
            ),
        ],
      ),
    );
  }
}
