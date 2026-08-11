import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:drift/native.dart';
import 'package:flutter/material.dart';
import 'package:logger/logger.dart';
import 'package:path/path.dart' as p;

import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/generated/terminal.swagger.dart';
import 'package:clubbar_terminal/main.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/locale_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/quarantine_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/repository/products_repository.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/repository/sync_repository.dart';
import 'package:clubbar_terminal/services/network_service.dart';
import 'package:clubbar_terminal/services/members_service.dart';
import 'package:clubbar_terminal/services/products_service.dart';
import 'package:clubbar_terminal/services/cart_service.dart';
import 'package:clubbar_terminal/services/sync_service.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/sound_service.dart';

/// Intercepts all HTTP requests at the dart:io level, returning empty JSON
/// responses. This prevents any real network calls from the app (including
/// TransactionHistoryService which bypasses NetworkService).
class MockHttpOverrides extends HttpOverrides {
  @override
  HttpClient createHttpClient(SecurityContext? context) => _MockHttpClient();
}

class _MockHttpClient implements HttpClient {
  @override
  bool autoUncompress = true;
  @override
  Duration? connectionTimeout;
  @override
  Duration idleTimeout = const Duration(seconds: 15);
  @override
  int? maxConnectionsPerHost;
  @override
  String? userAgent;

  @override
  Future<HttpClientRequest> openUrl(String method, Uri url) async =>
      _MockHttpClientRequest();

  @override
  Future<HttpClientRequest> getUrl(Uri url) => openUrl('GET', url);
  @override
  Future<HttpClientRequest> postUrl(Uri url) => openUrl('POST', url);
  @override
  Future<HttpClientRequest> putUrl(Uri url) => openUrl('PUT', url);
  @override
  Future<HttpClientRequest> patchUrl(Uri url) => openUrl('PATCH', url);
  @override
  Future<HttpClientRequest> deleteUrl(Uri url) => openUrl('DELETE', url);
  @override
  Future<HttpClientRequest> headUrl(Uri url) => openUrl('HEAD', url);
  @override
  Future<HttpClientRequest> open(String method, String host, int port, String path) =>
      openUrl(method, Uri(scheme: 'http', host: host, port: port, path: path));
  @override
  void close({bool force = false}) {}

  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}

class _MockHttpClientRequest implements HttpClientRequest {
  @override
  HttpHeaders get headers => _MockHttpHeaders();
  @override
  Encoding get encoding => utf8;
  @override
  set encoding(Encoding enc) {}
  @override
  Future<HttpClientResponse> get done => close();
  @override
  Future<HttpClientResponse> close() async => _MockHttpClientResponse();
  @override
  void add(List<int> data) {}
  @override
  Future addStream(Stream<List<int>> stream) async {}
  @override
  Future flush() async {}
  @override
  void write(Object? object) {}
  @override
  void abort([Object? exception, StackTrace? stackTrace]) {}

  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}

/// Returns 200 OK with empty JSON for all requests.
class _MockHttpClientResponse extends Stream<List<int>>
    implements HttpClientResponse {
  static final _body = utf8.encode('{"member_id":"mock","count":0,"transactions":[]}');

  @override
  int get statusCode => 200;
  @override
  String get reasonPhrase => 'OK';
  @override
  int get contentLength => _body.length;
  @override
  HttpHeaders get headers => _MockHttpHeaders();
  @override
  List<Cookie> get cookies => [];
  @override
  bool get isRedirect => false;
  @override
  bool get persistentConnection => true;
  @override
  List<RedirectInfo> get redirects => [];
  @override
  HttpClientResponseCompressionState get compressionState =>
      HttpClientResponseCompressionState.notCompressed;
  @override
  X509Certificate? get certificate => null;
  @override
  HttpConnectionInfo? get connectionInfo => null;
  @override
  Future<Socket> detachSocket() => throw UnsupportedError('mock');
  @override
  Future<HttpClientResponse> redirect(
          [String? method, Uri? url, bool? followLoops]) =>
      throw UnsupportedError('mock');

  @override
  StreamSubscription<List<int>> listen(void Function(List<int> event)? onData,
      {Function? onError, void Function()? onDone, bool? cancelOnError}) {
    return Stream<List<int>>.value(_body).listen(onData,
        onError: onError, onDone: onDone, cancelOnError: cancelOnError);
  }
}

class _MockHttpHeaders implements HttpHeaders {
  @override
  ContentType? get contentType => ContentType.json;
  @override
  List<String>? operator [](String name) => null;
  @override
  String? value(String name) => null;

  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}

/// A [NetworkService] that reports healthy and returns empty sync responses.
/// Used in integration tests so the UI shows "Online" status without
/// making any real HTTP calls.
class FakeNetworkService extends NetworkService {
  FakeNetworkService({required super.baseUrl});

  @override
  Future<bool> checkHealth() async => true;

  @override
  Future<MemberDeltaResponse?> syncMembers({int? since}) async =>
      MemberDeltaResponse(members: [], cursor: 0, count: 0, hasMore: false);

  @override
  Future<CategoryDeltaResponse?> syncCategories({int? since}) async =>
      CategoryDeltaResponse(categories: [], cursor: 0, count: 0, hasMore: false);

  @override
  Future<ProductDeltaResponse?> syncProducts({int? since}) async =>
      ProductDeltaResponse(products: [], cursor: 0, count: 0, hasMore: false);

  @override
  Future<TransactionBatchResponse> syncTransactions(
    List<Map<String, dynamic>> transactions, {
    List<String> memberIds = const [],
  }) async =>
      TransactionBatchResponse(
        acceptedIds: transactions.map((t) => t['id']?.toString() ?? '').toList(),
        rejected: const TransactionBatchResponse$Rejected(),
        memberBalances: {},
      );
}

