import 'dart:async';

import 'package:flutter/foundation.dart';

import 'package:clubbar_terminal/services/cart_service.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:clubbar_terminal/utils/app_logger.dart';

/// Where one dispense has got to, as far as the *terminal* is concerned.
///
/// Deliberately not the same vocabulary as the device's `state` field
/// (`dispensing` / `done` / `error`). This says what the terminal is doing —
/// waiting for the POST, polling, or finished with it — and is what the dialog
/// draws. What the *device* last said is [DispenseSession.lastReportedState]
/// and travels out in the [DispenseResult]; the two are never mixed, because
/// mixing them is what let a polling timeout be reported to `CartProvider` as
/// a completed dispense (#946).
enum DispensePhase {
  idle,
  requesting,
  dispensing,
  finished,
  failed,
}

/// The dispensing state machine: POST `/dispense`, then poll until the device
/// reports a final state, the poll deadline passes, or the session is
/// abandoned.
///
/// A plain class, with no widget and no `BuildContext`.
/// [DispensingProgressDialog] drives one of these and draws its [phase];
/// `flow_test/` drives one against the Go mock over real HTTP. That is the
/// point of it being here rather than inside the widget: a `StatefulWidget`
/// cannot be run against a real server from a plain `test()` — `flutter_test`
/// replaces `HttpClient` and fakes timers — so the flow suite used to drive a
/// *copy* of this machine (`flow_test/support/flow_dispense_session.dart`),
/// and a copy is exactly as good as the day someone last kept it in step.
///
/// Two behaviours here are the substance of #946 and must not be "simplified"
/// back:
///
/// * **Nothing invents a final state.** When the poll deadline passes with
///   tokens already seen, the result carries the last state the *device*
///   reported — `dispensing` — so `CartProvider` keeps the tracking row and
///   reconciliation bills whatever else falls. Reporting `done` there deleted
///   the row while the hopper was still running, and the tokens that landed
///   afterwards had nothing left to reconcile against (finding 5).
/// * **Polls are serial.** The next GET is scheduled only after the previous
///   one has answered or failed — never by a `Timer.periodic` that fires
///   regardless. A slow device used to collect up to twelve open connections;
///   an ESP8266 has a handful of TCP slots, and running out of heap mid-
///   dispense is how it resets (finding 7).
class DispenseSession extends ChangeNotifier {
  DispenseSession({
    required DispenserClient client,
    required CartService cartService,
    required this.txId,
    required this.quantity,
    this.pollInterval = const Duration(milliseconds: 500),
    this.requestTimeout = const Duration(seconds: 30),
    this.timeoutPerToken = const Duration(seconds: 10),
    this.maxRetries = 3,
    this.retryDelay = const Duration(seconds: 1),
  })  : _client = client,
        _cartService = cartService;

  final DispenserClient _client;
  final CartService _cartService;

  final String txId;
  final int quantity;

  /// How long to wait between the answer to one poll and the next request.
  /// 500 ms by default (#946) — with serial polling the interval is a gap
  /// between requests, not a rate at which they are launched.
  final Duration pollInterval;

  /// Ceiling on the POST phase, retries included.
  final Duration requestTimeout;

  /// Multiplied by [quantity] for the polling phase.
  final Duration timeoutPerToken;

  final int maxRetries;
  final Duration retryDelay;

  DispensePhase _phase = DispensePhase.idle;
  DispensePhase get phase => _phase;

  int _dispensed = 0;

  /// The highest token count the device has reported. It never goes backwards:
  /// a late answer carrying an older, lower count would otherwise walk the
  /// progress dots back and — before the counts became the bill's high-water
  /// mark — undercharge (#946).
  int get dispensed => _dispensed;

  String? _lastReportedState;

  /// The last `state` the device itself sent, or null if it never answered.
  String? get lastReportedState => _lastReportedState;

  DispenserException? _error;

  /// Why the session failed, when [phase] is [DispensePhase.failed].
  DispenserException? get error => _error;

