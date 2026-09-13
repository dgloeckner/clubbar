import 'dart:io';

import 'package:logger/logger.dart';

/// Turns the terminal's display on and off.
///
/// This is the *panel*, not a black window over it. An LCD showing black pixels
/// still has its backlight on, so the overlay this replaces (`blackscreen.py`,
/// removed in #763) saved no power and no heat — it only hid what was on
/// screen. Powering the output down puts the panel into its own standby.
///
/// Implementations must be **fail-soft**. A terminal that cannot power its
/// display down is a terminal with a bright screen, which is a nuisance; a
/// terminal that crashes trying is a terminal that cannot sell. Every failure
/// is logged and swallowed, and [ScreenBlanker] paints its black surface either
/// way, so a failed power-off degrades to exactly the old behaviour.
abstract class DisplayPower {
  /// Power the display on. Safe to call when it is already on.
  Future<void> on();

  /// Power the display off. Safe to call when it is already off.
  Future<void> off();

  /// Whether the panel is currently driven. `null` when this cannot be known
  /// (no readable source); callers then fall back to their own bookkeeping.
  ///
  /// This exists because the app's idea of the panel's state is *not* the
  /// panel's state (#920). Anything that decides to power the display on
  /// should ask the hardware, not its own flag.
  Future<bool?> isOn();
}

/// Powers the display via `wlopm`, which speaks Wayland's
/// `zwlr_output_power_management_v1` to the compositor.
///
/// **Why not DPMS.** `screen-idle.py` used a black window because "many cheap
/// touchscreens do not respond to DPMS power management commands, making xset /
/// vcgencmd unreliable". That was true, and it was about **X11 `xset dpms`**.
/// The terminal runs on Wayland/labwc now, where this protocol takes a
/// different path through the driver stack and asks the compositor for a real
/// atomic modeset. Measured on the terminal Pi (#763): `wlopm --off` moves the
/// DRM connector to `dpms=Off`, `enabled=disabled`, with the CRTC at
/// `active=0` — the Pi stops driving HDMI altogether, and the panel sleeps.
///
/// **Input is not necessarily unaffected.** The touchscreen is a USB device with
/// no relationship to the display pipeline, so it is tempting to conclude that
/// touches still arrive with the output off. On the terminal Pi they do not:
/// with the panel in standby the digitizer stops emitting entirely, while
/// staying enumerated — `lsusb`, `/proc/bus/input/devices` and its `event*` node
/// all still show it, and reading the node during a touch is the only check that
/// reveals it. The controller is bonded to the panel and stops scanning with it.
///
/// So on that hardware this mode means **wake on a card, not on a touch**: the
/// RFID reader is its own USB device and is unaffected. A terminal that must
/// wake on touch needs [ScreenBlanker] without a [DisplayPower], i.e.
/// `"mode": "overlay"` — see INSTALL.md §3.
///
/// ## Reading the state back (#920)
///
/// `wlopm` is a one-shot process: it sets the output's power and exits, leaving
/// the compositor with no client object whose destruction would restore
/// anything. Nothing in the app therefore survives a restart knowing what the
/// panel is doing — which is how the production terminal came back from an OTA
/// update with the app running, the scan processed, and the screen black.
///
/// **The kernel knows, so the kernel is asked.** [isOn] reads the DRM
/// connector's `enabled` attribute out of sysfs, at
/// `/sys/class/drm/card*-<output>/enabled`. The Wayland output name and the DRM
/// connector name are the same string (`HDMI-A-1`), so [output] resolves both
/// and there is no second config key; the `card*` prefix is a device index
/// (`card1` on the Pi) and is globbed, never hardcoded.
///
/// * **`enabled`, not `dpms`.** Both track the panel on this hardware, but
///   `enabled` is what the compositor's modeset actually toggles.
/// * **[isOn] never spawns a process.** It is called on every input event —
///   every keystroke of an RFID burst — and a `wlopm` per character is not
///   acceptable. It is a synchronous file read of microseconds. The one-shot
///   `wlopm` query stays what it always was: something for a human and the
///   runbook.
/// * **[on] verifies.** Measured on the Pi: `wlopm --off; sleep 2; wlopm --on`
///   left the connector `disabled` 3 s later, while a standalone `--on` a few
///   seconds afterwards worked. A request can be lost, so a single read-back
///   cannot be trusted and a single `--on` cannot be assumed. [on] polls until
///   the connector reads `enabled`, retries **once**, then gives up with a
///   warning — never a loop, never a throw.
class WlopmDisplayPower implements DisplayPower {
  /// The Wayland output to switch, e.g. `HDMI-A-1`. Names come from `wlopm`
  /// with no arguments; they are device-specific, so this is configured rather
  /// than guessed. Doubles as the DRM connector name — see the class doc.
  final String output;

  final String executable;

  /// A command that hangs must not hang the blanking timer with it.
  final Duration timeout;

  /// Where the DRM connectors live. A parameter so tests can point it at a
  /// temp tree; there is no reason to configure it on a real terminal.
  final String sysfsRoot;

