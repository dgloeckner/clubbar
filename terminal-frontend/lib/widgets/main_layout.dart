import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/dispenser_health_service.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';
import 'package:clubbar_terminal/widgets/clubbar_header.dart';
import 'package:clubbar_terminal/widgets/failed_sales_banner.dart';
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
    final session = context.watch<SessionController>();

    return Scaffold(
      backgroundColor: const Color(0xff0a1628),
      appBar: ClubBarHeader(
        connectionStatus: effectiveStatus,
        onStatusTap: () => showStatusInfoModal(context),
      ),
      // Every pointer-down anywhere in the app counts as member activity and
      // resets the session inactivity timer (ADR-0027 rule 6).
      body: Listener(
        behavior: HitTestBehavior.translucent,
        onPointerDown: (_) => session.recordActivity(),
        child: Column(
          children: [
            // Above everything, on every screen: a sale the backend refused is
            // money nobody will ever bill unless staff act (issue #152).
            const FailedSalesBanner(),
            Expanded(
              child: Stack(
                children: [
                  child,
                  if (session.showTimeoutWarning)
                    _TimeoutWarningOverlay(
                      secondsLeft: session.warningSecondsLeft,
                      onContinue: session.recordActivity,
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Full-screen "Still there?" countdown shown before the inactivity timeout
/// ends the session. Any tap dismisses it via the activity Listener above.
class _TimeoutWarningOverlay extends StatelessWidget {
  final int secondsLeft;
  final VoidCallback onContinue;

  const _TimeoutWarningOverlay({
    required this.secondsLeft,
    required this.onContinue,
  });

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;

    return Positioned.fill(
      child: ColoredBox(
        color: const Color(0xd90a1628),
        child: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                l10n.sessionTimeoutWarningTitle,
                style: TextStyle(
                  color: Colors.white,
                  fontSize: AppFontSizes.xxl,
                  fontWeight: FontWeight.bold,
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              Text(
                l10n.sessionTimeoutWarningBody(secondsLeft),
                style: TextStyle(
                  color: const Color(0xff94a3b8),
                  fontSize: AppFontSizes.lg,
                ),
              ),
              const SizedBox(height: AppSpacing.xl),
              FilledButton(
                key: const Key('session-timeout-continue'),
                style: FilledButton.styleFrom(
                  backgroundColor: const Color(0xff3b82f6),
                  padding: const EdgeInsets.symmetric(
                    horizontal: AppSpacing.xl,
                    vertical: AppSpacing.lg,
                  ),
                ),
                onPressed: onContinue,
                child: Text(
                  l10n.sessionTimeoutContinue,
                  style: TextStyle(fontSize: AppFontSizes.lg),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
