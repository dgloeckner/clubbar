@Timeout(Duration(seconds: 60))
library;

import 'package:clubbar_terminal/database/database.dart';
import 'package:drift/drift.dart' show Value;
import 'package:flutter_test/flutter_test.dart';

import 'support/flow_harness.dart';
import 'support/mock_dispenser.dart';

/// L3 of the epic's test plan (#944, #950): the money paths of a token
/// purchase, end to end over real HTTP against the Go mock from
/// `dgloeckner/remote-token-dispenser`, asserted on **rows in
/// `transactions_local` and `dispenser_operations`** — never on the UI.
///
/// Run it with `scripts/flow-test.sh` (see `flow_test/README.md`). It is not
/// part of `flutter test`, which runs `test/` only: this suite needs a binary
/// from another repository, and a suite that skips itself when that binary is
/// missing would be a green run that proved nothing.
void main() {
  late DispenserFlowHarness harness;

  Future<void> boot({
    Duration timeoutPerToken = const Duration(seconds: 2),
  }) async {
    harness = await DispenserFlowHarness.start(timeoutPerToken: timeoutPerToken);
  }

  tearDown(() async {
    // Every scenario asserts this: an ESP8266 has a handful of TCP slots, and
    // the polling loop must never stack requests on top of each other
    // (finding 7, #946).
    if (harness.expectSerialRequests) {
      expect(harness.proxy.maxInFlight, lessThanOrEqualTo(1),
          reason: 'the terminal had more than one request open at once');
    }
    await harness.dispose();
  });

  test('a clean dispense bills one row per token and closes the operation',
      () async {
    await boot();

    await harness.checkoutTokens(MockDispenser.qtySuccess);

    expect(await harness.billedTokens(), MockDispenser.qtySuccess);
    expect(await harness.billedCents(),
        MockDispenser.qtySuccess * DispenserFlowHarness.tokenPriceCents);
    expect(await harness.trackingRows(), isEmpty,
        reason: 'a finished dispense leaves nothing to reconcile');
    expect(harness.provider.items, isEmpty);
  });

  test('a jam part-way through bills what fell, and reconcile closes the row',
      () async {
    await boot();

    // Quantity 6 is the mock's `partial_dispense`: four tokens, then error.
    await harness.checkoutTokens(MockDispenser.qtyPartialDispense);

    expect(await harness.billedTokens(), 4,
        reason: 'the member pays for the tokens that came out, no more');

    // Asserted on the end state, not on who got there: today the dialog turns
    // the device's `error` into a `done` with four tokens and checkout closes
    // the row itself (finding 5), while the epic has reconciliation close it.
    // Either way the member owes four tokens and nothing stays open.
    await harness.ageTracking(const Duration(minutes: 1));
    await harness.reconcile();

    expect(await harness.billedTokens(), 4,
        reason: 'reconciliation must not bill the same tokens twice');
    expect(await harness.trackingRows(), isEmpty);
  });

  test('a lost POST response is retried and bills the tokens exactly once',
      () async {
    await boot();

    // The request reaches the dispenser and the motor starts; the answer never
    // comes back (dgloeckner/remote-token-dispenser#2). The retry carries the
    // same tx_id, so the dispenser answers 200 with the transaction it is
    // already running rather than starting a second one.
    harness.proxy.dropNextDispenseResponse = true;

    await harness.checkoutTokens(MockDispenser.qtySuccess);

    expect(harness.proxy.dispenseCount, greaterThanOrEqualTo(2),
        reason: 'the POST must have been retried for this test to mean anything');
    expect(await harness.billedTokens(), MockDispenser.qtySuccess);
    expect(await harness.trackingRows(), isEmpty);
  });

  test('a dispense finished while the app was dead is billed once by recovery',
      () async {
    await boot();

    // The app died between the dispense and the billing: the tracking row is
    // all that is left, and the dispenser still knows the transaction.
    const txId = 'killedapp0000001';
    await harness.db.into(harness.db.dispenserOperations).insert(
          DispenserOperationsCompanion.insert(
            dispenserTxId: txId,
            memberId: DispenserFlowHarness.memberId,
            productId: DispenserFlowHarness.tokenProductId,
            priceCents: DispenserFlowHarness.tokenPriceCents,
            requestedQty: MockDispenser.qtySuccess,
            createdAt: DateTime.now().toUtc().toIso8601String(),
            lastPolledAt: Value(DateTime.now()
                .toUtc()
                .subtract(const Duration(minutes: 5))
                .toIso8601String()),
          ),
        );
    await harness.client.dispenseTokens(
        txId: txId, quantity: MockDispenser.qtySuccess);
    await Future<void>.delayed(const Duration(milliseconds: 600));

    await harness.reconcile();
    await harness.reconcile(); // a second tick must change nothing

    expect(await harness.billedTokens(), MockDispenser.qtySuccess);
    expect(await harness.trackingRows(), isEmpty);
  });

  test('the dispenser being slow to load never stacks requests', () async {
    await boot(timeoutPerToken: const Duration(seconds: 3));

    // Quantity 7 is the mock's `load_delay`: 2,5 s before the first token.
    await harness.checkoutTokens(MockDispenser.qtyLoadDelay);

    expect(await harness.billedTokens(), MockDispenser.qtyLoadDelay);
    expect(harness.proxy.pollCount, greaterThan(1),
        reason: 'the suite must actually have polled through the load delay');
    expect(await harness.trackingRows(), isEmpty);
  });

  group('known defects — red until the issue that owns them lands', () {
    // Green since #945: both paths bill through
    // `CartService.billDispensedTokens`, on ids derived from the dispense, so
    // whoever runs second writes the rows that are already there.
    test('a stalled dialog and a recovery tick bill each token once (#945)',
        () async {
      await boot(timeoutPerToken: const Duration(seconds: 5));
      // Two components talk to the dispenser here on purpose — the dialog and
      // the recovery tick. Serial polling is asserted by every other scenario.
      harness.expectSerialRequests = false;

      // The dialog is still open (a slow poll, a frozen UI, a dropout that
      // outlasted the dispense) when the 60-second reconciliation tick comes
      // round and finds a tracking row nobody has polled for 40 seconds —
      // moved rather than waited for, see ageTracking. The tick bills the
      // tokens the dispenser reports and closes the row; the dialog then
      // finishes and bills the very same tokens again.
      //
      // The tick used to clear `polling_active` on *every* row before it
      // started, so the flag that is supposed to keep it off a live dialog
      // protected nothing. It no longer does — and billing no longer depends
      // on that flag either.
      harness.provider.duringDispense = (session) async {
        await Future<void>.delayed(const Duration(seconds: 3));
        await harness.ageTracking(const Duration(seconds: 40));
        await harness.reconcile();
      };

      await harness.checkoutTokens(MockDispenser.qtySuccessLong);

      expect(await harness.billedTokens(), MockDispenser.qtySuccessLong,
          reason: 'checkout and reconciliation billed the same tokens twice');
      expect(await harness.trackingRows(), isEmpty);
    });

    test('a polling timeout leaves the rest to be billed by reconcile (#946)',
        () async {
      // Quantity 15 dispenses at 500 ms per token; the poll phase is cut short
      // long before the dispenser is finished.
      await boot(timeoutPerToken: const Duration(milliseconds: 60));

      await harness.checkoutTokens(MockDispenser.qtySlowDispense);

      expect(await harness.trackingRows(), hasLength(1),
          reason: 'a timeout is not a completed dispense — the row must stay');

      await Future<void>.delayed(const Duration(seconds: 8));
      await harness.ageTracking(const Duration(minutes: 1));
      await harness.reconcile();

      expect(await harness.billedTokens(), MockDispenser.qtySlowDispense,
          reason: 'every token that fell must end up on the bill');
      expect(await harness.trackingRows(), isEmpty);
    }, skip: 'Red: finding 5 of the epic. Un-skip in #946.');

    test('a dispenser that was never reachable bills nothing and leaves nothing '
        '(#947)', () async {
      await boot(timeoutPerToken: const Duration(milliseconds: 300));
      await harness.mock.kill();

      await harness.checkoutTokens(MockDispenser.qtySuccess);

      expect(await harness.billedTokens(), 0,
          reason: 'nothing came out, so nothing is owed');

      await harness.mock.launch();
      await harness.ageTracking(const Duration(minutes: 1));
      await harness.reconcile();

      expect(await harness.trackingRows(), isEmpty,
          reason: 'a dispense that never started is not a manual '
              'reconciliation case');
    }, skip: 'Red: finding 10 of the epic. Un-skip in #947.');
  });

  group('pending a newer mock — the pin in build.yaml is what unblocks these',
      () {
    test('a reset mid-dispense still bills the tokens that fell', () async {},
        skip: 'The mock at the pinned commit forgets a crashed transaction '
            '(scenarios.go: crash_after_first clears activeTx without history), '
            'so no terminal behaviour can recover the count. Needs the '
            'persisted ring from dgloeckner/remote-token-dispenser#3.');

    test('a dispenser speaking protocol 1 is unavailable, not degraded',
        () async {},
        skip: 'The mock at the pinned commit has no --protocol flag and no '
            'protocol field in /health. Needs protocol 2 '
            '(dgloeckner/remote-token-dispenser#1, #6) and lands with #948.');
  });
}
