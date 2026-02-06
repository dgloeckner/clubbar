import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/l10n/app_localizations.dart';

void showErrorModal(
  BuildContext context,
  String message, {
  VoidCallback? onRetry,
}) {
  final l10n = AppLocalizations.of(context)!;

  showDialog(
    context: context,
    builder: (context) => AlertDialog(
      icon: Icon(
        Icons.error_outline,
        color: Theme.of(context).colorScheme.error,
        size: 32,
      ),
      title: Text(l10n.errorTitle),
      content: Text(message),
      actions: [
        TextButton(
          onPressed: () {
            Navigator.of(context).pop();
          },
          child: Text(l10n.dismiss),
        ),
        if (onRetry != null)
          TextButton(
            onPressed: () {
              Navigator.of(context).pop();
              onRetry();
            },
            child: Text(l10n.retry),
          ),
      ],
    ),
  );
}
