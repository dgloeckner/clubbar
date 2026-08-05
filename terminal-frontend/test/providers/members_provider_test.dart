import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/services/members_service.dart';

class MockMembersService extends Mock implements MembersService {}

void main() {
  group('MembersProvider', () {
    late MockMembersService mockService;
    late MembersProvider provider;

    setUpAll(() {
      registerFallbackValue(MembersCacheData(
        id: 'fallback',
        cardUid: 'fallback-uid',
        firstName: 'Fallback',
        lastName: 'User',
        preferredLanguage: 'de',
        isActive: 1,
        isSepaValid: 1,
        balanceCents: 0,
        updatedAt: '2024-01-01T00:00:00.000Z',
      ));
    });

    setUp(() {
      mockService = MockMembersService();
      provider = MembersProvider(service: mockService);
    });

    MembersCacheData createTestMember({
      String id = 'member-1',
      String cardUid = 'card-123',
      int balanceCents = 0,
    }) {
      return MembersCacheData(
        id: id,
        cardUid: cardUid,
        firstName: 'John',
        lastName: 'Doe',
        preferredLanguage: 'de',
        isActive: 1,
        isSepaValid: 1,
        balanceCents: balanceCents,
        updatedAt: DateTime.now().toIso8601String(),
      );
    }

    test('initial state is empty', () {
      expect(provider.members, isEmpty);
      expect(provider.selectedMember, isNull);
      expect(provider.memberDeckel, isNull);
      expect(provider.isLoading, isFalse);
      expect(provider.isSyncing, isFalse);
      expect(provider.lastError, isNull);
    });

    test('selectMemberByRfid sets selectedMember and memberDeckel', () async {
      final testMember = createTestMember(balanceCents: 500);

      when(() => mockService.lookupByRfid('card-123'))
          .thenAnswer((_) async => (testMember, null));
      when(() => mockService.getEffectiveBalance(testMember))
          .thenAnswer((_) async => 200); // 500 synced - 300 unsynced

      await provider.selectMemberByRfid('card-123');

      expect(provider.selectedMember, equals(testMember));
      expect(provider.memberDeckel, equals(200));
      expect(provider.lastError, isNull);
    });

    test('selectMemberByRfid sets error when member not found', () async {
      when(() => mockService.lookupByRfid('invalid-card'))
          .thenAnswer((_) async => (null, TerminalErrorKey.unknownCard));

      await provider.selectMemberByRfid('invalid-card');

      expect(provider.selectedMember, isNull);
      expect(provider.memberDeckel, isNull);
      expect(provider.lastErrorKey, equals(TerminalErrorKey.unknownCard));
    });

    test('selectMemberByRfid clears previous error on success', () async {
      // First set an error
      when(() => mockService.lookupByRfid('invalid'))
          .thenAnswer((_) async => (null, TerminalErrorKey.unknownCard));
      await provider.selectMemberByRfid('invalid');
      expect(provider.lastError, isNotNull);

      // Then succeed
      final testMember = createTestMember();
      when(() => mockService.lookupByRfid('card-123'))
          .thenAnswer((_) async => (testMember, null));
      when(() => mockService.getEffectiveBalance(testMember))
          .thenAnswer((_) async => 0);

      await provider.selectMemberByRfid('card-123');

      expect(provider.lastError, isNull);
      expect(provider.selectedMember, equals(testMember));
    });

    test('refreshDeckel recomputes effective balance from service', () async {
      final testMember = createTestMember(balanceCents: 1000);

      when(() => mockService.lookupByRfid('card-123'))
          .thenAnswer((_) async => (testMember, null));
      when(() => mockService.getEffectiveBalance(testMember))
          .thenAnswer((_) async => 1000);

      await provider.selectMemberByRfid('card-123');
      expect(provider.memberDeckel, equals(1000));

      // After a checkout, unsynced txn added, refresh Deckel
      when(() => mockService.getEffectiveBalance(testMember))
          .thenAnswer((_) async => 700); // -300 from new transaction

      await provider.refreshDeckel();

      expect(provider.memberDeckel, equals(700));
    });

    test('clearSelectedMember resets member and Deckel', () async {
      final testMember = createTestMember();
      when(() => mockService.lookupByRfid('card-123'))
          .thenAnswer((_) async => (testMember, null));
      when(() => mockService.getEffectiveBalance(testMember))
          .thenAnswer((_) async => 0);

      await provider.selectMemberByRfid('card-123');
      expect(provider.selectedMember, isNotNull);
      expect(provider.memberDeckel, isNotNull);

      provider.clearSelectedMember();
      expect(provider.selectedMember, isNull);
      expect(provider.memberDeckel, isNull);
    });

    test('refreshMembers updates members list', () async {
      final members = [createTestMember()];

      when(() => mockService.getAllMembers())
          .thenAnswer((_) async => members);

      await provider.refreshMembers();

      expect(provider.members, equals(members));
      expect(provider.isSyncing, isFalse);
    });

    test('refreshMembers sets isSyncing during operation', () async {
      when(() => mockService.getAllMembers()).thenAnswer((_) async {
        expect(provider.isSyncing, isTrue);
        return [];
      });

      await provider.refreshMembers();

      expect(provider.isSyncing, isFalse);
    });

    test('selectMemberByRfid sets a non-null sessionId on success', () async {
      final fakeMember = createTestMember();
      // Arrange: mock service returns a member
      when(() => mockService.lookupByRfid(any()))
          .thenAnswer((_) async => (fakeMember, null));
      when(() => mockService.getEffectiveBalance(any()))
          .thenAnswer((_) async => 0);

      // Act
      await provider.selectMemberByRfid('test-uid');

      // Assert — must be a valid UUID v4
      expect(provider.sessionId, matches(RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')));
    });

    test('clearSelectedMember clears sessionId', () async {
      final fakeMember = createTestMember();
      when(() => mockService.lookupByRfid(any()))
          .thenAnswer((_) async => (fakeMember, null));
      when(() => mockService.getEffectiveBalance(any()))
          .thenAnswer((_) async => 0);
      await provider.selectMemberByRfid('test-uid');

      provider.clearSelectedMember();

      expect(provider.sessionId, isNull);
    });

    test('setSelectedMember sets a new UUID sessionId on each login', () async {
      final fakeMember = createTestMember();
      when(() => mockService.getEffectiveBalance(any()))
          .thenAnswer((_) async => 0);

      await provider.setSelectedMember(fakeMember);
      final first = provider.sessionId;

      await provider.setSelectedMember(fakeMember);
      final second = provider.sessionId;

      expect(first, matches(RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')));
      expect(second, isNot(equals(first)));
    });
  });
}
