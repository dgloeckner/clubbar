# Fullscreen Kiosk Mode Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make the terminal app run fullscreen on Raspberry Pi via a `fullscreen` config flag, and remove all hardcoded screen size constraints.

**Architecture:** Add `fullscreen: bool` to `ConfigService` (config.json + env var override). Load config *before* window manager init so the fullscreen decision is available. Replace the current fixed 1280×720 `setSize`/`setMinimumSize`/`setMaximumSize` block with a simple `setFullScreen(true)` when the flag is set, or just `show()` otherwise. Remove the dead `AppConfig.screenWidth/screenHeight` constants that are defined but never referenced.

**Tech Stack:** Flutter `window_manager ^0.3.9`, Dart `Platform`, `ConfigService` (JSON + env vars per ADR-0019).

---

## Task 1: Remove dead screen-size constants from AppConfig

**Files:**
- Modify: `lib/config/app_config.dart` (lines 6–7)

No test needed — these constants have zero references in the codebase (verified with grep).

**Step 1: Delete the two dead lines**

In `lib/config/app_config.dart`, remove:
```dart
  static const int screenWidth = 800;
  static const int screenHeight = 480;
```

The file should look like:
```dart
class AppConfig {
  static const String appName = 'Ruderbar Terminal';
  static const String version = '0.1.0';

  // Display
  static const bool isProduction = false;

  // Sync timing
  ...
```

**Step 2: Verify no references exist**

```bash
grep -r "screenWidth\|screenHeight" lib/
```
Expected: no output.

**Step 3: Run existing tests to confirm nothing broke**

```bash
cd terminal-frontend
flutter test
```
Expected: all tests pass (same count as before).

**Step 4: Commit**

```bash
git add lib/config/app_config.dart
git commit -m "refactor: remove unused AppConfig.screenWidth/screenHeight constants"
```

---

## Task 2: Add `fullscreen` field to ConfigService (TDD)

**Files:**
- Modify: `lib/services/config_service.dart`
- Modify: `test/services/config_service_test.dart`

### Step 1: Write failing tests

Add a new group `'fullscreen configuration'` to `test/services/config_service_test.dart`, just before the closing `});` of the outer `group('ConfigService', ...)`:

```dart
    group('fullscreen configuration', () {
      test('fullscreen defaults to false', () async {
        await configService.load();
        expect(configService.fullscreen, isFalse);
      });

      test('loads fullscreen:true from config file', () async {
        final configFile = File('${tempDir.path}/config.json');
        configFile.writeAsStringSync(jsonEncode({
          'terminalId': 'Test-Terminal',
          'apiUrl': 'https://test.example.com/api',
          'apiToken': 'b' * 64,
          'fullscreen': true,
        }));

        await configService.load();

        expect(configService.fullscreen, isTrue);
      });

      test('loads fullscreen:false from config file', () async {
        final configFile = File('${tempDir.path}/config.json');
        configFile.writeAsStringSync(jsonEncode({
          'terminalId': 'Test-Terminal',
          'apiUrl': 'https://test.example.com/api',
          'apiToken': 'b' * 64,
          'fullscreen': false,
        }));

        await configService.load();

        expect(configService.fullscreen, isFalse);
      });

      test('fullscreen missing from config file defaults to false', () async {
        final configFile = File('${tempDir.path}/config.json');
        configFile.writeAsStringSync(jsonEncode({
          'terminalId': 'Test-Terminal',
          'apiUrl': 'https://test.example.com/api',
          'apiToken': 'b' * 64,
        }));

        await configService.load();

        expect(configService.fullscreen, isFalse);
      });

      test('clear resets fullscreen to false', () async {
        final configFile = File('${tempDir.path}/config.json');
        configFile.writeAsStringSync(jsonEncode({
          'terminalId': 'Test-Terminal',
          'apiUrl': 'https://test.example.com/api',
          'apiToken': 'b' * 64,
          'fullscreen': true,
        }));
        await configService.load();
        expect(configService.fullscreen, isTrue);

        await configService.clear();

        expect(configService.fullscreen, isFalse);
      });
    });
```

### Step 2: Run tests to verify they fail

```bash
cd terminal-frontend
flutter test test/services/config_service_test.dart
```
Expected: 5 new tests fail with `NoSuchMethodError: The getter 'fullscreen' was called on an instance of 'ConfigService'`.

### Step 3: Implement `fullscreen` in ConfigService

In `lib/services/config_service.dart`:

**a) Add private field** after `int _dispenserPollIntervalMs = 250;`:
```dart
  bool _fullscreen = false;
```

**b) Add getter** after `int get dispenserPollIntervalMs => _dispenserPollIntervalMs;`:
```dart
  bool get fullscreen => _fullscreen;
```

**c) Load from JSON** in the `load()` method, after the `_seedTestData` line:
```dart
        _fullscreen = json['fullscreen'] as bool? ?? false;
```

**d) Apply env var override** in the env section of `load()`, after the `TERMINAL_SEED_TEST_DATA` block:
```dart
    if (env.containsKey('TERMINAL_FULLSCREEN')) {
      _fullscreen = env['TERMINAL_FULLSCREEN']?.toLowerCase() == 'true';
    }
```

**e) Reset in `clear()`**, after `_dispenserPollIntervalMs = 250;`:
```dart
    _fullscreen = false;
```

Note: `fullscreen` is intentionally **not** written by `save()`. It is an operator/deployment setting set once via `config.json` or environment variable, not through the setup UI.

