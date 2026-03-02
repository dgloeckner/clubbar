import 'package:clubbar_terminal/models/member_dto.dart';

class MockRfidService {
  // Single test member with full SEPA data
  static final _mockMembers = {
    'RF-4821': MemberDTO(
      id: '550e8400-e29b-41d4-a716-446655440000',
      cardUid: 'RF-4821',
      firstName: 'Max',
      lastName: 'Mustermann',
      preferredLanguage: 'de',
      isActive: true,
      isSepaValid: true, // Has valid IBAN + mandate_reference
      updatedAt: '2025-02-01T10:00:00Z',
    ),
  };

  /// Simulate RFID card detection (called when user taps "detect" button)
  Future<MemberDTO?> detectCard({String? cardUidOverride}) async {
    // Simulate reader delay
    await Future.delayed(const Duration(milliseconds: 800));

    // Return mock member (or override for testing different scenarios)
    return _mockMembers[cardUidOverride ?? 'RF-4821'];
  }

  /// Get all mock members (just 1 for MVP)
  List<MemberDTO> getAllMockMembers() => _mockMembers.values.toList();
}
