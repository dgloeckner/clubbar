import 'dart:io';

import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:logger/logger.dart';
import 'package:provider/provider.dart';
import 'package:window_manager/window_manager.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/quarantine_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/app/terminal_material_app.dart';
import 'package:clubbar_terminal/providers/locale_provider.dart';
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
import 'package:clubbar_terminal/services/dispenser_client.dart';
import 'package:clubbar_terminal/services/dispenser_recovery_service.dart';
import 'package:clubbar_terminal/services/dispenser_health_service.dart';
import 'package:clubbar_terminal/services/error_file_output.dart';
import 'package:clubbar_terminal/services/rfid_reader_health_service.dart';
import 'package:clubbar_terminal/services/rfid_reader_probe.dart';
import 'package:clubbar_terminal/generated/terminal.swagger.dart';
import 'package:clubbar_terminal/utils/app_logger.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

/// Seed database with mock categories and products for development
Future<void> _seedMockData(ClubBarDatabase database) async {
  try {
    // Create repositories
    final productsRepo = ProductsRepository(database);
    final membersRepo = MembersRepository(database);

    // Check if categories exist (determines if we need to seed products/categories)
    final existingCategories = await database.select(database.categoriesCache).get();

    if (existingCategories.isEmpty) {
      // Seed categories and products only if they don't exist
      final seedDate = DateTime.parse('2025-02-01T10:00:00Z');
      await productsRepo.upsertCategories([
      Category(
        id: 'cat-1',
        names: {'de': 'Getränke', 'en': 'Drinks'},
        isActive: true,
        createdAt: seedDate,
        updatedAt: seedDate,
      ),
      Category(
        id: 'cat-2',
        names: {'de': 'Sauna', 'en': 'Sauna'},
        isActive: true,
        createdAt: seedDate,
        updatedAt: seedDate,
      ),
    ]);

    await productsRepo.upsertProducts([
      // Getränke / Drinks
      Product(
        id: 'prod-1',
        categoryId: 'cat-1',
        names: {'de': 'Pils 0,5l', 'en': 'Pils 0.5l'},
        priceCents: 350,
        isActive: true,
        createdAt: seedDate,
        updatedAt: seedDate,
      ),
      Product(
        id: 'prod-2',
        categoryId: 'cat-1',
        names: {'de': 'Radler 0,5l', 'en': 'Radler 0.5l'},
        priceCents: 300,
        isActive: true,
        createdAt: seedDate,
        updatedAt: seedDate,
      ),
      Product(
        id: 'prod-3',
        categoryId: 'cat-1',
        names: {'de': 'Weizen 0,5l', 'en': 'Weizen 0.5l'},
        priceCents: 380,
        isActive: true,
        createdAt: seedDate,
        updatedAt: seedDate,
      ),
      Product(
        id: 'prod-4',
        categoryId: 'cat-1',
        names: {'de': 'Wasser 0,33l', 'en': 'Water 0.33l'},
        priceCents: 150,
        isActive: true,
        createdAt: seedDate,
        updatedAt: seedDate,
      ),
      Product(
        id: 'prod-5',
        categoryId: 'cat-1',
        names: {'de': 'Apfelschorle', 'en': 'Apple Spritzer'},
        priceCents: 200,
        isActive: true,
        createdAt: seedDate,
        updatedAt: seedDate,
      ),
      Product(
        id: 'prod-6',
        categoryId: 'cat-1',
        names: {'de': 'Apfelwein 1l', 'en': 'Cider 1l'},
        priceCents: 300,
        isActive: true,
        createdAt: seedDate,
        updatedAt: seedDate,
      ),
      Product(
        id: 'prod-7',
        categoryId: 'cat-1',
        names: {'de': 'Wasser 1l', 'en': 'Water 1l'},
        priceCents: 200,
        isActive: true,
        createdAt: seedDate,
        updatedAt: seedDate,
      ),
      Product(
        id: 'prod-8',
        categoryId: 'cat-1',
        names: {'de': 'Kaffee', 'en': 'Coffee'},
        priceCents: 200,
        isActive: true,
        createdAt: seedDate,
        updatedAt: seedDate,
      ),
      // Sauna
      Product(
        id: 'prod-9',
        categoryId: 'cat-2',
        names: {'de': 'Sauna-Token', 'en': 'Sauna Token'},
        priceCents: 200,
        isActive: true,
        createdAt: seedDate,
        updatedAt: seedDate,
      ),
    ]);

    // Seed test members only when no real members exist yet
    await membersRepo.upsertMembers([
      Member(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        preferredLanguage: 'de',
        isActive: true,
        isSepaValid: true,
        createdAt: seedDate,
        updatedAt: seedDate,
      ),
      Member(
        id: 'member-2',
        cardUid: 'card-456',
        firstName: 'Jane',
        lastName: 'Smith',
        preferredLanguage: 'de',
        isActive: true,
        isSepaValid: true,
        createdAt: seedDate,
        updatedAt: seedDate,
      ),
    ]);
    }
  } catch (e) {
    // Silently fail - data might already exist
  }
}

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Load terminal configuration first — needed to decide fullscreen mode (ADR-0019)
  final configService = ConfigService();
  try {
    await configService.load();
  } on ConfigParseException catch (e) {
    stderr.writeln('clubbar-terminal: configuration invalid');
    stderr.writeln('');
    stderr.writeln(e.message);
    stderr.writeln('');
    stderr.writeln('Fix the JSON syntax errors, then restart the app.');
    exit(1);
  }
  AppFontSizes.applyConfig(configService.fontSizes);

  // Initialize sound service
  final soundService = SoundService(enabled: configService.soundsEnabled);
  await soundService.init();

  if (!configService.isConfigured) {
    final path = await configService.getConfigFilePath();
    stderr.writeln('clubbar-terminal: configuration missing');
    stderr.writeln('');
    stderr.writeln('Create a config.json file at:');
    stderr.writeln('  $path');
    stderr.writeln('');
    stderr.writeln('Minimal example:');
    stderr.writeln('  {');
    stderr.writeln('    "terminalId": "Club-Bar-Kühlschrank",');
    stderr.writeln('    "apiUrl":     "https://club.example.com/api",');
    stderr.writeln('    "apiToken":   "<64-char hex token from admin panel>"');
    stderr.writeln('  }');
    stderr.writeln('');
    stderr.writeln('See INSTALL.md for the full configuration reference.');
    exit(1);
  }

  // Initialize window manager for desktop (Linux/macOS/Windows)
  try {
    await windowManager.ensureInitialized();
    // NOTE: waitUntilReadyToShow() in 0.4+ takes a callback — it is no longer async.
    // The callback executes asynchronously relative to the rest of main(), so runApp()
    // will proceed concurrently with window setup. This is expected and correct.
    windowManager.waitUntilReadyToShow(null, () async {
      if (configService.fullscreen) {
        await windowManager.setFullScreen(true);
      }
      await windowManager.show();
    });
  } catch (e) {
    // Window manager not available (mobile platform or plugin issue)
  }

  // Set up file-based error logging
  final logsDir = await configService.getLogsDir();
  final errorLogFile = File('$logsDir/error.log');
  final failedTxnsPath = '$logsDir/failed_transactions.json';

  final logger = Logger(
    printer: SimplePrinter(printTime: true, colors: false),
    output: MultiOutput([
      ConsoleOutput(),
      ErrorFileOutput(file: errorLogFile),
    ]),
  );

  // Route ErrorSignal's raw-exception logging into the same sinks
  AppLog.configure(logger);

  // Initialize database
  final database = ClubBarDatabase();

  // Seed database with mock data (only when explicitly enabled)
  if (configService.seedTestData) {
    await _seedMockData(database);
  }

  // Dispenser integration: recovery and health monitoring
  DispenserHealthService? dispenserHealthService;
  DispenserRecoveryService? dispenserRecoveryService;
  if (configService.dispenserEnabled) {
    try {
      final dispenserClient = DispenserClient(
        baseUrl: configService.dispenserBaseUrl!,
        apiKey: configService.dispenserApiKey!,
        timeoutMs: configService.dispenserTimeoutMs,
      );

      // Crash recovery: recover incomplete transactions and start periodic reconciliation
      dispenserRecoveryService = DispenserRecoveryService(
        database: database,
        client: dispenserClient,
        logger: logger,
      );
      await dispenserRecoveryService.recoverIncompleteDispenses();
      dispenserRecoveryService.startPeriodicReconciliation();

      // Start health monitoring (60-second interval)
      dispenserHealthService = DispenserHealthService(client: dispenserClient);
      dispenserHealthService.startMonitoring();
    } catch (e) {
      // Dispenser offline or error - log and continue
      // App will function normally, recovery will retry on next boot
      logger.w('Dispenser setup failed: $e');
    }
  }

  // RFID reader presence monitoring (issue #35). Only for a terminal that was
  // told what its reader looks like — see INSTALL.md; elsewhere the reader
  // status simply stays unknown and no UI mentions it.
  RfidReaderHealthService? rfidReaderHealthService;
  if (configService.rfidReaderMonitoringEnabled) {
    rfidReaderHealthService = RfidReaderHealthService(
      probe: InputDevicesRfidReaderProbe(
        identity: configService.rfidReaderIdentity,
      ),
      interval: Duration(seconds: configService.rfidReaderPollIntervalSeconds),
    );
    rfidReaderHealthService.startMonitoring();
  }

  // Create repositories (data access layer)
  final membersRepo = MembersRepository(database);
  final productsRepo = ProductsRepository(database);
  final transactionsRepo = TransactionsRepository(database);
  final syncRepo = SyncRepository(database);

  // Create services (business logic layer)
  final networkService = NetworkService(
    baseUrl: configService.apiUrl!,
  );
  networkService.setAuthToken(configService.apiToken!);
  final membersService = MembersService(
    repository: membersRepo,
    transactionsRepository: transactionsRepo,
    networkService: networkService,
  );
  final productsService = ProductsService(repository: productsRepo);
  final cartService = CartService(
    database: database,
    repository: transactionsRepo,
  );
  final syncService = SyncService(
    networkService: networkService,
    membersRepo: membersRepo,
    productsRepo: productsRepo,
    transactionsRepo: transactionsRepo,
    syncRepo: syncRepo,
    logger: logger,
    failedTransactionsPath: failedTxnsPath,
  );

  // Create providers (UI state management)
  final localeProvider = LocaleProvider();
  final membersProvider = MembersProvider(
    service: membersService,
    localeProvider: localeProvider,
  );
  final productsProvider = ProductsProvider(
    service: productsService,
    config: configService,
    // Null when no dispenser is configured — then dispenser-gated products are
    // hidden outright and there is no health to watch (issue #31).
    dispenserHealth: dispenserHealthService,
  );

  final cartProvider = CartProvider(
    service: cartService,
    config: configService,
    soundService: soundService,
  );
  // Session lifecycle owner (ADR-0027): all session ends go through this.
  final sessionController = SessionController(
    membersProvider: membersProvider,
    cartProvider: cartProvider,
  );

  // Load products into provider (after seeding database)
  await productsProvider.refreshProducts();

  // Sales the backend refused permanently. Loaded before the first frame so a
  // terminal that restarts with a quarantined sale still warns (issue #152).
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

  runApp(ClubBarTerminalApp(
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
    dispenserHealthService: dispenserHealthService,
    dispenserRecoveryService: dispenserRecoveryService,
    rfidReaderHealthService: rfidReaderHealthService,
  ));
}