### Step 4: Run tests to verify they pass

```bash
flutter test test/services/config_service_test.dart
```
Expected: all tests pass (previous count + 5 new).

### Step 5: Run full test suite

```bash
flutter test
```
Expected: all tests pass.

### Step 6: Commit

```bash
git add lib/services/config_service.dart test/services/config_service_test.dart
git commit -m "feat: add fullscreen config field to ConfigService (JSON + env var)"
```

---

## Task 3: Update main.dart — config-first init, remove hardcoded sizes

**Files:**
- Modify: `lib/main.dart`

No new unit tests needed for `main.dart` (it's a wiring file; the behavior is covered by `ConfigService` tests and manual smoke testing on device).

### Step 1: Move config loading before window manager init

The current order in `main()` is: window manager → config. Change it to: config → window manager.

Replace the entire block from `WidgetsFlutterBinding.ensureInitialized()` through the first `await configService.load()` (lines 193–213) with:

```dart
void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Load terminal configuration first — needed to decide fullscreen mode (ADR-0019)
  final configService = ConfigService();
  await configService.load();

  // Initialize window manager for desktop (Linux/macOS/Windows)
  try {
    await windowManager.ensureInitialized();
    await windowManager.waitUntilReadyToShow();
    if (configService.fullscreen) {
      await windowManager.setFullScreen(true);
    }
    await windowManager.show();
  } catch (e) {
    // Window manager not available (mobile platform or plugin issue)
  }
```

Key changes:
- Config loads **before** window manager — no race condition.
- `waitUntilReadyToShow()` is now `await`-ed, not `.then()` — cleaner async flow.
- The three hardcoded `setSize` / `setMinimumSize` / `setMaximumSize` / `center()` calls are **gone**.
- `setFullScreen(true)` is called only when `configService.fullscreen == true`.

Remove the now-duplicate `final configService = ConfigService(); await configService.load();` lines that previously appeared after the window manager block.

### Step 2: Verify the file compiles and tests pass

```bash
flutter analyze lib/main.dart
flutter test
```
Expected: no analysis errors, all tests pass.

### Step 3: Commit

```bash
git add lib/main.dart
git commit -m "feat: fullscreen kiosk mode in main.dart; remove hardcoded 1280x720 window size"
```

---

## Task 4: Update INSTALL.md and plans INDEX.md

**Files:**
- Modify: `INSTALL.md`
- Modify: `docs/plans/INDEX.md`

### Step 1: Update section 4 of INSTALL.md

Replace the existing **Section 4 "Autostart the terminal app"** with:

```markdown
## 4. Autostart the terminal app

Create `~/.config/autostart/ruderbar-terminal.desktop`:

```ini
[Desktop Entry]
Type=Application
Name=Ruderbar Terminal
Exec=/opt/ruderbar-terminal/ruderbar_terminal
Hidden=false
X-GNOME-Autostart-enabled=true
```

The app reads its window mode from `config.json` on startup.

### Fullscreen / kiosk mode

To run the app fullscreen (recommended for production kiosk deployments), add
`"fullscreen": true` to the terminal's `config.json`:

```json
{
  "terminalId": "Ruderbar-Kühlschrank",
  "apiUrl": "https://club.example.com/api",
  "apiToken": "...",
  "fullscreen": true
}
```

Alternatively, set the environment variable `TERMINAL_FULLSCREEN=true` in the
`.desktop` file:

```ini
[Desktop Entry]
Type=Application
Name=Ruderbar Terminal
Exec=env TERMINAL_FULLSCREEN=true /opt/ruderbar-terminal/ruderbar_terminal
Hidden=false
X-GNOME-Autostart-enabled=true
```

> **Development machines:** Leave `fullscreen` unset (defaults to `false`) so
> the app opens in a normal window. Only set it on deployed kiosk hardware.
```

Also update the troubleshooting table — replace the row:
```
| App doesn't fill the screen | The app targets 1280×720; check display resolution matches or adjust in `main.dart` `windowManager.setSize()` |
```
with:
```
| App doesn't fill the screen | Set `"fullscreen": true` in `config.json` or `TERMINAL_FULLSCREEN=true` env var |
```

### Step 2: Update docs/plans/INDEX.md

Add this plan to the **Completed Plans** section:

```markdown
### Fullscreen Kiosk Mode (COMPLETED ✅)
- **Location:** `docs/plans/2026-02-22-fullscreen-kiosk-mode.md`
- **Completion Date:** 2026-02-22
- **Tasks:** 4 completed
- **Outcomes:**
  - `fullscreen` config field in ConfigService (JSON + `TERMINAL_FULLSCREEN` env var)
  - `main.dart` loads config before window init; uses `setFullScreen(true)` when enabled
  - Removed hardcoded 1280×720 window size constraints
  - Removed dead `AppConfig.screenWidth/screenHeight` constants
  - INSTALL.md updated with fullscreen setup instructions
```

### Step 3: Commit

```bash
git add INSTALL.md docs/plans/INDEX.md docs/plans/2026-02-22-fullscreen-kiosk-mode.md
git commit -m "docs: document fullscreen kiosk mode in INSTALL.md and update plans index"
```

---

## Verification

After all tasks, run the full test suite one final time:

```bash
flutter test
```

Expected: all tests pass (202+ existing + 5 new ConfigService tests).

Smoke test on device (or simulator):
1. Without `fullscreen` in config → app opens in normal window, resizable.
2. With `"fullscreen": true` in config → app fills the screen, no title bar.
