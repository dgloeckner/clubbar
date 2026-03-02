# Development Scripts

Utility scripts for development workflows.

## Available Scripts

### `reset-db.sh`

Resets the local SQLite database by removing the app's data file. On the next app launch, the database will be recreated and seeded with fresh mock data.

**Usage:**

```bash
# From project root
./scripts/reset-db.sh

# Or via Makefile
make reset-db

# Or reset and run in one command
make reset-and-run
```

**What it does:**
- Deletes the SQLite database file from the platform-specific app container
- Supports macOS and Linux
- On next app run, mock data is automatically seeded

**After running:**
1. Database is wiped clean
2. Run `flutter run` or `make run` to start the app
3. App will seed default categories and products on startup
4. Test RFID cards available:
   - `card-123` → John Doe
   - `card-456` → Jane Smith

**Platform-specific paths:**
- **macOS**: `~/Library/Containers/com.example.clubbarTerminal/Data/clubbar_terminal.db`
- **Linux**: `~/.local/share/clubbar_terminal/clubbar_terminal.db`

---

## Common Development Tasks

### Using Makefile

```bash
make help           # Show all available commands
make reset-db       # Reset database
make run            # Run the app
make test           # Run all tests
make analyze        # Run Flutter analyzer
make clean          # Clean build artifacts
make dev-setup      # Install dependencies
make reset-and-run  # Reset DB and run app
make test-all       # Run analyzer + all tests
```

### Manual Commands

```bash
# Reset database
./scripts/reset-db.sh

# Run tests
flutter test

# Analyze code
flutter analyze

# Clean and rebuild
flutter clean && flutter pub get

# Run app
flutter run
```

---

## Tips

- **Testing after DB reset**: After `make reset-db`, immediately run `make run` or the database state may be unclear
- **Multiple devices**: Use `flutter devices` to see connected devices, then `flutter run -d <device_id>`
- **Watch mode**: Add `-t lib/main.dart` to many commands to watch specific entry points
- **Verbose output**: Add `-v` to any command for verbose logging (e.g., `flutter run -v`)

---

## Extending Scripts

To add new scripts:
1. Create the `.sh` file in this directory
2. Make it executable: `chmod +x script-name.sh`
3. Add a corresponding Makefile target
4. Document it here
