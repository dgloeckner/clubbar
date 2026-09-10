import 'dart:async';

import 'package:audioplayers/audioplayers.dart';
import 'package:clubbar_terminal/utils/app_logger.dart';

enum SoundEvent {
  scanSuccess,
  scanError,
  checkoutSuccess,
  checkoutError,
  productAdd,
  productRemove,
  quantityChange,
  categorySwitch,
  dispenseSuccess,
  dispensePartial,
}

/// Builds the platform player behind one [SoundEvent]. Injectable so the
/// service can be tested without an audio stack behind it.
typedef AudioPlayerFactory = AudioPlayer Function();

/// A player in service, with the subscription that hears its failures.
class _Slot {
  final AudioPlayer player;
  final StreamSubscription<AudioEvent> errorWatch;

  _Slot(this.player, this.errorWatch);
}

/// Audio feedback for the terminal: one clip per [SoundEvent], one platform
/// player per clip.
///
/// A player is not for life. On Linux the plugin builds a GStreamer pipeline
/// per player, and a pipeline whose sink failed before it prerolled — the
/// sound server was down, or not up yet at boot — is never released: the
/// plugin answers every later play on it with "already prepared" and then
/// plays nothing, without an error. Since each event has its own player, the
/// scan chime can be dead for the evening while the cart sounds still work.
/// A fresh player starts from nothing and reconnects, so the cure is to throw
/// the old one away, which happens in three places:
///
/// - [renewPlayers], called at every login — the moment a till must be audible.
/// - A `play()` that throws.
/// - An error the plugin reports over the event stream, which is how a
///   GStreamer bus error arrives (never as an exception from `play()`).
///
/// The rebuild is lazy: marking is free, and the price — one platform call
/// to create a pipeline — is paid by the sound that is actually asked for.
/// A login therefore costs one player before the scan chime, not ten.
class SoundService {
  final bool _enabled;
  final AudioPlayerFactory _createPlayer;

  /// The player serving each event. A future rather than a player so that two
  /// overlapping plays that both find an entry stale share a single rebuild;
  /// callers never await [play], and a double tap is two plays in flight.
  /// A `null` result means the build failed; the event is then stale again.
  final Map<SoundEvent, Future<_Slot?>> _players = {};

  /// Events whose player must be replaced before its next use.
  final Set<SoundEvent> _stale = {};

  static const Map<SoundEvent, String> _files = {
    SoundEvent.scanSuccess: 'sounds/scan_success.mp3',
    SoundEvent.scanError: 'sounds/scan_error.mp3',
    SoundEvent.checkoutSuccess: 'sounds/checkout_success.mp3',
    SoundEvent.checkoutError: 'sounds/checkout_error.mp3',
    SoundEvent.productAdd: 'sounds/product_add.mp3',
    SoundEvent.productRemove: 'sounds/product_remove.mp3',
    SoundEvent.quantityChange: 'sounds/quantity_change.mp3',
    SoundEvent.categorySwitch: 'sounds/category_switch.mp3',
    // Dispensing has no dedicated clips of its own: it reuses the checkout
    // pair, since a full dispense is a success and a partial one is exactly
    // the "something needs attention" warning checkout_error already voices.
    SoundEvent.dispenseSuccess: 'sounds/checkout_success.mp3',
    SoundEvent.dispensePartial: 'sounds/checkout_error.mp3',
  };

  static const Map<SoundEvent, double> _volumes = {
    SoundEvent.scanSuccess: 0.7,
    SoundEvent.scanError: 0.6,
    SoundEvent.checkoutSuccess: 0.8,
    SoundEvent.checkoutError: 0.6,
    SoundEvent.productAdd: 0.3,
    SoundEvent.productRemove: 0.3,
    SoundEvent.quantityChange: 0.2,
    SoundEvent.categorySwitch: 0.15,
    SoundEvent.dispenseSuccess: 0.8,
    SoundEvent.dispensePartial: 0.6,
  };

  SoundService({required bool enabled, AudioPlayerFactory? createPlayer})
    : _enabled = enabled,
      _createPlayer = createPlayer ?? AudioPlayer.new;

  /// Initialize audio players. Call once at app startup.
  ///
  /// A player that fails to set up does not fail the start: it is logged,
  /// and its event is rebuilt on first use.
  Future<void> init() async {
    if (!_enabled) return;
    for (final event in SoundEvent.values) {
      _players[event] = _build(event);
    }
    await Future.wait(_players.values);
  }

  /// Start every player afresh on its next use.
  ///
  /// Called at login, before the scan chime, so a pipeline that wedged
  /// earlier in the day cannot keep a session silent. Cheap to call: nothing
  /// is built until a sound is asked for.
  void renewPlayers() {
    if (!_enabled) return;
    _stale.addAll(SoundEvent.values);
  }

  /// Play a sound. No-op when sounds are disabled or on error.
  Future<void> play(SoundEvent event) async {
    if (!_enabled) return;
    if (_stale.remove(event) || !_players.containsKey(event)) {
      _replace(event);
    }
    final slot = await _players[event];
    if (slot == null) return;
    try {
      await slot.player.stop();
      await slot.player.play(AssetSource(_files[event]!));
    } catch (e, st) {
      // Never let sound errors affect app functionality — but do not lose
      // the one message that names the cause, and do not keep a player that
      // has just proven it cannot play.
      AppLog.instance.w(
        'Sound ${event.name} failed; its player will be rebuilt on next use',
        error: e,
        stackTrace: st,
      );
      _stale.add(event);
    }
  }

  Future<void> dispose() async {
    final slots = await Future.wait(_players.values);
    _players.clear();
    _stale.clear();
    for (final slot in slots) {
      if (slot != null) await _discard(slot);
    }
  }

  /// Swap in a new player for [event]; the old one is disposed once it is
  /// known, without holding up the sound that asked for the replacement.
  void _replace(SoundEvent event) {
    final old = _players[event];
    _players[event] = _build(event);
    if (old != null) {
      unawaited(
        old.then((slot) {
          if (slot != null) return _discard(slot);
        }),
      );
    }
  }

  Future<_Slot?> _build(SoundEvent event) async {
    AudioPlayer? player;
    StreamSubscription<AudioEvent>? errorWatch;
    try {
      player = _createPlayer();
      // A GStreamer bus error is delivered here, as a stream error, possibly
      // long after play() returned successfully.
      errorWatch = player.eventStream.listen(
        (_) {},
        onError: (Object e, StackTrace st) {
          AppLog.instance.w(
            'Sound ${event.name} reported an error; its player will be '
            'rebuilt on next use',
            error: e,
            stackTrace: st,
          );
          _stale.add(event);
        },
      );
      await player.setVolume(_volumes[event]!);
      return _Slot(player, errorWatch);
    } catch (e, st) {
      AppLog.instance.w(
        'Sound ${event.name}: could not set up a player; will retry on next use',
        error: e,
        stackTrace: st,
      );
      _stale.add(event);
      await errorWatch?.cancel();
      if (player != null) await _disposeQuietly(player);
      return null;
    }
  }

  Future<void> _discard(_Slot slot) async {
    // Stop listening first: an error the dying pipeline posts on its way out
    // must not mark the replacement stale.
    await slot.errorWatch.cancel();
    await _disposeQuietly(slot.player);
  }

  Future<void> _disposeQuietly(AudioPlayer player) async {
    try {
      await player.dispose();
    } catch (e) {
      AppLog.instance.d('Sound: disposing a stale player failed: $e');
    }
  }
}
