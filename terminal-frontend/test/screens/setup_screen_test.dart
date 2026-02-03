import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/screens/setup_screen.dart';
import 'package:ruderbar_terminal/services/config_service.dart';

void main() {
  group('SetupScreen', () {
    late ConfigService configService;

    setUp(() {
      // Use a temp dir that doesn't exist to avoid file I/O issues in tests
      configService = ConfigService(configDir: '/tmp/setup_screen_test_${DateTime.now().millisecondsSinceEpoch}');
    });

    Widget buildTestWidget() {
      return MaterialApp(
        home: SetupScreen(configService: configService),
      );
    }

    testWidgets('displays title and subtitle', (tester) async {
      await tester.pumpWidget(buildTestWidget());

      expect(find.text('Terminal Setup'), findsOneWidget);
      expect(find.text('Connect this terminal to the Ruderbar backend.'), findsOneWidget);
    });

    testWidgets('displays three form fields', (tester) async {
      await tester.pumpWidget(buildTestWidget());

      expect(find.text('Terminal ID'), findsOneWidget);
      expect(find.text('API URL'), findsOneWidget);
      expect(find.text('API Token'), findsOneWidget);
    });

    testWidgets('displays Save & Connect button', (tester) async {
      await tester.pumpWidget(buildTestWidget());

      expect(find.text('Save & Connect'), findsOneWidget);
    });

    testWidgets('validates empty Terminal ID', (tester) async {
      await tester.pumpWidget(buildTestWidget());

      await tester.tap(find.text('Save & Connect'));
      await tester.pumpAndSettle();

      expect(find.text('Terminal ID is required'), findsOneWidget);
    });

    testWidgets('validates empty API URL', (tester) async {
      await tester.pumpWidget(buildTestWidget());

      // Fill Terminal ID only
      await tester.enterText(
        find.widgetWithText(TextFormField, 'e.g. Ruderbar-Kühlschrank'),
        'Test Terminal',
      );

      await tester.tap(find.text('Save & Connect'));
      await tester.pumpAndSettle();

      expect(find.text('API URL is required'), findsOneWidget);
    });

    testWidgets('validates invalid API URL format', (tester) async {
      await tester.pumpWidget(buildTestWidget());

      await tester.enterText(
        find.widgetWithText(TextFormField, 'e.g. Ruderbar-Kühlschrank'),
        'Test Terminal',
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'https://club.example.com/api'),
        'not-a-url',
      );

      await tester.tap(find.text('Save & Connect'));
      await tester.pumpAndSettle();

      expect(find.text('Enter a valid URL (e.g. https://club.example.com/api)'), findsOneWidget);
    });

    testWidgets('validates empty API Token', (tester) async {
      await tester.pumpWidget(buildTestWidget());

      await tester.enterText(
        find.widgetWithText(TextFormField, 'e.g. Ruderbar-Kühlschrank'),
        'Test Terminal',
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'https://club.example.com/api'),
        'https://test.example.com/api',
      );

      await tester.tap(find.text('Save & Connect'));
      await tester.pumpAndSettle();

      expect(find.text('API Token is required'), findsOneWidget);
    });

    testWidgets('validates token length', (tester) async {
      await tester.pumpWidget(buildTestWidget());

      await tester.enterText(
        find.widgetWithText(TextFormField, 'e.g. Ruderbar-Kühlschrank'),
        'Test Terminal',
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'https://club.example.com/api'),
        'https://test.example.com/api',
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, '64-character hex token'),
        'tooshort',
      );

      await tester.tap(find.text('Save & Connect'));
      await tester.pumpAndSettle();

      expect(find.text('Token must be 64 characters'), findsOneWidget);
    });

    testWidgets('validates token is hexadecimal', (tester) async {
      await tester.pumpWidget(buildTestWidget());

      await tester.enterText(
        find.widgetWithText(TextFormField, 'e.g. Ruderbar-Kühlschrank'),
        'Test Terminal',
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'https://club.example.com/api'),
        'https://test.example.com/api',
      );
      // 64 chars but contains non-hex characters
      await tester.enterText(
        find.widgetWithText(TextFormField, '64-character hex token'),
        'g' * 64,
      );

      await tester.tap(find.text('Save & Connect'));
      await tester.pumpAndSettle();

      expect(find.text('Token must be hexadecimal (0-9, a-f)'), findsOneWidget);
    });
  });
}