  /// How often [on] re-reads the connector while waiting for it to come up.
  final Duration pollInterval;

  /// How long [on] waits for the connector to read `enabled` before deciding
  /// the request was lost. Spent at most twice: once, then once after the
  /// single retry.
  final Duration verifyWindow;

  final Logger _logger;

  /// The resolved `.../enabled` file, or null when there is none. [_resolved]
  /// distinguishes "not looked yet" from "looked, found nothing" — the second
  /// must not be retried on every input event.
  File? _enabledFile;
  bool _resolved = false;

  /// Set once per instance, so a terminal with no readable node does not write
  /// the same warning for every keystroke of every scan.
  bool _readFailureLogged = false;

  /// The verification in flight, so overlapping [on] calls join it rather than
  /// each spawning their own `wlopm`.
  Future<void>? _pendingOn;

  WlopmDisplayPower({
    required this.output,
    this.executable = 'wlopm',
    this.timeout = const Duration(seconds: 5),
    this.sysfsRoot = '/sys/class/drm',
    this.pollInterval = const Duration(seconds: 1),
    this.verifyWindow = const Duration(seconds: 10),
    Logger? logger,
  }) : _logger = logger ?? Logger();

  @override
  Future<void> on() {
    final pending = _pendingOn;
    if (pending != null) return pending;

    final started = _turnOnAndVerify();
    _pendingOn = started;
    return started.whenComplete(() {
      if (identical(_pendingOn, started)) _pendingOn = null;
    });
  }

  @override
  Future<void> off() async {
    await _run('--off');
    // Debug only: a panel that ignores signal loss becomes visible in the log
    // without this changing what happens. Never a retry — see the class doc.
    final state = await isOn();
    if (state == true) {
      _logger.d('wlopm --off $output: connector still reads enabled');
    }
  }

  @override
  Future<bool?> isOn() async {
    final file = _resolveEnabledFile();
    if (file == null) return null;
    try {
      final value = file.readAsStringSync().trim();
      if (value == 'enabled') return true;
      if (value == 'disabled') return false;
      _logOnce('${file.path} reads "$value", which is neither '
          'enabled nor disabled');
      return null;
    } catch (e) {
      _logOnce('could not read ${file.path}: $e');
      return null;
    }
  }

  /// `wlopm --on`, then wait for the hardware to agree; once more if it does
  /// not. Returns having done everything it is willing to do — the caller is a
  /// wake path and has nothing to fall back on.
  Future<void> _turnOnAndVerify() async {
    await _run('--on');
    if (await _waitUntilOn()) return;

    _logger.w('$output still reads disabled after wlopm --on; retrying once');
    await _run('--on');
    if (await _waitUntilOn()) return;

    _logger.w('$output still reads disabled after a second wlopm --on; '
        'giving up (the panel may be unplugged or asleep on its own)');
  }

  /// True once the connector reads `enabled`, or as soon as the state cannot
  /// be known at all — an unverifiable panel is left exactly as it was before
  /// #920, rather than made to wait out both windows on every wake.
  Future<bool> _waitUntilOn() async {
    for (var waited = Duration.zero;;) {
      if (await isOn() != false) return true;
      if (waited >= verifyWindow) return false;
      await Future<void>.delayed(pollInterval);
      waited += pollInterval;
    }
  }

  /// Globs `<sysfsRoot>/card*-<output>/enabled`, once. The result — including
  /// its absence — is cached: this is on the per-input-event path.
  File? _resolveEnabledFile() {
    if (_resolved) return _enabledFile;
    _resolved = true;
    try {
      final root = Directory(sysfsRoot);
      if (!root.existsSync()) {
        _logOnce('$sysfsRoot does not exist; display state is unreadable');
        return null;
      }
      final pattern = RegExp(r'^card\d+-' + RegExp.escape(output) + r'$');
      for (final entry in root.listSync()) {
        if (!pattern.hasMatch(entry.uri.pathSegments
            .lastWhere((s) => s.isNotEmpty))) {
          continue;
        }
        final candidate = File('${entry.path}/enabled');
        if (candidate.existsSync()) {
          _enabledFile = candidate;
          return _enabledFile;
        }
      }
      _logOnce('no DRM connector for $output under $sysfsRoot; '
          'display state is unreadable');
    } catch (e) {
      _logOnce('could not scan $sysfsRoot for $output: $e');
    }
    return _enabledFile;
  }

  void _logOnce(String message) {
    if (_readFailureLogged) return;
    _readFailureLogged = true;
    _logger.w(message);
  }

  Future<void> _run(String flag) async {
    try {
      final result = await Process.run(executable, [flag, output])
          .timeout(timeout);
      if (result.exitCode != 0) {
        _logger.w(
          'wlopm $flag $output failed (exit ${result.exitCode}): '
          '${result.stderr}',
        );
      }
    } catch (e) {
      // Missing binary, no compositor, timeout — all the same to the caller.
      // The black surface is still painted, so the screen still goes dark.
      _logger.w('wlopm $flag $output could not run: $e');
    }
  }
}
