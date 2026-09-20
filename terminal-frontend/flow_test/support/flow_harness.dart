import 'dart:async';

import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/cart_item.dart';
import 'package:clubbar_terminal/models/credit_limit.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/services/cart_service.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:clubbar_terminal/services/dispenser_recovery_service.dart';
import 'package:clubbar_terminal/services/sound_service.dart';
import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:flutter/widgets.dart';
import 'package:mocktail/mocktail.dart';

import 'dispenser_proxy.dart';
import 'flow_dispense_session.dart';
import 'mock_dispenser.dart';

class _MockBuildContext extends Mock implements BuildContext {}

class _MockConfigService extends Mock implements ConfigService {}

class _MockSoundService extends Mock implements SoundService {}

/// Everything one flow scenario needs, wired the way the app wires it:
/// a real drift database, a real [CartService], a real [CartProvider], a real
/// [DispenserRecoveryService] and a real [DispenserClient] — talking HTTP to
/// the Go mock through an observing proxy.
///
/// The one seam is the dispensing dialog, which needs a widget tree. See
/// [FlowDispenseSession] for what stands in for it and why.
class DispenserFlowHarness {
  DispenserFlowHarness._({
    required this.mock,
    required this.proxy,
    required this.db,
    required this.cartService,
    required this.provider,
    required this.recovery,
    required this.client,
  });

  final MockDispenser mock;
  final DispenserProxy proxy;
  final ClubBarDatabase db;
  final CartService cartService;
  final FlowCartProvider provider;
  final DispenserRecoveryService recovery;
  final DispenserClient client;

  /// Whether the scenario expects the terminal to keep one request in flight
  /// at a time (finding 7, #946). True for every scenario where a single
  /// component talks to the dispenser; a scenario that deliberately has the
  /// recovery tick poll *while* a dispense runs turns it off and says so.
  bool expectSerialRequests = true;

  static const String memberId = 'member-flow';
  static const String tokenProductId = 'prod-sauna-token';
  static const int tokenPriceCents = 200;
  static const String sessionId = 'session-flow';

  static final MembersCacheData member = MembersCacheData(
    id: memberId,
    cardUid: 'card-flow',
    firstName: 'Flow',
    lastName: 'Tester',
    preferredLanguage: 'de',
    isActive: 1,
    isSepaValid: 1,
    balanceCents: 0,
    updatedAt: '2026-01-01T00:00:00.000Z',
  );

  /// Starts the mock, the proxy and an in-memory database, and wires the
  /// terminal's own services on top of them.
  static Future<DispenserFlowHarness> start({
    Duration pollInterval = const Duration(milliseconds: 50),
    Duration timeoutPerToken = const Duration(seconds: 2),
    Duration requestTimeout = const Duration(seconds: 5),
    Duration retryDelay = const Duration(milliseconds: 100),
  }) async {
    _registerFallbacks();

    final mock = await MockDispenser.start();
    final proxy = await DispenserProxy.start(mock.baseUrl);
    final db = ClubBarDatabase.forTesting(NativeDatabase.memory());

    final config = _MockConfigService();
    when(() => config.dispenserEnabled).thenReturn(true);
    when(() => config.dispenserBaseUrl).thenReturn(proxy.baseUrl);
    when(() => config.dispenserApiKey).thenReturn(mock.apiKey);
    when(() => config.dispenserTimeoutMs).thenReturn(2000);
    when(() => config.dispenserPollIntervalMs).thenReturn(pollInterval.inMilliseconds);
    when(() => config.creditLimitPolicy).thenReturn(CreditLimitPolicy.shipped);

    final sound = _MockSoundService();
    when(() => sound.play(any())).thenAnswer((_) async {});

    final cartService = CartService(
      database: db,
      repository: TransactionsRepository(db),
      configService: config,
    );

    final client = DispenserClient(
      baseUrl: proxy.baseUrl,
      apiKey: mock.apiKey,
      timeoutMs: 2000,
    );

    final provider = FlowCartProvider(
      service: cartService,
      cartService: cartService,
      config: config,
      soundService: sound,
      client: client,
      pollInterval: pollInterval,
      timeoutPerToken: timeoutPerToken,
      requestTimeout: requestTimeout,
      retryDelay: retryDelay,
    );

    final recovery = DispenserRecoveryService(database: db, client: client);

    return DispenserFlowHarness._(
      mock: mock,
      proxy: proxy,
      db: db,
      cartService: cartService,
      provider: provider,
      recovery: recovery,
      client: client,
    );
  }

