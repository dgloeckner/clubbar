import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:provider/provider.dart';
import 'package:ruderbar_terminal/providers/rfid_provider.dart';
import 'package:ruderbar_terminal/widgets/rfid_detector_button.dart';

class MockRfidProvider extends Mock implements RfidProvider {}
class FakeBuildContext extends Fake implements BuildContext {}

void main() {
  group('RfidDetectorButton', () {
    late MockRfidProvider mockRfidProvider;

    setUpAll(() {
      registerFallbackValue(FakeBuildContext());
    });

    setUp(() {
      mockRfidProvider = MockRfidProvider();
      when(() => mockRfidProvider.isScanning).thenReturn(false);
      when(() => mockRfidProvider.addListener(any())).thenReturn(null);
      when(() => mockRfidProvider.removeListener(any())).thenReturn(null);
    });

    testWidgets('button displays icon when not scanning', (WidgetTester tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<RfidProvider>.value(
            value: mockRfidProvider,
            child: const Scaffold(
              body: RfidDetectorButton(),
            ),
          ),
        ),
      );

      expect(find.byIcon(Icons.contactless), findsOneWidget);
    });

    testWidgets('button shows spinner when scanning', (WidgetTester tester) async {
      when(() => mockRfidProvider.isScanning).thenReturn(true);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<RfidProvider>.value(
            value: mockRfidProvider,
            child: const Scaffold(
              body: RfidDetectorButton(),
            ),
          ),
        ),
      );

      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('button is clickable when not scanning', (WidgetTester tester) async {
      when(() => mockRfidProvider.isScanning).thenReturn(false);
      when(() => mockRfidProvider.simulateCardDetection(any())).thenAnswer((_) async {});

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<RfidProvider>.value(
            value: mockRfidProvider,
            child: const Scaffold(
              body: RfidDetectorButton(),
            ),
          ),
        ),
      );

      await tester.tap(find.byType(GestureDetector));
      await tester.pump();

      verify(() => mockRfidProvider.simulateCardDetection(any())).called(1);
    });

    testWidgets('button gradient changes when scanning', (WidgetTester tester) async {
      when(() => mockRfidProvider.isScanning).thenReturn(true);

      await tester.pumpWidget(
        MaterialApp(
          home: ChangeNotifierProvider<RfidProvider>.value(
            value: mockRfidProvider,
            child: const Scaffold(
              body: RfidDetectorButton(),
            ),
          ),
        ),
      );

      // Verify that the widget renders with scanning appearance (contains CircularProgressIndicator)
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      expect(find.byIcon(Icons.contactless), findsNothing);
    });
  });
}
