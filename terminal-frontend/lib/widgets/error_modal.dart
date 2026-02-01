import 'package:flutter/material.dart';

void showErrorModal(
  BuildContext context,
  String message, {
  VoidCallback? onRetry,
}) {
  showDialog(
    context: context,
    builder: (context) => AlertDialog(
      icon: Icon(
        Icons.error_outline,
        color: Theme.of(context).colorScheme.error,
        size: 32,
      ),
      title: const Text('Error'),
      content: Text(message),
      actions: [
        TextButton(
          onPressed: () {
            Navigator.of(context).pop();
          },
          child: const Text('Dismiss'),
        ),
        if (onRetry != null)
          TextButton(
            onPressed: () {
              Navigator.of(context).pop();
              onRetry();
            },
            child: const Text('Retry'),
          ),
      ],
    ),
  );
}
