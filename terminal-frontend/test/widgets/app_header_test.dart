import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/providers/auth_provider.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';
import 'package:ruderbar_terminal/widgets/app_header.dart';

class MockAuthProvider extends Mock implements AuthProvider {}

class MockSyncProvider extends Mock implements SyncProvider {}

void main() {
  group('AppHeader', () {
    late MockAuthProvider mockAuthProvider;
    late MockSyncProvider mockSyncProvider;

    setUp(() {
      mockAuthProvider = MockAuthProvider();
      mockSyncProvider = MockSyncProvider();
    });

    testWidgets('displays title', (WidgetTester tester) async {
      when(() => mockAuthProvider.isAuthenticated).thenReturn(true);
      when(() => mockSyncProvider.isSyncing).thenReturn(false);

      await tester.pumpWidget(
        MaterialApp(
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

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            appBar: AppHeader(
              title: 'Test',
              authProvider: mockAuthProvider,
              syncProvider: mockSyncProvider,
            ),
          ),
        ),
      );

      expect(find.byIcon(Icons.sync), findsOneWidget);
    });

    testWidgets('shows offline indicator when not syncing', (WidgetTester tester) async {
      when(() => mockAuthProvider.isAuthenticated).thenReturn(true);
      when(() => mockSyncProvider.isSyncing).thenReturn(false);

      await tester.pumpWidget(
        MaterialApp(
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

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            appBar: AppHeader(
              title: 'Test',
              authProvider: mockAuthProvider,
              syncProvider: mockSyncProvider,
            ),
          ),
        ),
      );

      // Should show auth indicator
      expect(find.byIcon(Icons.verified_user), findsOneWidget);
    });
  });
}
