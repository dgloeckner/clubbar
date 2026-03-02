# Club Bar Terminal

Flutter-based POS terminal for the Club Bar club bar system. Supports offline-capable transaction processing with RFID/NFC member identification.

## Terminal Onboarding

### Prerequisites

- Backend running and accessible (e.g. `http://localhost:8080/api`)
- Admin access to create a terminal in the admin panel
- Terminal app installed on the target device

### 1. Create Terminal in Admin Panel

1. Log in to the admin panel
2. Navigate to **Terminals** > **Add Terminal**
3. Enter a descriptive Terminal ID (e.g. `Club Bar-Kühlschrank`)
4. The admin panel generates a 64-character hex API token (shown once)
5. Copy the Terminal ID and API Token

### 2. Configure Terminal App (First Launch)

On first launch, the terminal app shows a **Setup Screen**:

1. **Terminal ID** - Enter the ID from step 1 (e.g. `Club Bar-Kühlschrank`)
2. **API URL** - Enter the backend API URL (e.g. `https://club.example.com/api`)
3. **API Token** - Paste the 64-character hex token
4. Click **Save & Connect**

The app tests the connection via api endpoint. On success, config is saved and the terminal navigates to the idle screen.

### 3. Alternative: Environment Variables

For automated deployment or CI, set environment variables instead of using the setup screen:

```bash
export TERMINAL_ID="Club Bar-Kühlschrank"
export TERMINAL_API_URL="https://club.example.com/api"
export TERMINAL_API_TOKEN="<64-char-hex-token>"
```

Environment variables override config file values (per ADR-0019).

### 4. Alternative: Manual Config File

Create `config.json` at the platform-specific path:

| Platform | Path |
|----------|------|
| macOS | `~/Library/Containers/de.clubbar.clubbarTerminal/Data/Library/Application Support/de.clubbar.clubbarTerminal/config.json` |
| Linux | `~/.config/de.clubbar.clubbarTerminal/config.json` |
| Windows | `%APPDATA%\de.clubbar.clubbarTerminal\config.json` |

```json
{
  "terminalId": "Club Bar-Kühlschrank",
  "apiUrl": "https://club.example.com/api",
  "apiToken": "<64-char-hex-token>"
}
```

The file is created with permissions `600` (owner read/write only) on macOS/Linux.

### Token Rotation

1. In the admin panel, navigate to the terminal and click **Rotate Token**
2. Copy the new token
3. On the terminal, delete the config file (or clear via admin) and re-enter credentials on the setup screen

### Troubleshooting

| Issue | Solution |
|-------|----------|
| "Connection failed" on setup | Verify the API URL is correct and the backend is running. Check network connectivity. |
| Token rejected (401) | The token may have been rotated. Get a new token from the admin panel. |
| App shows setup screen after restart | Config file may have been deleted or is unreadable. Re-enter credentials. |
| Sync failures after setup | Check backend logs. The terminal retries automatically on the next sync cycle. |

## Development

```bash
# Install dependencies
flutter pub get

# Run in development mode (uses http://localhost:8080/api by default)
flutter run

# Run tests
flutter test

# Run specific test file
flutter test test/services/config_service_test.dart
```

In development mode without a config file, the app uses `http://localhost:8080/api` as the default API URL. Mock data is auto-seeded into the local database.
