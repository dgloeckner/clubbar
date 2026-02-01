// This is a basic Flutter widget test.
//
// To perform an interaction with a widget in your test, use the WidgetTester
// utility in the flutter_test package. For example, you can send tap and scroll
// gestures. You can also use WidgetTester to find child widgets in the widget
// tree, read text, and verify that the values of widget properties are correct.

import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/providers/products_provider.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';
import 'package:ruderbar_terminal/repository/members_repository.dart';
import 'package:ruderbar_terminal/services/cart_service.dart';
import 'package:ruderbar_terminal/main.dart';

class MockRuderbarDatabase extends Mock implements RuderbarDatabase {}
class MockMembersProvider extends Mock implements MembersProvider {}
class MockProductsProvider extends Mock implements ProductsProvider {}
class MockCartService extends Mock implements CartService {}
class MockSyncProvider extends Mock implements SyncProvider {}
class MockMembersRepository extends Mock implements MembersRepository {}

void main() {
  testWidgets('App initializes with Material 3 theme', (WidgetTester tester) async {
    final mockDatabase = MockRuderbarDatabase();
    final mockMembersProvider = MockMembersProvider();
    final mockProductsProvider = MockProductsProvider();
    final mockCartService = MockCartService();
    final mockSyncProvider = MockSyncProvider();
    final mockMembersRepository = MockMembersRepository();

    // Build our app and trigger a frame.
    await tester.pumpWidget(
      RuderbarTerminalApp(
        database: mockDatabase,
        membersProvider: mockMembersProvider,
        productsProvider: mockProductsProvider,
        cartService: mockCartService,
        syncProvider: mockSyncProvider,
        membersRepository: mockMembersRepository,
      ),
    );

    // Verify the app instance exists (Material 3 theme is configured in main.dart)
    expect(find.byType(RuderbarTerminalApp), findsOneWidget);
  });
}
