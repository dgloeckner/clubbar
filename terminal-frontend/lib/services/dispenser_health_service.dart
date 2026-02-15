import 'dart:async';
import 'package:ruderbar_terminal/services/dispenser_client.dart';

/// Service for monitoring dispenser health status
///
/// Periodically polls the ESP8266 to check dispenser status and track health metrics.
/// Used to warn users about dispenser issues before they attempt to purchase tokens.
class DispenserHealthService {
  final DispenserClient client;
  final Duration interval;
  Timer? _healthTimer;
  DispenserHealth? _lastHealth;

  DispenserHealthService({
    required this.client,
    this.interval = const Duration(seconds: 60),
  });

  /// Get the most recent health check result
  DispenserHealth? get currentHealth => _lastHealth;

  /// Start periodic health monitoring
  void startMonitoring() {
    // Cancel existing timer if any
    stopMonitoring();

    // Perform initial health check
    _performHealthCheck();

    // Set up periodic timer
    _healthTimer = Timer.periodic(interval, (_) {
      _performHealthCheck();
    });
  }

  /// Stop health monitoring
  void stopMonitoring() {
    _healthTimer?.cancel();
    _healthTimer = null;
  }

  /// Perform a single health check
  Future<void> _performHealthCheck() async {
    try {
      final health = await client.getHealth();
      _lastHealth = health;
    } catch (e) {
      // Dispenser offline or unreachable
      _lastHealth = DispenserHealth.offline();
    }
  }

  /// Clean up resources
  void dispose() {
    stopMonitoring();
  }
}
