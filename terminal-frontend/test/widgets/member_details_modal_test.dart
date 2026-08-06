import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/services/network_service.dart';
import 'package:clubbar_terminal/widgets/member_details_modal.dart';
import '../test_helpers.dart';

class MockMembersProvider extends Mock implements MembersProvider {}

class MockNetworkService extends Mock implements NetworkService {}

class MockDatabase extends Mock implements ClubBarDatabase {}

void main() {
  // Issue #27: this modal used to render `e.toString()` straight into the
  // member's face — "NetworkException: Sync members failed: HTTP 500".
  group('MemberDetailsModal transaction history failure (#27)', () {
    late MockMembersProvider mockMembersProvider;
    late MockNetworkService mockNetworkService;

    setUp(() {
      mockMembersProvider = MockMembersProvider();
      mockNetworkService = MockNetworkService();

      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);
      when(() => mockMembersProvider.selectedMember).thenReturn(
        MembersCacheData(
          id: 'member-1',
          cardUid: 'card-123',
          firstName: 'John',
          lastName: 'Doe',
          preferredLanguage: 'de',
          isActive: 1,
          isSepaValid: 1,
          balanceCents: 0,
          updatedAt: '2025-02-01T10:00:00Z',
        ),
      );

      // The exact failure that used to leak its text to the screen.
      when(() => mockNetworkService.checkHealth()).thenThrow(
        NetworkException('Sync members failed: HTTP 500'),
      );
    });

    Future<void> pumpModal(WidgetTester tester) async {
      await tester.pumpWidget(
        createTestApp(
          child: MultiProvider(
            providers: [
              ChangeNotifierProvider<MembersProvider>.value(
                value: mockMembersProvider,
              ),
              Provider<NetworkService>.value(value: mockNetworkService),
              Provider<ClubBarDatabase>.value(value: MockDatabase()),
            ],
            child: const Scaffold(body: MemberDetailsModal()),
          ),
        ),
      );
      await tester.pumpAndSettle();
    }

    testWidgets('shows the localized error, not the exception',
        (WidgetTester tester) async {
      await pumpModal(tester);

      expect(
        find.text(await errorCopy(TerminalErrorKey.transactionHistoryFailed)),
        findsOneWidget,
      );
    });

    testWidgets('no raw exception text reaches any rendered Text',
        (WidgetTester tester) async {
      await pumpModal(tester);

      final rendered = tester
          .widgetList<Text>(find.byType(Text))
          .map((t) => t.data ?? '')
          .join('\n');
      expect(rendered, isNot(contains('NetworkException')));
      expect(rendered, isNot(contains('HTTP 500')));
    });

    testWidgets('still offers a way out', (WidgetTester tester) async {
      await pumpModal(tester);

      expect(find.text('Erneut versuchen'), findsOneWidget);
    });
  });
}
