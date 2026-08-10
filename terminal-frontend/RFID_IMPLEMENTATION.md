# RFID Card Scanning Implementation

## Overview

The terminal frontend now supports **real RFID/NFC card scanning** for member identification using USB keyboard emulation readers. This is the most common and reliable method for integrating RFID readers into POS systems.

## Architecture

### Components

1. **RealRfidService** (`lib/services/real_rfid_service.dart`)
   - Stream-based API for card scan events
   - Normalizes card UIDs (trim, uppercase)
   - Broadcast stream supports multiple listeners

2. **RfidProvider** (`lib/providers/rfid_provider.dart`)
   - State management for scanning flow
   - Listens to card scan stream
   - Looks up members by card UID
   - Validates member status (active, SEPA valid)
   - Navigates to product selection on success
   - Displays errors for invalid/unknown cards

3. **IdleWaitingScreen** (`lib/screens/idle_waiting_screen.dart`)
   - `HardwareKeyboard.instance` handler captures USB keyboard input globally
   - Works on Linux/Wayland and macOS (no widget focus dependency)
   - Buffers characters, emits on Enter
   - Error display for scan failures
   - Optional demo button for testing without hardware

4. **MembersRepository** (`lib/repository/members_repository.dart`)
   - `findByCardUid(cardUid)` method for fast lookup
   - Validates member is active
   - Validates SEPA mandate is valid
   - Returns error messages for failed lookups

## How It Works

### USB Keyboard Emulation Flow

```
1. RFID reader detects card
   ↓
2. Reader types card UID + Enter (acts like keyboard)
   ↓
3. HardwareKeyboard handler receives key events
   ↓
4. Characters buffered; Enter triggers emitScan
   ↓
5. RfidProvider.emitScan(cardUid) called
   ↓
6. RealRfidService emits to cardScans stream
   ↓
7. RfidProvider.handleCardScan(cardUid) processes scan
   ↓
8. MembersRepository.findByCardUid(cardUid) lookup
   ↓
9. Success: Navigate to /products
   Error: Display error message
```

### HardwareKeyboard Handler Details

The keyboard handler:
- Registered via `HardwareKeyboard.instance.addHandler()` when the idle screen mounts
- Receives **all** key events regardless of which widget (if any) has focus
- Accumulates characters in a `StringBuffer`; on `Enter` emits the buffered UID
- Returns `false` (does not consume events) — other handlers continue to work
- Unregistered in `dispose()` — no resource leak
- Works on Linux/Wayland, macOS, and Windows without platform-specific code

This replaces the previous approach of a hidden `TextField` at `left: -1000`, which failed
on Linux/Wayland because the compositor does not route keyboard events to widgets outside
the visible viewport.

## Hardware Setup

### Compatible RFID Readers

Any USB HID (keyboard emulation) RFID/NFC reader will work, including:

- **13.56 MHz readers** (NFC, MIFARE Classic, DESFire)
- **125 kHz readers** (EM4100, EM4102 proximity cards)
- Common models:
  - ACR122U (NFC reader/writer)
  - ITEAD PN532 (NFC module with USB adapter)
  - Generic USB RFID readers from Amazon/AliExpress

### Reader Configuration

Most USB RFID readers can be configured to:

1. **Output format**: Card UID only (no prefix/suffix)
2. **Terminator**: Send Enter key after UID
3. **Case**: Uppercase or lowercase (service normalizes to uppercase)
4. **Separator**: None (just the raw UID)

Example UID formats:
- `0003195661` (10 digits, decimal)
- `AB12CD34` (8 characters, hex)
- `04:1A:2B:3C:4D` (colon-separated hex)

The service accepts any format and normalizes it (trim, uppercase).

### Testing Without Hardware

Use the **Demo Button** on the idle screen to simulate a card scan:
- Picks first active member from synced database
- Falls back to hardcoded mock member if DB is empty
- Useful for development and testing

## Member Database Requirements

### Backend Sync

Members must be synced from backend to local database with:
- `id` (UUID)
- `card_uid` (unique, nullable)
- `first_name`, `last_name`
- `is_active` (must be true)
- `is_sepa_valid` (must be true)
- `preferred_language`
- `balance_cents`
- `updated_at`

### Card UID Management

Card UIDs are managed via the **Admin UI**:
1. Admin assigns card UID to member in admin panel
2. Backend syncs members to terminal
3. Terminal caches members locally with card_uid index
4. Terminal looks up member by card_uid on scan

## Error Handling

The system validates and provides clear error messages:

| Error | Cause | Solution |
|-------|-------|----------|
| **Unknown card** | Card UID not in database | Assign card to member in admin UI |
| **Account inactive** | Member `is_active = false` | Reactivate member in admin UI |
| **SEPA mandate missing** | Member `is_sepa_valid = false` | Complete SEPA data in admin UI |
| **Database error** | Local DB query failed | Check terminal logs, resync data |

Errors are displayed in a red banner below the idle screen title.

## Reader Health Detection

