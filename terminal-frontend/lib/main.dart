import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:logger/logger.dart';
import 'package:provider/provider.dart';
import 'package:window_manager/window_manager.dart';
import 'package:ruderbar_terminal/l10n/app_localizations.dart';
import 'package:ruderbar_terminal/config/app_config.dart';
import 'package:ruderbar_terminal/config/app_router.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/providers/auth_provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/providers/products_provider.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';
import 'package:ruderbar_terminal/providers/rfid_provider.dart';
import 'package:ruderbar_terminal/providers/locale_provider.dart';
import 'package:ruderbar_terminal/repository/members_repository.dart';
import 'package:ruderbar_terminal/repository/products_repository.dart';
import 'package:ruderbar_terminal/repository/transactions_repository.dart';
import 'package:ruderbar_terminal/repository/sync_repository.dart';
import 'package:ruderbar_terminal/services/network_service.dart';
import 'package:ruderbar_terminal/services/members_service.dart';
import 'package:ruderbar_terminal/services/products_service.dart';
import 'package:ruderbar_terminal/services/cart_service.dart';
import 'package:ruderbar_terminal/services/sync_service.dart';
import 'package:ruderbar_terminal/services/config_service.dart';
import 'package:ruderbar_terminal/services/dispenser_client.dart';
import 'package:ruderbar_terminal/services/dispenser_recovery_service.dart';
import 'package:ruderbar_terminal/services/dispenser_health_service.dart';
import 'package:ruderbar_terminal/services/error_file_output.dart';
import 'package:ruderbar_terminal/models/category_dto.dart';
import 'package:ruderbar_terminal/models/product_dto.dart';
import 'package:ruderbar_terminal/models/member_dto.dart';

/// Seed database with mock categories and products for development
Future<void> _seedMockData(RuderbarDatabase database) async {
  try {
    // Create repositories
    final productsRepo = ProductsRepository(database);
    final membersRepo = MembersRepository(database);

    // Check if categories exist (determines if we need to seed products/categories)
    final existingCategories = await database.select(database.categoriesCache).get();

    if (existingCategories.isEmpty) {
      // Seed categories and products only if they don't exist
      await productsRepo.upsertCategories([
      CategoryDTO(
        id: 'cat-1',
        names: {'de': 'Getränke', 'en': 'Drinks'},
        displayOrder: 1,
        isActive: true,
        iconName: 'CategoryDrinksIcon',
        updatedAt: '2025-02-01T10:00:00Z',
      ),
      CategoryDTO(
        id: 'cat-2',
        names: {'de': 'Sauna', 'en': 'Sauna'},
        displayOrder: 2,
        isActive: true,
        iconName: 'CategorySaunaIcon',
        updatedAt: '2025-02-01T10:00:00Z',
      ),
    ]);

    await productsRepo.upsertProducts([
      // Getränke / Drinks
      ProductDTO(
        id: 'prod-1',
        categoryId: 'cat-1',
        names: {'de': 'Pils 0,5l', 'en': 'Pils 0.5l'},
        descriptions: null,
        priceCents: 350,
        isActive: true,
        iconName: 'PilsIcon',
        updatedAt: '2025-02-01T10:00:00Z',
      ),
      ProductDTO(
        id: 'prod-2',
        categoryId: 'cat-1',
        names: {'de': 'Radler 0,5l', 'en': 'Radler 0.5l'},
        descriptions: null,
        priceCents: 300,
        isActive: true,
        iconName: 'RadlerIcon',
        updatedAt: '2025-02-01T10:00:00Z',
      ),
      ProductDTO(
        id: 'prod-3',
        categoryId: 'cat-1',
        names: {'de': 'Weizen 0,5l', 'en': 'Weizen 0.5l'},
        descriptions: null,
        priceCents: 380,
        isActive: true,
        iconName: 'WeizenIcon',
        updatedAt: '2025-02-01T10:00:00Z',
      ),
      ProductDTO(
        id: 'prod-4',
        categoryId: 'cat-1',
        names: {'de': 'Wasser 0,33l', 'en': 'Water 0.33l'},
        descriptions: null,
        priceCents: 150,
        isActive: true,
        iconName: 'WaterSmallIcon',
        updatedAt: '2025-02-01T10:00:00Z',
      ),
      ProductDTO(
        id: 'prod-5',
        categoryId: 'cat-1',
        names: {'de': 'Apfelschorle', 'en': 'Apple Spritzer'},
        descriptions: null,
        priceCents: 200,
        isActive: true,
        iconName: 'LemonadeIcon',
        updatedAt: '2025-02-01T10:00:00Z',
      ),
      ProductDTO(
        id: 'prod-6',
        categoryId: 'cat-1',
        names: {'de': 'Apfelwein 1l', 'en': 'Cider 1l'},
        descriptions: null,
        priceCents: 300,
        isActive: true,
        iconName: 'ApplerIcon',
        updatedAt: '2025-02-01T10:00:00Z',
      ),
      ProductDTO(
        id: 'prod-7',
        categoryId: 'cat-1',
        names: {'de': 'Wasser 1l', 'en': 'Water 1l'},
        descriptions: null,
        priceCents: 200,
        isActive: true,
        iconName: 'WaterLargeIcon',
        updatedAt: '2025-02-01T10:00:00Z',
      ),
      ProductDTO(
        id: 'prod-8',
        categoryId: 'cat-1',
        names: {'de': 'Kaffee', 'en': 'Coffee'},
        descriptions: null,
        priceCents: 200,
        isActive: true,
        iconName: 'PilsIcon',
        updatedAt: '2025-02-01T10:00:00Z',
      ),
      // Sauna
      ProductDTO(
        id: 'prod-9',
        categoryId: 'cat-2',
        names: {'de': 'Sauna-Token', 'en': 'Sauna Token'},
        descriptions: null,
        priceCents: 200,
        isActive: true,
        iconName: 'SaunaTokenIcon',
        updatedAt: '2025-02-01T10:00:00Z',
      ),
    ]);

    // Seed test members only when no real members exist yet
    await membersRepo.upsertMembers([
      MemberDTO(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        preferredLanguage: 'de',
        isActive: true,
        isSepaValid: true,
        updatedAt: '2025-02-01T10:00:00Z',
      ),
      MemberDTO(
        id: 'member-2',
        cardUid: 'card-456',
        firstName: 'Jane',
        lastName: 'Smith',
        preferredLanguage: 'de',
        isActive: true,
        isSepaValid: true,
        updatedAt: '2025-02-01T10:00:00Z',
      ),
    ]);
    }
  } catch (e) {
    // Silently fail - data might already exist
  }
}

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Initialize window manager for desktop (macOS/Linux/Windows)
  try {
    await windowManager.ensureInitialized();
    windowManager.waitUntilReadyToShow().then((_) async {
      // Set fixed window size: 1280x720 (HD resolution)
      await windowManager.setSize(const Size(1280, 720));
      await windowManager.setMinimumSize(const Size(1280, 720));
      await windowManager.setMaximumSize(const Size(1280, 720));
      await windowManager.center();
      await windowManager.show();
    });
  } catch (e) {
    // Window manager not available (mobile platform or plugin issue)
    // App will run with default window size
  }

  // Load terminal configuration (ADR-0019)
  final configService = ConfigService();
  await configService.load();

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

  // Initialize database
  final database = RuderbarDatabase();

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

  // Create repositories (data access layer)
  final membersRepo = MembersRepository(database);
  final productsRepo = ProductsRepository(database);
  final transactionsRepo = TransactionsRepository(database);
  final syncRepo = SyncRepository(database);

  // Create services (business logic layer)
  final networkService = NetworkService(
    baseUrl: configService.apiUrl ?? AppConfig.apiBaseUrl,
  );
  if (configService.apiToken != null) {
    networkService.setAuthToken(configService.apiToken!);
  }
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
  );

  // Load products into provider (after seeding database)
  await productsProvider.refreshProducts();
  final syncProvider = SyncProvider(
    syncService: syncService,
    membersProvider: membersProvider,
    productsProvider: productsProvider,
    networkService: networkService,
  );

  runApp(RuderbarTerminalApp(
    database: database,
    localeProvider: localeProvider,
    membersProvider: membersProvider,
    productsProvider: productsProvider,
    cartService: cartService,
    syncProvider: syncProvider,
    membersRepository: membersRepo,
    transactionsRepository: transactionsRepo,
    configService: configService,
    networkService: networkService,
    dispenserHealthService: dispenserHealthService,
    dispenserRecoveryService: dispenserRecoveryService,
  ));
}

