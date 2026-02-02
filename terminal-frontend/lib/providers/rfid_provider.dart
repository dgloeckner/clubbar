import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/services/mock_rfid_service.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';

class RfidProvider extends ChangeNotifier {
  final MockRfidService _rfidService = MockRfidService();
  final MembersProvider _membersProvider;

  MembersCacheData? _detectedMember;
  bool _isScanning = false;
  String? _error;

  RfidProvider(this._membersProvider);

  MembersCacheData? get detectedMember => _detectedMember;
  bool get isScanning => _isScanning;
  String? get error => _error;

  /// Simulate RFID card detection (called from UI when user taps detect button)
  Future<void> simulateCardDetection(BuildContext context, {String? cardUidOverride}) async {
    _isScanning = true;
    _error = null;
    notifyListeners();

    try {
      final mockMember = await _rfidService.detectCard(cardUidOverride: cardUidOverride);

      if (mockMember == null) {
        _error = 'Unknown card';
        _detectedMember = null;
        _membersProvider.setError('Unknown card');
        _isScanning = false;
        notifyListeners();
      } else {
        // For development/testing: create a MembersCacheData from mock member
        // In production with real RFID, this would look up from synced database cache
        final member = MembersCacheData(
          id: mockMember.id,
          cardUid: mockMember.cardUid!,
          firstName: mockMember.firstName,
          lastName: mockMember.lastName,
          preferredLanguage: mockMember.preferredLanguage,
          isActive: mockMember.isActive ? 1 : 0,
          isSepaValid: mockMember.isSepaValid ? 1 : 0,
          updatedAt: mockMember.updatedAt,
        );

        _detectedMember = member;
        _error = null;
        // Set as selected member in MembersProvider
        _membersProvider.setSelectedMember(member);

        _isScanning = false;
        notifyListeners();

        // Navigate to products screen (use context immediately after notifyListeners)
        if (context.mounted) {
          context.go('/products');
        }
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
}
