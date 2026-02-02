import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/config/app_config.dart';
import 'package:ruderbar_terminal/config/app_router.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/providers/auth_provider.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/providers/products_provider.dart';
import 'package:ruderbar_terminal/providers/cart_provider.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';
import 'package:ruderbar_terminal/providers/rfid_provider.dart';
import 'package:ruderbar_terminal/repository/members_repository.dart';
import 'package:ruderbar_terminal/repository/products_repository.dart';
import 'package:ruderbar_terminal/repository/transactions_repository.dart';
import 'package:ruderbar_terminal/repository/sync_repository.dart';
import 'package:ruderbar_terminal/services/network_service.dart';
import 'package:ruderbar_terminal/services/members_service.dart';
import 'package:ruderbar_terminal/services/products_service.dart';
import 'package:ruderbar_terminal/services/cart_service.dart';
import 'package:ruderbar_terminal/services/sync_service.dart';
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
        names: {'de': 'Bier', 'en': 'Beer'},
        displayOrder: 1,
        isActive: true,
        updatedAt: '2025-02-01T10:00:00Z',
      ),
      CategoryDTO(
        id: 'cat-2',
        names: {'de': 'Wein', 'en': 'Wine'},
        displayOrder: 2,
        isActive: true,
        updatedAt: '2025-02-01T10:00:00Z',
      ),
    ]);

    await productsRepo.upsertProducts([
      ProductDTO(
        id: 'prod-1',
        categoryId: 'cat-1',
        names: {'de': 'Pilsner', 'en': 'Pilsner'},
        descriptions: null,
        priceCents: 350,
        isActive: true,
        updatedAt: '2025-02-01T10:00:00Z',
      ),
      ProductDTO(
        id: 'prod-2',
        categoryId: 'cat-1',
        names: {'de': 'Weißbier', 'en': 'Wheat Beer'},
        descriptions: null,
        priceCents: 400,
        isActive: true,
        updatedAt: '2025-02-01T10:00:00Z',
      ),
      ProductDTO(
        id: 'prod-3',
        categoryId: 'cat-2',
        names: {'de': 'Rotwein', 'en': 'Red Wine'},
        descriptions: null,
        priceCents: 600,
        isActive: true,
        updatedAt: '2025-02-01T10:00:00Z',
      ),
    ]);
    }

    // Insert test members for development (always ensure members exist)
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
  } catch (e) {
    // Silently fail - data might already exist
  }
}

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Initialize database
  final database = RuderbarDatabase();

  // Seed database with mock data for development
  await _seedMockData(database);

  // Create repositories (data access layer)
  final membersRepo = MembersRepository(database);
  final productsRepo = ProductsRepository(database);
  final transactionsRepo = TransactionsRepository(database);
  final syncRepo = SyncRepository(database);

  // Create services (business logic layer)
  final networkService = NetworkService();
  final membersService = MembersService(repository: membersRepo);
  final productsService = ProductsService(repository: productsRepo);
  final cartService = CartService(repository: transactionsRepo);
  final syncService = SyncService(
    networkService: networkService,
    membersRepo: membersRepo,
    productsRepo: productsRepo,
    transactionsRepo: transactionsRepo,
    syncRepo: syncRepo,
  );

  // Create providers (UI state management)
  final membersProvider = MembersProvider(service: membersService);
  final productsProvider = ProductsProvider(service: productsService);

  // Load products into provider (after seeding database)
  await productsProvider.refreshProducts();
  final syncProvider = SyncProvider(
    syncService: syncService,
    membersProvider: membersProvider,
    productsProvider: productsProvider,
  );

  runApp(RuderbarTerminalApp(
    database: database,
    membersProvider: membersProvider,
    productsProvider: productsProvider,
    cartService: cartService,
    syncProvider: syncProvider,
    membersRepository: membersRepo,
  ));
}

class RuderbarTerminalApp extends StatelessWidget {
  final RuderbarDatabase database;
  final MembersProvider membersProvider;
  final ProductsProvider productsProvider;
  final CartService cartService;
  final SyncProvider syncProvider;
  final MembersRepository membersRepository;

  const RuderbarTerminalApp({
    super.key,
    required this.database,
    required this.membersProvider,
    required this.productsProvider,
    required this.cartService,
    required this.syncProvider,
    required this.membersRepository,
  });

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => AuthProvider()),
        ChangeNotifierProvider<MembersProvider>(create: (_) => membersProvider),
        ChangeNotifierProvider<ProductsProvider>(create: (_) => productsProvider),
        ChangeNotifierProvider(create: (_) => CartProvider(service: cartService)),
        ChangeNotifierProvider<SyncProvider>(create: (_) => syncProvider),
        ChangeNotifierProvider(create: (_) => RfidProvider(membersProvider, membersRepository)),
      ],
      child: Builder(
        builder: (context) => MaterialApp.router(
          title: AppConfig.appName,
          theme: ThemeData(
            useMaterial3: true,
            colorScheme: ColorScheme.fromSeed(
              seedColor: const Color(0xFF3B82F6),
              brightness: Brightness.dark,
            ),
          ),
          routerConfig: createAppRouter(context),
        ),
      ),
    );
  }
}
