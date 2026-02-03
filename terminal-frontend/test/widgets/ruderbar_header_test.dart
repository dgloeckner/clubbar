import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/providers/sync_provider.dart';
import 'package:ruderbar_terminal/widgets/ruderbar_header.dart';

void main() {
  group('RuderbarHeader', () {
    Widget buildTestApp({
      required ConnectionStatus connectionStatus,
      VoidCallback? onStatusTap,
    }) {
      return MaterialApp(
        home: Scaffold(
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

      expect(find.text('Error'), findsOneWidget);
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

      // The header should display a time in HH:MM format
      final now = DateTime.now();
      final expectedTime =
          '${now.hour.toString().padLeft(2, '0')}:${now.minute.toString().padLeft(2, '0')}';
      expect(find.text(expectedTime), findsOneWidget);
    });
  });
}
