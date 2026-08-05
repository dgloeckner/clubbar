import 'dart:async';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/error_signal.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/generated/terminal.swagger.dart';
import 'package:clubbar_terminal/services/mock_rfid_service.dart';
import 'package:clubbar_terminal/services/real_rfid_service.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/services/sound_service.dart';

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

  RfidProvider(this._membersProvider, this._membersRepository,
      this._soundService, this._sessionController);

  MembersCacheData? get detectedMember => _detectedMember;
  bool get isScanning => _isScanning;

  /// Pending scan error, or null. Each occurrence is a distinct event, so
  /// scanning the same rejected card twice signals twice.
  TerminalError? get error => lastError;

  /// Start listening for real RFID card scans (automatic detection).
  /// Call this when the idle screen mounts.
  void startListening(BuildContext context) {
    _context = context;
    _scanSubscription = _realRfidService.cardScans.listen((cardUid) {
      handleCardScan(cardUid);
    });
  }

  /// Stop listening for RFID scans.
  /// Call this when leaving the idle screen.
  void stopListening() {
    _scanSubscription?.cancel();
    _scanSubscription = null;
    _context = null;
  }

  /// Emit a card UID to the real RFID service (called by hidden TextField).
  void emitScan(String cardUid) {
    _realRfidService.emitScan(cardUid);
  }

  /// Handle a card scan (lookup member by card UID and navigate).
  /// Errors are [TerminalErrorKey]s, localized by the UI at render time.
  Future<void> handleCardScan(String cardUid) async {
    if (_isScanning) return;

    _isScanning = true;
    resetError();
    notifyListeners();

    try {
      // Lookup member by card UID (returns an error key if failed)
      final (member, errorKey) = await _membersRepository.findByCardUid(cardUid);

      if (member != null) {
        // Success: member found, active, and SEPA valid.
        // ADR-0027 rule 3: an active session is protected — a foreign card
        // never ends or replaces it, so a rejected scan is ignored.
        final result = await _sessionController.startSession(member);
        if (result == SessionStartResult.rejectedActiveSession) {
          _soundService.play(SoundEvent.scanError);
          _isScanning = false;
          notifyListeners();
          return;
        }

        _detectedMember = member;
        resetError();
        _soundService.play(SoundEvent.scanSuccess);

        _isScanning = false;
        notifyListeners();

        // Navigate to product selection (only if context is available and mounted)
        if (_context != null && _context!.mounted) {
          _context!.go('/products');
        }
      } else {
        // Error: card not found, inactive, or SEPA missing
        final key = errorKey ?? TerminalErrorKey.memberLookupFailed;
        _detectedMember = null;
        emitError(key);
        _membersProvider.setError(key);
        _soundService.play(SoundEvent.scanError);
        _isScanning = false;
        notifyListeners();
      }
    } catch (e, stackTrace) {
      _detectedMember = null;
      emitError(TerminalErrorKey.memberLookupFailed,
          cause: e, stackTrace: stackTrace);
      _membersProvider.setError(TerminalErrorKey.memberLookupFailed);
      _soundService.play(SoundEvent.scanError);
      _isScanning = false;
      notifyListeners();
    }
  }

  /// Simulate RFID card detection (called from UI when user taps demo button).
  /// Uses a real synced member from the local DB if available, otherwise falls
  /// back to the hardcoded mock member (for offline-only development).
  Future<void> simulateCardDetection(BuildContext context, {String? cardUidOverride}) async {
    _isScanning = true;
    resetError();
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
          _isScanning = false;
          notifyListeners();
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

      // ADR-0027 rule 3: never replace an active session.
      final result = await _sessionController.startSession(member);
      if (result == SessionStartResult.rejectedActiveSession) {
        _soundService.play(SoundEvent.scanError);
        _isScanning = false;
        notifyListeners();
        return;
      }

      _detectedMember = member;
      resetError();

      _isScanning = false;
      notifyListeners();

      if (context.mounted) {
        context.go('/products');
      }
    } catch (e, stackTrace) {
      _detectedMember = null;
      emitError(TerminalErrorKey.memberLookupFailed,
          cause: e, stackTrace: stackTrace);
      _membersProvider.setError(TerminalErrorKey.memberLookupFailed);
      _isScanning = false;
      notifyListeners();
    }
  }

  void clearDetection() {
    _detectedMember = null;
    resetError();
    notifyListeners();
  }

  @override
  void dispose() {
    stopListening();
    _realRfidService.dispose();
    super.dispose();
  }
}
