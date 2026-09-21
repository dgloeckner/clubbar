import 'dart:async';

import 'package:logger/logger.dart';

import '../database/database.dart';
import '../generated/terminal.enums.swagger.dart' as api_enums;
import '../generated/terminal.swagger.dart';
import 'dispenser_client.dart';
import 'dispenser_health_service.dart';
import 'network_service.dart';

/// How many tracked dispense operations this terminal is carrying.
///
/// Two counts, and they are not two shades of the same thing (#947):
/// [pending] is work the terminal still expects to settle by itself on a
/// reconciliation tick, while [manual] is the short list a **human** has to
/// go through — rows the device acknowledged and then lost. A checkout
/// attempted against a dark dispenser is in neither: nothing was dispensed,
/// the row is closed, and it stopped being *manual reconciliation required*
/// when #947 landed.
class DispenserOperationCounts {
  const DispenserOperationCounts({required this.pending, required this.manual});

  /// Open operations the terminal will still try to settle on its own.
  final int pending;

  /// Operations on the *manual reconciliation required* list.
  final int manual;

  static const none = DispenserOperationCounts(pending: 0, manual: 0);
}

/// Where [TerminalStatusReporter] gets its two counts from.
typedef DispenserCountsReader = Future<DispenserOperationCounts> Function();

/// Counts the tracking table, split the way [DispenserOperationCounts]
/// describes.
///
/// `not_found` is the one state that means *a human has to settle this*: the
/// device accepted the transaction and then denied knowing it, so tokens may
/// be on the floor and nobody but a person can say how many
/// (`DispenserRecoveryService._resolveNotFound`). Everything else in the table
/// is still the terminal's own work.
DispenserCountsReader dispenserOperationCounts(ClubBarDatabase database) {
  return () async {
    final rows = await database.select(database.dispenserOperations).get();
    var manual = 0;
    for (final row in rows) {
      if (row.lastKnownState == 'not_found') manual++;
    }
    return DispenserOperationCounts(
      pending: rows.length - manual,
      manual: manual,
    );
  };
}

/// Tells the backend what this terminal knows about its dispenser
/// (ADR-0057, #953).
///
/// The backend cannot ask: the dispenser sits on the club's LAN and the
/// terminal is the only thing that reaches both. So a fault, an empty hopper,
/// an unplugged controller or a firmware speaking the wrong protocol is
/// visible to an operator **only** because this class says so.
///
/// Three properties are the whole design, and each one is a rule rather than a
/// preference:
///
/// - **It reports on the sync cadence *and* the moment something changes.**
///   Without the cadence, silence would mean both "nothing changed" and "the
///   terminal is gone". Without the change trigger, a jam would wait out the
///   sync interval with a member standing in front of it.
/// - **It cannot fail a sync cycle.** Every path through [reportNow] ends in a
///   completed future; a failure is a log line. A bar that cannot sell beer
///   because a peripheral's telemetry was refused is the failure this exists
///   to prevent, not an acceptable one.
/// - **It reports facts, never a verdict.** `available`, `unavailable_reason`
///   and `state_since` are the backend's to derive. [DispenserHealth] already
///   computes its own verdict for the kiosk, and serialising it would let the
///   panel and the kiosk describe one machine differently with nothing to
///   adjudicate.
///
/// Counts only. The terminal's `dispenser_operations` rows carry a member id
/// and a transaction id; none of that may ever appear in this document.
class TerminalStatusReporter {
  /// How long a change waits before it is reported.
  ///
  /// A Wi-Fi link that flaps produces a state change per poll, and each one
  /// would otherwise be a request. The window collapses a storm into one
  /// report carrying the state the machine *settled* in — and it is a ceiling,
  /// not a sliding window: the timer is started by the first change and never
  /// restarted, so a device flapping forever is still reported every five
  /// seconds rather than never.
  static const Duration defaultDebounce = Duration(seconds: 5);

  final NetworkService _network;

  /// The dispenser's health, or `null` when no dispenser is configured — which
  /// is itself a report (`configured: false`), not a reason to stay silent.
  final DispenserHealthService? _health;

