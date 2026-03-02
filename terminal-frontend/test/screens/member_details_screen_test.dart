import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/screens/member_details_screen.dart';
import '../test_helpers.dart';

class MockMembersProvider extends Mock implements MembersProvider {}

void main() {
  setUpAll(() {
    registerFallbackValue(MembersCacheData(
      id: 'test',
      cardUid: 'test',
      firstName: 'Test',
      lastName: 'User',
      preferredLanguage: 'de',
      isActive: 1,
      isSepaValid: 1,
      balanceCents: 0,
      updatedAt: '2024-01-01T00:00:00Z',
    ));
  });

  group('MemberDetailsScreen', () {
    late MockMembersProvider mockMembersProvider;

    setUp(() {
      mockMembersProvider = MockMembersProvider();
    });

    testWidgets('displays member information', (WidgetTester tester) async {
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        preferredLanguage: 'de',
        isActive: 1,
        isSepaValid: 1,
        balanceCents: 0,
        updatedAt: '2024-01-01T00:00:00Z',
      );

      when(() => mockMembersProvider.selectedMember).thenReturn(testMember);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        createTestApp(
          child: ChangeNotifierProvider<MembersProvider>.value(
            value: mockMembersProvider,
            child: const Scaffold(body: MemberDetailsScreen()),
          ),
        ),
      );

      expect(find.text('John'), findsOneWidget);
      expect(find.text('Doe'), findsOneWidget);
      expect(find.text('Aktiv'), findsOneWidget); // "Active" in German
    });

    testWidgets('displays no member selected message when member is null', (WidgetTester tester) async {
      when(() => mockMembersProvider.selectedMember).thenReturn(null);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        createTestApp(
          child: ChangeNotifierProvider<MembersProvider>.value(
            value: mockMembersProvider,
            child: const Scaffold(body: MemberDetailsScreen()),
          ),
        ),
      );

      expect(find.text('Kein Mitglied ausgewählt'), findsOneWidget); // "No member selected" in German
    });

    testWidgets('displays inactive status correctly', (WidgetTester tester) async {
      final inactiveMember = MembersCacheData(
        id: 'member-2',
        cardUid: 'card-456',
        firstName: 'Jane',
        lastName: 'Smith',
        preferredLanguage: 'de',
        isActive: 0,
        isSepaValid: 1,
        balanceCents: 0,
        updatedAt: '2024-01-01T00:00:00Z',
      );

      when(() => mockMembersProvider.selectedMember).thenReturn(inactiveMember);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        createTestApp(
          child: ChangeNotifierProvider<MembersProvider>.value(
            value: mockMembersProvider,
            child: const Scaffold(body: MemberDetailsScreen()),
          ),
        ),
      );

      expect(find.text('Inaktiv'), findsOneWidget); // "Inactive" in German
    });
  });
}
