import 'package:clubbar_terminal/generated/terminal.swagger.dart';

class MockRfidService {
  // Single test member with full SEPA data
  static final _mockMembers = {
    'RF-4821': Member(
      id: '550e8400-e29b-41d4-a716-446655440000',
      cardUid: 'RF-4821',
      firstName: 'Max',
      lastName: 'Mustermann',
      preferredLanguage: 'de',
      isActive: true,
      isSepaValid: true, // Has valid IBAN + mandate_reference
      createdAt: DateTime.parse('2025-02-01T10:00:00Z'),
      updatedAt: DateTime.parse('2025-02-01T10:00:00Z'),
    ),
  };

  /// Simulate RFID card detection (called when user taps "detect" button)
  Future<Member?> detectCard({String? cardUidOverride}) async {
    // Simulate reader delay
    await Future.delayed(const Duration(milliseconds: 800));

    // Return mock member (or override for testing different scenarios)
    return _mockMembers[cardUidOverride ?? 'RF-4821'];
  }

  /// Get all mock members (just 1 for MVP)
  List<Member> getAllMockMembers() => _mockMembers.values.toList();
}
