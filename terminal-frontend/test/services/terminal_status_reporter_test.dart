import 'package:drift/drift.dart' hide isNull, isNotNull;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';

import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/generated/terminal.enums.swagger.dart'
    as api_enums;
import 'package:clubbar_terminal/generated/terminal.swagger.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:clubbar_terminal/services/dispenser_health_service.dart';
import 'package:clubbar_terminal/services/network_service.dart';
import 'package:clubbar_terminal/services/terminal_status_reporter.dart';

class MockNetworkService extends Mock implements NetworkService {}

class MockDispenserClient extends Mock implements DispenserClient {}

/// A device answering with everything protocol 2 carries.
DispenserHealth healthy({
  DispenserDeviceState state = DispenserDeviceState.idle,
  DispenserFault fault = DispenserFault.none,
  int faultCode = 0,
}) {
  return DispenserHealth(
    protocol: dispenserProtocolVersion,
    state: state,
    fault: fault,
    faultCode: faultCode,
    totalDispenses: 1412,
    successful: 1409,
    jams: 3,
    successRate: 99.8,
    uptime: 86400,
    firmware: '1.2.0',
    resetReason: 'Power On',
    wifi: WifiInfo(rssi: -61, ip: '192.168.4.20', ssid: 'Ponyhof'),
    crashes: 0,
    requestedTokens: 1412,
    dispensedTokens: 1409,
    overrunTokens: 1,
  );
}

