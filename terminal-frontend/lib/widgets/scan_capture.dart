import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:clubbar_terminal/l10n/app_localizations.dart';
import 'package:clubbar_terminal/l10n/scan_hint_messages.dart';
import 'package:clubbar_terminal/l10n/terminal_error_messages.dart';
import 'package:clubbar_terminal/models/scan_hint.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/services/scan_log.dart';
import 'package:clubbar_terminal/utils/card_uid.dart';
import 'package:clubbar_terminal/utils/design_tokens.dart';

/// App-shell wrapper that captures RFID scans on **every** route and shows what
/// the terminal made of them.
///
/// Card taps used to be tied to the idle screen's lifecycle, so a tap anywhere
/// else did literally nothing (issue #26). Capture belongs to the shell: the
/// reader is a property of the terminal, not of a screen. What a scan *means*
/// per route is decided in [RfidProvider.handleCardScan] (ADR-0027
/// amendment 2) — this widget only feeds it events and tells it where the
/// member is standing.
class ScanCapture extends StatefulWidget {
  /// The current route, e.g. `/products` or `/confirmation/<id>`.
  final String location;

  final Widget child;

  const ScanCapture({
    required this.location,
    required this.child,
    super.key,
  });

  @override
  State<ScanCapture> createState() => _ScanCaptureState();
}

class _ScanCaptureState extends State<ScanCapture> {
  /// Longest gap tolerated between two characters of one scan.
  ///
  /// A reader types a whole UID as one burst of a few tens of milliseconds.
  /// Capturing app-wide (rather than on the idle screen only) means stray host
  /// keystrokes now reach this buffer on every screen, so anything arriving
  /// slower than a reader is dropped instead of being glued onto the next scan.
  static const _maxInterCharacterGap = Duration(milliseconds: 500);

  /// Keys that end a UID.
  ///
  /// A keyboard-wedge reader is configured to "send Enter" — and on a good
  /// number of them that is the *keypad's* Enter (USB HID usage 0x70058), a
  /// different key from Return (0x70028) with its own logical key. Listening for
  /// Return alone left such a reader unable to finish a scan at all: the
  /// characters sat in the buffer until [_maxInterCharacterGap] threw them
  /// away, which is silent (issue #370).
  static final _terminatorKeys = <LogicalKeyboardKey>{
    LogicalKeyboardKey.enter,
    LogicalKeyboardKey.numpadEnter,
  };

  /// Keys that are never part of a UID and are not worth reporting as lost
  /// input — a modifier's own press carries no character by definition.
  static final _modifierKeys = <LogicalKeyboardKey>{
    LogicalKeyboardKey.shift,
    LogicalKeyboardKey.shiftLeft,
    LogicalKeyboardKey.shiftRight,
    LogicalKeyboardKey.control,
    LogicalKeyboardKey.controlLeft,
    LogicalKeyboardKey.controlRight,
    LogicalKeyboardKey.alt,
    LogicalKeyboardKey.altLeft,
    LogicalKeyboardKey.altRight,
    LogicalKeyboardKey.meta,
    LogicalKeyboardKey.metaLeft,
    LogicalKeyboardKey.metaRight,
    LogicalKeyboardKey.capsLock,
    LogicalKeyboardKey.numLock,
    LogicalKeyboardKey.scrollLock,
    LogicalKeyboardKey.fn,
  };

  final StringBuffer _rfidBuffer = StringBuffer();
  Timer? _bufferResetTimer;
  RfidProvider? _rfidProvider;

  /// When the buffer's first character arrived, for the burst duration in the
  /// scan log: a reader types a UID in a few tens of milliseconds, so a burst
  /// measured in seconds was a human at a keyboard, not a card.
  DateTime? _burstStartedAt;

