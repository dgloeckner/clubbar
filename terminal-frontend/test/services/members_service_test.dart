import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/models/member_dto.dart';
import 'package:ruderbar_terminal/repository/members_repository.dart';
import 'package:ruderbar_terminal/repository/transactions_repository.dart';
import 'package:ruderbar_terminal/services/members_service.dart';

class MockMembersRepository extends Mock implements MembersRepository {}

class MockTransactionsRepository extends Mock
    implements TransactionsRepository {}

void main() {
  group('MembersService', () {
    late MockMembersRepository mockRepo;
    late MockTransactionsRepository mockTxnRepo;
    late MembersService service;

    setUp(() {
      mockRepo = MockMembersRepository();
      mockTxnRepo = MockTransactionsRepository();
      service = MembersService(
        repository: mockRepo,
        transactionsRepository: mockTxnRepo,
      );
    });

    test('lookupByRfid returns member when found and valid', () async {
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        preferredLanguage: 'de',
        isActive: 1,
        isSepaValid: 1,
        balanceCents: 0,
        updatedAt: DateTime.now().toIso8601String(),
      );

      when(() => mockRepo.findByCardUid('card-123'))
          .thenAnswer((_) async => (testMember, null));

      final (member, error) = await service.lookupByRfid('card-123');

      expect(member, equals(testMember));
      expect(error, isNull);
      verify(() => mockRepo.findByCardUid('card-123')).called(1);
    });

    test('lookupByRfid returns error when member not found', () async {
      when(() => mockRepo.findByCardUid('invalid-card'))
          .thenAnswer((_) async => (null, 'Unknown card'));

      final (member, error) = await service.lookupByRfid('invalid-card');

      expect(member, isNull);
      expect(error, isNotNull);
      verify(() => mockRepo.findByCardUid('invalid-card')).called(1);
    });

    test('lookupByRfid returns error when account inactive', () async {
      when(() => mockRepo.findByCardUid('card-456'))
          .thenAnswer((_) async => (null, 'Account inactive'));

      final (member, error) = await service.lookupByRfid('card-456');

      expect(member, isNull);
      expect(error, contains('inactive'));
    });

    test('lookupByRfid returns error when SEPA mandate not signed', () async {
      when(() => mockRepo.findByCardUid('card-789'))
          .thenAnswer((_) async => (null, 'SEPA mandate missing'));

      final (member, error) = await service.lookupByRfid('card-789');

      expect(member, isNull);
      expect(error, contains('SEPA'));
    });

    test('getEffectiveBalance returns synced balance when no unsynced txns',
        () async {
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        preferredLanguage: 'de',
        isActive: 1,
        isSepaValid: 1,
        balanceCents: 500,
        updatedAt: DateTime.now().toIso8601String(),
      );

      when(() => mockTxnRepo.getUnsyncedAmountForMember('member-1'))
          .thenAnswer((_) async => 0);

      final balance = await service.getEffectiveBalance(testMember);

      expect(balance, equals(500));
    });

    test('getEffectiveBalance adds unsynced transactions to synced balance',
        () async {
      final testMember = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'John',
        lastName: 'Doe',
        preferredLanguage: 'de',
        isActive: 1,
        isSepaValid: 1,
        balanceCents: 1000,
        updatedAt: DateTime.now().toIso8601String(),
      );

      when(() => mockTxnRepo.getUnsyncedAmountForMember('member-1'))
          .thenAnswer((_) async => -300);

      final balance = await service.getEffectiveBalance(testMember);

      expect(balance, equals(700));
    });

    test('getAllMembers returns list from repository', () async {
      final members = [
        MembersCacheData(
          id: 'member-1',
          cardUid: 'card-123',
          firstName: 'John',
          lastName: 'Doe',
          preferredLanguage: 'de',
          isActive: 1,
          isSepaValid: 1,
          balanceCents: 0,
          updatedAt: DateTime.now().toIso8601String(),
        ),
      ];

      when(() => mockRepo.getAllActive()).thenAnswer((_) async => members);

      final result = await service.getAllMembers();

      expect(result, equals(members));
      verify(() => mockRepo.getAllActive()).called(1);
    });

    test('refreshMembers returns list from repository', () async {
      final members = <MembersCacheData>[] as List<MembersCacheData>;

      when(() => mockRepo.getAllActive()).thenAnswer((_) async => members);

      final result = await service.refreshMembers();

      expect(result, equals(members));
    });
  });
}