void main() {
  late MockNetworkService network;
  late MockDispenserClient client;
  late DispenserHealthService health;

  /// Short enough to keep the suite fast, long enough that several changes
  /// inside one window are genuinely concurrent.
  const debounce = Duration(milliseconds: 40);

  setUpAll(() {
    registerFallbackValue(
      const TerminalStatusReport(dispenser: DispenserStatus(configured: false)),
    );
  });

  setUp(() {
    network = MockNetworkService();
    client = MockDispenserClient();
    when(() => network.reportTerminalStatus(any())).thenAnswer((_) async {});
    // No monitoring timer in tests: every poll is driven by `checkNow()`.
    health = DispenserHealthService(
      client: client,
      interval: const Duration(days: 1),
    );
  });

  /// Every report the network service was handed, in order.
  List<DispenserStatus> reported() => verify(
        () => network.reportTerminalStatus(captureAny()),
      ).captured.cast<TerminalStatusReport>().map((r) => r.dispenser).toList();

  TerminalStatusReporter reporterWith({
    DispenserHealthService? dispenserHealth,
    bool? dispenserConfigured,
    DispenserCountsReader? counts,
  }) {
    return TerminalStatusReporter(
      network: network,
      dispenserHealth: dispenserHealth,
      dispenserConfigured: dispenserConfigured,
      counts: counts,
      debounce: debounce,
    );
  }

  group('reports_on_sync_tick', () {
    test('sends what the last health poll said', () async {
      when(() => client.getHealth()).thenAnswer((_) async => healthy());
      await health.checkNow();

      final reporter = reporterWith(
        dispenserHealth: health,
        counts: () async =>
            const DispenserOperationCounts(pending: 2, manual: 1),
      );

      await reporter.reportNow();

      final sent = reported().single;
      expect(sent.configured, isTrue);
      expect(sent.contact, api_enums.DispenserStatusContact.reported);
      expect(sent.state, api_enums.DispenserStatusState.idle);
      expect(sent.fault, api_enums.DispenserStatusFault.none);
      expect(sent.faultCode, 0);
      expect(sent.firmware, '1.2.0');
      expect(sent.protocol, dispenserProtocolVersion);
      expect(sent.rssi, -61);
      expect(sent.uptimeS, 86400);
      expect(sent.resetReason, 'Power On');
      expect(sent.pendingReconciliations, 2);
      expect(sent.manualReconciliations, 1);
      expect(sent.lifetime?.requestedTokens, 1412);
      expect(sent.lifetime?.dispensedTokens, 1409);
      expect(sent.lifetime?.jams, 3);
      expect(sent.lifetime?.crashes, 0);
      expect(sent.lifetime?.overrunTokens, 1);
      // The mock dispenser does not emit it, and an absent counter is absent
      // rather than zero — a zero would overwrite what the panel knows.
      expect(sent.lifetime?.filteredPulses, isNull);
    });

    test('sends a fault with the code that names it', () async {
      when(() => client.getHealth()).thenAnswer((_) async => healthy(
            state: DispenserDeviceState.fault,
            fault: DispenserFault.hopperError,
            faultCode: 3,
          ));
      await health.checkNow();

      await reporterWith(dispenserHealth: health).reportNow();

      final sent = reported().single;
      expect(sent.state, api_enums.DispenserStatusState.fault);
      expect(sent.fault, api_enums.DispenserStatusFault.hopperError);
      expect(sent.faultCode, 3);
    });

    test('says nothing at all before the first poll has answered', () async {
      await reporterWith(dispenserHealth: health).reportNow();

      verifyNever(() => network.reportTerminalStatus(any()));
    });
  });

  group('reports_configured_false_without_dispenser', () {
    test('a terminal with no dispenser reports that, rather than nothing',
        () async {
      await reporterWith().reportNow();

      final sent = reported().single;
      expect(sent.configured, isFalse);
      expect(sent.contact, isNull);
      expect(sent.state, isNull);
      expect(sent.lifetime, isNull);
      expect(sent.pendingReconciliations, isNull);
    });

    test('a configured dispenser with no client is unreachable, not absent',
        () async {
      // The client failed to build at startup (a bad address, a missing key).
      // There is a machine at the bar and nothing here is reaching it —
      // reporting *no dispenser* would send whoever reads the panel looking
      // for a terminal that has none.
      await reporterWith(
        dispenserConfigured: true,
        counts: () async =>
            const DispenserOperationCounts(pending: 0, manual: 2),
      ).reportNow();

      final sent = reported().single;
      expect(sent.configured, isTrue);
      expect(sent.contact, api_enums.DispenserStatusContact.unreachable);
      expect(sent.state, isNull);
      expect(sent.manualReconciliations, 2);
    });
  });

  group('reports_offline_when_health_poll_fails', () {
    test('an unreachable device is contact unreachable with no state',
        () async {
      when(() => client.getHealth())
          .thenThrow(DispenserException('connection refused'));
      await health.checkNow();

      await reporterWith(
        dispenserHealth: health,
        counts: () async =>
            const DispenserOperationCounts(pending: 1, manual: 0),
      ).reportNow();

      final sent = reported().single;
      expect(sent.configured, isTrue);
      expect(sent.contact, api_enums.DispenserStatusContact.unreachable);
      expect(sent.state, isNull);
      expect(sent.fault, api_enums.DispenserStatusFault.none);
      // Counters the terminal never read must not travel as zeros: the panel
      // would show a hopper that had dispensed nothing in its life.
      expect(sent.lifetime, isNull);
      expect(sent.firmware, isNull);
      expect(sent.uptimeS, isNull);
      // The terminal's own two counts are still its own, and still true.
      expect(sent.pendingReconciliations, 1);
    });

    test('a protocol mismatch is its own contact, with what it claimed',
        () async {
      when(() => client.getHealth())
          .thenThrow(DispenserProtocolException('nope', reportedProtocol: 1));
      await health.checkNow();

      await reporterWith(dispenserHealth: health).reportNow();

      final sent = reported().single;
      expect(sent.contact, api_enums.DispenserStatusContact.protocolMismatch);
      expect(sent.state, isNull);
      expect(sent.protocol, 1);
      expect(sent.lifetime, isNull);
    });
  });

  group('reports_immediately_on_state_change', () {
    test('a fault appearing is reported without waiting for a sync', () async {
      when(() => client.getHealth()).thenAnswer((_) async => healthy());
      await health.checkNow();

      final reporter = reporterWith(dispenserHealth: health);
      reporter.start();
      addTearDown(reporter.dispose);

      when(() => client.getHealth()).thenAnswer((_) async =>
          healthy(state: DispenserDeviceState.fault, fault: DispenserFault.jam));
      await health.checkNow();
      await Future<void>.delayed(debounce * 3);

      expect(reported().single.fault, api_enums.DispenserStatusFault.jam);
    });

    test('a poll that changes nothing sends nothing', () async {
      when(() => client.getHealth()).thenAnswer((_) async => healthy());
      await health.checkNow();

      final reporter = reporterWith(dispenserHealth: health);
      reporter.start();
      addTearDown(reporter.dispose);

      // The cadence has already told the backend where this machine stands.
      await reporter.reportNow();
      clearInteractions(network);

      await health.checkNow();
      await health.checkNow();
      await Future<void>.delayed(debounce * 3);

      verifyNever(() => network.reportTerminalStatus(any()));
    });
  });

  group('debounces_flapping', () {
    test('a device flapping inside one window is reported once, at the end',
        () async {
      when(() => client.getHealth()).thenAnswer((_) async => healthy());
      await health.checkNow();

      final reporter = reporterWith(dispenserHealth: health);
      reporter.start();
      addTearDown(reporter.dispose);

      for (var i = 0; i < 6; i++) {
        when(() => client.getHealth()).thenAnswer((_) async => i.isEven
            ? DispenserHealth.offline()
            : healthy(state: DispenserDeviceState.dispensing));
        await health.checkNow();
      }
      await Future<void>.delayed(debounce * 4);

      final sent = reported();
      expect(sent, hasLength(1));
      // What it finally sends is the truth at the end of the window, not the
      // change that opened it: the last poll of the flap was a device that
      // answered, so that is what the backend hears about.
      expect(sent.single.contact, api_enums.DispenserStatusContact.reported);
      expect(sent.single.state, api_enums.DispenserStatusState.dispensing);
    });
  });

  group('failed_report_is_dropped_not_queued', () {
    test('a refused report is never retried, and never throws', () async {
      when(() => client.getHealth()).thenAnswer((_) async => healthy());
      await health.checkNow();
      when(() => network.reportTerminalStatus(any()))
          .thenThrow(NetworkException('backend down', statusCode: 502));

      final reporter = reporterWith(dispenserHealth: health);
      await reporter.reportNow();

      expect(reported(), hasLength(1));

      // The next cycle carries the status of *that* moment — there is no
      // backlog of stale ones behind it.
      when(() => network.reportTerminalStatus(any())).thenAnswer((_) async {});
      when(() => client.getHealth()).thenAnswer(
          (_) async => healthy(state: DispenserDeviceState.dispensing));
      await health.checkNow();
      await reporter.reportNow();

      final sent = reported();
      expect(sent, hasLength(1));
      expect(sent.single.state, api_enums.DispenserStatusState.dispensing);
    });

    test('a failed report does not silence the next state change', () async {
      when(() => client.getHealth()).thenAnswer((_) async => healthy());
      await health.checkNow();
      when(() => network.reportTerminalStatus(any()))
          .thenThrow(NetworkException('backend down'));

      final reporter = reporterWith(dispenserHealth: health);
      reporter.start();
      addTearDown(reporter.dispose);

      when(() => client.getHealth()).thenAnswer(
          (_) async => healthy(state: DispenserDeviceState.fault, fault: DispenserFault.jam));
      await health.checkNow();
      await Future<void>.delayed(debounce * 3);
      expect(reported(), hasLength(1));

      // Same episode, still unreported: the second attempt has to happen,
      // because the first one never reached anybody.
      when(() => network.reportTerminalStatus(any())).thenAnswer((_) async {});
      await reporter.reportNow();
      expect(reported().single.fault, api_enums.DispenserStatusFault.jam);
    });
  });

  group('payload_contains_no_member_or_tx_ids', () {
    test('the body carries exactly the keys the contract names', () async {
      when(() => client.getHealth()).thenAnswer((_) async => healthy());
      await health.checkNow();

      await reporterWith(
        dispenserHealth: health,
        counts: () async =>
            const DispenserOperationCounts(pending: 1, manual: 1),
      ).reportNow();

      final body = verify(() => network.reportTerminalStatus(captureAny()))
          .captured
          .single as TerminalStatusReport;
      final json = body.toJson();

      expect(json.keys, ['dispenser']);
      expect((json['dispenser'] as Map<String, dynamic>).keys, [
        'configured',
        'contact',
        'state',
        'fault',
        'fault_code',
        'firmware',
        'protocol',
        'rssi',
        'uptime_s',
        'reset_reason',
        'lifetime',
        'pending_reconciliations',
        'manual_reconciliations',
        'observed_at',
      ]);

      // Nothing that names a person or a purchase, at any depth.
      final flat = json.toString();
      for (final forbidden in ['member', 'tx_id', 'card', 'session']) {
        expect(flat.contains(forbidden), isFalse,
            reason: '"$forbidden" must never travel in a status report');
      }
    });

    test('observed_at is the terminal\'s own UTC reading, not a local clock',
        () async {
      when(() => client.getHealth()).thenAnswer((_) async => healthy());
      await health.checkNow();

      await reporterWith(dispenserHealth: health).reportNow();

      final sent = reported().single;
      expect(sent.observedAt, isNotNull);
      expect(sent.observedAt!.isUtc, isTrue);
      expect(sent.observedAt!.toIso8601String(), endsWith('Z'));
    });
  });

  group('DispenserOperationCounts from the tracking table', () {
    late ClubBarDatabase db;

    setUp(() {
      db = ClubBarDatabase.forTesting(NativeDatabase.memory());
    });

    tearDown(() => db.close());

    Future<void> seed(String txId, {String? lastKnownState}) {
      return db.into(db.dispenserOperations).insert(
            DispenserOperationsCompanion.insert(
              dispenserTxId: txId,
              memberId: 'member-1',
              productId: 'product-1',
              priceCents: 100,
              requestedQty: 1,
              createdAt: DateTime.now().toUtc().toIso8601String(),
              lastKnownState: Value(lastKnownState),
            ),
          );
    }

    test('counts what is still open apart from what a human must settle',
        () async {
      await seed('a');
      await seed('b', lastKnownState: 'dispensing');
      await seed('c', lastKnownState: 'not_found');

      final counts = await dispenserOperationCounts(db)();

      expect(counts.pending, 2);
      expect(counts.manual, 1);
    });

    test('an empty table is two zeros, not a missing report', () async {
      final counts = await dispenserOperationCounts(db)();

      expect(counts.pending, 0);
      expect(counts.manual, 0);
    });
  });
}
