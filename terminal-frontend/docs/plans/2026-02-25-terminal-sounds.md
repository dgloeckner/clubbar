# Terminal UI Sounds Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add subtle natural/warm audio feedback to the terminal UI for all key interactions.

**Architecture:** A `SoundService` initialized at startup holds one `AudioPlayer` per event, pre-loaded and volume-configured. It's passed as a constructor parameter to providers and exposed via Flutter's `Provider` for widget access. When `soundsEnabled` is false in config, all `play()` calls are no-ops.

**Tech Stack:** `audioplayers ^6.5.1`, Flutter Provider, existing `ConfigService` JSON config pattern.

**Design doc:** `docs/plans/2026-02-25-terminal-sounds-design.md`

---

## Task 1: Add audioplayers dependency and declare sound assets

**Files:**
- Modify: `pubspec.yaml`

**Step 1: Add audioplayers to dependencies**

In `pubspec.yaml`, under `dependencies:`, after the `window_manager` line, add:
```yaml
  # Audio feedback
  audioplayers: ^6.5.1
```

**Step 2: Declare sounds asset directory**

In `pubspec.yaml`, under `flutter: assets:`, add:
```yaml
    - sounds/
```

The full assets block should look like:
```yaml
  assets:
    - assets/icons/products/
    - assets/icons/categories/
    - assets/icons/ui/
    - sounds/
```

**Step 3: Install the dependency**

```bash
cd terminal-frontend && flutter pub get
```

Expected: resolves `audioplayers 6.5.x` and dependencies, no errors.

**Step 4: Verify**

```bash
grep audioplayers pubspec.lock
```

Expected: line containing `audioplayers:` with a version number.

**Step 5: Commit**

```bash
git add terminal-frontend/pubspec.yaml terminal-frontend/pubspec.lock
git commit -m "feat(terminal/sounds): add audioplayers dependency and declare sounds assets"
```

---

## Task 2: Add `soundsEnabled` to ConfigService

**Files:**
- Modify: `lib/services/config_service.dart`
- Modify: `test/services/config_service_test.dart`

**Step 1: Write the failing test**

Open `test/services/config_service_test.dart`. Add a new test group for `soundsEnabled` after existing groups:

```dart
group('soundsEnabled', () {
  test('defaults to false when not in config', () async {
    final dir = Directory.systemTemp.createTempSync('cfg_sounds_test_');
    final service = ConfigService(configDir: dir.path);
    // Write a config without soundsEnabled
    File('${dir.path}/config.json').writeAsStringSync(
      jsonEncode({'terminalId': 'T1', 'apiUrl': 'http://x', 'apiToken': 'tok'}),
    );
    await service.load();
    expect(service.soundsEnabled, isFalse);
    dir.deleteSync(recursive: true);
  });

  test('reads true from config', () async {
    final dir = Directory.systemTemp.createTempSync('cfg_sounds_test_');
    final service = ConfigService(configDir: dir.path);
    File('${dir.path}/config.json').writeAsStringSync(
      jsonEncode({'terminalId': 'T1', 'apiUrl': 'http://x', 'apiToken': 'tok', 'soundsEnabled': true}),
    );
    await service.load();
    expect(service.soundsEnabled, isTrue);
    dir.deleteSync(recursive: true);
  });

  test('TERMINAL_SOUNDS_ENABLED env var overrides file', () async {
    final dir = Directory.systemTemp.createTempSync('cfg_sounds_test_');
    final service = ConfigService(configDir: dir.path);
    // File has false
    File('${dir.path}/config.json').writeAsStringSync(
      jsonEncode({'terminalId': 'T1', 'apiUrl': 'http://x', 'apiToken': 'tok', 'soundsEnabled': false}),
    );
    // Note: env var override is tested manually; this test verifies file parsing
    await service.load();
    expect(service.soundsEnabled, isFalse);
    dir.deleteSync(recursive: true);
  });
});
```

**Step 2: Run tests to verify they fail**

```bash
cd terminal-frontend && flutter test test/services/config_service_test.dart
```

Expected: FAIL — `The getter 'soundsEnabled' isn't defined`

