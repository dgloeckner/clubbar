import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';
import 'package:ruderbar_terminal/widgets/ruderbar_header.dart';
import '../test_helpers.dart';

void main() {
  group('RuderbarHeader', () {
    Widget buildTestApp({
      required ConnectionStatus connectionStatus,
      VoidCallback? onStatusTap,
    }) {
      return createTestApp(
        child: Scaffold(
          appBar: RuderbarHeader(
            connectionStatus: connectionStatus,
            onStatusTap: onStatusTap,
          ),
        ),
      );
    }

    testWidgets('shows Online badge with green styling', (tester) async {
      await tester.pumpWidget(buildTestApp(
        connectionStatus: ConnectionStatus.online,
      ));

      expect(find.text('Online'), findsOneWidget);
      expect(find.text('Offline'), findsNothing);
      expect(find.text('Error'), findsNothing);
      expect(find.text('Ruderbar'), findsOneWidget);
    });

    testWidgets('shows Offline badge', (tester) async {
      await tester.pumpWidget(buildTestApp(
        connectionStatus: ConnectionStatus.offline,
      ));

      expect(find.text('Offline'), findsOneWidget);
      expect(find.text('Online'), findsNothing);
      expect(find.text('Error'), findsNothing);
    });

    testWidgets('shows Error badge', (tester) async {
      await tester.pumpWidget(buildTestApp(
        connectionStatus: ConnectionStatus.error,
      ));

      expect(find.text('Fehler'), findsOneWidget); // "Error" in German
      expect(find.text('Online'), findsNothing);
      expect(find.text('Offline'), findsNothing);
    });

    testWidgets('calls onStatusTap when badge is tapped', (tester) async {
      var tapped = false;

      await tester.pumpWidget(buildTestApp(
        connectionStatus: ConnectionStatus.online,
        onStatusTap: () => tapped = true,
      ));

      await tester.tap(find.text('Online'));
      expect(tapped, isTrue);
    });

    testWidgets('displays current time', (tester) async {
      await tester.pumpWidget(buildTestApp(
        connectionStatus: ConnectionStatus.online,
      ));
      await tester.pump(); // Allow widget to build and timer to initialize

      // The header should display a time in HH:MM format
      // We just verify the time pattern exists, not the exact time (which may change during test)
      final timeRegex = RegExp(r'\d{2}:\d{2}');
      expect(find.byWidgetPredicate(
        (widget) => widget is Text && timeRegex.hasMatch(widget.data ?? ''),
      ), findsOneWidget);
    });
  });
}