/// Creates an in-memory [ClubBarDatabase] seeded with minimal test data:
/// - 1 category ("Drinks")
/// - 2 products ("Pils 0.5l" at 350 cents, "Water 0.33l" at 150 cents)
/// - 1 member ("Test Member" with card UID "test-card-001")
Future<ClubBarDatabase> createTestDatabase() async {
  final db = ClubBarDatabase.forTesting(NativeDatabase.memory());
  final productsRepo = ProductsRepository(db);
  final membersRepo = MembersRepository(db);

  await productsRepo.upsertCategories([
    Category(
      id: 'int-cat-1',
      names: {'de': 'Getränke', 'en': 'Drinks'},
      isActive: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
  ]);

  await productsRepo.upsertProducts([
    Product(
      id: 'int-prod-1',
      categoryId: 'int-cat-1',
      names: {'de': 'Pils 0,5l', 'en': 'Pils 0.5l'},
      descriptions: null,
      priceCents: 350,
      isActive: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
    Product(
      id: 'int-prod-2',
      categoryId: 'int-cat-1',
      names: {'de': 'Wasser 0,33l', 'en': 'Water 0.33l'},
      descriptions: null,
      priceCents: 150,
      isActive: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
  ]);

  await membersRepo.upsertMembers([
    Member(
      id: 'int-member-1',
      cardUid: 'test-card-001',
      firstName: 'Test',
      lastName: 'Member',
      preferredLanguage: 'de',
      isActive: true,
      isSepaValid: true,
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
  ]);

  return db;
}

/// Builds the full [ClubBarTerminalApp] widget tree backed by the given
/// [database].
///
/// Uses real repositories and services but a network service pointed at
/// a non-routable address (no real HTTP calls). SyncService is created
/// with logging disabled and a temp path for failed transactions.
///
/// Pass [cartService] to substitute the real [CartService] — e.g. with a
/// slower one that keeps a checkout in flight long enough to tap again.
///
/// The returned widget can be passed to [tester.pumpWidget] in
/// integration tests.
Future<Widget> buildTestApp(
  ClubBarDatabase database, {
  CartService? cartService,
}) async {
  // Install mock HTTP overrides so all dart:io HTTP calls return 200 OK.
  // This prevents TransactionHistoryService (which bypasses NetworkService)
  // from failing with connection refused.
  HttpOverrides.global = MockHttpOverrides();

  // Temporary directory for failed-transactions log
  final tmpDir = await Directory.systemTemp.createTemp('clubbar_inttest_');
  final failedTxnsPath = p.join(tmpDir.path, 'failed_transactions.json');

  // ConfigService with a temp config directory containing valid config
  final configDir = await Directory.systemTemp.createTemp('clubbar_config_');
  final configFile = File(p.join(configDir.path, 'config.json'));
  await configFile.writeAsString(jsonEncode({
    'terminalId': 'integration-test-terminal',
    'apiUrl': 'http://localhost:1',
    'apiToken': 'test-token-0000000000000000000000000000000000000000000000000000000000',
    'seedTestData': false,
    'demoMode': false,
    'fullscreen': false,
    'soundsEnabled': false,
  }));
  final configService = ConfigService(configDir: configDir.path);
  await configService.load();

  // Repositories
  final membersRepo = MembersRepository(database);
  final productsRepo = ProductsRepository(database);
  final transactionsRepo = TransactionsRepository(database);
  final syncRepo = SyncRepository(database);

  // Services
  final networkService = FakeNetworkService(
    baseUrl: configService.apiUrl!,
  );
  networkService.setAuthToken(configService.apiToken!);

  final membersService = MembersService(
    repository: membersRepo,
    transactionsRepository: transactionsRepo,
    networkService: networkService,
  );
  final productsService = ProductsService(repository: productsRepo);
  final effectiveCartService = cartService ??
      CartService(
        database: database,
        repository: transactionsRepo,
      );

  final logger = Logger(level: Level.off);
  final syncService = SyncService(
    networkService: networkService,
    membersRepo: membersRepo,
    productsRepo: productsRepo,
    transactionsRepo: transactionsRepo,
    syncRepo: syncRepo,
    logger: logger,
    failedTransactionsPath: failedTxnsPath,
  );

  final soundService = SoundService(enabled: false);

  // Providers
  final localeProvider = LocaleProvider();
  final membersProvider = MembersProvider(
    service: membersService,
    localeProvider: localeProvider,
  );
  final productsProvider = ProductsProvider(
    service: productsService,
    config: configService,
  );

  // Load products into provider so the UI has data
  await productsProvider.refreshProducts();

  final quarantineProvider = QuarantineProvider(
    transactionsRepo: transactionsRepo,
    membersRepo: membersRepo,
  );
  await quarantineProvider.refresh();

  final syncProvider = SyncProvider(
    syncService: syncService,
    membersProvider: membersProvider,
    productsProvider: productsProvider,
    networkService: networkService,
    quarantineProvider: quarantineProvider,
    configService: configService,
  );

  final cartProvider = CartProvider(
    service: effectiveCartService,
    config: configService,
    soundService: soundService,
  );
  final sessionController = SessionController(
    membersProvider: membersProvider,
    cartProvider: cartProvider,
  );

  return ClubBarTerminalApp(
    database: database,
    localeProvider: localeProvider,
    membersProvider: membersProvider,
    productsProvider: productsProvider,
    cartProvider: cartProvider,
    sessionController: sessionController,
    syncProvider: syncProvider,
    quarantineProvider: quarantineProvider,
    membersRepository: membersRepo,
    transactionsRepository: transactionsRepo,
    configService: configService,
    networkService: networkService,
    soundService: soundService,
    dispenserHealthService: null,
    dispenserRecoveryService: null,
  );
}
