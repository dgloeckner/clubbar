import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/dispenser_health_service.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:clubbar_terminal/widgets/clubbar_header.dart';
import 'package:clubbar_terminal/widgets/status_info_modal.dart';

/// Main layout with persistent header across all screens.
/// Only the body content animates during navigation.
class MainLayout extends StatelessWidget {
  final Widget child;

  const MainLayout({
    super.key,
    required this.child,
  });

  /// Compute effective connection status considering both backend and dispenser
  ConnectionStatus _computeEffectiveStatus(
    ConnectionStatus backendStatus,
    DispenserHealth? dispenserHealth,
  ) {
    // If backend is offline or error, that takes precedence
    if (backendStatus == ConnectionStatus.offline ||
        backendStatus == ConnectionStatus.error) {
      return backendStatus;
    }

    // Backend is online - check dispenser
    if (dispenserHealth != null) {
      final isDispenserOffline =
          dispenserHealth.dispenser == 'offline' ||
          dispenserHealth.status == 'error';

      if (isDispenserOffline) {
        // Show warning when backend is online but dispenser is offline
        return ConnectionStatus.error;
      }
    }

    // Everything is good
    return backendStatus;
  }

  @override
  Widget build(BuildContext context) {
    final backendStatus = context.select<SyncProvider, ConnectionStatus>(
      (p) => p.connectionStatus,
    );

    // Try to get dispenser health (may not be available if dispenser disabled)
    DispenserHealth? dispenserHealth;
    try {
      final healthService = context.watch<DispenserHealthService>();
      dispenserHealth = healthService.currentHealth;
    } catch (_) {
      // Dispenser not configured
    }

    final effectiveStatus = _computeEffectiveStatus(backendStatus, dispenserHealth);

    return Scaffold(
      backgroundColor: const Color(0xff0a1628),
      appBar: ClubBarHeader(
        connectionStatus: effectiveStatus,
        onStatusTap: () => showStatusInfoModal(context),
      ),
      body: child,
    );
  }
}