class RuderbarTerminalApp extends StatelessWidget {
  final RuderbarDatabase database;
  final LocaleProvider localeProvider;
  final MembersProvider membersProvider;
  final ProductsProvider productsProvider;
  final CartService cartService;
  final SyncProvider syncProvider;
  final MembersRepository membersRepository;
  final TransactionsRepository transactionsRepository;
  final ConfigService configService;
  final NetworkService networkService;
  final DispenserHealthService? dispenserHealthService;
  final DispenserRecoveryService? dispenserRecoveryService;

  const RuderbarTerminalApp({
    super.key,
    required this.database,
    required this.localeProvider,
    required this.membersProvider,
    required this.productsProvider,
    required this.cartService,
    required this.syncProvider,
    required this.membersRepository,
    required this.transactionsRepository,
    required this.configService,
    required this.networkService,
    this.dispenserHealthService,
    this.dispenserRecoveryService,
  });

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        Provider<RuderbarDatabase>.value(value: database),
        Provider<NetworkService>.value(value: networkService),
        Provider<ConfigService>.value(value: configService),
        Provider<TransactionsRepository>.value(value: transactionsRepository),
        if (dispenserHealthService != null)
          ChangeNotifierProvider<DispenserHealthService>.value(value: dispenserHealthService!),
        ChangeNotifierProvider<LocaleProvider>.value(value: localeProvider),
        ChangeNotifierProvider(create: (_) => AuthProvider()),
        ChangeNotifierProvider<MembersProvider>.value(value: membersProvider),
        ChangeNotifierProvider<ProductsProvider>(create: (_) => productsProvider),
        ChangeNotifierProvider(create: (_) => CartProvider(service: cartService, config: configService)),
        ChangeNotifierProvider<SyncProvider>(create: (_) => syncProvider),
        ChangeNotifierProvider(create: (_) => RfidProvider(membersProvider, membersRepository)),
      ],
      child: Builder(
        builder: (context) {
          final localeProvider = context.watch<LocaleProvider>();

          return MaterialApp.router(
            title: AppConfig.appName,
            theme: ThemeData(
              useMaterial3: true,
              colorScheme: ColorScheme.fromSeed(
                seedColor: const Color(0xFF3B82F6),
                brightness: Brightness.dark,
              ),
            ),
            // Localization configuration
            localizationsDelegates: const [
              AppLocalizations.delegate,
              GlobalMaterialLocalizations.delegate,
              GlobalWidgetsLocalizations.delegate,
              GlobalCupertinoLocalizations.delegate,
            ],
            supportedLocales: LocaleProvider.supportedLocales,
            locale: localeProvider.locale,
            routerConfig: createAppRouter(context, configService: configService),
          );
        },
      ),
    );
  }
}