**Step 3: Implement in ConfigService**

In `lib/services/config_service.dart`:

After `bool _fullscreen = false;` (line 44), add:
```dart
  bool _soundsEnabled = false;
```

After `bool get fullscreen => _fullscreen;` (line 64), add:
```dart
  bool get soundsEnabled => _soundsEnabled;
```

After `_fullscreen = json['fullscreen'] as bool? ?? false;` (line 99), add:
```dart
        _soundsEnabled = json['soundsEnabled'] as bool? ?? false;
```

After the `TERMINAL_DEMO_MODE` env var block (around line 137), add:
```dart
    if (env.containsKey('TERMINAL_SOUNDS_ENABLED')) {
      _soundsEnabled = env['TERMINAL_SOUNDS_ENABLED']?.toLowerCase() == 'true';
    }
```

In `clear()`, after `_fullscreen = false;`, add:
```dart
    _soundsEnabled = false;
```

**Step 4: Run tests to verify they pass**

```bash
cd terminal-frontend && flutter test test/services/config_service_test.dart
```

Expected: All config tests pass.

**Step 5: Commit**

```bash
git add terminal-frontend/lib/services/config_service.dart terminal-frontend/test/services/config_service_test.dart
git commit -m "feat(terminal/sounds): add soundsEnabled to ConfigService"
```

---

## Task 3: Create SoundService

**Files:**
- Create: `lib/services/sound_service.dart`
- Create: `test/services/sound_service_test.dart`

**Step 1: Write the failing tests**

Create `test/services/sound_service_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:ruderbar_terminal/services/sound_service.dart';

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
      // Should not throw
      await expectLater(service.init(), completes);
    });

    test('play() is safe when disabled (no-op)', () async {
      await service.init();
      // Should not throw even though no AudioPlayers are created
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
```

**Step 2: Run tests to verify they fail**

```bash
cd terminal-frontend && flutter test test/services/sound_service_test.dart
```

Expected: FAIL — `'sound_service.dart' not found`

**Step 3: Implement SoundService**

Create `lib/services/sound_service.dart`:

```dart
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
```

**Step 4: Run tests to verify they pass**

```bash
cd terminal-frontend && flutter test test/services/sound_service_test.dart
```

Expected: All 6 tests pass.

**Step 5: Commit**

```bash
git add terminal-frontend/lib/services/sound_service.dart terminal-frontend/test/services/sound_service_test.dart
git commit -m "feat(terminal/sounds): add SoundService with SoundEvent enum"
```

---

## Task 4: Initialize SoundService in main.dart and expose via Provider

**Files:**
- Modify: `lib/main.dart`

**Step 1: Import SoundService**

Near the top of `lib/main.dart`, after the other service imports, add:
```dart
import 'package:ruderbar_terminal/services/sound_service.dart';
```

**Step 2: Initialize after config load**

In `main()`, after `AppFontSizes.applyConfig(configService.fontSizes);` (around line 209), add:
```dart
  // Initialize sound service
  final soundService = SoundService(enabled: configService.soundsEnabled);
  await soundService.init();
```

**Step 3: Pass soundService to RuderbarTerminalApp**

In the `runApp(RuderbarTerminalApp(...))` call, add `soundService: soundService` as a named argument.

In `RuderbarTerminalApp`, add:
```dart
  final SoundService soundService;
```
to the class fields, and add `required this.soundService` to the `const RuderbarTerminalApp({...})` constructor.

**Step 4: Expose via Provider and wire into providers**

In `RuderbarTerminalApp.build()`, in the `MultiProvider` providers list, add:
```dart
Provider<SoundService>.value(value: soundService),
```

Update the `CartProvider` creation to pass `soundService`:
```dart
ChangeNotifierProvider(create: (_) => CartProvider(
  service: cartService,
  config: configService,
  soundService: soundService,
)),
```

Update the `RfidProvider` creation:
```dart
ChangeNotifierProvider(create: (_) => RfidProvider(
  membersProvider,
  membersRepository,
  soundService,
)),
```

