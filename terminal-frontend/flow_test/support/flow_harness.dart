import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/cart_item.dart';
import 'package:clubbar_terminal/models/credit_limit.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/services/cart_service.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/repository/products_repository.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/dispense_session.dart';
import 'package:clubbar_terminal/services/products_service.dart';
import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:clubbar_terminal/services/dispenser_health_service.dart';
import 'package:clubbar_terminal/services/dispenser_recovery_service.dart';
import 'package:clubbar_terminal/services/sound_service.dart';
import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:flutter/widgets.dart';
import 'package:mocktail/mocktail.dart';

import 'dispenser_proxy.dart';
import 'mock_dispenser.dart';

class _MockBuildContext extends Mock implements BuildContext {}

class _MockConfigService extends Mock implements ConfigService {}

class _MockSoundService extends Mock implements SoundService {}

/// Everything one flow scenario needs, wired the way the app wires it:
/// a real drift database, a real [CartService], a real [CartProvider], a real
/// [DispenserRecoveryService] and a real [DispenserClient] — talking HTTP to
/// the Go mock through an observing proxy.
///
/// The one seam is the dialog *widget*, which needs a widget tree. Its state
/// machine is not a seam any more: since #946 that is [DispenseSession], a
/// plain class in `lib/`, and [FlowCartProvider] runs the very same one the
/// dialog runs. The proxy's `maxInFlight` assertion therefore binds on
/// production code.
class DispenserFlowHarness {
  DispenserFlowHarness._({
    required this.mock,
    required this.proxy,
    required this.db,
    required this.cartService,
    required this.provider,
    required this.recovery,
    required this.client,
    required this.health,
    required this.products,
  });

  final MockDispenser mock;
  final DispenserProxy proxy;
  final ClubBarDatabase db;
  final CartService cartService;
  final FlowCartProvider provider;
  final DispenserRecoveryService recovery;
  final DispenserClient client;

  /// The real health poller, on the real client. It polls only when a
  /// scenario asks it to (`checkNow`), so it never competes with a dispense
  /// for the device's single connection.
  final DispenserHealthService health;

  /// The real grid provider, reading [health]: what the member can tap.
  final ProductsProvider products;

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
  /// The token as the grid knows it, for [ProductsProvider.isProductAvailable].
  static final ProductsCacheData tokenProduct = ProductsCacheData(
    id: tokenProductId,
    categoryId: 'cat-sauna',
    names: '{"de":"Sauna-Token"}',
    priceCents: tokenPriceCents,
    isActive: 1,
    requiresDispenser: 1,
    updatedAt: '2026-01-01T00:00:00.000Z',
  );

  static Future<DispenserFlowHarness> start({
    Duration pollInterval = const Duration(milliseconds: 50),
    Duration timeoutPerToken = const Duration(seconds: 2),
    Duration requestTimeout = const Duration(seconds: 5),
    Duration retryDelay = const Duration(milliseconds: 100),
    int protocolClaim = 2,
    String? clientSigningKey,
  }) async {
    _registerFallbacks();

    final mock = await MockDispenser.start(protocolClaim: protocolClaim);
    // Normally the two ends share the secret. A scenario hands in a different
    // one to stand where a mis-provisioned kiosk stands (#951).
    final signingKey = clientSigningKey ?? mock.signingKey;
    final proxy = await DispenserProxy.start(mock.baseUrl);
    final db = ClubBarDatabase.forTesting(NativeDatabase.memory());

    final config = _MockConfigService();
    when(() => config.dispenserEnabled).thenReturn(true);
    when(() => config.dispenserBaseUrl).thenReturn(proxy.baseUrl);
    when(() => config.dispenserSigningKey).thenReturn(signingKey);
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
      signingKey: signingKey,
      timeoutMs: 2000,
    );

    final provider = FlowCartProvider(
      service: cartService,
      cartService: cartService,
      config: config,
      soundService: sound,
      dispenserClient: client,
      pollInterval: pollInterval,
      timeoutPerToken: timeoutPerToken,
      requestTimeout: requestTimeout,
      retryDelay: retryDelay,
    );

    final recovery = DispenserRecoveryService(
      database: db,
      client: client,
      cartService: cartService,
    );

