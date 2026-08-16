import 'dart:async';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/scan_hint.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/error_signal.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/generated/terminal.swagger.dart';
import 'package:clubbar_terminal/services/mock_rfid_service.dart';
import 'package:clubbar_terminal/services/real_rfid_service.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/services/scan_log.dart';
import 'package:clubbar_terminal/services/sound_service.dart';
import 'package:clubbar_terminal/utils/card_uid.dart';

class RfidProvider extends ChangeNotifier with ErrorSignal {
  final MockRfidService _mockRfidService = MockRfidService();
  final RealRfidService _realRfidService = RealRfidService();
  final MembersProvider _membersProvider;
  final MembersRepository _membersRepository;
  final SoundService _soundService;
  final SessionController _sessionController;

  MembersCacheData? _detectedMember;
  bool _isScanning = false;
  StreamSubscription<String>? _scanSubscription;
  BuildContext? _context;
  ScanHint? _hint;
  int _hintSequence = 0;

  /// Supplies the route a scan is being handled on, for the per-route policy of
  /// ADR-0027 amendment 2.
  ///
  /// Installed by `ScanCapture` at the app shell — the only place that knows
  /// the router. Left null (route treated as idle) in unit tests and until the
  /// shell mounts.
  String Function()? locationResolver;

  RfidProvider(this._membersProvider, this._membersRepository,
      this._soundService, this._sessionController);

  MembersCacheData? get detectedMember => _detectedMember;
  bool get isScanning => _isScanning;

  /// Pending scan error, or null. Each occurrence is a distinct event, so
  /// scanning the same rejected card twice signals twice.
  TerminalError? get error => lastError;

  /// Pending scan *policy* outcome, or null — a refused or ignored tap rather
  /// than a failure (ADR-0027 rules 3, 4 and 7). Like [error], each occurrence
  /// is a distinct event.
  ScanHint? get hint => _hint;

  /// The receipt screen is the one place where a valid card may take the
  /// terminal over (ADR-0027 rule 9).
  bool get _onConfirmationScreen =>
      (locationResolver?.call() ?? '/idle').startsWith('/confirmation');

  /// Start listening for real RFID card scans (automatic detection).
  ///
  /// Called once by the app shell: scans must be captured on every route
  /// (issue #26), so this is deliberately not tied to a screen's lifecycle.
  void startListening(BuildContext context) {
    _context = context;
    _scanSubscription ??= _realRfidService.cardScans.listen((cardUid) {
      handleCardScan(cardUid);
    });
  }

  /// Stop listening for RFID scans (app shell teardown).
  void stopListening() {
    _scanSubscription?.cancel();
    _scanSubscription = null;
    _context = null;
  }

  /// Emit a card UID to the real RFID service (called by hidden TextField).
  void emitScan(String cardUid) {
    _realRfidService.emitScan(cardUid);
  }

