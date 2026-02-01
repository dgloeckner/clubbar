import 'package:flutter/foundation.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/repository/members_repository.dart';
import 'package:ruderbar_terminal/services/mock_rfid_service.dart';

class RfidProvider extends ChangeNotifier {
  final MockRfidService _rfidService = MockRfidService();
  final MembersRepository _membersRepository;

  MembersCacheData? _detectedMember;
  bool _isScanning = false;
  String? _error;

  RfidProvider(this._membersRepository);

  MembersCacheData? get detectedMember => _detectedMember;
  bool get isScanning => _isScanning;
  String? get error => _error;

  /// Simulate RFID card detection (called from UI when user taps detect button)
  Future<void> simulateCardDetection({String? cardUidOverride}) async {
    _isScanning = true;
    _error = null;
    notifyListeners();

    try {
      final mockMember = await _rfidService.detectCard(cardUidOverride: cardUidOverride);

      if (mockMember == null) {
        _error = 'Unknown card';
        _detectedMember = null;
      } else {
        // Look up in local cache (should exist from sync)
        final (member, error) =
            await _membersRepository.findByCardUid(mockMember.cardUid!);

        if (member != null) {
          _detectedMember = member;
          _error = null;
        } else {
          // If not in cache, return error
          _error = error ?? 'Member not found in cache';
          _detectedMember = null;
        }
      }
    } catch (e) {
      _error = 'Error: $e';
      _detectedMember = null;
    }

    _isScanning = false;
    notifyListeners();
  }

  void clearDetection() {
    _detectedMember = null;
    _error = null;
    notifyListeners();
  }
}
