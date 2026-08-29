import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/providers/sync_provider.dart';
import 'package:clubbar_terminal/services/config_service.dart';
import 'package:clubbar_terminal/services/rfid_reader_health_service.dart';
import 'package:clubbar_terminal/widgets/clubbar_header.dart';
import '../test_helpers.dart';
import '../utils/wcag.dart';

void main() {
  group('ClubBarHeader', () {
    Widget buildTestApp({
      required ConnectionStatus connectionStatus,
      RfidReaderStatus readerStatus = RfidReaderStatus.unknown,
      bool isSyncing = false,
      VoidCallback? onStatusTap,
      String? displayName,
      DateTime Function()? now,
    }) {
      return createTestApp(
        child: Scaffold(
          appBar: ClubBarHeader(
            connectionStatus: connectionStatus,
            readerStatus: readerStatus,
            isSyncing: isSyncing,
            onStatusTap: onStatusTap,
            displayName: displayName ?? ConfigService.defaultDisplayName,
            now: now,
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

    /// The header's own background — what a pill's translucent tint sits on.
    /// Read from the tree so the contrast checks below measure what is
    /// painted rather than a constant copied out of the widget.
    Color headerBackgroundOf(WidgetTester tester) {
      final header = tester.widget<Container>(
        find
            .descendant(
              of: find.byType(ClubBarHeader),
              matching: find.byType(Container),
            )
            .first,
      );
      return (header.decoration as BoxDecoration).color!;
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

      // #40 muted the quiet pill's label to 70 % so a healthy terminal would
      // not shout. That put it at 3.6:1 green and 2.6:1 red on its own tint —
      // under the 4.5:1 AA needs (#41), and the quiet state is the one showing
      // almost all the time. The label is full strength in both states now.
      //
      // The intent behind the muting survives intact: an alerting pill is
      // still louder, via the stronger fill, heavier border, and larger bolder
      // type asserted below. Only the illegible part is gone.
      testWidgets('every pill label clears AA against its own fill',
          (tester) async {
        // Read the colours off the rendered tree rather than restating them:
        // the pill's fill is a translucent tint of the status colour, and its
        // label is whatever `_pillTextColor` decided, so a table here would be
        // a guess about the widget instead of a check on it.
        Future<void> check(
          ConnectionStatus status,
          String label, {
          RfidReaderStatus reader = RfidReaderStatus.unknown,
        }) async {
          await tester.pumpWidget(buildTestApp(
            connectionStatus: status,
            readerStatus: reader,
          ));

          final fill = (tester.widget<Container>(pillOf(label)).decoration
                  as BoxDecoration)
              .color!;
          final onHeader = compositeOver(fill, headerBackgroundOf(tester));

          expectTextContrast(
            compositeOver(textColorOf(tester, label), onHeader),
            onHeader,
            why: '"$label" pill label',
          );
        }

        await check(ConnectionStatus.online, 'Online');
        await check(ConnectionStatus.offline, 'Offline');
        await check(ConnectionStatus.error, 'Fehler'); // "Error" in German
        await check(ConnectionStatus.online, 'Scanner OK',
            reader: RfidReaderStatus.connected);
        await check(ConnectionStatus.online, 'Scanner fehlt',
            reader: RfidReaderStatus.disconnected);
      });

      testWidgets('an alerting pill is still louder than a quiet one',
          (tester) async {
        await tester.pumpWidget(buildTestApp(
          connectionStatus: ConnectionStatus.online,
        ));
        final quiet = tester.widget<Text>(find.text('Online')).style!;
        final quietFill =
            (tester.widget<Container>(pillOf('Online')).decoration
                    as BoxDecoration)
                .color!;

        await tester.pumpWidget(buildTestApp(
          connectionStatus: ConnectionStatus.offline,
        ));
        final loud = tester.widget<Text>(find.text('Offline')).style!;
        final loudFill =
            (tester.widget<Container>(pillOf('Offline')).decoration
                    as BoxDecoration)
                .color!;

        expect(loud.fontSize, greaterThan(quiet.fontSize!),
            reason: 'an alert should be readable from further away');
        expect(loud.fontWeight!.value, greaterThan(quiet.fontWeight!.value));
        expect(loudFill.a, greaterThan(quietFill.a),
            reason: 'the alerting tint should be the stronger one');
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

    // Issue #297: the header used to hard-code "Club Bar", so a deploying
    // club could not show its own name without forking.
    group('configurable club name (#297)', () {
      testWidgets('falls back to "Club Bar" when no name is configured',
          (tester) async {
        await tester.pumpWidget(buildTestApp(
          connectionStatus: ConnectionStatus.online,
        ));

        expect(find.text('Club Bar'), findsOneWidget);
      });

      testWidgets('shows the configured club name instead', (tester) async {
        await tester.pumpWidget(buildTestApp(
          connectionStatus: ConnectionStatus.online,
          displayName: 'SV Musterverein',
        ));

        expect(find.text('SV Musterverein'), findsOneWidget);
        expect(find.text('Club Bar'), findsNothing);
      });

      testWidgets('a long name still ellipsizes rather than overflowing',
          (tester) async {
        await tester.pumpWidget(buildTestApp(
          connectionStatus: ConnectionStatus.online,
          displayName:
              'A Very Long Club Name That Would Never Fit The Header Bar',
        ));

        final text = tester.widget<Text>(find.textContaining('A Very Long'));
        expect(text.overflow, TextOverflow.ellipsis);
        // Renders without a layout overflow error.
        expect(tester.takeException(), isNull);
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

    // Issue #298: this request used to hit no bundled font at all and
    // silently fall back to the platform default.
    testWidgets('the clock requests the bundled monospace family (#298)',
        (tester) async {
      await tester.pumpWidget(buildTestApp(
        connectionStatus: ConnectionStatus.online,
      ));
      await tester.pump();

      final timeRegex = RegExp(r'\d{2}:\d{2}');
      final clock = tester.widget<Text>(find.byWidgetPredicate(
        (widget) => widget is Text && timeRegex.hasMatch(widget.data ?? ''),
      ));

      expect(clock.style?.fontFamily, 'JetBrains Mono');
    });
  });

  /// Issue #760: the clock renders `HH:mm`, so a one-second tick repainted the
  /// header fifty-nine times a minute for a value that had not changed — on
  /// every screen, forever, and on through the blanked display. Sleeping to
  /// the minute boundary makes the wake-up rate match the render rate.
  group('ClubBarHeader clock (#760)', () {
    /// A clock the test moves by hand. [tester.pump] advances the fake timer
    /// queue; this advances what the widget reads when one of those fires, so
    /// the two stay in step without waiting on real time.
    DateTime clock = DateTime(2026, 8, 29, 20, 14, 30);

    Widget buildHeader() => createTestApp(
          child: Scaffold(
            appBar: ClubBarHeader(
              connectionStatus: ConnectionStatus.online,
              now: () => clock,
            ),
          ),
        );

    setUp(() {
      clock = DateTime(2026, 8, 29, 20, 14, 30);
    });

    testWidgets('does not repaint between minute boundaries', (tester) async {
      await tester.pumpWidget(buildHeader());
      expect(find.text('20:14'), findsOneWidget);

      // 29 seconds short of the boundary. A one-second periodic timer would
      // have fired 29 times by here; the aligned timer has not fired at all,
      // so `pump` finds nothing to rebuild.
      clock = clock.add(const Duration(seconds: 29));
      await tester.pump(const Duration(seconds: 29));

      expect(find.text('20:14'), findsOneWidget);
      expect(tester.binding.hasScheduledFrame, isFalse,
          reason: 'no timer should have asked for a frame within the minute');
    });

    testWidgets('repaints on the second the minute turns', (tester) async {
      await tester.pumpWidget(buildHeader());
      expect(find.text('20:14'), findsOneWidget);

      // The widget mounted at :30, so the first fire is 30 s later — not 60.
      clock = DateTime(2026, 8, 29, 20, 15, 0);
      await tester.pump(const Duration(seconds: 30));

      expect(find.text('20:15'), findsOneWidget);
    });

    testWidgets('keeps ticking after the first boundary', (tester) async {
      await tester.pumpWidget(buildHeader());

      clock = DateTime(2026, 8, 29, 20, 15, 0);
      await tester.pump(const Duration(seconds: 30));
      expect(find.text('20:15'), findsOneWidget);

      // Re-armed for a full minute this time, since it is now on the boundary.
      clock = DateTime(2026, 8, 29, 20, 16, 0);
      await tester.pump(const Duration(seconds: 60));
      expect(find.text('20:16'), findsOneWidget);
    });
  });
}