  /// Handle a card scan captured anywhere in the app (issue #26).
  ///
  /// Scans arrive on every route, so the per-route policy of ADR-0027
  /// amendment 2 lives here rather than in whichever screen happens to be
  /// mounted:
  ///
  /// - a checkout/dispense in flight refuses every scan (rule 7),
  /// - an active session is protected from foreign cards (rule 3),
  /// - the active member's own re-tap is a no-op that counts as activity
  ///   (rule 4),
  /// - on the confirmation screen a valid card finalizes the shown receipt and
  ///   takes the terminal over (rule 9).
  ///
  /// Failures are [TerminalErrorKey]s and refusals are [ScanHintKey]s; both are
  /// localized by the UI at render time.
  ///
  /// [rawCardUid] is normalized here rather than at each input path: this is where
  /// every scan converges, so no future caller can reintroduce the case
  /// mismatch that rejected valid cards as "Unknown token" (issue #18).
  Future<void> handleCardScan(String rawCardUid) async {
    final cardUid = normalizeCardUid(rawCardUid);

    // A tap dropped here is the one refusal that says nothing at all — no
    // sound, no banner — so it is at least written down (issue #370). The
    // window is a whole lookup wide, and a lookup waits on the backend for the
    // member's balance.
    if (_isScanning) {
      ScanLog.instance.record(ScanEventKind.droppedBusy, uid: cardUid);
      return;
    }

    // ADR-0027 rule 7: while billing runs, every scan is refused and none is
    // queued. Checked before the lookup so a refused tap cannot touch the
    // session at all.
    if (_sessionController.isCriticalOperationInFlight) {
      _emitHint(ScanHintKey.transactionInProgress, SoundEvent.scanError);
      return;
    }

    _isScanning = true;
    resetError();
    _hint = null;
    notifyListeners();

    try {
      // Lookup member by card UID (returns an error key if failed)
      final (member, errorKey) = await _membersRepository.findByCardUid(cardUid);

      if (member == null) {
        // Error: card not found, inactive, or SEPA missing. On the confirmation
        // screen this deliberately leaves the receipt on screen (rule 9).
        final key = errorKey ?? TerminalErrorKey.memberLookupFailed;
        ScanLog.instance
            .record(ScanEventKind.rejected, uid: cardUid, detail: key.name);
        _detectedMember = null;
        emitError(key);
        _membersProvider.setError(key);
        _soundService.play(SoundEvent.scanError);
        return;
      }

      if (!await _startSessionForScannedCard(member)) return;

      ScanLog.instance.record(ScanEventKind.accepted, uid: cardUid);

      // Navigate to product selection (only if context is available and mounted)
      if (_context != null && _context!.mounted) {
        _context!.go('/products');
      }
    } catch (e, stackTrace) {
      ScanLog.instance.record(ScanEventKind.rejected,
          uid: cardUid, detail: 'lookup threw: $e');
      _detectedMember = null;
      emitError(TerminalErrorKey.memberLookupFailed,
          cause: e, stackTrace: stackTrace);
      _membersProvider.setError(TerminalErrorKey.memberLookupFailed);
      _soundService.play(SoundEvent.scanError);
    } finally {
      _isScanning = false;
      notifyListeners();
    }
  }

  /// Apply the per-route session policy to a card that resolved to [member],
  /// and report whether a fresh session actually started.
  ///
  /// The single place where ADR-0027 rules 3, 4, 7 and 9 are applied, shared by
  /// a real scan and the demo button — a future rule change must not be able to
  /// take effect on only one of them. Callers own the surrounding notification;
  /// this records the hint and plays the sound.
  Future<bool> _startSessionForScannedCard(MembersCacheData member) async {
    // ADR-0027 rule 9: the receipt is a finished transaction, not an open
    // cart — there is nothing to protect, and taking over is the queue win.
    if (_onConfirmationScreen && !_sessionController.endSession()) {
      // Only a critical operation can refuse the end (rule 7).
      _setHint(ScanHintKey.transactionInProgress);
      _soundService.play(SoundEvent.scanError);
      return false;
    }

    switch (await _sessionController.startSession(member)) {
      case SessionStartResult.rejectedActiveSession:
        // Rule 3: a foreign card never ends, replaces or merges a session.
        _setHint(ScanHintKey.logOutFirst);
        _soundService.play(SoundEvent.scanError);
        return false;
      case SessionStartResult.sameMemberNoOp:
        // Rule 4: an accidental double-tap must not wipe the cart, and a member
        // still standing at the terminal is not idle.
        _sessionController.recordActivity();
        _setHint(ScanHintKey.alreadyLoggedIn);
        _soundService.play(SoundEvent.scanSuccess);
        return false;
      case SessionStartResult.started:
        _detectedMember = member;
        resetError();
        _soundService.play(SoundEvent.scanSuccess);
        return true;
    }
  }