  /// Whether a dispenser is configured at this terminal at all.
  ///
  /// Normally the same thing as having a health service, and deliberately
  /// separable: a terminal whose dispenser is configured but whose client
  /// could not be built at startup has no health to report and is **not** a
  /// terminal without a dispenser. Reporting it as `configured: false` would
  /// print *no dispenser* on the panel for a machine standing at the bar.
  final bool _configured;

  final DispenserCountsReader? _counts;
  final Duration _debounce;
  final Logger _logger;

  Timer? _pending;
  bool _sending = false;

  /// The episode the last **delivered** report described, so an unchanged poll
  /// costs nothing and a report that never arrived is not treated as sent.
  String? _reportedEpisode;

  TerminalStatusReporter({
    required NetworkService network,
    DispenserHealthService? dispenserHealth,
    bool? dispenserConfigured,
    DispenserCountsReader? counts,
    Duration debounce = defaultDebounce,
    Logger? logger,
  })  : _network = network,
        _health = dispenserHealth,
        _configured = dispenserConfigured ?? dispenserHealth != null,
        _counts = counts,
        _debounce = debounce,
        _logger = logger ?? Logger();

  /// Watch the health service and report a change as it happens.
  ///
  /// `DispenserHealthService` polls the device on its own timer and notifies
  /// on every result, which is exactly the signal needed here — no second
  /// timer, and no second thing talking to the device's handful of TCP slots.
  void start() {
    _health?.addListener(_onHealthChanged);
  }

  /// Stop watching. Safe to call twice, and safe to call without [start].
  void dispose() {
    _health?.removeListener(_onHealthChanged);
    _pending?.cancel();
    _pending = null;
  }

  /// Send the current status, now.
  ///
  /// Called on every sync cycle and by the debounce timer. **Never throws and
  /// never rethrows**: the caller is a sync cycle, and telemetry that can fail
  /// one is worse than no telemetry at all.
  Future<void> reportNow() async {
    if (_sending) {
      // A report is already on the wire. Dropping this one costs nothing: the
      // next cadence or change carries the truth of *its* moment, and a queue
      // of stale statuses has no value to anybody (ADR-0057).
      return;
    }
    _sending = true;
    try {
      final report = await _build();
      if (report == null) return;

      await _network.reportTerminalStatus(report);
      _reportedEpisode = _episodeOf(report.dispenser);
    } catch (e) {
      // Dropped, not queued. The next report carries the current truth.
      _logger.w('Dispenser status not reported: $e');
    } finally {
      _sending = false;
    }
  }

  void _onHealthChanged() {
    final health = _health?.currentHealth;
    if (health == null) return;

    final episode = _episodeOfHealth(health);
    if (episode == _reportedEpisode) return;
    if (_pending != null) return;

    _pending = Timer(_debounce, () {
      _pending = null;
      // Unawaited on purpose: this is a timer callback, and the method it
      // calls swallows its own failures.
      unawaited(reportNow());
    });
  }

  /// The report to send, or `null` when there is nothing yet to say.
  Future<TerminalStatusReport?> _build() async {
    if (!_configured) {
      // A terminal with no dispenser says so. The panel has to be able to
      // show *no dispenser* rather than *unknown*, and only a report can tell
      // those apart.
      return const TerminalStatusReport(
        dispenser: DispenserStatus(configured: false),
      );
    }

    final health = _health;
    if (health == null) {
      // Configured, and there is nothing here that can talk to it — the
      // client failed to start. From the panel's side that is a dispenser
      // nobody is reaching, which is exactly what `unreachable` says.
      final counts = await _readCounts();
      return TerminalStatusReport(
        dispenser: DispenserStatus(
          configured: true,
          contact: api_enums.DispenserStatusContact.unreachable,
          fault: api_enums.DispenserStatusFault.none,
          faultCode: 0,
          pendingReconciliations: counts?.pending,
          manualReconciliations: counts?.manual,
        ),
      );
    }

    final current = health.currentHealth;
    if (current == null) {
      // The first poll has not answered yet. There is nothing to report that
      // would not be a guess, and the window is one poll wide.
      return null;
    }

    final reported = current.contact == DispenserContact.reported;
    final state = reported ? current.state : null;
    if (reported && state == null) {
      // A device that answered must say what it is doing; without it the
      // backend has no verdict to render. Not producible by a conforming
      // device — `DispenserHealth.fromJson` refuses a document with no state.
      _logger.w('Dispenser reported without a state; nothing to report');
      return null;
    }

    final counts = await _readCounts();

    return TerminalStatusReport(
      dispenser: DispenserStatus(
        configured: true,
        contact: _contact(current.contact),
        state: _state(state),
        fault: _fault(current.fault),
        faultCode: current.faultCode,
        // Everything below is what the device *said*. A machine we did not
        // reach said nothing, and sending the zeros `DispenserHealth.offline()`
        // carries would tell the panel a hopper had dispensed nothing in its
        // life. `protocol` is the exception: on a mismatch it is the claim
        // that caused the mismatch, and it is the one number that names the
        // errand.
        firmware: reported ? current.firmware : null,
        protocol: current.protocol,
        rssi: reported ? current.wifi?.rssi : null,
        uptimeS: reported ? current.uptime : null,
        resetReason: reported ? current.resetReason : null,
        lifetime: reported ? _lifetime(current) : null,
        // The terminal's own two counts, true whether the device answered or
        // not — they are rows in its database, not readings from the machine.
        pendingReconciliations: counts?.pending,
        manualReconciliations: counts?.manual,
        observedAt: health.lastCheckedAt,
      ),
    );
  }

