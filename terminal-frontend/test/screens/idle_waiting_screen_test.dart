import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/rfid_provider.dart';
import 'package:ruderbar_terminal/screens/idle_waiting_screen.dart';

class MockRfidProvider extends Mock implements RfidProvider {}

void main() {
  group('IdleWaitingScreen', () {
    late MockRfidProvider mockRfidProvider;

    setUp(() {
      mockRfidProvider = MockRfidProvider();
    });

    testWidgets('displays welcome text', (WidgetTester tester) async {
      when(() => mockRfidProvider.addListener(any())).thenReturn(null);
      when(() => mockRfidProvider.removeListener(any())).thenReturn(null);
      when(() => mockRfidProvider.isScanning).thenReturn(false);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<RfidProvider>.value(
            value: mockRfidProvider,
            child: const Scaffold(body: IdleWaitingScreen()),
          ),
        ),
      );

      expect(find.text('Durstig?'), findsOneWidget);
    });

    testWidgets('displays subtitle text', (WidgetTester tester) async {
      when(() => mockRfidProvider.addListener(any())).thenReturn(null);
      when(() => mockRfidProvider.removeListener(any())).thenReturn(null);
      when(() => mockRfidProvider.isScanning).thenReturn(false);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<RfidProvider>.value(
            value: mockRfidProvider,
            child: const Scaffold(body: IdleWaitingScreen()),
          ),
        ),
      );

      expect(find.text('Halte deine Karte an den Scanner'), findsOneWidget);
    });

    testWidgets('displays demo button', (WidgetTester tester) async {
      when(() => mockRfidProvider.addListener(any())).thenReturn(null);
      when(() => mockRfidProvider.removeListener(any())).thenReturn(null);
      when(() => mockRfidProvider.isScanning).thenReturn(false);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<RfidProvider>.value(
            value: mockRfidProvider,
            child: const Scaffold(body: IdleWaitingScreen()),
          ),
        ),
      );

      expect(find.text('Demo: Scan Card'), findsOneWidget);
    });

    testWidgets('demo button is disabled when scanning', (WidgetTester tester) async {
      when(() => mockRfidProvider.addListener(any())).thenReturn(null);
      when(() => mockRfidProvider.removeListener(any())).thenReturn(null);
      when(() => mockRfidProvider.isScanning).thenReturn(true);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<RfidProvider>.value(
            value: mockRfidProvider,
            child: const Scaffold(body: IdleWaitingScreen()),
          ),
        ),
      );

      final buttonFinder = find.byType(ElevatedButton);
      expect(buttonFinder, findsOneWidget);

      final button = buttonFinder.evaluate().single.widget as ElevatedButton;
      expect(button.onPressed, isNull);
    });
  });
}
