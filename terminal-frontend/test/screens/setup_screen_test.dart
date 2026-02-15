import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/screens/setup_screen.dart';
import 'package:ruderbar_terminal/services/config_service.dart';
import '../test_helpers.dart';

void main() {
  group('SetupScreen', () {
    late ConfigService configService;

    setUp(() {
      // Use a temp dir that doesn't exist to avoid file I/O issues in tests
      configService = ConfigService(configDir: '/tmp/setup_screen_test_${DateTime.now().millisecondsSinceEpoch}');
    });

    Widget buildTestWidget() {
      return createTestApp(
        child: SetupScreen(configService: configService),
      );
    }

    testWidgets('displays title and subtitle', (tester) async {
      await tester.pumpWidget(buildTestWidget());

      expect(find.text('Terminal-Einrichtung'), findsOneWidget); // "Terminal Setup" in German
      expect(find.text('Verbinde dieses Terminal mit dem Ruderbar-Backend.'), findsOneWidget); // Subtitle in German
    });

    testWidgets('displays three form fields', (tester) async {
      await tester.pumpWidget(buildTestWidget());

      expect(find.text('Terminal-ID'), findsOneWidget); // "Terminal ID" in German
      expect(find.text('API-URL'), findsOneWidget); // "API URL" in German
      expect(find.text('API-Token'), findsOneWidget); // "API Token" in German
    });

    testWidgets('displays Save & Connect button', (tester) async {
      await tester.pumpWidget(buildTestWidget());

      expect(find.text('Speichern & Verbinden'), findsOneWidget); // "Save & Connect" in German
    });

    testWidgets('validates empty Terminal ID', (tester) async {
      await tester.pumpWidget(buildTestWidget());

      await tester.tap(find.text('Speichern & Verbinden'));
      await tester.pumpAndSettle();

      expect(find.text('Terminal-ID ist erforderlich'), findsOneWidget); // "Terminal ID is required" in German
    });

    testWidgets('validates empty API URL', (tester) async {
      await tester.pumpWidget(buildTestWidget());

      // Fill Terminal ID only (hint text is still in English in source code)
      await tester.enterText(
        find.widgetWithText(TextFormField, 'e.g. Ruderbar-Kühlschrank'),
        'Test Terminal',
      );

      await tester.tap(find.text('Speichern & Verbinden'));
      await tester.pumpAndSettle();

      expect(find.text('API-URL ist erforderlich'), findsOneWidget); // "API URL is required" in German
    });

    testWidgets('validates invalid API URL format', (tester) async {
      await tester.pumpWidget(buildTestWidget());

      await tester.enterText(
        find.widgetWithText(TextFormField, 'e.g. Ruderbar-Kühlschrank'), // Hint text still in English in source
        'Test Terminal',
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'https://club.example.com/api'),
        'not-a-url',
      );

      await tester.tap(find.text('Speichern & Verbinden'));
      await tester.pumpAndSettle();

      expect(find.text('Gib eine gültige URL ein (z.B. https://club.example.com/api)'), findsOneWidget); // Invalid URL message in German
    });

    testWidgets('validates empty API Token', (tester) async {
      await tester.pumpWidget(buildTestWidget());

      await tester.enterText(
        find.widgetWithText(TextFormField, 'e.g. Ruderbar-Kühlschrank'), // Hint text still in English in source
        'Test Terminal',
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'https://club.example.com/api'),
        'https://valid.url.com/api',
      );

      await tester.tap(find.text('Speichern & Verbinden'));
      await tester.pumpAndSettle();

      expect(find.text('API-Token ist erforderlich'), findsOneWidget); // "API Token is required" in German
    });
  });
}
