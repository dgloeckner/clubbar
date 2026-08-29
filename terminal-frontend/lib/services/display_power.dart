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
/// Input is unaffected: the touchscreen is a USB device with no relationship to
/// the display pipeline, so touches still arrive with the output off. That is
/// what makes wake-on-touch work, and it was verified on hardware before this
/// was written.
class WlopmDisplayPower implements DisplayPower {
  /// The Wayland output to switch, e.g. `HDMI-A-1`. Names come from `wlopm`
  /// with no arguments; they are device-specific, so this is configured rather
  /// than guessed.
  final String output;

  final String executable;

  /// A command that hangs must not hang the blanking timer with it.
  final Duration timeout;

  final Logger _logger;

  WlopmDisplayPower({
    required this.output,
    this.executable = 'wlopm',
    this.timeout = const Duration(seconds: 5),
    Logger? logger,
  }) : _logger = logger ?? Logger();

  @override
  Future<void> on() => _run('--on');

  @override
  Future<void> off() => _run('--off');

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
