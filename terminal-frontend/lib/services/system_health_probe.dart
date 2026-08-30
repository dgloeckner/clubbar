import 'dart:io';

/// How hot the SoC is, in the terms staff need rather than in degrees.
///
/// The thresholds are the Pi's own, not a judgement call: the SoC begins
/// throttling at its **80 °C soft limit**, and a terminal idling in a room sits
/// at roughly 59 °C, so anything under 60 °C is unremarkable. A bar in summer
/// has less headroom than a desk, which is what [warm] exists to say — it is
/// not a fault, it is the last chance to open a window before the till slows
/// down at exactly the hour it is busiest (#760).
enum ThermalState {
  /// Below [SystemHealth.warmThresholdCelsius] — nothing to report.
  normal,

  /// Between the warm threshold and the soft limit. Working, with less
  /// headroom than it looks.
  warm,

  /// At or above [SystemHealth.throttleThresholdCelsius]: the SoC is dropping
  /// clock speed to save itself, and the terminal is slower than it should be.
  throttling,
}

/// One reading of the terminal machine's thermal and power state.
///
/// Every field is nullable and `null` means **"this machine cannot be asked"**,
/// never "fine". A developer laptop has neither sysfs path, and the UI must
/// leave the section out entirely rather than report a temperature it invented
/// or an error nobody can act on — the same way the dispenser tab is
/// conditional today.
class SystemHealth {
  /// The temperature at which the terminal stops being unremarkable.
  static const double warmThresholdCelsius = 60.0;

  /// The Pi's soft limit — the SoC throttles itself at and above this.
  static const double throttleThresholdCelsius = 80.0;

  /// SoC temperature in °C, or null when the machine does not expose one.
  final double? temperatureCelsius;

  /// True while the power supply is sagging below the SoC's low-critical
  /// threshold, false while it is not, null when the machine cannot say.
  ///
  /// This is the reading worth waking someone for. Undervoltage is the leading
  /// cause of SD-card corruption, it announces itself nowhere else, and it
  /// keeps corrupting until the power supply is replaced — by which time the
  /// terminal may not boot, and a terminal that cannot boot cannot sell.
  final bool? undervoltage;

  const SystemHealth({this.temperatureCelsius, this.undervoltage});

  /// Nothing could be read at all — the section has no reason to exist.
  static const SystemHealth unavailable = SystemHealth();

  /// Whether there is anything worth putting on screen. A machine that answers
  /// one of the two questions still gets a section, showing only that one.
  bool get isAvailable => temperatureCelsius != null || undervoltage != null;

  /// Where [temperatureCelsius] sits against the Pi's own limits, or null when
  /// there is no temperature to classify.
  ThermalState? get thermalState {
    final celsius = temperatureCelsius;
    if (celsius == null) return null;
    if (celsius >= throttleThresholdCelsius) return ThermalState.throttling;
    if (celsius >= warmThresholdCelsius) return ThermalState.warm;
    return ThermalState.normal;
  }

  /// True only for a condition staff must act on now: the SoC is throttling,
  /// or the power supply is sagging. [ThermalState.warm] is deliberately not
  /// one — a warning nobody needs to act on is a warning they learn to ignore.
  bool get needsAttention =>
      undervoltage == true || thermalState == ThermalState.throttling;
}

/// Answers what the machine can say about its own temperature and power.
abstract class SystemHealthProbe {
  /// Never throws and never reports a failure as a fault: an unreadable path
  /// yields null for that field, so the UI stays silent rather than blaming
  /// the hardware for a missing file.
  Future<SystemHealth> read();
}

/// Reads both values out of sysfs, which is world-readable and needs no
/// privileges, no daemon and no packages.
///
/// **Why not `vcgencmd`.** The obvious answer — `vcgencmd measure_temp` and
/// `vcgencmd get_throttled` — needs `/dev/vcio`, which is only group-readable
/// when a udev rule matches it, and that broke on the terminal Pi:
/// `raspberrypi-sys-mods` 1:20260612 ships rules naming only the newer
/// `vcio_gencmd`/`vcio_crypto` nodes while kernel 6.12.47 still creates plain
/// `/dev/vcio`, leaving it `0600 root:root`. A reboot does not fix it. Sysfs
/// carries the same two numbers with none of that.
class SysfsSystemHealthProbe implements SystemHealthProbe {
  /// milli-°C in a single line, e.g. `58913` → 58.9 °C. `type` reads
  /// `cpu-thermal`, and the file is mode `-r--r--r--`.
  static const String defaultThermalPath =
      '/sys/class/thermal/thermal_zone0/temp';

  /// The hwmon class directory. The undervoltage flag lives in whichever child
  /// has `name` = `rpi_volt`; **the numbering is not stable across boots**, so
  /// the directory is searched rather than hardcoded to `hwmon1`.
  static const String defaultHwmonRoot = '/sys/class/hwmon';

  /// The device whose `in0_lcrit_alarm` is the undervoltage bit.
  static const String rpiVoltageSensorName = 'rpi_volt';

  final String thermalPath;
  final String hwmonRoot;

  const SysfsSystemHealthProbe({
    this.thermalPath = defaultThermalPath,
    this.hwmonRoot = defaultHwmonRoot,
  });

  @override
  Future<SystemHealth> read() async {
    return SystemHealth(
      temperatureCelsius: await _readTemperature(),
      undervoltage: await _readUndervoltage(),
    );
  }

  Future<double?> _readTemperature() async {
    final raw = await _readTrimmed(File(thermalPath));
    if (raw == null) return null;
    final milliCelsius = int.tryParse(raw);
    // A thermal zone that reports something other than an integer is a zone we
    // do not understand; inventing a temperature from it would be worse than
    // showing none.
    if (milliCelsius == null) return null;
    return milliCelsius / 1000.0;
  }

  Future<bool?> _readUndervoltage() async {
    final alarmFile = await _findVoltageAlarmFile();
    if (alarmFile == null) return null;
    final raw = await _readTrimmed(alarmFile);
    if (raw == null) return null;
    // `1` is the alarm. Anything else — `0`, or a value from a driver that
    // does not mean what we think — is not reported as undervoltage.
    if (raw == '1') return true;
    if (raw == '0') return false;
    return null;
  }

  /// The `in0_lcrit_alarm` of the `rpi_volt` hwmon device, or null when this
  /// machine has no such device (every non-Pi, and every non-Linux, host).
  Future<File?> _findVoltageAlarmFile() async {
    final root = Directory(hwmonRoot);
    try {
      if (!await root.exists()) return null;
      await for (final entry in root.list(followLinks: true)) {
        final name = await _readTrimmed(File('${entry.path}/name'));
        if (name != rpiVoltageSensorName) continue;
        final alarm = File('${entry.path}/in0_lcrit_alarm');
        if (await alarm.exists()) return alarm;
      }
    } on FileSystemException {
      // An unreadable hwmon tree says nothing about the power supply.
      return null;
    }
    return null;
  }

  Future<String?> _readTrimmed(File file) async {
    try {
      if (!await file.exists()) return null;
      return (await file.readAsString()).trim();
    } on FileSystemException {
      return null;
    }
  }
}
