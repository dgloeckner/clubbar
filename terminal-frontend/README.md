# Club Bar Terminal

Flutter-based POS terminal for the Club Bar system. Supports offline-capable transaction processing with RFID/NFC member identification.

## Terminal Onboarding

### Prerequisites

- Backend running and accessible (e.g. `http://localhost:8080/api`)
- Admin access to create a terminal in the admin panel
- Terminal app installed on the target device

### 1. Create Terminal in Admin Panel

1. Log in to the admin panel
2. Navigate to **Settings** > **Terminals** > **Add Terminal**
3. Enter a descriptive Terminal ID (e.g. `Club Bar-Kühlschrank`)
4. The admin panel generates a 64-character hex API token (shown once)
5. Copy the Terminal ID and API Token

### 2. Configure Terminal App

The terminal reads its configuration from a `config.json` file. Without a valid config, the app exits with an error message to stderr indicating the expected config file path.

Create `config.json` at the platform-specific path:

| Platform | Path |
|----------|------|
| Linux | `~/.local/share/de.clubbar.clubbar_terminal/config.json` |
| macOS | `~/Library/Containers/de.clubbar.clubbarTerminal/Data/Library/Application Support/de.clubbar.clubbarTerminal/config.json` |
| Windows | `%APPDATA%\de.clubbar.clubbar_terminal\config.json` |

**Required fields:**

```json
{
  "terminalId": "Club Bar-Kühlschrank",
  "apiUrl": "https://club.example.com/api",
  "apiToken": "<64-char-hex-token>"
}
```

**Optional fields:**

```json
{
  "terminalId": "Club Bar-Kühlschrank",
  "apiUrl": "https://club.example.com/api",
  "apiToken": "<64-char-hex-token>",
  "fullscreen": true,
  "soundsEnabled": true,
  "demoMode": false,
  "seedTestData": false,
  "fontSizes": {
    "xs": 12, "sm": 14, "base": 16, "lg": 18, "xl": 24, "xxl": 32, "xxxl": 48
  },
  "dispenser": {
    "enabled": false,
    "baseUrl": "http://192.168.1.100",
    "apiKey": "<dispenser-api-key>"
  }
}
```

| Field | Default | Description |
|-------|---------|-------------|
| `fullscreen` | `false` | Launch in fullscreen mode |
| `soundsEnabled` | `true` | Audio feedback for scans, checkout, cart actions |
| `demoMode` | `false` | Shows a "Simulate Card Scan" button on idle screen (no RFID reader needed) |
| `seedTestData` | `false` | Seeds mock categories, products, and members into local DB (dev only) |
| `fontSizes` | — | Override the 7 font size scale steps |
| `dispenser` | — | Token dispenser hardware integration (ESP8266 / Azkoyen Hopper) |

### 3. Alternative: Environment Variables

For automated deployment or CI, environment variables override config file values:

```bash
export TERMINAL_ID="Club Bar-Kühlschrank"
export TERMINAL_API_URL="https://club.example.com/api"
export TERMINAL_API_TOKEN="<64-char-hex-token>"
export TERMINAL_FULLSCREEN="true"
export TERMINAL_SOUNDS_ENABLED="true"
export TERMINAL_SEED_TEST_DATA="false"
export TERMINAL_DEMO_MODE="false"
export DISPENSER_ENABLED="false"
export DISPENSER_BASE_URL="http://192.168.1.100"
export DISPENSER_API_KEY="<dispenser-api-key>"
```

Note: `fontSizes` cannot be set via environment variables.

### Token Rotation

1. In the admin panel, navigate to the terminal and click **Rotate Token**
2. Copy the new token
3. Update `apiToken` in the terminal's `config.json` and restart the app

### Troubleshooting

| Issue | Solution |
|-------|----------|
| App exits immediately | Config file missing or has invalid JSON. Check stderr for the expected path. |
| Token rejected (401) | The token may have been rotated. Get a new token from the admin panel and update `config.json`. |
| Sync shows "offline" | Backend unreachable. Verify `apiUrl` and network connectivity. The terminal continues operating offline. |
| Sync failures | Check backend logs. The terminal retries automatically every 60 seconds. |

## Development

```bash
# Install dependencies
flutter pub get

# Run in development mode
flutter run

# Run unit tests
flutter test

# Run integration tests (requires display — use xvfb-run on headless Linux)
flutter test integration_test/

# Run specific test file
flutter test test/services/config_service_test.dart
```

For local development, create a `config.json` with `seedTestData: true` and `demoMode: true` to get mock data and a simulated RFID scan button without needing hardware or a running backend.
