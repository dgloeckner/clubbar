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
      bool isSyncing = false,
      VoidCallback? onStatusTap,
    }) {
      return createTestApp(
        child: Scaffold(
          appBar: ClubBarHeader(
            connectionStatus: connectionStatus,
            readerStatus: readerStatus,
            isSyncing: isSyncing,
            onStatusTap: onStatusTap,
          ),
        ),
      );
    }

    /// The pill box wrapping [label] — the thing a finger has to hit.
    Finder pillOf(String label) => find.ancestor(
          of: find.text(label),
          matching: find.byType(Container),
        ).first;

    /// The colour the pill's own text is actually painted in.
    Color textColorOf(WidgetTester tester, String label) =>
        tester.widget<Text>(find.text(label)).style!.color!;

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

    // Issue #40: the pill used to be 12 px text at 70% opacity in a ~24 px
    // box — the smallest, faintest thing on screen, and the only way into the
    // diagnostics modal.
    group('touch target and legibility (issue #40)', () {
      testWidgets('the connection pill is at least 44 px tall',
          (tester) async {
        await tester.pumpWidget(buildTestApp(
          connectionStatus: ConnectionStatus.online,
        ));

        expect(
          tester.getSize(pillOf('Online')).height,
          greaterThanOrEqualTo(kHeaderPillTouchTarget),
        );
      });

      testWidgets('the reader pill is at least 44 px tall too',
          (tester) async {
        await tester.pumpWidget(buildTestApp(
          connectionStatus: ConnectionStatus.online,
          readerStatus: RfidReaderStatus.disconnected,
        ));

        expect(
          tester.getSize(pillOf('Scanner fehlt')).height,
          greaterThanOrEqualTo(kHeaderPillTouchTarget),
        );
      });

      testWidgets('a tap on the pill padding counts, not just on the text',
          (tester) async {
        var tapped = false;

        await tester.pumpWidget(buildTestApp(
          connectionStatus: ConnectionStatus.offline,
          onStatusTap: () => tapped = true,
        ));

        // Top-left inside the pill box: padding, no glyph under the finger.
        final box = tester.getRect(pillOf('Offline'));
        await tester.tapAt(box.topLeft + const Offset(3, 3));
        expect(tapped, isTrue);
      });

      testWidgets('offline text is fully opaque, online text is muted',
          (tester) async {
        await tester.pumpWidget(buildTestApp(
          connectionStatus: ConnectionStatus.offline,
        ));
        expect(textColorOf(tester, 'Offline').a, 1.0);

        await tester.pumpWidget(buildTestApp(
          connectionStatus: ConnectionStatus.online,
        ));
        expect(textColorOf(tester, 'Online').a, lessThan(1.0));
      });

      testWidgets('each state carries its own icon', (tester) async {
        await tester.pumpWidget(buildTestApp(
          connectionStatus: ConnectionStatus.offline,
        ));
        expect(find.byIcon(Icons.cloud_off), findsOneWidget);

        await tester.pumpWidget(buildTestApp(
          connectionStatus: ConnectionStatus.error,
        ));
        expect(find.byIcon(Icons.warning_amber_rounded), findsOneWidget);

        await tester.pumpWidget(buildTestApp(
          connectionStatus: ConnectionStatus.online,
          readerStatus: RfidReaderStatus.disconnected,
        ));
        expect(find.byIcon(Icons.sensors_off), findsOneWidget);
        expect(find.byIcon(Icons.cloud_done_outlined), findsOneWidget);
      });
    });

    group('syncing indicator (issue #40)', () {
      testWidgets('no spinner while idle', (tester) async {
        await tester.pumpWidget(buildTestApp(
          connectionStatus: ConnectionStatus.online,
        ));

        expect(find.byType(CircularProgressIndicator), findsNothing);
      });

      testWidgets('a sync in progress shows a spinner in the pill',
          (tester) async {
        await tester.pumpWidget(buildTestApp(
          connectionStatus: ConnectionStatus.online,
          isSyncing: true,
        ));
        await tester.pump();

        expect(find.byType(CircularProgressIndicator), findsOneWidget);
        // The spinner replaces the state icon, it does not stack with it.
        expect(find.byIcon(Icons.cloud_done_outlined), findsNothing);
      });

      testWidgets('a retry after a failure still reads as an error',
          (tester) async {
        await tester.pumpWidget(buildTestApp(
          connectionStatus: ConnectionStatus.error,
          isSyncing: true,
        ));
        await tester.pump();

        expect(find.text('Fehler'), findsOneWidget);
        expect(find.byType(CircularProgressIndicator), findsOneWidget);
      });
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
