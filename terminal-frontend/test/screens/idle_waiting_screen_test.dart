import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/rfid_provider.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';
import 'package:ruderbar_terminal/screens/idle_waiting_screen.dart';
import '../test_helpers.dart';

class MockRfidProvider extends Mock implements RfidProvider {}
class MockSyncProvider extends Mock implements SyncProvider {}

void main() {
  group('IdleWaitingScreen', () {
    late MockRfidProvider mockRfidProvider;
    late MockSyncProvider mockSyncProvider;

    setUp(() {
      mockRfidProvider = MockRfidProvider();
      mockSyncProvider = MockSyncProvider();
      when(() => mockSyncProvider.startBackgroundSync(intervalSeconds: any(named: 'intervalSeconds')))
          .thenReturn(null);
      when(() => mockSyncProvider.addListener(any())).thenReturn(null);
      when(() => mockSyncProvider.removeListener(any())).thenReturn(null);
    });

    Widget buildTestApp() {
      return createTestApp(
        child: MultiProvider(
          providers: [
            ChangeNotifierProvider<RfidProvider>.value(value: mockRfidProvider),
            ChangeNotifierProvider<SyncProvider>.value(value: mockSyncProvider),
          ],
          child: const Scaffold(body: IdleWaitingScreen()),
        ),
      );
    }

    testWidgets('displays welcome text', (WidgetTester tester) async {
      when(() => mockRfidProvider.addListener(any())).thenReturn(null);
      when(() => mockRfidProvider.removeListener(any())).thenReturn(null);
      when(() => mockRfidProvider.isScanning).thenReturn(false);

      await tester.pumpWidget(buildTestApp());

      expect(find.text('Durstig?'), findsOneWidget); // German translation
    });

    testWidgets('displays subtitle text', (WidgetTester tester) async {
      when(() => mockRfidProvider.addListener(any())).thenReturn(null);
      when(() => mockRfidProvider.removeListener(any())).thenReturn(null);
      when(() => mockRfidProvider.isScanning).thenReturn(false);

      await tester.pumpWidget(buildTestApp());

      expect(find.text('Halte deine Karte an den Scanner'), findsOneWidget); // German translation
    });

    testWidgets('displays demo button', (WidgetTester tester) async {
      when(() => mockRfidProvider.addListener(any())).thenReturn(null);
      when(() => mockRfidProvider.removeListener(any())).thenReturn(null);
      when(() => mockRfidProvider.isScanning).thenReturn(false);

      await tester.pumpWidget(buildTestApp());

      expect(find.text('Demo: Karte scannen'), findsOneWidget); // German translation
    });

    testWidgets('demo button is disabled when scanning', (WidgetTester tester) async {
      when(() => mockRfidProvider.addListener(any())).thenReturn(null);
      when(() => mockRfidProvider.removeListener(any())).thenReturn(null);
      when(() => mockRfidProvider.isScanning).thenReturn(true);

      await tester.pumpWidget(buildTestApp());

      final button = tester.widget<ElevatedButton>(
        find.widgetWithText(ElevatedButton, 'Demo: Karte scannen'),
      );
      expect(button.onPressed, isNull); // Button disabled when scanning
    });
  });
}
