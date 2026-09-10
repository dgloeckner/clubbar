import 'dart:async';

import 'package:audioplayers/audioplayers.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:clubbar_terminal/services/sound_service.dart';

class MockAudioPlayer extends Mock implements AudioPlayer {}

/// A player the service can drive without a platform behind it: every call
/// succeeds unless a test says otherwise, and the event stream is a controller
/// the test can push an error into — which is how the Linux plugin reports a
/// GStreamer failure (over the event channel, never as a thrown exception).
class FakePlayer {
  final MockAudioPlayer mock = MockAudioPlayer();
  final StreamController<AudioEvent> events =
      StreamController<AudioEvent>.broadcast();

  FakePlayer() {
    when(() => mock.eventStream).thenAnswer((_) => events.stream);
    when(() => mock.setVolume(any())).thenAnswer((_) async {});
    when(() => mock.stop()).thenAnswer((_) async {});
    when(() => mock.play(any())).thenAnswer((_) async {});
    when(() => mock.dispose()).thenAnswer((_) async {});
  }
}

void main() {
  setUpAll(() {
    registerFallbackValue(AssetSource('sounds/fallback.mp3'));
  });

  group('SoundEvent', () {
    test('has all expected values', () {
      expect(
        SoundEvent.values,
        containsAll([
          SoundEvent.scanSuccess,
          SoundEvent.scanError,
          SoundEvent.checkoutSuccess,
          SoundEvent.checkoutError,
          SoundEvent.productAdd,
          SoundEvent.productRemove,
          SoundEvent.quantityChange,
          SoundEvent.categorySwitch,
          SoundEvent.dispenseSuccess,
          SoundEvent.dispensePartial,
        ]),
      );
      expect(SoundEvent.values, hasLength(10));
    });
  });

  group('SoundService (disabled)', () {
    late SoundService service;
    var created = 0;

    setUp(() {
      created = 0;
      service = SoundService(
        enabled: false,
        createPlayer: () {
          created++;
          return FakePlayer().mock;
        },
      );
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
      await expectLater(service.play(SoundEvent.scanSuccess), completes);
    });

    test('renewPlayers() is safe when disabled (no-op)', () async {
      await service.init();
      service.renewPlayers();
      await service.play(SoundEvent.scanSuccess);
      expect(created, 0);
    });

    test('dispose() is safe when disabled', () async {
      await service.init();
      await expectLater(service.dispose(), completes);
    });
  });

  group('SoundService (enabled)', () {
    late SoundService service;
    late List<FakePlayer> players;

    /// The player currently serving [event]: players are built in
    /// [SoundEvent.values] order at init, so the first ten map 1:1.
    FakePlayer initial(SoundEvent event) => players[event.index];

    setUp(() async {
      players = [];
      service = SoundService(
        enabled: true,
        createPlayer: () {
          final p = FakePlayer();
          players.add(p);
          return p.mock;
        },
      );
      await service.init();
    });

    tearDown(() async {
      await service.dispose();
    });

    test(
      'init() builds one player per event at its configured volume',
      () async {
        expect(players, hasLength(SoundEvent.values.length));
        verify(
          () => initial(SoundEvent.checkoutSuccess).mock.setVolume(0.8),
        ).called(1);
        verify(
          () => initial(SoundEvent.categorySwitch).mock.setVolume(0.15),
        ).called(1);
      },
    );

    test('play() stops and replays the clip on the existing player', () async {
      await service.play(SoundEvent.scanSuccess);

      final player = initial(SoundEvent.scanSuccess).mock;
      final calls = verifyInOrder([
        () => player.stop(),
        () => player.play(captureAny()),
      ]);
      final source = calls[1].captured.single;
      expect(source, isA<AssetSource>());
      expect((source as AssetSource).path, 'sounds/scan_success.mp3');
      // No rebuild: the players from init are the ones in use.
      expect(players, hasLength(SoundEvent.values.length));
    });

    test(
      'renewPlayers() replaces a player on its next play, not before',
      () async {
        service.renewPlayers();
        // Marking alone builds nothing — the cost lands on the sound that is
        // actually asked for, so a login pays for one player, not ten.
        expect(players, hasLength(SoundEvent.values.length));

        await service.play(SoundEvent.scanSuccess);

        expect(players, hasLength(SoundEvent.values.length + 1));
        final fresh = players.last;
        verify(() => fresh.mock.setVolume(0.7)).called(1);
        verify(() => fresh.mock.play(any())).called(1);
        // The wedged one is gone, and nothing was played on it.
        final old = initial(SoundEvent.scanSuccess).mock;
        verify(() => old.dispose()).called(1);
        verifyNever(() => old.play(any()));
      },
    );

    test(
      'after renewPlayers() every event gets a fresh player, once each',
      () async {
        service.renewPlayers();

        await service.play(SoundEvent.productAdd);
        await service.play(SoundEvent.productAdd);
        await service.play(SoundEvent.checkoutSuccess);

        // productAdd rebuilt once (then reused), checkoutSuccess rebuilt once.
        expect(players, hasLength(SoundEvent.values.length + 2));
        verify(
          () => players[SoundEvent.values.length].mock.play(any()),
        ).called(2);
        verify(() => players.last.mock.play(any())).called(1);
      },
    );

    test(
      'two overlapping plays after renewPlayers() share one rebuild',
      () async {
        service.renewPlayers();

        // Callers never await play(); a double tap is two plays in flight.
        await Future.wait([
          service.play(SoundEvent.quantityChange),
          service.play(SoundEvent.quantityChange),
        ]);

        expect(players, hasLength(SoundEvent.values.length + 1));
        verify(() => players.last.mock.play(any())).called(2);
      },
    );

    test(
      'a play() that throws is swallowed and the player is rebuilt next time',
      () async {
        final broken = initial(SoundEvent.scanError).mock;
        when(() => broken.play(any())).thenThrow(
          Exception('Failed to set source (Domain: gst-resource-error-quark)'),
        );

        await expectLater(service.play(SoundEvent.scanError), completes);
        await service.play(SoundEvent.scanError);

        expect(players, hasLength(SoundEvent.values.length + 1));
        verify(() => broken.dispose()).called(1);
        verify(() => players.last.mock.play(any())).called(1);
      },
    );

    test(
      'an error reported on the event stream marks the player for rebuild',
      () async {
        // The plugin's way of saying a GStreamer bus error happened, possibly
        // long after play() returned successfully.
        initial(
          SoundEvent.checkoutSuccess,
        ).events.addError(Exception('Disconnected: Connection terminated'));
        await Future<void>.delayed(Duration.zero);

        await service.play(SoundEvent.checkoutSuccess);

        expect(players, hasLength(SoundEvent.values.length + 1));
        verify(
          () => initial(SoundEvent.checkoutSuccess).mock.dispose(),
        ).called(1);
        verify(() => players.last.mock.play(any())).called(1);
      },
    );

    test('an error from a player already replaced does not trigger another '
        'rebuild', () async {
      service.renewPlayers();
      await service.play(SoundEvent.scanSuccess);
      final replaced = initial(SoundEvent.scanSuccess);

      replaced.events.addError(Exception('late error from the old pipeline'));
      await Future<void>.delayed(Duration.zero);
      await service.play(SoundEvent.scanSuccess);

      expect(players, hasLength(SoundEvent.values.length + 1));
    });

    test(
      'a player whose setup fails is disposed and retried on the next play',
      () async {
        final own = <FakePlayer>[];
        final flaky = SoundService(
          enabled: true,
          createPlayer: () {
            final p = FakePlayer();
            own.add(p);
            if (own.length == 1) {
              when(
                () => p.mock.setVolume(any()),
              ).thenThrow(Exception('no platform'));
            }
            return p.mock;
          },
        );
        addTearDown(flaky.dispose);

        // init() must survive one player failing to set up.
        await expectLater(flaky.init(), completes);
        expect(own, hasLength(SoundEvent.values.length));
        verify(() => own.first.mock.dispose()).called(1);

        // The first event's first play rebuilds, and this time succeeds.
        await flaky.play(SoundEvent.values.first);

        expect(own, hasLength(SoundEvent.values.length + 1));
        verify(() => own.last.mock.play(any())).called(1);
      },
    );

    test('dispose() disposes every player and stops watching them', () async {
      service.renewPlayers();
      await service.play(SoundEvent.scanSuccess);

      await service.dispose();

      for (final p in players) {
        verify(() => p.mock.dispose()).called(1);
      }
      // A late error after dispose is nobody's business.
      players.first.events.addError(Exception('late'));
      await Future<void>.delayed(Duration.zero);
    });
  });
}
