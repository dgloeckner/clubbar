import 'dart:async';
import 'package:flutter/foundation.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';

/// Service for monitoring dispenser health status
///
/// Periodically polls the ESP8266 to check dispenser status and track health metrics.
/// Used to warn users about dispenser issues before they attempt to purchase tokens.
class DispenserHealthService extends ChangeNotifier {
  final DispenserClient client;
  final Duration interval;
  Timer? _healthTimer;
  DispenserHealth? _lastHealth;
  DateTime? _lastCheckedAt;

  DispenserHealthService({
    required this.client,
    this.interval = const Duration(seconds: 15),
  });

  /// Get the most recent health check result
  DispenserHealth? get currentHealth => _lastHealth;

  /// When [currentHealth] was observed, in UTC — `null` before the first poll.
  ///
  /// The terminal's own clock, not the device's: an ESP8266 has no wall clock
  /// and reports an uptime instead. It travels as `observed_at` in the status
  /// report (#953), *beside* the backend's receipt stamp and never instead of
  /// it, because a kiosk whose clock is wrong must not be able to date a fault
  /// (ADR-0057).
  DateTime? get lastCheckedAt => _lastCheckedAt;

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

  /// Perform an immediate health check on demand (e.g. when status modal opens).
  Future<void> checkNow() => _performHealthCheck();

  /// Perform a single health check
  Future<void> _performHealthCheck() async {
    try {
      final health = await client.getHealth();
      _lastHealth = health;
      _lastCheckedAt = DateTime.now().toUtc();
      notifyListeners(); // Notify UI of health status change
    } on DispenserProtocolException catch (e) {
      // The device answered — in a protocol this terminal does not speak, or
      // in a shape it could not read. That is *not* offline, and reporting it
      // as offline sent whoever was called out looking for a network fault
      // that did not exist (#948). The dispenser is unavailable either way;
      // only the reason on the screen differs, and the reason is the point.
      _lastHealth =
          DispenserHealth.protocolMismatch(reportedProtocol: e.reportedProtocol);
      _lastCheckedAt = DateTime.now().toUtc();
      notifyListeners();
    } catch (e) {
      // Dispenser offline or unreachable
      _lastHealth = DispenserHealth.offline();
      _lastCheckedAt = DateTime.now().toUtc();
      notifyListeners(); // Notify UI that dispenser went offline
    }
  }

  /// Clean up resources
  @override
  void dispose() {
    stopMonitoring();
    super.dispose();
  }
}