/// Scroll behaviour for touchscreen kiosk use:
/// - Enables finger-drag scrolling on Linux (not included in the Flutter
///   desktop default which only covers mouse/trackpad).
/// - Uses BouncingScrollPhysics for a natural overscroll feel.
class _KioskScrollBehavior extends MaterialScrollBehavior {
  @override
  Set<PointerDeviceKind> get dragDevices => const {
    PointerDeviceKind.touch,
    PointerDeviceKind.mouse,
  };

  @override
  ScrollPhysics getScrollPhysics(BuildContext context) =>
      const BouncingScrollPhysics(parent: AlwaysScrollableScrollPhysics());
}

class ClubBarTerminalApp extends StatelessWidget {
  final ClubBarDatabase database;
  final LocaleProvider localeProvider;
  final MembersProvider membersProvider;
  final ProductsProvider productsProvider;
  final CartProvider cartProvider;
  final SessionController sessionController;
  final SyncProvider syncProvider;
  final QuarantineProvider quarantineProvider;
  final MembersRepository membersRepository;
  final TransactionsRepository transactionsRepository;
  final ConfigService configService;
  final NetworkService networkService;
  final SoundService soundService;
  final DispenserHealthService? dispenserHealthService;
  final DispenserRecoveryService? dispenserRecoveryService;
  final RfidReaderHealthService? rfidReaderHealthService;

