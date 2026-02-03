import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/repository/members_repository.dart';
import 'package:ruderbar_terminal/models/member_dto.dart';
import 'package:ruderbar_terminal/services/mock_rfid_service.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';

class RfidProvider extends ChangeNotifier {
  final MockRfidService _rfidService = MockRfidService();
  final MembersProvider _membersProvider;
  final MembersRepository _membersRepository;

  MembersCacheData? _detectedMember;
  bool _isScanning = false;
  String? _error;

  RfidProvider(this._membersProvider, this._membersRepository);

  MembersCacheData? get detectedMember => _detectedMember;
  bool get isScanning => _isScanning;
  String? get error => _error;

  /// Simulate RFID card detection (called from UI when user taps detect button).
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
        final mockMember = await _rfidService.detectCard(cardUidOverride: cardUidOverride);
        if (mockMember == null) {
          _error = 'Unknown card';
          _detectedMember = null;
          _membersProvider.setError('Unknown card');
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
}
