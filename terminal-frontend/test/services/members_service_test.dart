import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/generated/terminal.swagger.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/repository/transactions_repository.dart';
import 'package:clubbar_terminal/services/members_service.dart';
import 'package:clubbar_terminal/services/network_service.dart';

class MockMembersRepository extends Mock implements MembersRepository {}

class MockTransactionsRepository extends Mock
    implements TransactionsRepository {}

class MockNetworkService extends Mock implements NetworkService {}

void main() {
  group('MembersService', () {
    late MockMembersRepository mockRepo;
    late MockTransactionsRepository mockTxnRepo;
    late MembersService service;

    // Helper to create a test member
    MembersCacheData makeMember({int balanceCents = 0}) => MembersCacheData(
          id: 'member-1',
          cardUid: 'card-123',
          firstName: 'John',
          lastName: 'Doe',
          preferredLanguage: 'de',
          isActive: 1,
          isSepaValid: 1,
          balanceCents: balanceCents,
          updatedAt: DateTime.now().toIso8601String(),
        );

    setUp(() {
      mockRepo = MockMembersRepository();
      mockTxnRepo = MockTransactionsRepository();
      // Service without network — no balance refresh
      service = MembersService(
        repository: mockRepo,
        transactionsRepository: mockTxnRepo,
      );
    });

    group('lookupByRfid (no network)', () {
      test('returns member when found and valid', () async {
        final testMember = makeMember();
        when(() => mockRepo.findByCardUid('card-123'))
            .thenAnswer((_) async => (testMember, null));
        when(() => mockRepo.findById('member-1'))
            .thenAnswer((_) async => testMember);

        final (member, error) = await service.lookupByRfid('card-123');

        expect(member, equals(testMember));
        expect(error, isNull);
        verify(() => mockRepo.findByCardUid('card-123')).called(1);
      });

      test('returns error when member not found', () async {
        when(() => mockRepo.findByCardUid('invalid-card'))
            .thenAnswer((_) async => (null, TerminalErrorKey.unknownCard));

        final (member, error) = await service.lookupByRfid('invalid-card');

        expect(member, isNull);
        expect(error, isNotNull);
        verify(() => mockRepo.findByCardUid('invalid-card')).called(1);
      });

      test('returns error when account inactive', () async {
        when(() => mockRepo.findByCardUid('card-456'))
            .thenAnswer((_) async => (null, TerminalErrorKey.accountInactive));

        final (member, error) = await service.lookupByRfid('card-456');

        expect(member, isNull);
        expect(error, equals(TerminalErrorKey.accountInactive));
      });

      test('returns error when SEPA mandate not signed', () async {
        when(() => mockRepo.findByCardUid('card-789'))
            .thenAnswer((_) async => (null, TerminalErrorKey.sepaMissing));

        final (member, error) = await service.lookupByRfid('card-789');

        expect(member, isNull);
        expect(error, equals(TerminalErrorKey.sepaMissing));
      });
    });

    group('lookupByRfid (with network)', () {
      late MockNetworkService mockNetwork;
      late MembersService serviceWithNetwork;

      setUp(() {
        mockNetwork = MockNetworkService();
        serviceWithNetwork = MembersService(
          repository: mockRepo,
          transactionsRepository: mockTxnRepo,
          networkService: mockNetwork,
        );
      });

      test('triggers balance refresh and updates cache on success', () async {
        final testMember = makeMember();
        final updatedMember = makeMember(balanceCents: 4200);

        when(() => mockRepo.findByCardUid('card-123'))
            .thenAnswer((_) async => (testMember, null));
        when(() => mockTxnRepo.getUnsyncedTransactions())
            .thenAnswer((_) async => []);
        when(() => mockNetwork.syncTransactions(any(), memberIds: any(named: 'memberIds')))
            .thenAnswer((_) async => TransactionBatchResponse(
                  acceptedIds: [],
                  rejected: const TransactionBatchResponse$Rejected(),
                  memberBalances: {'member-1': 4200},
                ));
        when(() => mockRepo.updateMemberBalance('member-1', 4200))
            .thenAnswer((_) async {});
        when(() => mockRepo.findById('member-1'))
            .thenAnswer((_) async => updatedMember);

        final (member, error) =
            await serviceWithNetwork.lookupByRfid('card-123');

        expect(error, isNull);
        expect(member?.balanceCents, equals(4200));
        verify(() => mockRepo.updateMemberBalance('member-1', 4200)).called(1);
      });

      test('succeeds even when network sync throws', () async {
        final testMember = makeMember();

        when(() => mockRepo.findByCardUid('card-123'))
            .thenAnswer((_) async => (testMember, null));
        when(() => mockTxnRepo.getUnsyncedTransactions())
            .thenAnswer((_) async => []);
        when(() => mockNetwork.syncTransactions(any(), memberIds: any(named: 'memberIds')))
            .thenThrow(NetworkException('offline'));
        when(() => mockRepo.findById('member-1'))
            .thenAnswer((_) async => testMember);

        final (member, error) =
            await serviceWithNetwork.lookupByRfid('card-123');

        expect(member, isNotNull);
        expect(error, isNull);
      });

      /// #191: after a settlement the member's tab is zero and there is nothing
      /// to upload, so the batch is empty. If the request does not *name* the
      /// member, the backend reports no balance for them and the terminal keeps
      /// showing the pre-settlement Deckel until their next purchase.
      test('names the scanned member so an empty batch still asks for a balance',
          () async {
        final testMember = makeMember(balanceCents: 2500);
        final settledMember = makeMember(balanceCents: 0);

        when(() => mockRepo.findByCardUid('card-123'))
            .thenAnswer((_) async => (testMember, null));
        when(() => mockTxnRepo.getUnsyncedTransactions())
            .thenAnswer((_) async => []);
        when(() => mockNetwork.syncTransactions(any(),
                memberIds: any(named: 'memberIds')))
            .thenAnswer((_) async => TransactionBatchResponse(
                  acceptedIds: [],
                  rejected: const TransactionBatchResponse$Rejected(),
                  memberBalances: {'member-1': 0},
                ));
        when(() => mockRepo.updateMemberBalance('member-1', 0))
            .thenAnswer((_) async {});
        when(() => mockRepo.findById('member-1'))
            .thenAnswer((_) async => settledMember);

        final (member, error) =
            await serviceWithNetwork.lookupByRfid('card-123');

        expect(error, isNull);
        expect(member?.balanceCents, equals(0));
        verify(() => mockNetwork.syncTransactions(
              [],
              memberIds: ['member-1'],
            )).called(1);
      });

      test('skips balance update when member not in sync response', () async {
        final testMember = makeMember();

        when(() => mockRepo.findByCardUid('card-123'))
            .thenAnswer((_) async => (testMember, null));
        when(() => mockTxnRepo.getUnsyncedTransactions())
            .thenAnswer((_) async => []);
        when(() => mockNetwork.syncTransactions(any(), memberIds: any(named: 'memberIds')))
            .thenAnswer((_) async => TransactionBatchResponse(
                  acceptedIds: [],
                  rejected: const TransactionBatchResponse$Rejected(),
                  memberBalances: {}, // empty — member not in response
                ));
        when(() => mockRepo.findById('member-1'))
            .thenAnswer((_) async => testMember);

        await serviceWithNetwork.lookupByRfid('card-123');

        verifyNever(() => mockRepo.updateMemberBalance(any(), any()));
      });
    });

    group('getEffectiveBalance', () {
      // The arithmetic itself lives in TransactionsRepository, so that the
      // credit-limit check and the member bar read the same definition.
      test('reports the repository\'s effective balance', () async {
        final testMember = makeMember(balanceCents: 1000);
        when(() => mockTxnRepo.getEffectiveBalance(testMember))
            .thenAnswer((_) async => 700);

        final balance = await service.getEffectiveBalance(testMember);

        expect(balance, equals(700));
      });
    });

    group('getAllMembers / refreshMembers', () {
      test('getAllMembers returns list from repository', () async {
        final members = [makeMember()];
        when(() => mockRepo.getAllActive()).thenAnswer((_) async => members);

        final result = await service.getAllMembers();

        expect(result, equals(members));
        verify(() => mockRepo.getAllActive()).called(1);
      });

      test('refreshMembers returns list from repository', () async {
        final members = <MembersCacheData>[];
        when(() => mockRepo.getAllActive()).thenAnswer((_) async => members);

        final result = await service.refreshMembers();

        expect(result, equals(members));
      });
    });
  });
}
