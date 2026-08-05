import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:clubbar_terminal/config/app_config.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/providers/cart_provider.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';

/// Outcome of a [SessionController.startSession] attempt (ADR-0027 rules 1-4).
enum SessionStartResult {
  /// No session was active; a fresh session started with an empty cart.
  started,

  /// The active member re-scanned their own card; nothing changes.
  sameMemberNoOp,

  /// A different card was scanned while a session is active. The active
  /// session is protected (ADR-0027 rule 3) — the scan is rejected.
  rejectedActiveSession,
}

/// Owns the terminal session lifecycle (ADR-0027).
///
/// A session ends in exactly three ways — explicit logout, inactivity
/// timeout, or checkout completion — and every one of them must go through
/// [endSession]. No screen may clear member or cart state directly.
class SessionController extends ChangeNotifier {
  final MembersProvider _membersProvider;
  final CartProvider _cartProvider;
  final Duration inactivityTimeout;
  final Duration warningDuration;

  Timer? _inactivityTimer;
  Timer? _warningTicker;
  bool _warningActive = false;
  int _warningSecondsLeft = 0;
  int _criticalOps = 0;

  SessionController({
    required MembersProvider membersProvider,
    required CartProvider cartProvider,
    this.inactivityTimeout = AppConfig.inactivityTimeout,
    this.warningDuration = AppConfig.inactivityWarningDuration,
  })  : _membersProvider = membersProvider,
        _cartProvider = cartProvider;

  bool get hasActiveSession => _membersProvider.selectedMember != null;

  /// True while the "Still there?" countdown is on screen.
  bool get showTimeoutWarning => _warningActive;

  /// Seconds remaining on the warning countdown (0 when no warning shown).
  int get warningSecondsLeft => _warningSecondsLeft;

  /// Start a session for [member] scanned on the idle screen.
  ///
  /// The cart is cleared defensively before the member is set, so a cart can
  /// never leak across a session boundary even if a teardown path was missed.
  Future<SessionStartResult> startSession(MembersCacheData member) async {
    final current = _membersProvider.selectedMember;
    if (current != null) {
      return current.id == member.id
          ? SessionStartResult.sameMemberNoOp
          : SessionStartResult.rejectedActiveSession;
    }

    _cartProvider.clearCart();
    await _membersProvider.setSelectedMember(member);
    _startInactivityTimer();
    notifyListeners();
    return SessionStartResult.started;
  }

  /// True while a checkout/dispense is in flight (ADR-0027 rule 7).
  bool get isCriticalOperationInFlight => _criticalOps > 0;

  /// End the active session: cart is silently discarded (ADR-0027 rule 5).
  ///
  /// Refused while a critical operation is in flight (ADR-0027 rule 7):
  /// nothing ends a session during billing — not the inactivity timer, not an
  /// explicit logout tap, not a card scan. Guarding here rather than only in
  /// the UI keeps future callers from reintroducing #48.
  ///
  /// Returns whether the session actually ended — callers that navigate away
  /// must gate on it, or they would leave a still-billing session behind.
  bool endSession() {
    if (isCriticalOperationInFlight) return false;

    _cancelTimers();
    _cartProvider.clearCart();
    _membersProvider.clearSelectedMember();
    notifyListeners();
    return true;
  }

  /// Record a member interaction; resets the inactivity timer and dismisses
  /// the warning countdown. No-op without an active session or while a
  /// critical operation suspends the timer.
  void recordActivity() {
    if (!hasActiveSession || _criticalOps > 0) return;
    _startInactivityTimer();
  }

  /// Suspend the inactivity timer while a checkout/dispense is in flight
  /// (ADR-0027 rule 7): an auto-logout must never interrupt billing.
  void beginCriticalOperation() {
    _criticalOps++;
    _cancelTimers();
  }

  /// Resume the inactivity timer once the critical operation finished.
  void endCriticalOperation() {
    if (_criticalOps > 0) _criticalOps--;
    if (_criticalOps == 0 && hasActiveSession) _startInactivityTimer();
  }

  void _startInactivityTimer() {
    _cancelTimers();
    _inactivityTimer = Timer(inactivityTimeout, _showWarning);
  }

  void _showWarning() {
    _warningActive = true;
    _warningSecondsLeft = warningDuration.inSeconds;
    notifyListeners();

    _warningTicker = Timer.periodic(const Duration(seconds: 1), (_) {
      _warningSecondsLeft--;
      if (_warningSecondsLeft <= 0) {
        endSession();
      } else {
        notifyListeners();
      }
    });
  }

  void _cancelTimers() {
    _inactivityTimer?.cancel();
    _inactivityTimer = null;
    _warningTicker?.cancel();
    _warningTicker = null;
    if (_warningActive) {
      _warningActive = false;
      _warningSecondsLeft = 0;
      notifyListeners();
    }
  }

  @override
  void dispose() {
    _inactivityTimer?.cancel();
    _warningTicker?.cancel();
    super.dispose();
  }
}
