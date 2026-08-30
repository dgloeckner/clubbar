import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/services/system_health_probe.dart';

void main() {
  group('SystemHealth', () {
    test('classifies a temperature against the Pi\'s own limits', () {
      // 59 °C is where the terminal idles in a room — unremarkable.
      expect(
        const SystemHealth(temperatureCelsius: 58.9).thermalState,
        ThermalState.normal,
      );
      // The boundaries themselves belong to the state they open, not the one
      // they close.
      expect(
        const SystemHealth(temperatureCelsius: 60.0).thermalState,
        ThermalState.warm,
      );
      expect(
        const SystemHealth(temperatureCelsius: 79.9).thermalState,
        ThermalState.warm,
      );
      expect(
        const SystemHealth(temperatureCelsius: 80.0).thermalState,
        ThermalState.throttling,
      );
      expect(
        const SystemHealth(temperatureCelsius: 84.2).thermalState,
        ThermalState.throttling,
      );
    });

    test('has no thermal state without a temperature', () {
      expect(const SystemHealth(undervoltage: false).thermalState, isNull);
    });

    test('is unavailable only when neither value could be read', () {
      expect(SystemHealth.unavailable.isAvailable, isFalse);
      expect(const SystemHealth(temperatureCelsius: 40).isAvailable, isTrue);
      expect(const SystemHealth(undervoltage: false).isAvailable, isTrue);
    });

    test('asks for attention on throttling and undervoltage, not on warm', () {
      expect(const SystemHealth(temperatureCelsius: 55).needsAttention, isFalse);
      // Warm is information, not an alarm: a warning nobody has to act on is
      // a warning staff learn to ignore.
      expect(const SystemHealth(temperatureCelsius: 70).needsAttention, isFalse);
      expect(const SystemHealth(temperatureCelsius: 82).needsAttention, isTrue);
      expect(const SystemHealth(undervoltage: true).needsAttention, isTrue);
      expect(const SystemHealth(undervoltage: false).needsAttention, isFalse);
    });
  });

  group('SysfsSystemHealthProbe', () {
    late Directory root;

    setUp(() {
      // Created before anything can skip or throw, and removed by path rather
      // than by glob — a cleanup that can point at a path the test did not
      // create is the failure mode this project has already been bitten by.
      root = Directory.systemTemp.createTempSync('clubbar_sysfs_');
    });

    tearDown(() {
      if (root.existsSync()) root.deleteSync(recursive: true);
    });

    String writeThermal(String contents) {
      final file = File('${root.path}/thermal_zone0_temp');
      file.writeAsStringSync(contents);
      return file.path;
    }

    /// Builds a `/sys/class/hwmon` in the kernel's own shape: several devices,
    /// each a directory carrying a `name`, and only one of them `rpi_volt`.
    String writeHwmon({
      required Map<String, String> namesByDir,
      String? alarmDir,
      String alarm = '0',
    }) {
      final hwmon = Directory('${root.path}/hwmon')..createSync();
      namesByDir.forEach((dir, name) {
        final entry = Directory('${hwmon.path}/$dir')..createSync();
        File('${entry.path}/name').writeAsStringSync('$name\n');
      });
      if (alarmDir != null) {
        File('${hwmon.path}/$alarmDir/in0_lcrit_alarm')
            .writeAsStringSync('$alarm\n');
      }
      return hwmon.path;
    }

    test('reads milli-°C as degrees', () async {
      final probe = SysfsSystemHealthProbe(
        thermalPath: writeThermal('58913\n'),
        hwmonRoot: '${root.path}/absent',
      );

      final health = await probe.read();

      expect(health.temperatureCelsius, closeTo(58.913, 0.0001));
      expect(health.thermalState, ThermalState.normal);
    });

    test('finds the undervoltage flag by name, not by hwmon number', () async {
      // The rpi_volt device is hwmon3 here and hwmon1 is something else
      // entirely — hardcoding a number would read the wrong sensor, and the
      // numbering is not stable across boots.
      final hwmonRoot = writeHwmon(
        namesByDir: {
          'hwmon0': 'cpu_thermal',
          'hwmon1': 'acpitz',
          'hwmon3': 'rpi_volt',
        },
        alarmDir: 'hwmon3',
        alarm: '1',
      );

      final probe = SysfsSystemHealthProbe(
        thermalPath: writeThermal('81200\n'),
        hwmonRoot: hwmonRoot,
      );

      final health = await probe.read();

      expect(health.undervoltage, isTrue);
      expect(health.thermalState, ThermalState.throttling);
      expect(health.needsAttention, isTrue);
    });

    test('a cleared alarm is false, not missing', () async {
      final probe = SysfsSystemHealthProbe(
        thermalPath: writeThermal('45000'),
        hwmonRoot: writeHwmon(
          namesByDir: {'hwmon1': 'rpi_volt'},
          alarmDir: 'hwmon1',
        ),
      );

      expect((await probe.read()).undervoltage, isFalse);
    });

    test('a machine with neither path reports nothing rather than failing',
        () async {
      // This is every developer laptop: the section must be absent, not an
      // error and not an invented temperature.
      const probe = SysfsSystemHealthProbe(
        thermalPath: '/nonexistent/thermal_zone0/temp',
        hwmonRoot: '/nonexistent/hwmon',
      );

      final health = await probe.read();

      expect(health.temperatureCelsius, isNull);
      expect(health.undervoltage, isNull);
      expect(health.isAvailable, isFalse);
    });

    test('hwmon without an rpi_volt device says nothing about voltage',
        () async {
      final probe = SysfsSystemHealthProbe(
        thermalPath: writeThermal('50000'),
        hwmonRoot: writeHwmon(
          namesByDir: {'hwmon0': 'cpu_thermal', 'hwmon1': 'nvme'},
        ),
      );

      expect((await probe.read()).undervoltage, isNull);
    });

    test('unparseable readings are reported as unknown, never as a value',
        () async {
      final probe = SysfsSystemHealthProbe(
        thermalPath: writeThermal('not-a-number'),
        hwmonRoot: writeHwmon(
          namesByDir: {'hwmon0': 'rpi_volt'},
          alarmDir: 'hwmon0',
          alarm: 'yes',
        ),
      );

      final health = await probe.read();

      expect(health.temperatureCelsius, isNull);
      expect(health.undervoltage, isNull);
    });
  });
}
