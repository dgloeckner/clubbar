import 'dart:convert';
import 'dart:io';

import 'package:drift/native.dart';
import 'package:flutter/material.dart';
import 'package:logger/logger.dart';
import 'package:path/path.dart' as p;

import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/main.dart';
import 'package:clubbar_terminal/models/category_dto.dart';
import 'package:clubbar_terminal/models/product_dto.dart';
import 'package:clubbar_terminal/models/member_dto.dart';
import 'package:clubbar_terminal/providers/locale_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/products_provider.dart';
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

/// Creates an in-memory [ClubBarDatabase] seeded with minimal test data:
/// - 1 category ("Drinks")
/// - 2 products ("Pils 0.5l" at 350 cents, "Water 0.33l" at 150 cents)
/// - 1 member ("Test Member" with card UID "test-card-001")
Future<ClubBarDatabase> createTestDatabase() async {
  final db = ClubBarDatabase.forTesting(NativeDatabase.memory());

  final productsRepo = ProductsRepository(db);
  final membersRepo = MembersRepository(db);

  await productsRepo.upsertCategories([
    CategoryDTO(
      id: 'int-cat-1',
      names: {'de': 'Getränke', 'en': 'Drinks'},
      isActive: true,
      iconName: 'CategoryDrinksIcon',
      updatedAt: '2025-02-01T10:00:00Z',
    ),
  ]);

  await productsRepo.upsertProducts([
    ProductDTO(
      id: 'int-prod-1',
      categoryId: 'int-cat-1',
      names: {'de': 'Pils 0,5l', 'en': 'Pils 0.5l'},
      descriptions: null,
      priceCents: 350,
      isActive: true,
      iconName: 'PilsIcon',
      updatedAt: '2025-02-01T10:00:00Z',
    ),
    ProductDTO(
      id: 'int-prod-2',
      categoryId: 'int-cat-1',
      names: {'de': 'Wasser 0,33l', 'en': 'Water 0.33l'},
      descriptions: null,
      priceCents: 150,
      isActive: true,
      iconName: 'WaterSmallIcon',
      updatedAt: '2025-02-01T10:00:00Z',
    ),
  ]);

  await membersRepo.upsertMembers([
    MemberDTO(
      id: 'int-member-1',
      cardUid: 'test-card-001',
      firstName: 'Test',
      lastName: 'Member',
      preferredLanguage: 'de',
      isActive: true,
      isSepaValid: true,
      updatedAt: '2025-02-01T10:00:00Z',
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
/// The returned widget can be passed to [tester.pumpWidget] in
/// integration tests.
Future<Widget> buildTestApp(ClubBarDatabase database) async {
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

  final syncProvider = SyncProvider(
    syncService: syncService,
    membersProvider: membersProvider,
    productsProvider: productsProvider,
    networkService: networkService,
  );

  return ClubBarTerminalApp(
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
    soundService: soundService,
    dispenserHealthService: null,
    dispenserRecoveryService: null,
  );
}
