import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'package:clubbar_terminal/services/display_power.dart';

/// Blanks the terminal's screen after a period with no input, and wakes it on
/// the next touch or key.
///
/// **This replaces `scripts/screen-idle.py` and `scripts/blackscreen.py`**
/// (#763). Those were two Python processes: one reading raw `/dev/input/event*`
/// to spot inactivity, the other drawing a full-screen black GTK window. That
/// arrangement cost more than it looked like:
///
/// * The kiosk user needed `usermod -aG input`, i.e. read access to *every*
///   input device on the machine, purely because the idle timer lived outside
///   the app. Nothing here needs that — the compositor already delivers this
///   app its events.
/// * The overlay was a *second window*, so it could lose z-order. A restart of
///   the terminal app mapped a fresh full-screen window above it while
///   `screen-idle.py` still believed the screen was blanked, and blanking then
///   never happened again (#762). There is no second window here, so there is
///   no such state to get wrong.
/// * It needed GTK3/PyGObject on the Pi.
///
/// ## Waking
///
/// Touches and keys are treated differently, deliberately:
///
/// * **A touch that wakes the screen is swallowed.** Someone reaching for a
///   dark terminal must not have their first tap land on whatever button is
///   underneath. The old overlay got this right by construction, since the tap
///   hit the black window; [_BlankSurface] does the same job here.
/// * **Keys are passed through.** The RFID reader is a keyboard-wedge device,
///   so a card tap arrives as keystrokes. Swallowing the first one would eat a
///   character out of the middle of a UID and turn a scan into a silent
///   failure — which is what the old overlay did, since it consumed a key
///   before quitting. A member should be able to wake this terminal by
///   presenting their card and simply be logged in.
class ScreenBlanker extends StatefulWidget {
  /// Blanking is opt-in, like [ConfigService.fullscreen]: a kiosk sets it, a
  /// development machine does not want its screen going black mid-work.
  final bool enabled;

  final Duration timeout;

  /// Powers the panel down as well as painting it black. Null paints only —
  /// the fallback for hardware whose panel ignores signal loss.
  final DisplayPower? displayPower;

  final Widget child;

  const ScreenBlanker({
    required this.enabled,
    required this.timeout,
    required this.child,
    this.displayPower,
    super.key,
  });

  @override
  State<ScreenBlanker> createState() => _ScreenBlankerState();
}

class _ScreenBlankerState extends State<ScreenBlanker> {
  Timer? _timer;
  bool _blanked = false;

  @override
  void initState() {
    super.initState();
    HardwareKeyboard.instance.addHandler(_onKeyEvent);
    _restartTimer();
  }

  @override
  void didUpdateWidget(ScreenBlanker oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.enabled != widget.enabled ||
        oldWidget.timeout != widget.timeout) {
      if (_blanked) _wake();
      _restartTimer();
    }
  }

  @override
  void dispose() {
    HardwareKeyboard.instance.removeHandler(_onKeyEvent);
    _timer?.cancel();
    // Never leave a terminal with its display switched off because the app
    // went away. The screen is the only thing the bar can see.
    if (_blanked) widget.displayPower?.on();
    super.dispose();
  }

  void _restartTimer() {
    _timer?.cancel();
    if (!widget.enabled) return;
    _timer = Timer(widget.timeout, _blank);
  }

  void _blank() {
    if (!mounted || _blanked) return;
    setState(() => _blanked = true);
    widget.displayPower?.off();
  }

  void _wake() {
    if (!_blanked) return;
    setState(() => _blanked = false);
    widget.displayPower?.on();
  }

  /// Any input at all is activity: it wakes a blanked screen and otherwise
  /// pushes the deadline out.
  void _onActivity() {
    if (_blanked) {
      _wake();
    }
    _restartTimer();
  }

  /// Always returns false — this only *observes*.
  ///
  /// Every [HardwareKeyboard] handler runs whatever the others return, so this
  /// is not what keeps the key reaching [ScanCapture]. What returning false
  /// avoids is marking the event **handled**, which would stop it reaching the
  /// focus system below — text fields included.
  bool _onKeyEvent(KeyEvent event) {
    if (event is KeyDownEvent) _onActivity();
    return false;
  }

  @override
  Widget build(BuildContext context) {
    return Listener(
      // Translucent so the whole surface reports a hit even where nothing is
      // painted, and so this never takes an event away from the app below.
      behavior: HitTestBehavior.translucent,
      onPointerDown: (_) => _onActivity(),
      child: Stack(
        children: [
          widget.child,
          if (_blanked) const Positioned.fill(child: _BlankSurface()),
        ],
      ),
    );
  }
}

/// The black surface itself.
///
/// It is painted even when the panel is powered down, for two reasons: it
/// swallows the tap that wakes the screen, and it means a panel that ignores
/// the power-off still goes dark. Static, so it costs one frame and then
/// nothing — the terminal must not render while nobody is looking (#760).
class _BlankSurface extends StatelessWidget {
  const _BlankSurface();

  @override
  Widget build(BuildContext context) {
    return const AbsorbPointer(
      child: ColoredBox(color: Colors.black),
    );
  }
}