  static bool _fallbacksRegistered = false;

  /// mocktail needs a sample value for every type matched with `any()`.
  static void _registerFallbacks() {
    if (_fallbacksRegistered) return;
    registerFallbackValue(SoundEvent.productAdd);
    _fallbacksRegistered = true;
  }

  /// One member buying [quantity] tokens — the whole of `CartProvider.checkout`,
  /// including the tracking row, the dispense and the billing.
  Future<void> checkoutTokens(int quantity) async {
    provider.addItem(
      tokenProductId,
      'Sauna-Token',
      tokenPriceCents,
      quantity,
      'de',
      requiresDispenser: true,
    );
    await provider.checkout(_MockBuildContext(), member, sessionId);
  }

  /// What the member is billed: one row per token, as
  /// `createTransactionsFromDispenseResult` writes them.
  Future<List<TransactionsLocalData>> tokenRows() async {
    return (db.select(db.transactionsLocal)
          ..where((t) => t.productId.equals(tokenProductId)))
        .get();
  }

  Future<int> billedTokens() async => (await tokenRows()).length;

  Future<int> billedCents() async => (await tokenRows())
      .fold<int>(0, (sum, row) => sum + row.amountCents);

  /// The crash-recovery tracking rows. An empty list means the terminal
  /// considers every dispense settled.
  Future<List<DispenserOperation>> trackingRows() =>
      db.select(db.dispenserOperations).get();

  /// One pass of the reconciliation the app runs every 60 s.
  Future<void> reconcile() => recovery.recoverIncompleteDispenses();

  /// Makes the tracking rows look [age] older than they are.
  ///
  /// The recovery service skips anything polled in the last 30 seconds — a
  /// constant a test cannot compress by waiting. A scenario that needs "the
  /// dialog has been silent for 40 seconds" moves the clock instead of
  /// spending 40 seconds of the suite's 60-second budget on it.
  Future<void> ageTracking(Duration age) async {
    final rows = await trackingRows();
    for (final row in rows) {
      if (row.lastPolledAt == null) continue;
      await (db.update(db.dispenserOperations)
            ..where((t) => t.dispenserTxId.equals(row.dispenserTxId)))
          .write(DispenserOperationsCompanion(
        lastPolledAt: Value(
            DateTime.parse(row.lastPolledAt!).subtract(age).toIso8601String()),
      ));
    }
  }

  Future<void> dispose() async {
    recovery.dispose();
    await proxy.dispose();
    await mock.dispose();
    await db.close();
  }
}

/// [CartProvider] with the dialog replaced by [FlowDispenseSession].
///
/// Everything else — the tracking row, the billing, the cleanup rules, the
/// cart — is the production code under test.
class FlowCartProvider extends CartProvider {
  FlowCartProvider({
    required super.service,
    required super.config,
    required super.soundService,
    required this.cartService,
    required this.client,
    required this.pollInterval,
    required this.timeoutPerToken,
    required this.requestTimeout,
    required this.retryDelay,
  });

  /// The same instance handed to `super.service`; `CartProvider` keeps that
  /// one private, and the stand-in dialog needs it for the tracking writes.
  final CartService cartService;

  final DispenserClient client;
  final Duration pollInterval;
  final Duration timeoutPerToken;
  final Duration requestTimeout;
  final Duration retryDelay;

  /// The sessions this provider ran, newest last — a test asserts on what the
  /// "dialog" saw as well as on what was billed.
  final List<FlowDispenseSession> sessions = <FlowDispenseSession>[];

  /// Runs while the session is polling. Used by the dropout scenario to reach
  /// in mid-dispense (pause the mock, age the tracking row, reconcile).
  Future<void> Function(FlowDispenseSession session)? duringDispense;

  @override
  Future<DispenseResult?> showDispensingDialog(
    BuildContext context,
    List<CartItem> tokenProducts,
    String dispenserTxId,
  ) async {
    final quantity =
        tokenProducts.fold(0, (sum, item) => sum + item.quantity);
    final session = FlowDispenseSession(
      client: client,
      cartService: cartService,
      txId: dispenserTxId,
      quantity: quantity,
      pollInterval: pollInterval,
      timeoutPerToken: timeoutPerToken,
      requestTimeout: requestTimeout,
      retryDelay: retryDelay,
    );
    sessions.add(session);

    final run = session.run();
    final hook = duringDispense;
    if (hook != null) {
      duringDispense = null;
      await hook(session);
    }
    return run;
  }
}
