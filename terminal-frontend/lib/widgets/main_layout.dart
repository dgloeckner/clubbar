import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';
import 'package:ruderbar_terminal/widgets/ruderbar_header.dart';
import 'package:ruderbar_terminal/widgets/status_info_modal.dart';

/// Main layout with persistent header across all screens.
/// Only the body content animates during navigation.
class MainLayout extends StatelessWidget {
  final Widget child;

  const MainLayout({
    super.key,
    required this.child,
  });

  @override
  Widget build(BuildContext context) {
    final status = context.select<SyncProvider, ConnectionStatus>(
      (p) => p.connectionStatus,
    );
    return Scaffold(
      backgroundColor: const Color(0xff0a1628),
      appBar: RuderbarHeader(
        connectionStatus: status,
        onStatusTap: () => showStatusInfoModal(context),
      ),
      body: child,
    );
  }
}