A keyboard-wedge reader is passive: it types a UID and says nothing else, so an
unplugged or dead reader used to be indistinguishable from a quiet bar — the
idle screen kept pulsing "hold your token to the scanner" forever (issue #35).

**How presence is checked** (`lib/services/rfid_reader_probe.dart`):
`InputDevicesRfidReaderProbe` reads `/proc/bus/input/devices`, the kernel's
list of attached input devices, and looks for the block matching the reader's
configured USB vendor id, product id and/or name substring. Unplugging removes
the block; plugging back in restores it, which is what makes recovery work
without a restart.

**How it is surfaced** (`lib/services/rfid_reader_health_service.dart`):
`RfidReaderHealthService` polls the probe every `rfidReader.pollIntervalSeconds`
(default 5 s) and exposes a three-state status:

| Status | Meaning | UI |
|--------|---------|-----|
| `connected` | reader seen in the device list | green *Scanner OK* pill in the header |
| `disconnected` | reader not in the device list | red *Scanner fehlt* pill; idle screen swaps to "Scanner nicht verbunden — bitte Personal informieren"; the RFID button stops pulsing |
| `unknown` | nothing configured, or a platform without `/proc/bus/input/devices` | nothing at all — the UI is exactly as it was before this feature |

The `unknown` state is deliberate: a terminal that cannot check must never
accuse its own hardware. Monitoring stays off until an installation describes
its reader in `config.json` — see
[Reader health monitoring](INSTALL.md#reader-health-monitoring) for how to read
the ids off the kiosk.

The status modal (tap the header pill) shows the reader's state and, when it is
gone, when it was last seen — and re-checks on open, so staff who just pushed
the cable back in get an answer immediately rather than waiting out a poll.

## Testing

### Unit Tests

Run RFID service tests:

```bash
cd terminal-frontend
flutter test test/services/real_rfid_service_test.dart
```

### Integration Testing with Real Hardware

1. **Connect USB RFID reader** to terminal device
2. **Start terminal app** (goes to idle screen)
3. **Scan a card** assigned to a member
4. **Verify**:
   - Terminal navigates to product selection
   - Member name appears in header
   - No error messages shown

5. **Test error cases**:
   - Scan unknown card → "Unknown card" error
   - Scan card for inactive member → "Account inactive" error
   - Scan card for member without SEPA → "SEPA mandate missing" error

### Debugging Card Scans

If scans aren't working:

1. **Check reader is recognized**:
   - Linux: `lsusb` should show reader
   - macOS: System Report → USB shows reader
   - Windows: Device Manager → Keyboards shows reader

2. **Test reader output**:
   - Open a text editor
   - Scan a card
   - Verify UID appears followed by newline

3. **Check HardwareKeyboard handler**:
   - Add `debugPrint('key: ${event.character}')` in `_onKeyEvent`
   - Scan card
   - Verify characters appear in console followed by the UID being emitted

4. **Check member lookup**:
   - Add debug print in `handleCardScan`
   - Verify card UID matches database exactly
   - Check `is_active` and `is_sepa_valid` flags

## Configuration

### Adjusting Scan Behavior

Edit `lib/providers/rfid_provider.dart`:

```dart
// Change scan validation logic
Future<void> handleCardScan(String cardUid) async {
  // Custom validation here
  final (member, error) = await _membersRepository.findByCardUid(cardUid);

  // Custom error handling here
  if (member == null) {
    _error = error ?? 'Unknown error';
    // Show custom error modal instead of banner
    showErrorDialog(context, _error!);
    return;
  }

  // Custom success handling here
  _membersProvider.setSelectedMember(member);
  context.go('/products');
}
```

## Migration from Mock to Real

The implementation preserves the mock service for testing:

- **Demo button** still works (uses `simulateCardDetection`)
- **Real scanning** uses new `RealRfidService` + hidden TextField
- Both can coexist (useful for demos without hardware)

To disable demo button, comment out in `idle_waiting_screen.dart`:

```dart
// Remove or comment out demo button Consumer<RfidProvider>
```

## Future Enhancements

Potential improvements:

1. **Serial/Bluetooth readers** - Add platform channels for non-USB readers
2. **Multi-reader support** - Handle multiple readers simultaneously
3. **Scan history** - Log recent scans for debugging
4. **Configurable timeouts** - Set max time between scans
5. **Custom UID formats** - Support prefix/suffix stripping
6. **Audio feedback** - Beep on successful scan
7. **Visual feedback** - Flash RFID button on scan

## Troubleshooting

### Card not recognized

- Verify card UID in admin UI matches reader output
- Check case sensitivity (service uppercases, but DB may be case-sensitive)
- Verify member is synced to terminal (check local DB)

### Focus keeps getting stolen

- Increase delay in focus listener (default 100ms)
- Check if other widgets request focus on tap
- Verify no modals/overlays steal focus

### Scans too slow

- Check reader configuration (baud rate, delay settings)
- Verify no network calls blocking scan handler
- Profile `handleCardScan` method for bottlenecks

### Multiple scans for one card

- Reader may send duplicate UIDs
- Add debounce logic in `handleCardScan`
- Configure reader to single-scan mode

## Support

For issues or questions:
- Check Flutter/Dart logs for errors
- Review backend sync logs for data issues
- Test reader with text editor to isolate hardware issues
- Consult RFID reader documentation for configuration