    final health = DispenserHealthService(client: client);
    final products = ProductsProvider(
      service: ProductsService(repository: ProductsRepository(db)),
      config: config,
      dispenserHealth: health,
    );

    return DispenserFlowHarness._(
      mock: mock,
      proxy: proxy,
      db: db,
      cartService: cartService,
      provider: provider,
      recovery: recovery,
      client: client,
      health: health,
      products: products,
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
  ///
  /// Deliberately [DispenserRecoveryService.reconcile] and not the boot path:
  /// the tick must leave `polling_active` alone (#945).
  Future<void> reconcile() => recovery.reconcile();

  /// Makes the tracking rows look [age] older than they are — **both** the
  /// moment they were last polled and the moment they were created.
  ///
  /// The recovery service skips anything polled in the last 30 seconds, and
  /// since #947 it waits out `unacknowledgedGrace` from `created_at` before
  /// reading a 404 as "the request never arrived". Neither constant can be
  /// compressed by waiting, and a scenario that needs "this has been sitting
  /// here for three minutes" moves the clock instead of spending three minutes
  /// of the suite's 60-second budget on it.
  ///
  /// `created_at` is moved with it rather than in a second helper, because the
  /// two are one clock: a row polled forty seconds ago cannot have been
  /// created five seconds ago, and a test that ages only one of them is
  /// describing a row the app can never produce.
  Future<void> ageTracking(Duration age) async {
    final rows = await trackingRows();
    for (final row in rows) {
      final polled = row.lastPolledAt;
      await (db.update(db.dispenserOperations)
            ..where((t) => t.dispenserTxId.equals(row.dispenserTxId)))
          .write(DispenserOperationsCompanion(
        lastPolledAt: polled == null
            ? const Value.absent()
            : Value(DateTime.parse(polled).subtract(age).toIso8601String()),
        createdAt: Value(
            DateTime.parse(row.createdAt).subtract(age).toIso8601String()),
      ));
    }
  }

  /// `GET /health` read straight off the mock, bypassing every terminal
  /// class — what the device really says, for a scenario that asserts the
  /// terminal refused a device that was working.
  Future<Map<String, dynamic>> rawHealth() async {
    final client = HttpClient();
    try {
      final request = await client.getUrl(Uri.parse('${mock.baseUrl}/health'));
      final response = await request.close();
      final body = await response.transform(utf8.decoder).join();
      return jsonDecode(body) as Map<String, dynamic>;
    } finally {
      client.close(force: true);
    }
  }

  Future<void> dispose() async {
    products.dispose();
    health.dispose();
    recovery.dispose();
    await proxy.dispose();
    await mock.dispose();
    await db.close();
  }
}

/// [CartProvider] with the dialog *widget* replaced by a bare
/// [DispenseSession] — the same class the widget drives.
///
/// The only thing skipped is the `showDialog` call and the pixels: the state
/// machine, the tracking row, the billing, the cleanup rules and the cart are
/// all the production code under test. Durations are injected so a scenario
/// the device takes five seconds over takes the suite well under that.
class FlowCartProvider extends CartProvider {
  FlowCartProvider({
    required super.service,
    required super.config,
    required super.soundService,
    required super.dispenserClient,
    required this.cartService,
    required this.pollInterval,
    required this.timeoutPerToken,
    required this.requestTimeout,
    required this.retryDelay,
  }) : client = dispenserClient!;

  /// The same instance handed to `super.service`; `CartProvider` keeps that
  /// one private, and the session needs it for the tracking writes.
  final CartService cartService;

  final DispenserClient client;
  final Duration pollInterval;
  final Duration timeoutPerToken;
  final Duration requestTimeout;
  final Duration retryDelay;

  /// The sessions this provider ran, newest last — a test asserts on what the
  /// dialog would have seen as well as on what was billed.
  final List<DispenseSession> sessions = <DispenseSession>[];

  /// Runs while the session is polling. Used by the dropout scenario to reach
  /// in mid-dispense (pause the mock, age the tracking row, reconcile).
  Future<void> Function(DispenseSession session)? duringDispense;

  @override
  Future<DispenseResult?> showDispensingDialog(
    BuildContext context,
    List<CartItem> tokenProducts,
    String dispenserTxId,
  ) async {
    final quantity =
        tokenProducts.fold(0, (sum, item) => sum + item.quantity);
    final session = DispenseSession(
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
