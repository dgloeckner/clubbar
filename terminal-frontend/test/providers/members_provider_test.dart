import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/services/members_service.dart';

class MockMembersService extends Mock implements MembersService {}

void main() {
  group('MembersProvider', () {
    late MockMembersService mockService;
    late MembersProvider provider;

    setUp(() {
      mockService = MockMembersService();
      provider = MembersProvider(service: mockService);
    });

    test('initial state is empty', () {
      expect(provider.members, isEmpty);
      expect(provider.selectedMember, isNull);
      expect(provider.isLoading, isFalse);
      expect(provider.isSyncing, isFalse);
      expect(provider.lastError, isNull);
    });

    test('selectMemberByRfid sets selectedMember when found', () async {
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        preferredLanguage: 'de',
        isActive: 1,
        isSepaValid: 1,
        updatedAt: DateTime.now().toIso8601String(),
      );

      when(() => mockService.lookupByRfid('card-123'))
          .thenAnswer((_) async => (testMember, null));

      await provider.selectMemberByRfid('card-123');

      expect(provider.selectedMember, equals(testMember));
      expect(provider.lastError, isNull);
    });

    test('selectMemberByRfid sets error when member not found', () async {
      when(() => mockService.lookupByRfid('invalid-card'))
          .thenAnswer((_) async => (null, 'Member not found'));

      await provider.selectMemberByRfid('invalid-card');

      expect(provider.selectedMember, isNull);
      expect(provider.lastError, equals('Member not found'));
    });

    test('selectMemberByRfid clears previous error on success', () async {
      // First set an error
      when(() => mockService.lookupByRfid('invalid'))
          .thenAnswer((_) async => (null, 'Not found'));
      await provider.selectMemberByRfid('invalid');
      expect(provider.lastError, isNotNull);

      // Then succeed
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        preferredLanguage: 'de',
        isActive: 1,
        isSepaValid: 1,
        updatedAt: DateTime.now().toIso8601String(),
      );
      when(() => mockService.lookupByRfid('card-123'))
          .thenAnswer((_) async => (testMember, null));

      await provider.selectMemberByRfid('card-123');

      expect(provider.lastError, isNull);
      expect(provider.selectedMember, equals(testMember));
    });

    test('clearSelectedMember resets member', () async {
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        preferredLanguage: 'de',
        isActive: 1,
        isSepaValid: 1,
        updatedAt: DateTime.now().toIso8601String(),
      );
      when(() => mockService.lookupByRfid('card-123'))
          .thenAnswer((_) async => (testMember, null));

      await provider.selectMemberByRfid('card-123');
      expect(provider.selectedMember, isNotNull);

      provider.clearSelectedMember();
      expect(provider.selectedMember, isNull);
    });

    test('refreshMembers updates members list', () async {
      final members = [
        MembersCacheData(
          id: 'member-1',
          cardUid: 'card-123',
          firstName: 'John',
          lastName: 'Doe',
          preferredLanguage: 'de',
          isActive: 1,
          isSepaValid: 1,
          updatedAt: DateTime.now().toIso8601String(),
        ),
      ];

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
  });
}