  @override
  void initState() {
    super.initState();
    // Capture all keyboard input globally — works on Linux/Wayland and macOS.
    HardwareKeyboard.instance.addHandler(_onKeyEvent);

    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      final rfidProvider = context.read<RfidProvider>();
      _rfidProvider = rfidProvider;
      // The shell is the only place that knows the route, and the provider
      // needs it to apply the per-route scan policy.
      rfidProvider.locationResolver = () => widget.location;
      rfidProvider.startListening(context);
    });
  }

  @override
  void dispose() {
    _bufferResetTimer?.cancel();
    HardwareKeyboard.instance.removeHandler(_onKeyEvent);
    // Null when the shell is torn down before its first post-frame callback.
    _rfidProvider?.stopListening();
    super.dispose();
  }

  /// Accumulate characters from the RFID reader (USB keyboard emulation).
  /// Emits the buffered UID when a terminator is received.
  ///
  /// Every branch that ends a keystroke's life here records why in [ScanLog]:
  /// this stage produces no sound, no banner and no spinner, so before the
  /// scan log a tap lost in it was indistinguishable from a tap that never
  /// happened — which is the whole of issue #370's symptom.
  bool _onKeyEvent(KeyEvent event) {
    if (event is! KeyDownEvent) return false;

    // A modifier's own press is not input and not a loss; saying so would bury
    // the buffer under Shift presses on a terminal with a keyboard attached.
    if (_modifierKeys.contains(event.logicalKey)) return false;

    // A held modifier means a shortcut, never reader output. Recorded because
    // a modifier the compositor thinks is still held silences the reader
    // completely, and looks from the bar exactly like a dead scanner.
    final keyboard = HardwareKeyboard.instance;
    if (keyboard.isControlPressed ||
        keyboard.isMetaPressed ||
        keyboard.isAltPressed) {
      ScanLog.instance.record(ScanEventKind.modifierSuppressed,
          detail: _keyLabel(event));
      return false;
    }

    if (_isTerminator(event)) {
      _bufferResetTimer?.cancel();
      // A reader that types lower-case hex must reach the same member as one
      // that types upper-case (issue #18) — see [normalizeCardUid].
      final uid = normalizeCardUid(_rfidBuffer.toString());
      final startedAt = _burstStartedAt;
      _rfidBuffer.clear();
      _burstStartedAt = null;

      if (uid.isEmpty) {
        ScanLog.instance.record(ScanEventKind.emptyTerminator,
            detail: _keyLabel(event));
      } else if (_rfidProvider == null) {
        // The shell subscribes in its first post-frame callback; a card held
        // to the reader while the app is still starting lands here.
        ScanLog.instance.record(ScanEventKind.captureNotReady, uid: uid);
      } else {
        ScanLog.instance.record(
          ScanEventKind.uidCaptured,
          uid: uid,
          detail: '${uid.length} chars in ${_burstDuration(startedAt)}',
        );
        _rfidProvider!.emitScan(uid);
      }
    } else if (event.character != null && event.character!.isNotEmpty) {
      if (_rfidBuffer.isEmpty) _burstStartedAt = DateTime.now();
      _rfidBuffer.write(event.character);
      // Anything slower than a reader is dropped, so a stale partial UID is
      // neither emitted on a later Enter nor glued onto the next scan.
      _bufferResetTimer?.cancel();
      _bufferResetTimer = Timer(_maxInterCharacterGap, _discardPartialScan);
    } else {
      // A key that carries no character can never become part of a UID. A
      // reader typing on the numeric keypad with NumLock off arrives here for
      // every digit and produces nothing at all — the USB HID usage in the
      // detail is what identifies that case from the field.
      ScanLog.instance.record(ScanEventKind.unprintableKey,
          detail: _keyLabel(event));
    }
    return false; // don't consume — let other widgets handle events normally
  }

  /// Whether [event] ends a UID. Both Enter keys count, and so does a platform
  /// that delivers the key only as a newline character.
  bool _isTerminator(KeyEvent event) =>
      _terminatorKeys.contains(event.logicalKey) ||
      event.character == '\n' ||
      event.character == '\r';

  /// Throw away characters that arrived too slowly to be one scan.
  void _discardPartialScan() {
    if (_rfidBuffer.isNotEmpty) {
      ScanLog.instance.record(
        ScanEventKind.partialDiscarded,
        detail: '${_rfidBuffer.length} chars, no terminator',
      );
    }
    _rfidBuffer.clear();
    _burstStartedAt = null;
  }

  String _burstDuration(DateTime? startedAt) => startedAt == null
      ? 'unknown'
      : '${DateTime.now().difference(startedAt).inMilliseconds}ms';

  /// Identify a key in a way that survives a release build.
  ///
  /// [KeyboardKey.debugName] is stripped outside debug mode, so the ids go in
  /// too: the HID usage is what tells "the reader is on the keypad" (0x58 is
  /// keypad Enter, 0x5c keypad 4) from "the reader is on the main block".
  String _keyLabel(KeyEvent event) {
    final name = event.logicalKey.debugName;
    final hid =
        '0x${event.physicalKey.usbHidUsage.toRadixString(16).padLeft(2, '0')}';
    return name == null ? 'hid $hid' : '$name (hid $hid)';
  }

  @override
  Widget build(BuildContext context) {
    // The idle screen renders scan errors inline on the RFID button; showing
    // them twice there would just be noise.
    final showFeedback = widget.location != '/idle';

    return Stack(
      children: [
        widget.child,
        if (showFeedback) const _ScanFeedbackBanner(),
      ],
    );
  }
}