  /// Record a scan hint occurrence without notifying — for callers that are
  /// about to notify anyway (mirrors [ErrorSignal.emitError]).
  ///
  /// Every refusal goes to the scan log too, so the log tells the whole story
  /// of a tap: what the reader typed, and what the policy did with it.
  void _setHint(ScanHintKey key) {
    ScanLog.instance.record(ScanEventKind.refused, detail: key.name);
    _hint = ScanHint(key: key, sequence: ++_hintSequence);
  }

  /// Refuse a scan outright: hint, sound, notify. Used on the paths that never
  /// enter the scanning state.
  void _emitHint(ScanHintKey key, SoundEvent sound) {
    _setHint(key);
    _soundService.play(sound);
    notifyListeners();
  }

  /// Drop the pending hint and notify. Call after the hint has been displayed.
  void clearHint() {
    _hint = null;
    notifyListeners();
  }

  /// Simulate RFID card detection (called from UI when user taps demo button).
  /// Uses a real synced member from the local DB if available, otherwise falls
  /// back to the hardcoded mock member (for offline-only development).
  Future<void> simulateCardDetection(BuildContext context, {String? cardUidOverride}) async {
    if (_sessionController.isCriticalOperationInFlight) {
      _emitHint(ScanHintKey.transactionInProgress, SoundEvent.scanError);
      return;
    }

    _isScanning = true;
    resetError();
    _hint = null;
    notifyListeners();

    try {
      // Simulate reader delay
      await Future.delayed(const Duration(milliseconds: 800));

      // Try to pick a real member from the local DB (synced from backend)
      final activeMembers = await _membersRepository.getAllActive();
      final MembersCacheData member;

      if (activeMembers.isNotEmpty) {
        member = activeMembers.first;
      } else {
        // No synced members yet — fall back to mock member for offline dev
        final mockMember = await _mockRfidService.detectCard(cardUidOverride: cardUidOverride);
        if (mockMember == null) {
          _detectedMember = null;
          emitError(TerminalErrorKey.unknownCard);
          _membersProvider.setError(TerminalErrorKey.unknownCard);
          _soundService.play(SoundEvent.scanError);
          return;
        }
        member = MembersCacheData(
          id: mockMember.id,
          cardUid: mockMember.cardUid,
          firstName: mockMember.firstName,
          lastName: mockMember.lastName,
          preferredLanguage: mockMember.preferredLanguage,
          isActive: mockMember.isActive ? 1 : 0,
          isSepaValid: mockMember.isSepaValid ? 1 : 0,
          balanceCents: 0,
          updatedAt: mockMember.updatedAt.toIso8601String(),
        );
        // Persist mock member locally for offline dev
        try {
          await _membersRepository.upsertMembers([
            Member(
              id: member.id,
              cardUid: member.cardUid ?? '',
              firstName: member.firstName,
              lastName: member.lastName,
              preferredLanguage: member.preferredLanguage,
              isActive: member.isActive == 1,
              isSepaValid: member.isSepaValid == 1,
              createdAt: DateTime.now(),
              updatedAt: DateTime.tryParse(member.updatedAt) ?? DateTime.now(),
            ),
          ]);
        } catch (_) {}
      }

      // Exactly the same per-route policy as a real scan.
      if (!await _startSessionForScannedCard(member)) return;

      if (context.mounted) {
        context.go('/products');
      }
    } catch (e, stackTrace) {
      _detectedMember = null;
      emitError(TerminalErrorKey.memberLookupFailed,
          cause: e, stackTrace: stackTrace);
      _membersProvider.setError(TerminalErrorKey.memberLookupFailed);
      _soundService.play(SoundEvent.scanError);
    } finally {
      _isScanning = false;
      notifyListeners();
    }
  }

  void clearDetection() {
    _detectedMember = null;
    resetError();
    _hint = null;
    notifyListeners();
  }

  @override
  void dispose() {
    stopListening();
    _realRfidService.dispose();
    super.dispose();
  }
}
