import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

import 'package:clubbar_terminal/services/updater_handshake.dart';

/// The two files ADR-0054 has the app and the Pi's updater exchange.
///
/// Every property asserted here is one the updater *acts* on: it refuses to
/// update while unsynced sales exist, it rolls a new version back when the
/// heartbeat does not move, and it never retries a tag it blacklisted. A file
/// that lies in either direction costs either a night's update (harmless) or a
/// rolled-back terminal that was fine (not).
void main() {
  late Directory dir;

  setUp(() async {
    dir = await Directory.systemTemp.createTemp('clubbar-handshake-');
  });

  tearDown(() async {
    if (await dir.exists()) await dir.delete(recursive: true);
  });

  group('TerminalStatusFile', () {
    test('writes the heartbeat and the unsynced count as JSON', () async {
      final status = TerminalStatusFile.inDirectory(dir.path);

      await status.write(
        appVersion: 'v1.0.7',
        lastSyncAt: DateTime.utc(2026, 9, 6, 4, 3, 11),
        unsyncedTransactions: 0,
      );

      final decoded = jsonDecode(await status.file.readAsString()) as Map<String, dynamic>;
      expect(decoded['app_version'], 'v1.0.7');
      expect(decoded['last_sync_at'], '2026-09-06T04:03:11.000Z');
      expect(decoded['unsynced_transactions'], 0);
      expect(decoded['written_at'], isA<String>());
    });

    test('stamps the heartbeat in UTC whatever the terminal thinks local is', () async {
      // The updater compares this against its own clock. A local-time stamp
      // would read as up to a day in the future or the past depending on the
      // Pi's zone, and the future direction would pass a watchdog check that
      // should have failed.
      final status = TerminalStatusFile.inDirectory(dir.path);

      await status.write(
        appVersion: 'v1.0.7',
        lastSyncAt: DateTime.utc(2026, 9, 6, 4, 3, 11).toLocal(),
        unsyncedTransactions: 0,
      );

      final decoded = jsonDecode(await status.file.readAsString()) as Map<String, dynamic>;
      expect(decoded['last_sync_at'], '2026-09-06T04:03:11.000Z');
    });

    test('reports a terminal that has never synced as having no heartbeat', () async {
      final status = TerminalStatusFile.inDirectory(dir.path);

      await status.write(appVersion: 'v1.0.7', lastSyncAt: null, unsyncedTransactions: 3);

      final decoded = jsonDecode(await status.file.readAsString()) as Map<String, dynamic>;
      // Null, not "now": a fresh install that has never reached its backend
      // must not look healthy to a watchdog.
      expect(decoded['last_sync_at'], isNull);
      expect(decoded['unsynced_transactions'], 3);
    });

    test('replaces the previous status rather than appending to it', () async {
      final status = TerminalStatusFile.inDirectory(dir.path);

      await status.write(appVersion: 'v1.0.7', lastSyncAt: null, unsyncedTransactions: 5);
      await status.write(
        appVersion: 'v1.0.7',
        lastSyncAt: DateTime.utc(2026, 9, 6, 4, 3, 11),
        unsyncedTransactions: 0,
      );

      final decoded = jsonDecode(await status.file.readAsString()) as Map<String, dynamic>;
      expect(decoded['unsynced_transactions'], 0);
    });

    test('leaves no temporary file behind for the updater to trip over', () async {
      final status = TerminalStatusFile.inDirectory(dir.path);

      await status.write(appVersion: 'v1.0.7', lastSyncAt: null, unsyncedTransactions: 0);

      final names = dir.listSync().map((e) => e.path.split('/').last).toList();
      expect(names, [TerminalStatusFile.fileName]);
    });

    test('never throws when the status file cannot be written', () async {
      // A disk that is full or a data directory gone read-only must not cost a
      // sale. The updater then sees a stale heartbeat and declines to update,
      // which is the safe direction.
      final status = TerminalStatusFile(File('${dir.path}/status.json/nested'));

      await expectLater(
        status.write(appVersion: 'v1.0.7', lastSyncAt: null, unsyncedTransactions: 0),
        completes,
      );
    });
  });

  group('UpdaterState', () {
    test('reads the tag whose update failed here', () async {
      final file = File('${dir.path}/${UpdaterState.fileName}');
      await file.writeAsString(jsonEncode({'blocked_version': 'v1.0.8'}));

      final state = await UpdaterState.readFromDirectory(dir.path);

      expect(state.blockedVersion, 'v1.0.8');
    });

    test('reports nothing blocked when the file is absent', () async {
      // The ordinary case: most terminals never fail an update.
      final state = await UpdaterState.readFromDirectory(dir.path);

      expect(state.blockedVersion, isNull);
    });

    test('reports nothing blocked rather than failing on a malformed file', () async {
      // Half a JSON document — the app was started while the updater was
      // writing, or the Pi lost power mid-write. Neither is worth refusing to
      // start a till for; the cost is one missing header.
      final file = File('${dir.path}/${UpdaterState.fileName}');
      await file.writeAsString('{"blocked_version": "v1.0');

      final state = await UpdaterState.readFromDirectory(dir.path);

      expect(state.blockedVersion, isNull);
    });

    test('ignores a blocked version that is not a string', () async {
      final file = File('${dir.path}/${UpdaterState.fileName}');
      await file.writeAsString(jsonEncode({'blocked_version': 42}));

      final state = await UpdaterState.readFromDirectory(dir.path);

      expect(state.blockedVersion, isNull);
    });

    test('treats an empty blocked version as nothing blocked', () async {
      // How the updater clears a block: it writes the file back with an empty
      // value rather than deleting it, so an operator can see the block was
      // cleared deliberately.
      final file = File('${dir.path}/${UpdaterState.fileName}');
      await file.writeAsString(jsonEncode({'blocked_version': ''}));

      final state = await UpdaterState.readFromDirectory(dir.path);

      expect(state.blockedVersion, isNull);
    });
  });
}
