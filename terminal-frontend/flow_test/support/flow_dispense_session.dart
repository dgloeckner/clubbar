import 'dart:async';

import 'package:clubbar_terminal/services/cart_service.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';

/// The dispensing state machine, without the widget around it.
///
/// `DispensingProgressDialog` owns the real one today: POST with retries, then
/// polling, then done / partial / error. A `StatefulWidget` cannot be driven
/// against a real HTTP server from a plain `test()` — `flutter_test` replaces
/// `HttpClient` and runs timers in a fake zone — so the flow suite drives this
/// replica instead, over the same [DispenserClient] and the same
/// [CartService] tracking writes.
///
/// **This is a stand-in, and it is meant to disappear.** Its constants and
/// branches are copied from `DispensingProgressDialog` (see the mapping in each
/// member below). #946 pulls that state machine out of the widget into a
/// plain class; the moment it does, this file should be deleted and the
/// harness pointed at the real one. Until then: a change to the dialog's
/// behaviour is a change here too, and `flow_test/README.md` says so.
///
/// Differences from the widget, all deliberate:
/// * every duration is injected, so a scenario that takes the device 5 s takes
///   the suite well under that;
/// * polling is **serial** — one request at a time, the next scheduled only
///   after the previous answered. The widget uses `Timer.periodic`, which can
///   stack them (finding 7). The proxy asserts the serial property; when #946
///   lands, that assertion starts covering production code.
class FlowDispenseSession {
  FlowDispenseSession({
    required this.client,
    required this.cartService,
    required this.txId,
    required this.quantity,
    this.pollInterval = const Duration(milliseconds: 50),
    this.requestTimeout = const Duration(seconds: 5),
    this.timeoutPerToken = const Duration(seconds: 2),
    this.maxRetries = 3,
    this.retryDelay = const Duration(milliseconds: 100),
  });

  final DispenserClient client;
  final CartService cartService;
  final String txId;
  final int quantity;

  /// `config.dispenserPollIntervalMs` in the widget (250 ms in production).
  final Duration pollInterval;

  /// `_maxRequestTimeout` (30 s) — the ceiling on the POST phase.
  final Duration requestTimeout;

  /// `_timeoutPerToken` (10 s) — multiplied by [quantity] for the poll phase.
  final Duration timeoutPerToken;

  /// `_maxRetries` (3) and `_retryDelayMs` (1000).
  final int maxRetries;
  final Duration retryDelay;

  /// What `onError` received, if anything.
  DispenserException? error;

  int _dispensed = 0;
  int get dispensed => _dispensed;

  /// Runs the machine to its end and returns what `onComplete` would have been
  /// given — or null when the dialog would have closed with an error.
  Future<DispenseResult?> run() async {
    await _setPollingActive(true);
    try {
      final started = await _post();
      if (started == null) return null;
      return await _poll();
    } finally {
      await _setPollingActive(false);
    }
  }

  Future<DispenseResult?> _post() async {
    final deadline = DateTime.now().add(requestTimeout);
    var attempt = 0;

    while (true) {
      try {
        final result =
            await client.dispenseTokens(txId: txId, quantity: quantity);
        _dispensed = result.dispensed;
        await cartService.updateDispenserOperationState(
          dispenserTxId: txId,
          state: result.state,
          lastKnownDispensed: result.dispensed,
          lastPolledAt: DateTime.now().toUtc().toIso8601String(),
        );
        return result;
      } on DispenserBusyException catch (e) {
        return _fail(e);
      } on DispenserNotFoundException catch (e) {
        return _fail(e);
      } on DispenserException catch (e) {
        if (attempt >= maxRetries || DateTime.now().isAfter(deadline)) {
          return _fail(DispenserException(
              'Request failed after $maxRetries retries: ${e.message}'));
        }
        attempt++;
        await Future<void>.delayed(retryDelay);
      }
    }
  }

  Future<DispenseResult?> _poll() async {
    final deadline = DateTime.now().add(timeoutPerToken * quantity);

    while (true) {
      if (DateTime.now().isAfter(deadline)) {
        // The widget's polling timeout: some tokens is "partial success",
        // none is an error. Finding 5 (#946) is that the first branch reports
        // `done` for a device that may still be dispensing.
        if (_dispensed > 0) return _partial();
        return _fail(DispenserException(
            'Polling timeout after ${timeoutPerToken * quantity}'));
      }

      await Future<void>.delayed(pollInterval);

      // The widget writes the heartbeat for the attempt, before the request
      // (#945) — a failing poll must not let the row look abandoned.
      await cartService.updateDispenserOperationState(
        dispenserTxId: txId,
        lastPolledAt: DateTime.now().toUtc().toIso8601String(),
      );

      try {
        final result = await client.getStatus(txId);
        _dispensed = result.dispensed;
        await cartService.updateDispenserOperationState(
          dispenserTxId: txId,
          state: result.state,
          lastKnownDispensed: result.dispensed,
          lastPolledAt: DateTime.now().toUtc().toIso8601String(),
        );

        if (result.state == 'done') return result;
        if (result.state == 'error') {
          if (_dispensed > 0) return _partial();
          return _fail(DispenserException('Dispenser reported error'));
        }
      } on DispenserNotFoundException catch (e) {
        return _fail(e);
      } on DispenserException {
        // Network trouble mid-poll: keep trying until the deadline.
      }
    }
  }

  /// The widget's `_handlePartialSuccess`: a result that claims `done` with
  /// fewer tokens than asked for.
  DispenseResult _partial() => DispenseResult(
        txId: txId,
        state: 'done',
        quantity: quantity,
        dispensed: _dispensed,
      );

  Null _fail(DispenserException e) {
    error = e;
    return null;
  }

  Future<void> _setPollingActive(bool active) async {
    await cartService.updateDispenserOperationState(
      dispenserTxId: txId,
      pollingActive: active ? 1 : 0,
      lastPolledAt: DateTime.now().toUtc().toIso8601String(),
    );
  }
}