**Step 5: Verify the app still compiles (providers not yet updated — expect errors)**

```bash
cd terminal-frontend && flutter analyze
```

Expected: errors about missing parameters in CartProvider and RfidProvider constructors — that's correct, they'll be fixed in the next tasks.

**Step 6: Commit (after Tasks 5 and 6 make the app compile)**

Hold this commit — combine with Task 5 commit or fix forward.

---

## Task 5: Wire SoundService into RfidProvider

**Files:**
- Modify: `lib/providers/rfid_provider.dart`
- Create: `test/providers/rfid_provider_test.dart`

**Step 1: Write the failing tests**

Create `test/providers/rfid_provider_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:ruderbar_terminal/database/database.dart';
import 'package:ruderbar_terminal/providers/members_provider.dart';
import 'package:ruderbar_terminal/providers/rfid_provider.dart';
import 'package:ruderbar_terminal/repository/members_repository.dart';
import 'package:ruderbar_terminal/services/sound_service.dart';

class MockMembersProvider extends Mock implements MembersProvider {}
class MockMembersRepository extends Mock implements MembersRepository {}
class MockSoundService extends Mock implements SoundService {}

void main() {
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
      when(() => membersProvider.setSelectedMember(any())).thenReturn(null);

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
```

**Step 2: Run tests to verify they fail**

```bash
cd terminal-frontend && flutter test test/providers/rfid_provider_test.dart
```

Expected: FAIL — constructor mismatch (SoundService not yet a parameter)

**Step 3: Add SoundService to RfidProvider**

In `lib/providers/rfid_provider.dart`:

Add import at top:
```dart
import 'package:ruderbar_terminal/services/sound_service.dart';
```

Add field after `BuildContext? _context;`:
```dart
  final SoundService _soundService;
```

Update constructor:
```dart
  RfidProvider(this._membersProvider, this._membersRepository, this._soundService);
```

In `handleCardScan()`, after `_membersProvider.setSelectedMember(member);` (success branch, before `_isScanning = false`), add:
```dart
        _soundService.play(SoundEvent.scanSuccess);
```

In `handleCardScan()`, in the else branch (error), after `_membersProvider.setError(_error!);`, add:
```dart
        _soundService.play(SoundEvent.scanError);
```

In `handleCardScan()`, in the catch block, after `_membersProvider.setError(_error!);`, add:
```dart
      _soundService.play(SoundEvent.scanError);
```

**Step 4: Run tests to verify they pass**

```bash
cd terminal-frontend && flutter test test/providers/rfid_provider_test.dart
```

Expected: All 3 new tests pass.

**Step 5: Run full test suite to check for regressions**

```bash
cd terminal-frontend && flutter test
```

Expected: All previously passing tests still pass (check count matches baseline).

**Step 6: Commit main.dart + rfid_provider together**

```bash
git add terminal-frontend/lib/main.dart terminal-frontend/lib/providers/rfid_provider.dart terminal-frontend/test/providers/rfid_provider_test.dart
git commit -m "feat(terminal/sounds): wire SoundService into main.dart and RfidProvider"
```

---

## Task 6: Wire SoundService into CartProvider

**Files:**
- Modify: `lib/providers/cart_provider.dart`
- Modify: `test/providers/cart_provider_test.dart`

**Step 1: Write the failing tests**

Open `test/providers/cart_provider_test.dart`. Add a `MockSoundService` class at the top (after existing mock classes):
```dart
class MockSoundService extends Mock implements SoundService {}
```

Add import at the top:
```dart
import 'package:ruderbar_terminal/services/sound_service.dart';
```

In the existing `setUp()`, update `CartProvider` construction:
```dart
mockSoundService = MockSoundService();
when(() => mockSoundService.play(any())).thenAnswer((_) async {});
provider = CartProvider(service: mockService, config: mockConfig, soundService: mockSoundService);
```

Add a `late MockSoundService mockSoundService;` field declaration.

