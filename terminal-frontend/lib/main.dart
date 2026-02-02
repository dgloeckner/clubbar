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

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Initialize database
  final database = RuderbarDatabase();

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
        ChangeNotifierProvider(create: (_) => RfidProvider(membersRepository, membersProvider)),
      ],
      child: MaterialApp.router(
        title: AppConfig.appName,
        theme: ThemeData(
          useMaterial3: true,
          colorScheme: ColorScheme.fromSeed(
            seedColor: const Color(0xFF3B82F6),
            brightness: Brightness.dark,
          ),
        ),
        routerConfig: appRouter,
      ),
    );
  }
}
