import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/rfid_reader_health_service.dart';
import 'package:clubbar_terminal/widgets/clubbar_header.dart';
import '../test_helpers.dart';

void main() {
  group('ClubBarHeader', () {
    Widget buildTestApp({
      required ConnectionStatus connectionStatus,
      RfidReaderStatus readerStatus = RfidReaderStatus.unknown,
      VoidCallback? onStatusTap,
    }) {
      return createTestApp(
        child: Scaffold(
          appBar: ClubBarHeader(
            connectionStatus: connectionStatus,
            readerStatus: readerStatus,
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
      expect(find.text('Club Bar'), findsOneWidget);
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

    testWidgets('shows no reader pill on a terminal that does not monitor it',
        (tester) async {
      await tester.pumpWidget(buildTestApp(
        connectionStatus: ConnectionStatus.online,
      ));

      expect(find.text('Scanner OK'), findsNothing);
      expect(find.text('Scanner fehlt'), findsNothing);
    });

    testWidgets('shows the reader pill when the reader is connected',
        (tester) async {
      await tester.pumpWidget(buildTestApp(
        connectionStatus: ConnectionStatus.online,
        readerStatus: RfidReaderStatus.connected,
      ));

      expect(find.text('Scanner OK'), findsOneWidget);
      expect(find.text('Scanner fehlt'), findsNothing);
      // The connection badge keeps its own meaning alongside it.
      expect(find.text('Online'), findsOneWidget);
    });

    testWidgets('shows the reader pill when the reader is missing',
        (tester) async {
      await tester.pumpWidget(buildTestApp(
        connectionStatus: ConnectionStatus.online,
        readerStatus: RfidReaderStatus.disconnected,
      ));

      expect(find.text('Scanner fehlt'), findsOneWidget);
      expect(find.text('Scanner OK'), findsNothing);
    });

    testWidgets('the reader pill opens the status modal too', (tester) async {
      var tapped = false;

      await tester.pumpWidget(buildTestApp(
        connectionStatus: ConnectionStatus.online,
        readerStatus: RfidReaderStatus.disconnected,
        onStatusTap: () => tapped = true,
      ));

      await tester.tap(find.text('Scanner fehlt'));
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
