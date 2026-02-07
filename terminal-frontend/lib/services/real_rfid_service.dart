import 'dart:async';

/// Real RFID service for USB keyboard emulation readers.
///
/// Most USB RFID/NFC readers act as a keyboard (HID device) that types the
/// card UID followed by Enter when a card is scanned. This service provides
/// a stream-based API to capture those scans.
///
/// Usage:
/// 1. Create a hidden TextField in your UI
/// 2. When TextField's onSubmitted is called, emit the UID to the stream
/// 3. Listen to cardScans stream to handle detected cards
class RealRfidService {
  final StreamController<String> _scanController = StreamController<String>.broadcast();

  /// Stream of card UIDs detected by the RFID reader.
  /// Each emission represents a complete card scan (UID + Enter key).
  Stream<String> get cardScans => _scanController.stream;

  /// Emit a card UID to the stream (called by UI when TextField receives input).
  /// This should be called from TextField's onSubmitted callback.
  void emitScan(String cardUid) {
    final trimmedUid = cardUid.trim().toUpperCase();
    if (trimmedUid.isNotEmpty) {
      _scanController.add(trimmedUid);
    }
  }

  /// Clean up resources when service is no longer needed.
  void dispose() {
    _scanController.close();
  }
}
