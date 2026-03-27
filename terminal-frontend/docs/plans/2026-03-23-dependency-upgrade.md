# Dependency Upgrade — Terminal Frontend Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade all outdated Flutter/Dart dependencies in the terminal frontend to their latest available versions, fixing any breaking API changes.

**Architecture:** Group dependencies by risk level and upgrade in batches. Each batch ends with `flutter analyze` and `flutter test` to catch regressions immediately. High-risk packages (go_router 12→17, sqlite3 2→3, window_manager 0.3→0.5) get dedicated tasks with explicit migration guidance.

**Tech Stack:** Flutter/Dart, `pubspec.yaml` version constraints, `build_runner` for code generation (drift, chopper, json_serializable).

---

## Upgrade Summary

| Group | Packages | Risk |
|-------|----------|------|
| A | chopper, uuid, flutter_svg, logger, audioplayers, json_annotation, json_serializable, build_runner | Low — patch/minor |
| B | drift, drift_dev, sqflite_common_ffi | Medium — re-run codegen required |
| C | window_manager 0.3.9 → 0.5.1 | Medium — `waitUntilReadyToShow` API changed |
| D | sqlite3 2.9.4 → 3.2.0, sqlite3_flutter_libs 0.5.41 → 0.6.0+eol | Medium — major sqlite3 version |
| E | go_router 12.1.3 → 17.1.0 | High — `state.matchedLocation` removed |
| — | screen_retriever, ffi, native_toolchain_c, dart_style, vector_graphics | Transitive — updated automatically by `flutter pub upgrade`, no pubspec changes needed |

All commands run from `terminal-frontend/` unless stated otherwise.

---

## Chunk 1: Baseline and Low-Risk Patches

### Task 1: Establish Baseline

**Files:** (read-only)

- [ ] **Step 1: Run all tests and record baseline count**

```bash
cd terminal-frontend
flutter test --reporter=compact 2>&1 | tail -5
```

Expected: output shows passing count (e.g., `310 passed, 5 failed` from known pre-existing failures).
**Write down this exact number** — any new failure below baseline is a regression to investigate.

- [ ] **Step 2: Verify clean analysis**

```bash
flutter analyze
```

Expected: `No issues found!` or a fixed set of known warnings. Record any existing warnings so you can distinguish old from new.

---

### Task 2: Group A — Patch/Minor Updates (No API Changes Expected)

**Files:**
- Modify: `pubspec.yaml`

Packages: `chopper ^8.0.0→^8.5.1`, `flutter_svg ^2.0.0→^2.2.4`, `logger ^2.6.2→^2.7.0`, `audioplayers ^6.5.1→^6.6.0`, `uuid ^4.5.2→^4.5.3`, `json_annotation ^4.9.0→^4.11.0`, `json_serializable ^6.8.0→^6.13.1`, `build_runner ^2.10.5→^2.13.1`

Note: `chopper_generator: ^8.0.0` already satisfies 8.6.1 — no pubspec change needed for it; `flutter pub upgrade` will resolve it.

- [ ] **Step 1: Update pubspec.yaml — Group A packages**

In `pubspec.yaml`, change these lines:

```yaml
# Before → After
chopper: ^8.0.0             → chopper: ^8.5.1
flutter_svg: ^2.0.0         → flutter_svg: ^2.2.4
logger: ^2.6.2              → logger: ^2.7.0
audioplayers: ^6.5.1        → audioplayers: ^6.6.0
uuid: ^4.5.2                → uuid: ^4.5.3
json_annotation: ^4.9.0     → json_annotation: ^4.11.0
# dev_dependencies:
json_serializable: ^6.8.0   → json_serializable: ^6.13.1
build_runner: ^2.10.5       → build_runner: ^2.13.1
```

- [ ] **Step 2: Resolve updated packages**

```bash
flutter pub upgrade
```

Expected: Resolves without conflicts. If any package conflicts appear, check the error and loosen/tighten the constraint.

- [ ] **Step 3: Check for compile errors**

```bash
flutter analyze
```

Expected: Same as baseline (no new issues).

- [ ] **Step 4: Run tests**

```bash
flutter test --reporter=compact 2>&1 | tail -5
```

Expected: Passing count ≥ baseline. Zero new failures.

- [ ] **Step 5: Commit**

```bash
git add pubspec.yaml pubspec.lock
git commit -m "chore(terminal): upgrade patch/minor deps (chopper, svg, logger, audioplayers, json, build_runner)"
```

---

### Task 3: Group B — Drift Ecosystem + Regenerate Code

**Files:**
- Modify: `pubspec.yaml`
- Regenerate: `lib/database/database.g.dart`
- Regenerate: `lib/generated/terminal.swagger.dart` and related files

Packages: `drift ^2.30.1→^2.32.0`, `sqflite_common_ffi ^2.3.7+1→^2.4.0+2`, `drift_dev ^2.30.1→^2.32.0`

- [ ] **Step 1: Update pubspec.yaml — Drift packages**

