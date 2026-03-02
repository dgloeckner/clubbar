import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/screens/member_greeting_screen.dart';

class MockMembersProvider extends Mock implements MembersProvider {}

void main() {
  group('MemberGreetingScreen', () {
    late MockMembersProvider mockMembersProvider;

    setUp(() {
      mockMembersProvider = MockMembersProvider();
    });

    testWidgets('displays member name and welcome message', (WidgetTester tester) async {
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        preferredLanguage: 'de',
        isActive: 1,
        isSepaValid: 1,
        balanceCents: 0,
        updatedAt: '2025-02-01T10:00:00Z',
      );

      when(() => mockMembersProvider.selectedMember).thenReturn(testMember);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<MembersProvider>.value(
            value: mockMembersProvider,
            child: const Scaffold(
              body: MemberGreetingScreen(),
            ),
          ),
        ),
      );

      expect(find.text('Welcome, John'), findsOneWidget);
      expect(find.text('Doe'), findsOneWidget);
    });

    testWidgets('displays error message for unknown card', (WidgetTester tester) async {
      when(() => mockMembersProvider.selectedMember).thenReturn(null);
      when(() => mockMembersProvider.lastError).thenReturn('Member not found');
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<MembersProvider>.value(
            value: mockMembersProvider,
            child: const Scaffold(
              body: MemberGreetingScreen(),
            ),
          ),
        ),
      );

      expect(find.text('Member not found'), findsOneWidget);
    });

    testWidgets('displays Continue Shopping button', (WidgetTester tester) async {
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        preferredLanguage: 'de',
        isActive: 1,
        isSepaValid: 1,
        balanceCents: 0,
        updatedAt: '2025-02-01T10:00:00Z',
      );

      when(() => mockMembersProvider.selectedMember).thenReturn(testMember);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<MembersProvider>.value(
            value: mockMembersProvider,
            child: const Scaffold(
              body: MemberGreetingScreen(),
            ),
          ),
        ),
      );

      expect(find.text('Continue Shopping'), findsOneWidget);
    });

    testWidgets('displays Scan Card button when no member selected', (WidgetTester tester) async {
      when(() => mockMembersProvider.selectedMember).thenReturn(null);
      when(() => mockMembersProvider.lastError).thenReturn(null);
      when(() => mockMembersProvider.addListener(any())).thenReturn(null);
      when(() => mockMembersProvider.removeListener(any())).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<MembersProvider>.value(
            value: mockMembersProvider,
            child: const Scaffold(
              body: MemberGreetingScreen(),
            ),
          ),
        ),
      );

      expect(find.text('Scan Card'), findsOneWidget);
    });
  });
}
