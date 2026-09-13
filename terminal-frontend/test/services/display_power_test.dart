import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:logger/logger.dart';

import 'package:clubbar_terminal/services/display_power.dart';

/// A stand-in for `wlopm` that records every invocation.
///
/// The real binary talks to a compositor, so the tests drive a shell script
/// instead: it appends its arguments to a log file, which is what lets a test
/// assert *how many times* the process was spawned — the property §1 of #920
/// cares about (one `--on` retry, and one process for overlapping calls).
class FakeWlopm {
  final Directory dir;
  late final String path;

  FakeWlopm(this.dir) {
    path = '${dir.path}/wlopm';
    File(path).writeAsStringSync('#!/bin/sh\necho "\$@" >> "$path.log"\n');
    Process.runSync('chmod', ['+x', path]);
  }

  List<String> get calls {
    final log = File('$path.log');
    if (!log.existsSync()) return [];
    return log
        .readAsLinesSync()
        .where((l) => l.trim().isNotEmpty)
        .toList(growable: false);
  }
}

void main() {
  const output = 'HDMI-A-1';

  late Directory temp;
  late Directory sysfs;
  late FakeWlopm wlopm;

  /// Creates `<sysfs>/<card>-<output>/enabled` with [state].
  void writeNode(String state, {String card = 'card1'}) {
    final node = Directory('${sysfs.path}/$card-$output')
      ..createSync(recursive: true);
    File('${node.path}/enabled').writeAsStringSync('$state\n');
  }

  void setNode(String state, {String card = 'card1'}) =>
      File('${sysfs.path}/$card-$output/enabled').writeAsStringSync('$state\n');

  WlopmDisplayPower subject() => WlopmDisplayPower(
        output: output,
        executable: wlopm.path,
        sysfsRoot: sysfs.path,
        // The real windows are 10 s; the tests must not sleep 20 s to watch
        // two of them elapse.
        pollInterval: const Duration(milliseconds: 5),
        verifyWindow: const Duration(milliseconds: 25),
        logger: Logger(level: Level.off),
      );

  setUp(() {
    // Created here, removed in tearDown: nothing outside this tree is touched,
    // and the path is assigned before anything can skip.
    temp = Directory.systemTemp.createTempSync('display_power_test');
    sysfs = Directory('${temp.path}/drm')..createSync(recursive: true);
    wlopm = FakeWlopm(temp);
  });

  tearDown(() {
    if (temp.existsSync()) temp.deleteSync(recursive: true);
  });

  group('WlopmDisplayPower.isOn', () {
    test('reads the DRM connector node found by glob', () async {
      writeNode('enabled');

      expect(await subject().isOn(), isTrue);
    });

    test('reports a disabled connector as off', () async {
      writeNode('disabled');

      expect(await subject().isOn(), isFalse);
    });

    test('finds the node whatever the card index is', () async {
      // `card*` is a device index — card1 on the terminal Pi, card0 elsewhere.
      // Hardcoding it would make this unreadable on the next board.
      writeNode('enabled', card: 'card0');

      expect(await subject().isOn(), isTrue);
    });

    test('returns null when no node matches the output', () async {
      writeNode('enabled', card: 'card1');
      final power = WlopmDisplayPower(
        output: 'DP-2',
        executable: wlopm.path,
        sysfsRoot: sysfs.path,
        logger: Logger(level: Level.off),
      );

      expect(await power.isOn(), isNull);
    });

    test('returns null when the sysfs root does not exist', () async {
      final power = WlopmDisplayPower(
        output: output,
        executable: wlopm.path,
        sysfsRoot: '${temp.path}/no-such-root',
        logger: Logger(level: Level.off),
      );

      expect(await power.isOn(), isNull);
    });

    test('never spawns a process — it is called per input event', () async {
      writeNode('enabled');
      final power = subject();

      for (var i = 0; i < 5; i++) {
        await power.isOn();
      }

      expect(wlopm.calls, isEmpty);
    });

    test('caches the resolved node', () async {
      writeNode('enabled');
      final power = subject();
      expect(await power.isOn(), isTrue);

      // The resolution is cached, not the reading: a node that appears under a
      // second card later must not change where this reads from, but a changed
      // value must be seen.
      writeNode('enabled', card: 'card9');
      setNode('disabled');

      expect(await power.isOn(), isFalse);
    });

    test('caches the absence of a node', () async {
      final power = subject();
      expect(await power.isOn(), isNull);

      writeNode('enabled');

      expect(await power.isOn(), isNull,
          reason: 'the negative resolution is cached too');
    });
  });

  group('WlopmDisplayPower.on', () {
    test('returns as soon as the connector reads enabled', () async {
      writeNode('enabled');

      await subject().on();

      expect(wlopm.calls, ['--on $output']);
    });

    test('returns without verifying when there is no node to read', () async {
      // Unverifiable is not the same as off: a terminal with no readable node
      // must behave exactly as it did before #920.
      await subject().on();

      expect(wlopm.calls, ['--on $output']);
    });

    test('retries wlopm --on exactly once when the panel stays disabled',
        () async {
      // Measured on the Pi: an `--on` issued shortly after an `--off` can be
      // lost. One retry, then give up — never a loop.
      writeNode('disabled');

      await subject().on();

      expect(wlopm.calls, ['--on $output', '--on $output']);
    });

    test('gives up after the second window instead of looping', () async {
      writeNode('disabled');
      final power = subject();

      await power.on().timeout(const Duration(seconds: 5));

      expect(wlopm.calls.length, 2);
      expect(await power.isOn(), isFalse);
    });

    test('stops retrying once a later poll sees the panel come up', () async {
      writeNode('disabled');
      final power = subject();

      // The panel comes up during the first window, as a working --on does.
      Future.delayed(const Duration(milliseconds: 10), () => setNode('enabled'));
      await power.on();

      expect(wlopm.calls, ['--on $output']);
    });

    test('overlapping calls spawn one process', () async {
      writeNode('disabled');
      final power = subject();

      await Future.wait([power.on(), power.on(), power.on()]);

      expect(wlopm.calls.length, 2,
          reason: 'one verified on(), which retries once — not three of them');
    });

    test('a later call runs again once the first has settled', () async {
      writeNode('enabled');
      final power = subject();

      await power.on();
      await power.on();

      expect(wlopm.calls.length, 2);
    });
  });

  group('WlopmDisplayPower.off', () {
    test('issues a single wlopm --off and does not verify', () async {
      // A panel that ignores signal loss must not turn this into a retry loop.
      writeNode('enabled');

      await subject().off();

      expect(wlopm.calls, ['--off $output']);
    });

    test('survives a missing binary', () async {
      final power = WlopmDisplayPower(
        output: output,
        executable: '${temp.path}/not-installed',
        sysfsRoot: sysfs.path,
        logger: Logger(level: Level.off),
      );

      await expectLater(power.off(), completes);
    });
  });
}