  const ClubBarTerminalApp({
    super.key,
    required this.database,
    required this.localeProvider,
    required this.membersProvider,
    required this.productsProvider,
    required this.cartProvider,
    required this.sessionController,
    required this.syncProvider,
    required this.quarantineProvider,
    required this.membersRepository,
    required this.transactionsRepository,
    required this.configService,
    required this.networkService,
    required this.soundService,
    this.dispenserHealthService,
    this.dispenserRecoveryService,
    this.rfidReaderHealthService,
  });

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        Provider<ClubBarDatabase>.value(value: database),
        Provider<NetworkService>.value(value: networkService),
        Provider<ConfigService>.value(value: configService),
        Provider<SoundService>.value(value: soundService),
        Provider<TransactionsRepository>.value(value: transactionsRepository),
        if (dispenserHealthService != null)
          ChangeNotifierProvider<DispenserHealthService>.value(value: dispenserHealthService!),
        if (rfidReaderHealthService != null)
          ChangeNotifierProvider<RfidReaderHealthService>.value(value: rfidReaderHealthService!),
        ChangeNotifierProvider<LocaleProvider>.value(value: localeProvider),
        ChangeNotifierProvider<MembersProvider>.value(value: membersProvider),
        ChangeNotifierProvider<ProductsProvider>(create: (_) => productsProvider),
        ChangeNotifierProvider<CartProvider>.value(value: cartProvider),
        ChangeNotifierProvider<SessionController>.value(value: sessionController),
        ChangeNotifierProvider<SyncProvider>(create: (_) => syncProvider),
        ChangeNotifierProvider<QuarantineProvider>.value(value: quarantineProvider),
        ChangeNotifierProvider(create: (_) => RfidProvider(membersProvider, membersRepository, soundService, sessionController)),
      ],
      child: TerminalMaterialApp(
        configService: configService,
        scrollBehavior: _KioskScrollBehavior(),
      ),
    );
  }
}
