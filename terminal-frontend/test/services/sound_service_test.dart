import 'package:flutter_test/flutter_test.dart';
import 'package:clubbar_terminal/services/sound_service.dart';

void main() {
  group('SoundEvent', () {
    test('has all expected values', () {
      expect(SoundEvent.values, containsAll([
        SoundEvent.scanSuccess,
        SoundEvent.scanError,
        SoundEvent.checkoutSuccess,
        SoundEvent.checkoutError,
        SoundEvent.productAdd,
        SoundEvent.productRemove,
        SoundEvent.quantityChange,
        SoundEvent.categorySwitch,
      ]));
      expect(SoundEvent.values, hasLength(8));
    });
  });

  group('SoundService (disabled)', () {
    late SoundService service;

    setUp(() {
      service = SoundService(enabled: false);
    });

    tearDown(() async {
      await service.dispose();
    });

    test('can be created with enabled=false', () {
      expect(service, isNotNull);
    });

    test('init() is safe when disabled', () async {
      await expectLater(service.init(), completes);
    });

    test('play() is safe when disabled (no-op)', () async {
      await service.init();
      await expectLater(
        service.play(SoundEvent.scanSuccess),
        completes,
      );
    });

    test('dispose() is safe when disabled', () async {
      await service.init();
      await expectLater(service.dispose(), completes);
    });
  });
}