  Future<DispenserOperationCounts?> _readCounts() async {
    final counts = _counts;
    if (counts == null) return null;
    try {
      return await counts();
    } catch (e) {
      // A database that will not answer costs the two counts, not the report:
      // contact, state and fault are the fields somebody is waiting on.
      _logger.w('Could not count dispenser operations: $e');
      return null;
    }
  }

  DispenserLifetimeCounters _lifetime(DispenserHealth health) {
    return DispenserLifetimeCounters(
      requestedTokens: health.requestedTokens,
      dispensedTokens: health.dispensedTokens,
      jams: health.jams,
      crashes: health.crashes,
      overrunTokens: health.overrunTokens,
      // Absent rather than zero when the device does not publish it — the
      // mock does not, and a zero would overwrite a real count in the panel.
      filteredPulses: health.filteredPulses,
    );
  }

  /// What makes two reports the same episode — the backend's own rule
  /// (`DispenserStatusReport::episodeKey()`), applied here so that an
  /// unchanged poll costs no request at all.
  String _episodeOf(DispenserStatus dispenser) {
    return [
      dispenser.configured ? 'configured' : 'absent',
      dispenser.contact?.value ?? '-',
      dispenser.state?.value ?? '-',
      dispenser.fault?.value ?? 'none',
      '${dispenser.faultCode ?? 0}',
    ].join('|');
  }

  String _episodeOfHealth(DispenserHealth health) {
    final reported = health.contact == DispenserContact.reported;
    return [
      'configured',
      _contact(health.contact).value ?? '-',
      (reported ? _state(health.state) : null)?.value ?? '-',
      _fault(health.fault).value ?? 'none',
      '${health.faultCode}',
    ].join('|');
  }

  api_enums.DispenserStatusContact _contact(DispenserContact contact) {
    return switch (contact) {
      DispenserContact.reported => api_enums.DispenserStatusContact.reported,
      DispenserContact.unreachable =>
        api_enums.DispenserStatusContact.unreachable,
      DispenserContact.protocolMismatch =>
        api_enums.DispenserStatusContact.protocolMismatch,
    };
  }

  api_enums.DispenserStatusState? _state(DispenserDeviceState? state) {
    return switch (state) {
      DispenserDeviceState.idle => api_enums.DispenserStatusState.idle,
      DispenserDeviceState.dispensing =>
        api_enums.DispenserStatusState.dispensing,
      DispenserDeviceState.fault => api_enums.DispenserStatusState.fault,
      null => null,
    };
  }

  api_enums.DispenserStatusFault _fault(DispenserFault fault) {
    return switch (fault) {
      DispenserFault.none => api_enums.DispenserStatusFault.none,
      DispenserFault.jam => api_enums.DispenserStatusFault.jam,
      DispenserFault.hopperError =>
        api_enums.DispenserStatusFault.hopperError,
    };
  }
}