```yaml
# dependencies:
drift: ^2.30.1              → drift: ^2.32.0
sqflite_common_ffi: ^2.3.7+1 → sqflite_common_ffi: ^2.4.0+2
# dev_dependencies:
drift_dev: ^2.30.1          → drift_dev: ^2.32.0
```

- [ ] **Step 2: Resolve packages**

```bash
flutter pub upgrade
```

- [ ] **Step 3: Regenerate all code (drift + chopper + json_serializable)**

```bash
dart run build_runner build --delete-conflicting-outputs
```

Expected: Completes without errors. Updated `.g.dart` and `.swagger.dart` files in `lib/database/` and `lib/generated/`.

If build_runner errors on drift schema: check the drift 2.32 changelog at https://drift.simonbinder.eu/migrations/ — drift minor releases rarely have breaking API changes.

- [ ] **Step 4: Analyze and test**

```bash
flutter analyze
flutter test --reporter=compact 2>&1 | tail -5
```

Expected: Clean analysis, passing count ≥ baseline.

- [ ] **Step 5: Commit (include generated files)**

```bash
git add pubspec.yaml pubspec.lock lib/database/ lib/generated/
git commit -m "chore(terminal): upgrade drift 2.32 + sqflite_common_ffi 2.4, regenerate code"
```

---

## Chunk 2: Medium-Risk Package Upgrades

### Task 4: Group C — window_manager 0.3.9 → 0.5.1

**Files:**
- Modify: `pubspec.yaml`
- Modify: `lib/main.dart` (window init block, lines ~225–234)

**Known breaking change:** In window_manager 0.4.0, `waitUntilReadyToShow()` changed from an async method to a synchronous one that takes a callback. The `await` form no longer compiles.

Current code in `main.dart`:
```dart
await windowManager.ensureInitialized();
await windowManager.waitUntilReadyToShow();    // ← BREAKS in 0.4+
if (configService.fullscreen) {
  await windowManager.setFullScreen(true);
}
await windowManager.show();
```

- [ ] **Step 1: Update pubspec.yaml**

```yaml
window_manager: ^0.3.9  →  window_manager: ^0.5.1
```

- [ ] **Step 2: Resolve packages**

```bash
flutter pub upgrade
```

- [ ] **Step 3: Run flutter analyze to confirm the exact error**

```bash
flutter analyze
```

Expected error in `lib/main.dart`:
`The method 'waitUntilReadyToShow' isn't defined...` or type mismatch on the return value.

- [ ] **Step 4: Update main.dart window init block**

In `lib/main.dart`, replace the window manager initialization block with the 0.4+ callback pattern:

**Before (lines ~224–234):**
```dart
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

**After:**
```dart
try {
  await windowManager.ensureInitialized();
  // NOTE: waitUntilReadyToShow() in 0.4+ takes a callback — it is no longer async.
  // The callback executes asynchronously relative to the rest of main(), so runApp()
  // will proceed concurrently with window setup. This is expected and correct.
  windowManager.waitUntilReadyToShow(null, () async {
    if (configService.fullscreen) {
      await windowManager.setFullScreen(true);
    }
    await windowManager.show();
  });
} catch (e) {
  // Window manager not available (mobile platform or plugin issue)
}
```

- [ ] **Step 5: Verify and test**

```bash
flutter analyze
flutter test --reporter=compact 2>&1 | tail -5
```

Expected: Clean analysis. Passing count ≥ baseline.

- [ ] **Step 6: Commit**

```bash
git add pubspec.yaml pubspec.lock lib/main.dart
git commit -m "chore(terminal): upgrade window_manager 0.3.9 → 0.5.1, update waitUntilReadyToShow callback pattern"
```

---

### Task 5: Group D — sqlite3 2.x → 3.x + sqlite3_flutter_libs 0.6.0+eol

**Files:**
- Modify: `pubspec.yaml`

**Context on `+eol`:** The version string `0.6.0+eol` uses `+eol` as build metadata, signalling the package publisher considers this the last planned release. The package still functions — this is a heads-up to plan future migration. Accept this version now and track as a future cleanup item if the package is later removed from pub.dev.

`sqlite3` 3.x is a major version bump but drift 2.32 supports it. No code changes expected — drift abstracts the sqlite3 API.

- [ ] **Step 1: Update pubspec.yaml**

```yaml
sqlite3_flutter_libs: ^0.5.41  →  sqlite3_flutter_libs: ^0.6.0
```

Note: `sqlite3` itself is a transitive dependency of `drift` and `sqlite3_flutter_libs` — do not pin it directly in pubspec.yaml. It will resolve to 3.x automatically.

- [ ] **Step 2: Resolve packages and verify resolved versions**

```bash
flutter pub upgrade
flutter pub deps | grep sqlite3
```

Expected: `sqlite3 3.x.x` and `sqlite3_flutter_libs 0.6.0+eol` both resolved.

- [ ] **Step 3: Run database-focused tests**

```bash
flutter test test/database_test.dart test/repository_test.dart test/sync_service_test.dart --reporter=expanded
```

Expected: All database, repository, and sync service tests pass. These exercise the SQLite layer most directly.

- [ ] **Step 4: Full test suite**

```bash
flutter test --reporter=compact 2>&1 | tail -5
```

Expected: Passing count ≥ baseline.

- [ ] **Step 5: Commit**

```bash
git add pubspec.yaml pubspec.lock
git commit -m "chore(terminal): upgrade sqlite3_flutter_libs 0.6.0+eol (sqlite3 3.x transitive)"
```

---

## Chunk 3: High-Risk — go_router 12 → 17

### Task 6: Group E — go_router 12.1.3 → 17.1.0

**Files:**
- Modify: `pubspec.yaml`
- Modify: `lib/config/app_router.dart` (redirect uses `state.matchedLocation`)
- Possibly modify: `lib/screens/shopping_cart_screen.dart`, `lib/screens/product_selection_screen.dart`, `lib/screens/checkout_confirmation_screen.dart`, `lib/providers/rfid_provider.dart` (these use `context.go()` which is stable)

**Known breaking change:** `GoRouterState.matchedLocation` was deprecated in go_router 13 and removed by 17. The replacement is `state.uri.path` (type `String`, non-nullable — prefer this over `state.fullPath` which is `String?`).

The `redirect` callback signature, `ShellRoute(builder: ...)`, `GoRoute(pageBuilder: ...)`, `state.pathParameters`, and `context.go()` are all unchanged.

- [ ] **Step 1: Update pubspec.yaml**

```yaml
go_router: ^12.0.0  →  go_router: ^17.1.0
```

- [ ] **Step 2: Resolve packages**

```bash
flutter pub upgrade
```

- [ ] **Step 3: Run flutter analyze and record all errors**

```bash
flutter analyze 2>&1
```

Expected errors in `lib/config/app_router.dart`:
- `The getter 'matchedLocation' isn't defined for the type 'GoRouterState'`

