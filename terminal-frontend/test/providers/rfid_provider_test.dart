import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/database/database.dart';
import 'package:clubbar_terminal/providers/members_provider.dart';
import 'package:clubbar_terminal/providers/rfid_provider.dart';
import 'package:clubbar_terminal/repository/members_repository.dart';
import 'package:clubbar_terminal/services/sound_service.dart';

class MockMembersProvider extends Mock implements MembersProvider {}
class MockMembersRepository extends Mock implements MembersRepository {}
class MockSoundService extends Mock implements SoundService {}

void main() {
  setUpAll(() {
    registerFallbackValue(SoundEvent.scanSuccess);
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
  late RfidProvider provider;

  setUp(() {
    membersProvider = MockMembersProvider();
    membersRepository = MockMembersRepository();
    soundService = MockSoundService();
    provider = RfidProvider(membersProvider, membersRepository, soundService);
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
          .thenAnswer((_) async => (null, 'rfidErrorUnknownCard'));
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
}
