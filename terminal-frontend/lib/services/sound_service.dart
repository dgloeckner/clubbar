import 'package:audioplayers/audioplayers.dart';

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

class SoundService {
  final bool _enabled;
  final Map<SoundEvent, AudioPlayer> _players = {};

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

  SoundService({required bool enabled}) : _enabled = enabled;

  /// Initialize audio players. Call once at app startup.
  Future<void> init() async {
    if (!_enabled) return;
    for (final event in SoundEvent.values) {
      final player = AudioPlayer();
      await player.setVolume(_volumes[event]!);
      _players[event] = player;
    }
  }

  /// Play a sound. No-op when sounds are disabled or on error.
  Future<void> play(SoundEvent event) async {
    if (!_enabled) return;
    final player = _players[event];
    if (player == null) return;
    try {
      await player.stop();
      await player.play(AssetSource(_files[event]!));
    } catch (_) {
      // Never let sound errors affect app functionality
    }
  }

  Future<void> dispose() async {
    for (final player in _players.values) {
      await player.dispose();
    }
    _players.clear();
  }
}