Note any other errors too before fixing.

- [ ] **Step 4: Fix app_router.dart — replace matchedLocation with state.uri.path**

In `lib/config/app_router.dart`, replace all uses of `state.matchedLocation` with `state.uri.path`:

**Before (lines ~22–35):**
```dart
if (selectedMember != null &&
    !state.matchedLocation.startsWith('/products') &&
    !state.matchedLocation.startsWith('/cart') &&
    !state.matchedLocation.startsWith('/member-details') &&
    !state.matchedLocation.startsWith('/confirmation')) {
  return '/products';
}

if (selectedMember == null &&
    (state.matchedLocation.startsWith('/products') ||
        state.matchedLocation.startsWith('/cart') ||
        state.matchedLocation.startsWith('/member-details'))) {
  return '/idle';
}
```

**After:**
```dart
final path = state.uri.path;
if (selectedMember != null &&
    !path.startsWith('/products') &&
    !path.startsWith('/cart') &&
    !path.startsWith('/member-details') &&
    !path.startsWith('/confirmation')) {
  return '/products';
}

if (selectedMember == null &&
    (path.startsWith('/products') ||
        path.startsWith('/cart') ||
        path.startsWith('/member-details'))) {
  return '/idle';
}
```

- [ ] **Step 5: Fix any remaining errors from flutter analyze**

```bash
flutter analyze
```

If additional errors appear (e.g., `ShellRoute` API change, `CustomTransitionPage` moved):
- Search the error message for the class/method name
- Check the go_router 17.x CHANGELOG at https://pub.dev/packages/go_router/changelog for the migration

Repeat analyze → fix until `No issues found!`.

- [ ] **Step 6: Run navigation tests**

```bash
flutter test test/navigation/ --reporter=expanded
```

Expected: All navigation tests pass. The test at `test/navigation/app_navigation_test.dart` exercises the router config directly — it should pass if the redirect logic is correct.

- [ ] **Step 7: Run full test suite**

```bash
flutter test --reporter=compact 2>&1 | tail -5
```

Expected: Passing count ≥ baseline.

- [ ] **Step 8: Commit**

```bash
git add pubspec.yaml pubspec.lock lib/config/app_router.dart
git commit -m "chore(terminal): upgrade go_router 12 → 17, replace matchedLocation with state.uri.path"
```

---

## Chunk 4: Final Verification

### Task 7: Full Verification Run

- [ ] **Step 1: Run complete test suite**

```bash
flutter test --reporter=expanded 2>&1 | tail -30
```

Expected: All tests pass. Count matches or exceeds baseline.

- [ ] **Step 2: Final clean analysis**

```bash
flutter analyze
```

Expected: `No issues found!`

- [ ] **Step 3: Update plans/INDEX.md**

Add entry to `terminal-frontend/docs/plans/INDEX.md` under Completed Plans:

```markdown
### Dependency Upgrade 2026-03-23 (COMPLETED ✅)
- **Location:** `docs/plans/2026-03-23-dependency-upgrade.md`
- **Completion Date:** 2026-03-23
- **Key changes:** go_router 12→17 (matchedLocation→uri.path), window_manager 0.3→0.5 (callback init), sqlite3 2→3, drift 2.32, audioplayers 6.6, build_runner 2.13
```

- [ ] **Step 4: Final commit**

```bash
git add docs/plans/INDEX.md
git commit -m "docs(terminal): mark dependency upgrade plan complete"
```