/// Zero-size listener that renders scan feedback into the **root** overlay.
///
/// Placing the banner in the shell's own [Stack] would hide it behind exactly
/// the screens where it matters most: `showDialog` defaults to the root
/// navigator, so the dispensing progress dialog and its modal barrier cover the
/// whole shell — and rule 7's "please wait" hint is refused *during* a dispense.
/// The entry is re-inserted on every occurrence so it lands on top of whatever
/// route is currently showing.
///
/// What it shows is the outcome of a scan taken outside the idle screen — a
/// refusal hint (ADR-0027 rules 3, 4, 7) or a lookup failure. Every card tap
/// must produce *some* defined feedback on every screen (issue #26 acceptance
/// criteria); a sound alone is not enough when the reader is behind the member.
class _ScanFeedbackBanner extends StatefulWidget {
  const _ScanFeedbackBanner();

  @override
  State<_ScanFeedbackBanner> createState() => _ScanFeedbackBannerState();
}

class _ScanFeedbackBannerState extends State<_ScanFeedbackBanner> {
  static const _displayDuration = Duration(seconds: 5);
  static const _fadeDuration = Duration(milliseconds: 500);

  RfidProvider? _rfidProvider;
  Timer? _dismissTimer;
  Timer? _clearTimer;
  OverlayEntry? _entry;

  /// The occurrences already shown, so a repeat of the same key still displays
  /// (see [TerminalError.sequence] and [ScanHint.sequence]).
  TerminalError? _shownError;
  ScanHint? _shownHint;

  String? _message;
  bool _isHint = false;
  double _opacity = 1.0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      final rfidProvider = context.read<RfidProvider>();
      _rfidProvider = rfidProvider;
      rfidProvider.addListener(_onScanOutcome);
      _onScanOutcome();
    });
  }

  @override
  void dispose() {
    _dismissTimer?.cancel();
    _clearTimer?.cancel();
    _rfidProvider?.removeListener(_onScanOutcome);
    _entry?.remove();
    _entry = null;
    super.dispose();
  }

  void _onScanOutcome() {
    final provider = _rfidProvider;
    if (provider == null || !mounted) return;

    final hint = provider.hint;
    final error = provider.error;

    if (hint != null && hint != _shownHint) {
      _shownHint = hint;
      _shownError = error;
      _show(hint.message(AppLocalizations.of(context)!), isHint: true);
    } else if (error != null && error != _shownError) {
      _shownError = error;
      _shownHint = hint;
      _show(error.message(AppLocalizations.of(context)!), isHint: false);
    }
  }

  /// Show [message] at full opacity for [_displayDuration], then fade it out.
  ///
  /// Both timers of a previous occurrence are cancelled first: a stale clear
  /// firing mid-display would wipe the new banner after a few hundred
  /// milliseconds (the bug fixed for the idle screen in issue #17).
  void _show(String message, {required bool isHint}) {
    _dismissTimer?.cancel();
    _clearTimer?.cancel();

    _message = message;
    _isHint = isHint;
    _opacity = 1.0;
    // Remove and re-insert rather than just rebuild: overlay entries stack in
    // insertion order, so this is what puts the banner above a dialog that is
    // already on screen.
    _entry?.remove();
    _entry = OverlayEntry(builder: _buildBanner);
    Overlay.of(context, rootOverlay: true).insert(_entry!);

    _dismissTimer = Timer(_displayDuration, () {
      if (!mounted) return;
      _opacity = 0.0;
      _entry?.markNeedsBuild();

      _clearTimer = Timer(_fadeDuration, () {
        if (!mounted) return;
        _message = null;
        _entry?.remove();
        _entry = null;
      });
    });
  }

  Widget _buildBanner(BuildContext context) {
    // Just below the persistent header, clear of the screen's own controls.
    return Positioned(
      top: kToolbarHeight + AppSpacing.md,
      left: AppSpacing.lg,
      right: AppSpacing.lg,
      child: IgnorePointer(
        child: AnimatedOpacity(
          opacity: _opacity,
          // Must match the clear timer, or the banner is torn out of the tree
          // mid-fade.
          duration: _fadeDuration,
          child: Container(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.lg,
              vertical: AppSpacing.md,
            ),
            decoration: BoxDecoration(
              color: _isHint
                  ? AppColors.semanticWarning
                  : AppColors.semanticDanger.withValues(alpha: 0.95),
              borderRadius: BorderRadius.circular(AppBorderRadius.md),
            ),
            child: Text(
              _message ?? '',
              textAlign: TextAlign.center,
              style: TextStyle(
                color: Colors.white,
                fontSize: AppFontSizes.lg,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ),
      ),
    );
  }

  // Nothing to render in place: the banner lives in the root overlay.
  @override
  Widget build(BuildContext context) => const SizedBox.shrink();
}
