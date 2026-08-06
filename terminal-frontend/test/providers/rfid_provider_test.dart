import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/controllers/session_controller.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/models/scan_hint.dart';
import 'package:clubbar_terminal/models/terminal_error.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/services/sound_service.dart';

class MockMembersProvider extends Mock implements MembersProvider {}
class MockMembersRepository extends Mock implements MembersRepository {}
class MockSoundService extends Mock implements SoundService {}
class MockSessionController extends Mock implements SessionController {}

void main() {
  setUpAll(() {
    registerFallbackValue(SoundEvent.scanSuccess);
    registerFallbackValue(TerminalErrorKey.unknownCard);
    registerFallbackValue(MembersCacheData(
      id: 'fallback',
      cardUid: null,
      firstName: 'F',
      lastName: 'B',
      preferredLanguage: 'de',
      isActive: 1,
      isSepaValid: 1,
      balanceCents: 0,
      updatedAt: '2025-01-01T00:00:00Z',
    ));
  });

  late MockMembersProvider membersProvider;
  late MockMembersRepository membersRepository;
  late MockSoundService soundService;
  late MockSessionController sessionController;
  late RfidProvider provider;

  MembersCacheData member(String id) => MembersCacheData(
        id: id,
        cardUid: 'card-$id',
        firstName: 'Test',
        lastName: 'User',
        preferredLanguage: 'de',
        isActive: 1,
        isSepaValid: 1,
        balanceCents: 0,
        updatedAt: '2025-01-01T00:00:00Z',
      );

  setUp(() {
    membersProvider = MockMembersProvider();
    membersRepository = MockMembersRepository();
    soundService = MockSoundService();
    sessionController = MockSessionController();
    when(() => sessionController.startSession(any()))
        .thenAnswer((_) async => SessionStartResult.started);
    when(() => sessionController.isCriticalOperationInFlight).thenReturn(false);
    when(() => sessionController.endSession()).thenReturn(true);
    when(() => sessionController.recordActivity()).thenReturn(null);
    provider = RfidProvider(
        membersProvider, membersRepository, soundService, sessionController);
    when(() => soundService.play(any())).thenAnswer((_) async {});
  });

  group('RfidProvider sounds', () {
    test('plays scanSuccess on successful card scan', () async {
      final member = MembersCacheData(
        id: 'member-1',
        cardUid: 'card-123',
        firstName: 'Test',
        lastName: 'User',
        preferredLanguage: 'de',
        isActive: 1,
        isSepaValid: 1,
        balanceCents: 0,
        updatedAt: '2025-01-01T00:00:00Z',
      );
      when(() => membersRepository.findByCardUid(any()))
          .thenAnswer((_) async => (member, null));
      when(() => membersProvider.setSelectedMember(any())).thenAnswer((_) async {});

      await provider.handleCardScan('card-123');

      verify(() => soundService.play(SoundEvent.scanSuccess)).called(1);
    });

    test('plays scanError when card not found', () async {
      when(() => membersRepository.findByCardUid(any()))
          .thenAnswer((_) async => (null, TerminalErrorKey.unknownCard));
      when(() => membersProvider.setError(any())).thenReturn(null);

      await provider.handleCardScan('unknown-card');

      verify(() => soundService.play(SoundEvent.scanError)).called(1);
    });

    test('plays scanError on exception', () async {
      when(() => membersRepository.findByCardUid(any()))
          .thenThrow(Exception('DB error'));
      when(() => membersProvider.setError(any())).thenReturn(null);

      await provider.handleCardScan('card-123');

      verify(() => soundService.play(SoundEvent.scanError)).called(1);
    });
  });

  group('RfidProvider error signalling', () {
    test('a repeat scan of the same rejected card is a distinct occurrence',
        () async {
      when(() => membersRepository.findByCardUid(any()))
          .thenAnswer((_) async => (null, TerminalErrorKey.unknownCard));
      when(() => membersProvider.setError(any())).thenReturn(null);

      await provider.handleCardScan('unknown-card');
      final first = provider.error;

      await provider.handleCardScan('unknown-card');
      final second = provider.error;

      // Same key, but not equal — the UI re-shows the banner on change, so an
      // equal repeat would be silently swallowed (see issue #17).
      expect(first!.key, TerminalErrorKey.unknownCard);
      expect(second!.key, TerminalErrorKey.unknownCard);
      expect(second, isNot(equals(first)));
    });
  });

  // Issue #18: the provider is where every input path converges, so it is the
  // one place that has to guarantee a canonical UID reaches the lookup.
  group('RfidProvider card UID normalization', () {
    test('a lower-case scan is looked up canonically', () async {
      when(() => membersRepository.findByCardUid(any()))
          .thenAnswer((_) async => (member('member-a'), null));

      await provider.handleCardScan('abcd1234');

      verify(() => membersRepository.findByCardUid('ABCD1234')).called(1);
    });

    test('the reader\'s surrounding whitespace never reaches the lookup',
        () async {
      when(() => membersRepository.findByCardUid(any()))
          .thenAnswer((_) async => (member('member-a'), null));

      await provider.handleCardScan('  AbCd1234\t');

      verify(() => membersRepository.findByCardUid('ABCD1234')).called(1);
    });
  });

  // Issue #26 / ADR-0027 amendment 2: scans arrive on every route now, so the
  // provider — not the screen that happens to be mounted — owns the policy.
  group('RfidProvider per-route scan policy', () {
    test('rule 7: a scan during a critical operation is refused, not queued',
        () async {
      when(() => sessionController.isCriticalOperationInFlight).thenReturn(true);

      await provider.handleCardScan('card-123');

      expect(provider.hint?.key, ScanHintKey.transactionInProgress);
      expect(provider.error, isNull);
      verify(() => soundService.play(SoundEvent.scanError)).called(1);
      // Never looked the card up — a refused scan does not touch the session.
      verifyNever(() => membersRepository.findByCardUid(any()));
      verifyNever(() => sessionController.startSession(any()));
    });

    test('rule 3: a foreign card during an active session is rejected with a '
        'log-out-first hint', () async {
      when(() => membersRepository.findByCardUid(any()))
          .thenAnswer((_) async => (member('member-b'), null));
      when(() => sessionController.startSession(any()))
          .thenAnswer((_) async => SessionStartResult.rejectedActiveSession);

      await provider.handleCardScan('card-member-b');

      expect(provider.hint?.key, ScanHintKey.logOutFirst);
      expect(provider.detectedMember, isNull);
      verify(() => soundService.play(SoundEvent.scanError)).called(1);
      verifyNever(() => sessionController.endSession());
    });

    test('rule 4: the same member re-tapping gets a hint and counts as activity',
        () async {
      when(() => membersRepository.findByCardUid(any()))
          .thenAnswer((_) async => (member('member-a'), null));
      when(() => sessionController.startSession(any()))
          .thenAnswer((_) async => SessionStartResult.sameMemberNoOp);

      await provider.handleCardScan('card-member-a');

      expect(provider.hint?.key, ScanHintKey.alreadyLoggedIn);
      expect(provider.error, isNull);
      verify(() => sessionController.recordActivity()).called(1);
      verify(() => soundService.play(SoundEvent.scanSuccess)).called(1);
      verifyNever(() => sessionController.endSession());
    });

    test('a repeat foreign tap is a distinct hint occurrence', () async {
      when(() => membersRepository.findByCardUid(any()))
          .thenAnswer((_) async => (member('member-b'), null));
      when(() => sessionController.startSession(any()))
          .thenAnswer((_) async => SessionStartResult.rejectedActiveSession);

      await provider.handleCardScan('card-member-b');
      final first = provider.hint;
      await provider.handleCardScan('card-member-b');

      expect(provider.hint, isNot(equals(first)));
    });

    test('rule 9: on the confirmation screen a valid card finalizes the receipt '
        'and starts a fresh session', () async {
      provider.locationResolver = () => '/confirmation/session-1';
      final newMember = member('member-b');
      when(() => membersRepository.findByCardUid(any()))
          .thenAnswer((_) async => (newMember, null));
      when(() => membersProvider.setSelectedMember(any()))
          .thenAnswer((_) async {});

      await provider.handleCardScan('card-member-b');

      verifyInOrder([
        () => sessionController.endSession(),
        () => sessionController.startSession(newMember),
      ]);
      expect(provider.detectedMember, newMember);
      expect(provider.hint, isNull);
      verify(() => soundService.play(SoundEvent.scanSuccess)).called(1);
    });

    test('rule 9: an invalid card on the confirmation screen does not finalize '
        'the receipt', () async {
      provider.locationResolver = () => '/confirmation/session-1';
      when(() => membersRepository.findByCardUid(any()))
          .thenAnswer((_) async => (null, TerminalErrorKey.accountInactive));
      when(() => membersProvider.setError(any())).thenReturn(null);

      await provider.handleCardScan('unknown-card');

      expect(provider.error?.key, TerminalErrorKey.accountInactive);
      verifyNever(() => sessionController.endSession());
      verifyNever(() => sessionController.startSession(any()));
    });

    test('a takeover refused by the session controller shows the wait hint',
        () async {
      provider.locationResolver = () => '/confirmation/session-1';
      when(() => membersRepository.findByCardUid(any()))
          .thenAnswer((_) async => (member('member-b'), null));
      // Rule 7 guard inside endSession() wins even if the flag flipped after
      // the scan was accepted.
      when(() => sessionController.endSession()).thenReturn(false);

      await provider.handleCardScan('card-member-b');

      expect(provider.hint?.key, ScanHintKey.transactionInProgress);
      verifyNever(() => sessionController.startSession(any()));
    });
  });
}