  bool _abandoned = false;
  bool _disposed = false;

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }

  /// [notifyListeners] after `dispose()` throws, and the session outlives the
  /// dialog by however long the last request takes to answer: the widget is
  /// gone, its listener with it, and the loop is still unwinding.
  void _notify() {
    if (_disposed) return;
    notifyListeners();
  }

  /// Stop at the next opportunity: the dialog has gone away.
  ///
  /// The in-flight request is *not* cancelled — `package:http` has no cancel,
  /// and the client is the app's one shared [DispenserClient] (closing it
  /// would take the recovery and health services down with it). What abandoning
  /// buys is that its answer changes nothing and no further request is sent.
  void abandon() {
    _abandoned = true;
  }

  /// Runs the machine to its end.
  ///
  /// Returns what the device last said — never a state it did not say — or
  /// null when the dispense failed outright, in which case [error] says why.
  Future<DispenseResult?> run() async {
    await _setPollingActive(true);
    try {
      final started = await _post();
      if (started == null) return null;
      if (started.state == 'done') return _finish(started);
      return await _poll();
    } finally {
      await _setPollingActive(false);
    }
  }

  Future<DispenseResult?> _post() async {
    _enter(DispensePhase.requesting);

    final deadline = _Deadline(requestTimeout);
    var attempt = 0;

    while (true) {
      if (_abandoned) return null;
      try {
        final result =
            await _client.dispenseTokens(txId: txId, quantity: quantity);
        if (_abandoned) return null;
        _observe(result);
        await _track(
            state: result.state, dispensed: _dispensed, acknowledged: true);
        return result;
      } on DispenserBusyException catch (e) {
        return _fail(e);
      } on DispenserNotFoundException catch (e) {
        return _fail(e);
      } on DispenserException catch (e) {
        if (attempt >= maxRetries || deadline.passed) {
          return _fail(DispenserException(
              'Request failed after $maxRetries retries: ${e.message}'));
        }
        attempt++;
        AppLog.instance
            .w('Dispense request failed, retry $attempt/$maxRetries: ${e.message}');
        await Future<void>.delayed(retryDelay);
      }
    }
  }

  Future<DispenseResult?> _poll() async {
    _enter(DispensePhase.dispensing);

    final deadline = _Deadline(timeoutPerToken * quantity);

    while (true) {
      if (_abandoned) return null;

      if (deadline.passed) {
        // The poll phase ran out. That says the *terminal* stopped watching —
        // usually because the network went away — and says nothing at all
        // about the hopper, which may still be turning. So: report the last
        // state the device actually reported (`dispensing`), which makes
        // `CartProvider` keep the tracking row for reconciliation, and never
        // a fabricated `done` (finding 5, #946).
        if (_dispensed > 0) {
          return _finish(DispenseResult(
            txId: txId,
            state: _lastReportedState ?? 'dispensing',
            quantity: quantity,
            dispensed: _dispensed,
          ));
        }
        return _fail(DispenserException(
            'Polling timeout after ${timeoutPerToken * quantity}'));
      }

      await Future<void>.delayed(pollInterval);
      if (_abandoned) return null;

      // The heartbeat is written for the *attempt*, before the request, not
      // for the answer. `lastPolledAt` is what tells the recovery service that
      // this dispense has an owner; during a WiFi dropout every poll fails,
      // and writing it only on success let the timestamp age past 30 s while
      // the dialog was very much alive (#945).
      await _track();

      try {
        final result = await _client.getStatus(txId);
        if (_abandoned) return null;
        _observe(result);
        await _track(
            state: result.state, dispensed: _dispensed, acknowledged: true);

        if (result.state == 'done') return _finish(result);
        if (result.state == 'error') return _finish(result);
      } on DispenserNotFoundException catch (e) {
        return _fail(e);
      } on DispenserException catch (e) {
        // Network trouble mid-poll: keep trying until the deadline. The next
        // request goes out only now, after this one has failed — that is what
        // keeps the device down to one open connection.
        AppLog.instance.w('Polling error: ${e.message}');
      }
    }
  }

  /// Takes in what the device said, without ever letting the count regress.
  void _observe(DispenseResult result) {
    _lastReportedState = result.state;
    if (result.dispensed > _dispensed) {
      _dispensed = result.dispensed;
      _notify();
    }
  }

  DispenseResult _finish(DispenseResult result) {
    _enter(DispensePhase.finished);
    return result;
  }

  Null _fail(DispenserException e) {
    _error = e;
    _enter(DispensePhase.failed);
    return null;
  }

  void _enter(DispensePhase phase) {
    if (_phase == phase) return;
    _phase = phase;
    _notify();
  }

  /// Writes down what the device just said — and, with [acknowledged], the
  /// bare fact *that* it said something.
  ///
  /// The two `_track` calls that pass `acknowledged: true` are exactly the two
  /// places a response from the device has been parsed: the answer to the POST
  /// and the answer to a poll. The heartbeat call before each request does not
  /// pass it, and neither does anything outside this class — a request that
  /// went out is not an acknowledgement, which is the whole point of the flag
  /// (#947).
  Future<void> _track(
      {String? state, int? dispensed, bool acknowledged = false}) async {
    await _cartService.updateDispenserOperationState(
      dispenserTxId: txId,
      state: state,
      lastKnownDispensed: dispensed,
      lastPolledAt: DateTime.now().toUtc().toIso8601String(),
      acknowledged: acknowledged,
    );
  }

  Future<void> _setPollingActive(bool active) async {
    await _cartService.updateDispenserOperationState(
      dispenserTxId: txId,
      pollingActive: active ? 1 : 0,
      lastPolledAt: DateTime.now().toUtc().toIso8601String(),
    );
  }
}

/// A deadline on the wall clock.
///
/// Deliberately not a [Timer]: the phases here are `await`ed loops, and a timer
/// firing beside them is a second thing that can decide the session is over.
/// One loop, one deadline it checks itself.
class _Deadline {
  _Deadline(this.budget) : _start = DateTime.now();

  final Duration budget;
  final DateTime _start;

  bool get passed => DateTime.now().difference(_start) >= budget;
}
