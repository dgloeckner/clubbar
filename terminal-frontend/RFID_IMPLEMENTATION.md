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
   - Hidden TextField to capture USB keyboard input
   - Auto-focus management (prevents focus loss)
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
3. Hidden TextField captures input (always focused)
   ↓
4. TextField's onSubmitted callback fires
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

### Hidden TextField Details

The hidden TextField is:
- **Positioned off-screen** (`left: -1000`) but still focusable
- **Always focused** via focus listener (re-focuses if lost)
- **Transparent styling** (invisible to user)
- **Auto-focus on mount** via `autofocus: true`
- **Re-focuses after scan** to capture next card

This approach works because USB RFID readers emulate a keyboard, typing the card UID followed by Enter when a card is scanned.

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

3. **Check hidden TextField focus**:
   - Add debug print in `onSubmitted` callback
   - Scan card
   - Verify callback fires with UID

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

### Disabling Auto-Focus

If auto-focus interferes with other inputs, disable focus listener in `idle_waiting_screen.dart`:

```dart
@override
void initState() {
  super.initState();
  // Comment out focus listener
  // _rfidFocusNode.addListener(() { ... });
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
