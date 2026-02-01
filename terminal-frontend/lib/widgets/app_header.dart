import 'package:flutter/material.dart';
import 'package:ruderbar_terminal/providers/auth_provider.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';

class AppHeader extends AppBar {
  AppHeader({
    required String title,
    required AuthProvider authProvider,
    required SyncProvider syncProvider,
    Key? key,
  }) : super(
    key: key,
    title: Text(title),
    elevation: 0,
    actions: [
      // Sync status indicator
      Padding(
        padding: const EdgeInsets.all(16.0),
        child: Center(
          child: Icon(
            syncProvider.isSyncing ? Icons.sync : Icons.cloud_done,
            color: Colors.grey[600],
          ),
        ),
      ),
      // Auth indicator
      if (authProvider.isAuthenticated)
        const Padding(
          padding: EdgeInsets.all(16.0),
          child: Icon(Icons.verified_user),
        ),
    ],
  );
}
