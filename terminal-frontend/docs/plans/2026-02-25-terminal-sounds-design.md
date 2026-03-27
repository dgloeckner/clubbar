# Terminal UI Sound Design
**Date:** 2026-02-25

## Overview

Add subtle, natural/warm audio feedback to the terminal UI to enhance the user experience with non-intrusive sound cues at key interaction points.

## Design Decisions

- **Style:** Natural/warm — real instrument samples (marimba, xylophone, bells, wooden taps), not synthetic beeps
- **Coverage:** Full — all interactions have sound, with a clear volume hierarchy
- **Control:** Config flag `sounds_enabled` in `app_config.json` (alongside `demo_mode`)
- **Package:** `audioplayers ^6.x` — lightweight, desktop-compatible, good for short UI clips
- **Format:** MP3 128kbps mono, sourced from Freesound.org under CC0 license

## Sound Inventory

| Tier | Event | File | Duration | Volume |
|------|-------|------|----------|--------|
| High | RFID scan success | `scan_success.mp3` | ~250ms | 70% |
| High | Checkout success | `checkout_success.mp3` | ~500ms | 80% |
| Medium | RFID scan error | `scan_error.mp3` | ~300ms | 60% |
| Medium | Checkout error | `checkout_error.mp3` | ~300ms | 60% |
| Low | Product added to cart | `product_add.mp3` | ~150ms | 30% |
| Low | Product removed/deleted | `product_remove.mp3` | ~150ms | 30% |
| Low | Quantity +/− | `quantity_change.mp3` | ~100ms | 20% |
| Low | Category switched | `category_switch.mp3` | ~120ms | 15% |

### Sound Character Guidance

| File | Character |
|------|-----------|
| `scan_success.mp3` | Soft marimba or small bell — single warm note |
| `checkout_success.mp3` | Warm ascending 2–3 note chime (cafe door bell feel) |
| `scan_error.mp3` | Soft low wooden thud or descending tone |
| `checkout_error.mp3` | Soft descending tone or muted warning buzz |
| `product_add.mp3` | Tiny xylophone tap or soft cork pop |
| `product_remove.mp3` | Muted thud or soft low pop |
| `quantity_change.mp3` | Barely-there tick (reused for both + and −) |
| `category_switch.mp3` | Subtle paper swipe or soft click |

### Freesound.org Search Terms (CC0 filter)

| File | Search terms |
|------|-------------|
| `scan_success.mp3` | `marimba single note` / `soft bell ding` / `xylophone hit warm` |
| `checkout_success.mp3` | `chime ascending` / `bell sequence warm` / `success chime cafe` |
| `scan_error.mp3` | `low thud soft` / `wooden knock` / `error tone warm` |
| `checkout_error.mp3` | `descending tone soft` / `low bell error` / `soft buzz warning` |
| `product_add.mp3` | `cork pop soft` / `xylophone tap` / `click pop short` |
| `product_remove.mp3` | `muted thud` / `soft pop low` / `wood tap low` |
| `quantity_change.mp3` | `tick soft` / `click subtle` / `ui tick` |
| `category_switch.mp3` | `paper swipe` / `soft click ui` / `whoosh subtle short` |

## Architecture

### Config

`app_config.json` gains one new boolean field:
```json
{
  "demo_mode": false,
  "sounds_enabled": true
}
```

`AppConfig` (or `ConfigService`) parses `sounds_enabled` as a bool, defaulting to `false` if absent.

### SoundService

A singleton service initialized at app startup. All sound logic is isolated here.

```
SoundService
  - instance (singleton)
  - play(SoundEvent event) → checks config, plays clip at correct volume
  - dispose()

SoundEvent (enum)
  scanSuccess | scanError
  productAdd | productRemove | quantityChange | categorySwitch
  checkoutSuccess | checkoutError
```

- Volume constants defined per-event inside `SoundService`
- If `soundsEnabled == false`, `play()` returns immediately (no audio loaded)
- Assets preloaded at startup for instant playback with no latency

### Asset Location

```
terminal-frontend/
└── assets/
    └── sounds/
        ├── scan_success.mp3
        ├── scan_error.mp3
        ├── checkout_success.mp3
        ├── checkout_error.mp3
        ├── product_add.mp3
        ├── product_remove.mp3
        ├── quantity_change.mp3
        └── category_switch.mp3
```

Declared in `pubspec.yaml` under `flutter.assets`.

## Integration Points

| Caller | File | Event |
|--------|------|-------|
| `RfidProvider.handleCardScan()` | `rfid_provider.dart` | `scanSuccess` / `scanError` |
| `CartProvider.addItem()` | `cart_provider.dart` | `productAdd` |
| `CartProvider.decreaseItem()` | `cart_provider.dart` | `quantityChange` |
| `CartProvider.removeItem()` | `cart_provider.dart` | `productRemove` |
| `CartProvider.checkout()` | `cart_provider.dart` | `checkoutSuccess` / `checkoutError` |
| Category chip `onTap` | `category_tabs.dart` (widget) | `categorySwitch` |
