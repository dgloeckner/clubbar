import 'dart:async';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/models/member_dto.dart';
import 'package:clubbar_terminal/services/mock_rfid_service.dart';
import 'package:clubbar_terminal/services/real_rfid_service.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/services/sound_service.dart';

class RfidProvider extends ChangeNotifier {
  final MockRfidService _mockRfidService = MockRfidService();
  final RealRfidService _realRfidService = RealRfidService();
  final MembersProvider _membersProvider;
  final MembersRepository _membersRepository;
  final SoundService _soundService;

  MembersCacheData? _detectedMember;
  bool _isScanning = false;
  String? _error;
  StreamSubscription<String>? _scanSubscription;
  BuildContext? _context;

  RfidProvider(this._membersProvider, this._membersRepository, this._soundService);

  MembersCacheData? get detectedMember => _detectedMember;
  bool get isScanning => _isScanning;
  String? get error => _error;

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
  /// Errors are i18n keys (e.g., 'rfidErrorUnknownCard') to be translated by UI.
  Future<void> handleCardScan(String cardUid) async {
    if (_isScanning) return;

    _isScanning = true;
    _error = null;
    notifyListeners();

    try {
      // Lookup member by card UID (returns i18n error key if failed)
      final (member, errorKey) = await _membersRepository.findByCardUid(cardUid);

      if (member != null) {
        // Success: member found, active, and SEPA valid
        _detectedMember = member;
        _error = null;
        _membersProvider.setSelectedMember(member);
        _soundService.play(SoundEvent.scanSuccess);

        _isScanning = false;
        notifyListeners();

        // Navigate to product selection (only if context is available and mounted)
        if (_context != null && _context!.mounted) {
          _context!.go('/products');
        }
      } else {
        // Error: card not found, inactive, or SEPA missing (i18n key)
        _error = errorKey ?? 'rfidErrorDatabaseError';
        _detectedMember = null;
        _membersProvider.setError(_error!);
        _soundService.play(SoundEvent.scanError);
        _isScanning = false;
        notifyListeners();
      }
    } catch (e) {
      _error = 'rfidErrorDatabaseError';
      _detectedMember = null;
      _membersProvider.setError(_error!);
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
    _error = null;
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
          _error = 'rfidErrorUnknownCard';
          _detectedMember = null;
          _membersProvider.setError('rfidErrorUnknownCard');
          _isScanning = false;
          notifyListeners();
          return;
        }
        member = MembersCacheData(
          id: mockMember.id,
          cardUid: mockMember.cardUid!,
          firstName: mockMember.firstName,
          lastName: mockMember.lastName,
          preferredLanguage: mockMember.preferredLanguage,
          isActive: mockMember.isActive ? 1 : 0,
          isSepaValid: mockMember.isSepaValid ? 1 : 0,
          balanceCents: 0,
          updatedAt: mockMember.updatedAt,
        );
        // Persist mock member locally for offline dev
        try {
          await _membersRepository.upsertMembers([
            MemberDTO(
              id: member.id,
              cardUid: member.cardUid,
              firstName: member.firstName ?? 'Unknown',
              lastName: member.lastName ?? 'User',
              preferredLanguage: member.preferredLanguage,
              isActive: member.isActive == 1,
              isSepaValid: member.isSepaValid == 1,
              updatedAt: member.updatedAt,
            ),
          ]);
        } catch (_) {}
      }

      _detectedMember = member;
      _error = null;
      _membersProvider.setSelectedMember(member);

      _isScanning = false;
      notifyListeners();

      if (context.mounted) {
        context.go('/products');
      }
    } catch (e) {
      _error = 'Error: $e';
      _detectedMember = null;
      _membersProvider.setError(_error!);
      _isScanning = false;
      notifyListeners();
    }
  }

  void clearDetection() {
    _detectedMember = null;
    _error = null;
    notifyListeners();
  }

  @override
  void dispose() {
    stopListening();
    _realRfidService.dispose();
    super.dispose();
  }
}
