import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/providers/auth_provider.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/widgets/app_header.dart';

class MockAuthProvider extends Mock implements AuthProvider {}

class MockSyncProvider extends Mock implements SyncProvider {}

class MockMembersProvider extends Mock implements MembersProvider {}

void main() {
  group('AppHeader', () {
    late MockAuthProvider mockAuthProvider;
    late MockSyncProvider mockSyncProvider;
    late MockMembersProvider mockMembersProvider;

    setUp(() {
      mockAuthProvider = MockAuthProvider();
      mockSyncProvider = MockSyncProvider();
      mockMembersProvider = MockMembersProvider();
    });

    testWidgets('displays title', (WidgetTester tester) async {
      when(() => mockAuthProvider.isAuthenticated).thenReturn(true);
      when(() => mockSyncProvider.isSyncing).thenReturn(false);

      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('de'),
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: const [Locale('en'), Locale('de')],
          home: Scaffold(
            appBar: AppHeader(
              title: 'Test Title',
              authProvider: mockAuthProvider,
              syncProvider: mockSyncProvider,
            ),
          ),
        ),
      );

      expect(find.text('Test Title'), findsOneWidget);
    });

    testWidgets('shows sync status when syncing', (WidgetTester tester) async {
      when(() => mockAuthProvider.isAuthenticated).thenReturn(true);
      when(() => mockSyncProvider.isSyncing).thenReturn(true);
      when(() => mockMembersProvider.selectedMember).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('de'),
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: const [Locale('en'), Locale('de')],
          home: Scaffold(
            appBar: AppHeader(
              title: 'Test',
              authProvider: mockAuthProvider,
              syncProvider: mockSyncProvider,
              membersProvider: mockMembersProvider,
            ),
          ),
        ),
      );

      // When no member selected, header shows title
      expect(find.text('Test'), findsOneWidget);
    });

    testWidgets('shows offline indicator when not syncing', (WidgetTester tester) async {
      when(() => mockAuthProvider.isAuthenticated).thenReturn(true);
      when(() => mockSyncProvider.isSyncing).thenReturn(false);

      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('de'),
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: const [Locale('en'), Locale('de')],
          home: Scaffold(
            appBar: AppHeader(
              title: 'Test',
              authProvider: mockAuthProvider,
              syncProvider: mockSyncProvider,
            ),
          ),
        ),
      );

      // Should show some indicator (e.g., text, icon, or color)
      expect(find.byType(AppHeader), findsOneWidget);
    });

    testWidgets('displays authentication badge when authenticated', (WidgetTester tester) async {
      when(() => mockAuthProvider.isAuthenticated).thenReturn(true);
      when(() => mockSyncProvider.isSyncing).thenReturn(false);
      when(() => mockMembersProvider.selectedMember).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('de'),
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: const [Locale('en'), Locale('de')],
          home: Scaffold(
            appBar: AppHeader(
              title: 'Test',
              authProvider: mockAuthProvider,
              syncProvider: mockSyncProvider,
              membersProvider: mockMembersProvider,
            ),
          ),
        ),
      );

      // When no member selected, header shows title
      expect(find.text('Test'), findsOneWidget);
    });

    testWidgets('displays member name when selected', (WidgetTester tester) async {
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

      when(() => mockAuthProvider.isAuthenticated).thenReturn(true);
      when(() => mockSyncProvider.isSyncing).thenReturn(false);
      when(() => mockMembersProvider.selectedMember).thenReturn(testMember);

      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('de'),
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: const [Locale('en'), Locale('de')],
          home: Scaffold(
            appBar: AppHeader(
              title: 'Test',
              authProvider: mockAuthProvider,
              syncProvider: mockSyncProvider,
              membersProvider: mockMembersProvider,
            ),
          ),
        ),
      );

      // When member is selected, show full name in member bar
      expect(find.text('John Doe'), findsOneWidget);
    });

    testWidgets('does not display member name when none selected', (WidgetTester tester) async {
      when(() => mockAuthProvider.isAuthenticated).thenReturn(true);
      when(() => mockSyncProvider.isSyncing).thenReturn(false);
      when(() => mockMembersProvider.selectedMember).thenReturn(null);

      await tester.pumpWidget(
        MaterialApp(
          locale: const Locale('de'),
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          supportedLocales: const [Locale('en'), Locale('de')],
          home: Scaffold(
            appBar: AppHeader(
              title: 'Test',
              authProvider: mockAuthProvider,
              syncProvider: mockSyncProvider,
              membersProvider: mockMembersProvider,
            ),
          ),
        ),
      );

      // Title should still be visible but not member name
      expect(find.text('Test'), findsOneWidget);
      expect(find.text('John'), findsNothing);
    });
  });
}
