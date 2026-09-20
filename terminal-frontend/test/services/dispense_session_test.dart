// The dispensing state machine, lifted out of the dialog in #946 so that what
// it does when the device answers — and when it stops answering — can be
// tested without a widget tree.
import 'package:clubbar_terminal/services/cart_service.dart';
import 'package:clubbar_terminal/services/dispense_session.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';

class MockCartService extends Mock implements CartService {}

class MockDispenserClient extends Mock implements DispenserClient {}

void main() {
  late MockCartService cartService;
  late MockDispenserClient client;

  setUp(() {
    cartService = MockCartService();
    client = MockDispenserClient();
    when(() => cartService.updateDispenserOperationState(
          dispenserTxId: any(named: 'dispenserTxId'),
          state: any(named: 'state'),
          transactionsCreated: any(named: 'transactionsCreated'),
          lastKnownDispensed: any(named: 'lastKnownDispensed'),
          pollingActive: any(named: 'pollingActive'),
          lastPolledAt: any(named: 'lastPolledAt'),
          acknowledged: any(named: 'acknowledged'),
        )).thenAnswer((_) async => (true, null));
  });

  DispenseSession session({
    int quantity = 3,
    Duration pollInterval = const Duration(milliseconds: 1),
    Duration timeoutPerToken = const Duration(milliseconds: 20),
  }) =>
      DispenseSession(
        client: client,
        cartService: cartService,
        txId: 'tx-session',
        quantity: quantity,
        pollInterval: pollInterval,
        timeoutPerToken: timeoutPerToken,
        requestTimeout: const Duration(seconds: 1),
        retryDelay: const Duration(milliseconds: 1),
      );

  void acceptsDispense({String state = 'dispensing', int dispensed = 0}) {
    when(() => client.dispenseTokens(
            txId: any(named: 'txId'), quantity: any(named: 'quantity')))
        .thenAnswer((_) async => DispenseResult(
            txId: 'tx-session',
            state: state,
            quantity: 3,
            dispensed: dispensed));
  }

  test('a polling timeout reports the last state the device reported, never done',
      () async {
    // The classic dropout: two tokens have fallen, then the network goes away
    // and the device never says it is finished. The hopper may well still be
    // turning — reporting `done` here is what deleted the tracking row and
    // left the rest of the tokens with nothing to reconcile against
    // (finding 5, #946).
    acceptsDispense();
    when(() => client.getStatus(any())).thenAnswer((_) async => DispenseResult(
        txId: 'tx-session', state: 'dispensing', quantity: 3, dispensed: 2));

    final result = await session().run();

    expect(result, isNotNull);
    expect(result!.state, 'dispensing',
        reason: 'a timeout is not a completed dispense');
    expect(result.dispensed, 2);
  });

  test('a polling timeout with nothing dispensed fails rather than reporting a '
      'dispense', () async {
    acceptsDispense();
    when(() => client.getStatus(any())).thenAnswer((_) async => DispenseResult(
        txId: 'tx-session', state: 'dispensing', quantity: 3, dispensed: 0));

    final s = session();
    final result = await s.run();

    expect(result, isNull);
    expect(s.phase, DispensePhase.failed);
    expect(s.error, isA<DispenserException>());
  });

  test('a jam is passed on as the device reported it, tokens and all', () async {
    acceptsDispense();
    when(() => client.getStatus(any())).thenAnswer((_) async => DispenseResult(
        txId: 'tx-session', state: 'error', quantity: 3, dispensed: 1));

    final result = await session().run();

    expect(result!.state, 'error');
    expect(result.dispensed, 1);
  });

  test('never more than one status request is in flight', () async {
    // The device is slow to answer — the case that used to stack twelve
    // connections on an ESP8266's handful of TCP slots (finding 7, #946).
    var inFlight = 0;
    var maxInFlight = 0;
    var answered = 0;

    acceptsDispense();
    when(() => client.getStatus(any())).thenAnswer((_) async {
      inFlight++;
      if (inFlight > maxInFlight) maxInFlight = inFlight;
      await Future<void>.delayed(const Duration(milliseconds: 30));
      inFlight--;
      answered++;
      return DispenseResult(
        txId: 'tx-session',
        state: answered >= 3 ? 'done' : 'dispensing',
        quantity: 3,
        dispensed: answered,
      );
    });

    final result = await session(
      pollInterval: const Duration(milliseconds: 1),
      timeoutPerToken: const Duration(seconds: 5),
    ).run();

    expect(result!.state, 'done');
    expect(answered, greaterThan(1),
        reason: 'the test only means something if it polled more than once');
    expect(maxInFlight, 1);
  });

  test('a stale answer with a lower count does not move progress backwards',
      () async {
    final seen = <int>[];
    var call = 0;

    acceptsDispense();
    when(() => client.getStatus(any())).thenAnswer((_) async {
      call++;
      // Out-of-order answers: 2, then a straggler from before it, then the end.
      final counts = [2, 1, 3];
      final dispensed = counts[call.clamp(1, counts.length) - 1];
      return DispenseResult(
        txId: 'tx-session',
        state: call >= 3 ? 'done' : 'dispensing',
        quantity: 3,
        dispensed: dispensed,
      );
    });

    final s = session(timeoutPerToken: const Duration(seconds: 5));
    s.addListener(() => seen.add(s.dispensed));

    final result = await s.run();

    expect(result!.dispensed, 3);
    expect(seen, isNot(contains(1)),
        reason: 'the progress dots must never walk back');
    for (var i = 1; i < seen.length; i++) {
      expect(seen[i], greaterThanOrEqualTo(seen[i - 1]));
    }
  });

  test('an abandoned session stops talking to the device', () async {
    acceptsDispense();
    when(() => client.getStatus(any())).thenAnswer((_) async => DispenseResult(
        txId: 'tx-session', state: 'dispensing', quantity: 3, dispensed: 1));

    final s = session(
      pollInterval: const Duration(milliseconds: 5),
      timeoutPerToken: const Duration(seconds: 5),
    );
    final run = s.run();
    await Future<void>.delayed(const Duration(milliseconds: 20));
    s.abandon();

    expect(await run, isNull);
    final pollsWhileAlive = verify(() => client.getStatus(any())).callCount;
    await Future<void>.delayed(const Duration(milliseconds: 50));
    verifyNever(() => client.getStatus(any()));
    expect(pollsWhileAlive, greaterThan(0));
  });

  test('a POST that fails is retried, and its tokens are polled for as usual',
      () async {
    var attempts = 0;
    when(() => client.dispenseTokens(
        txId: any(named: 'txId'),
        quantity: any(named: 'quantity'))).thenAnswer((_) async {
      attempts++;
      if (attempts == 1) throw DispenserException('Connection reset');
      return DispenseResult(
          txId: 'tx-session', state: 'dispensing', quantity: 3, dispensed: 0);
    });
    when(() => client.getStatus(any())).thenAnswer((_) async => DispenseResult(
        txId: 'tx-session', state: 'done', quantity: 3, dispensed: 3));

    final result = await session(timeoutPerToken: const Duration(seconds: 5))
        .run();

    expect(attempts, 2);
    expect(result!.state, 'done');
    expect(result.dispensed, 3);
  });

  test('a busy dispenser is not retried', () async {
    when(() => client.dispenseTokens(
            txId: any(named: 'txId'), quantity: any(named: 'quantity')))
        .thenThrow(DispenserBusyException());

    final s = session();
    expect(await s.run(), isNull);
    expect(s.error, isA<DispenserBusyException>());
    verify(() => client.dispenseTokens(
        txId: any(named: 'txId'), quantity: any(named: 'quantity'))).called(1);
  });

  test('the tracking row is flagged while polling and released afterwards',
      () async {
    acceptsDispense();
    when(() => client.getStatus(any())).thenAnswer((_) async => DispenseResult(
        txId: 'tx-session', state: 'done', quantity: 3, dispensed: 3));

    await session(timeoutPerToken: const Duration(seconds: 5)).run();

    verify(() => cartService.updateDispenserOperationState(
          dispenserTxId: 'tx-session',
          pollingActive: 1,
          lastPolledAt: any(named: 'lastPolledAt'),
        )).called(1);
    verify(() => cartService.updateDispenserOperationState(
          dispenserTxId: 'tx-session',
          pollingActive: 0,
          lastPolledAt: any(named: 'lastPolledAt'),
        )).called(1);
  });

  /// #947: `acknowledged` separates "the request never arrived" from "the
  /// device lost a transaction it accepted" when a later GET answers 404. It
  /// is set by an *answer*, which is why it is written here and not beside the
  /// request that went out.
  test('the device answering at all latches the acknowledgement', () async {
    acceptsDispense(state: 'done', dispensed: 3);

    await session().run();

    verify(() => cartService.updateDispenserOperationState(
          dispenserTxId: 'tx-session',
          state: 'done',
          lastKnownDispensed: 3,
          lastPolledAt: any(named: 'lastPolledAt'),
          acknowledged: true,
        )).called(1);
  });

  test('a dispenser that never answers acknowledges nothing', () async {
    when(() => client.dispenseTokens(
            txId: any(named: 'txId'), quantity: any(named: 'quantity')))
        .thenThrow(DispenserException('Connection refused'));

    final result = await session().run();

    expect(result, isNull);
    verifyNever(() => cartService.updateDispenserOperationState(
          dispenserTxId: any(named: 'dispenserTxId'),
          state: any(named: 'state'),
          transactionsCreated: any(named: 'transactionsCreated'),
          lastKnownDispensed: any(named: 'lastKnownDispensed'),
          pollingActive: any(named: 'pollingActive'),
          lastPolledAt: any(named: 'lastPolledAt'),
          acknowledged: true,
        ));
  });
}