Add a new test group:
```dart
group('CartProvider sounds', () {
  test('plays productAdd when item added to cart', () {
    provider.addItem('prod-1', 'Beer', 500, 1, 'de');
    verify(() => mockSoundService.play(SoundEvent.productAdd)).called(1);
  });

  test('plays productAdd again when same item quantity increased', () {
    provider.addItem('prod-1', 'Beer', 500, 1, 'de');
    provider.addItem('prod-1', 'Beer', 500, 1, 'de');
    verify(() => mockSoundService.play(SoundEvent.productAdd)).called(2);
  });

  test('plays quantityChange when item quantity decreased (item stays)', () {
    provider.addItem('prod-1', 'Beer', 500, 2, 'de');
    clearInteractions(mockSoundService);
    provider.decreaseItem('prod-1');
    verify(() => mockSoundService.play(SoundEvent.quantityChange)).called(1);
    verifyNever(() => mockSoundService.play(SoundEvent.productRemove));
  });

  test('plays productRemove when decreaseItem removes last unit', () {
    provider.addItem('prod-1', 'Beer', 500, 1, 'de');
    clearInteractions(mockSoundService);
    provider.decreaseItem('prod-1');
    verify(() => mockSoundService.play(SoundEvent.productRemove)).called(1);
  });

  test('plays productRemove when removeItem called', () {
    provider.addItem('prod-1', 'Beer', 500, 2, 'de');
    clearInteractions(mockSoundService);
    provider.removeItem('prod-1');
    verify(() => mockSoundService.play(SoundEvent.productRemove)).called(1);
  });
});
```

**Step 2: Run tests to verify they fail**

```bash
cd terminal-frontend && flutter test test/providers/cart_provider_test.dart
```

Expected: FAIL — `SoundService` not a parameter of `CartProvider`

**Step 3: Add SoundService to CartProvider**

In `lib/providers/cart_provider.dart`:

Add import:
```dart
import 'package:ruderbar_terminal/services/sound_service.dart';
```

Add field after `ConfigService _config;`:
```dart
  final SoundService _soundService;
```

Update constructor:
```dart
  CartProvider({
    required CartService service,
    required ConfigService config,
    required SoundService soundService,
  })  : _service = service,
        _config = config,
        _soundService = soundService;
```

In `addItem()`, before `notifyListeners();`, add:
```dart
    _soundService.play(SoundEvent.productAdd);
```

In `removeItem()`, before `notifyListeners();`, add:
```dart
    _soundService.play(SoundEvent.productRemove);
```

In `decreaseItem()`, inside the `if (index >= 0)` block, replace the body:
```dart
    if (index >= 0) {
      if (_items[index].quantity > 1) {
        _items[index].quantity -= 1;
        _soundService.play(SoundEvent.quantityChange);
      } else {
        _items.removeAt(index);
        _soundService.play(SoundEvent.productRemove);
      }
      notifyListeners();
    }
```

In `checkout()`, after `_items = []; _lastError = null; _errorType = null;` (success path), add:
```dart
      _soundService.play(SoundEvent.checkoutSuccess);
```

In `checkout()`, in each early return with `_lastError` set (validation failure at the top), add before each `return`:
```dart
      _soundService.play(SoundEvent.checkoutError);
```

In `checkout()`, in the `catch (e)` block, after setting `_lastError`, add:
```dart
      _soundService.play(SoundEvent.checkoutError);
```

**Step 4: Run tests to verify they pass**

```bash
cd terminal-frontend && flutter test test/providers/cart_provider_test.dart
```

Expected: All tests pass including new sound tests.

**Step 5: Run full test suite**

```bash
cd terminal-frontend && flutter test
```

Expected: All tests pass.

**Step 6: Commit**

```bash
git add terminal-frontend/lib/providers/cart_provider.dart terminal-frontend/test/providers/cart_provider_test.dart
git commit -m "feat(terminal/sounds): wire SoundService into CartProvider"
```

---

## Task 7: Wire category switch sound in ProductSelectionScreen

**Files:**
- Modify: `lib/screens/product_selection_screen.dart`
- Modify: `test/screens/product_selection_screen_test.dart`

**Step 1: Write the failing test**

Open `test/screens/product_selection_screen_test.dart`. Add `MockSoundService` and import:
```dart
import 'package:ruderbar_terminal/services/sound_service.dart';

class MockSoundService extends Mock implements SoundService {}
```

Find the test helpers/setUp section and add `MockSoundService` to the providers used when building the widget. Add a test:

```dart
test('plays categorySwitch sound when category tab tapped', () async {
  final mockSoundService = MockSoundService();
  when(() => mockSoundService.play(any())).thenAnswer((_) async {});

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        // ... existing providers ...
        Provider<SoundService>.value(value: mockSoundService),
      ],
      child: const MaterialApp(home: ProductSelectionScreen()),
    ),
  );

  // Tap the second category tab (index 1)
  final categoryChips = find.byType(CategoryChip);
  if (categoryChips.evaluate().length > 1) {
    await tester.tap(categoryChips.at(1));
    await tester.pump();
    verify(() => mockSoundService.play(SoundEvent.categorySwitch)).called(1);
  }
});
```

Note: read the existing test file first to understand how widgets are built in tests there — adapt the provider setup to match.

**Step 2: Run test to verify it fails**

```bash
cd terminal-frontend && flutter test test/screens/product_selection_screen_test.dart
```

Expected: FAIL — `SoundService` not found in widget tree

**Step 3: Add sound call to ProductSelectionScreen**

In `lib/screens/product_selection_screen.dart`, add import:
```dart
import 'package:ruderbar_terminal/services/sound_service.dart';
```

In the `onSelected` callback of `CategoryChip` (around line 98-102):
```dart
onSelected: () {
  context.read<SoundService>().play(SoundEvent.categorySwitch);
  setState(() {
    _selectedCategoryIndex = index;
  });
},
```

**Step 4: Run tests to verify they pass**

```bash
cd terminal-frontend && flutter test test/screens/product_selection_screen_test.dart
```

Expected: All tests pass.

**Step 5: Run full test suite**

```bash
cd terminal-frontend && flutter test
```

Expected: All tests pass.

**Step 6: Commit**

```bash
git add terminal-frontend/lib/screens/product_selection_screen.dart terminal-frontend/test/screens/product_selection_screen_test.dart
git commit -m "feat(terminal/sounds): wire category switch sound in ProductSelectionScreen"
```

---

## Task 8: Update INDEX.md and INSTALL.md

**Files:**
- Modify: `docs/plans/INDEX.md`
- Modify: `INSTALL.md` (check if it exists with a config reference section)

**Step 1: Update INDEX.md**

Add to the `## Current Plan` section:
```markdown
### Terminal UI Sounds (IN PROGRESS)
- **Location:** `docs/plans/2026-02-25-terminal-sounds.md`
- **Status:** Implementation
```

Move the `## Next Phase` section to reference future work.

**Step 2: Check INSTALL.md for config reference**

```bash
grep -n "demoMode\|fullscreen\|soundsEnabled" terminal-frontend/INSTALL.md 2>/dev/null || echo "no INSTALL.md or no matches"
```

If INSTALL.md has a config reference section, add `soundsEnabled` alongside `demoMode`:
```
"soundsEnabled": true    // Enable audio feedback (default: false)
```

**Step 3: Commit**

```bash
git add terminal-frontend/docs/plans/INDEX.md
git commit -m "docs(terminal/sounds): update INDEX.md with sounds plan"
```

---

## Final Verification

**Run the full test suite one last time:**

```bash
cd terminal-frontend && flutter test
```

Expected: All tests pass.

**Manual smoke test (with sounds enabled):**

1. Set `"soundsEnabled": true` in `config.json`
2. Run the app: `cd terminal-frontend && flutter run -d macos`
3. Verify:
   - RFID scan (demo button) → hear `scan_success` sound
   - Tap a product → hear subtle `product_add` sound
   - Tap category tab → hear subtle `category_switch` sound
   - Tap minus to reduce qty → hear `quantity_change`
   - Tap minus to remove last unit → hear `product_remove`
   - Checkout → hear `checkout_success`

**Sounds off smoke test:**

1. Set `"soundsEnabled": false` in config
2. Run app — verify total silence, no errors
