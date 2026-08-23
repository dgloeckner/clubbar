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
- **macOS**: `~/Library/Containers/de.clubbar.clubbarTerminal/Data/clubbar_terminal.db`
- **Linux**: `~/.local/share/clubbar_terminal/clubbar_terminal.db`

### `audio-diagnose.sh`

Captures the state of terminal audio **while it is broken**, on the Pi. For the
fault where sound worked, nothing was changed, sound is gone, and a reboot
brings it back — the reboot is what destroys the evidence, so run this first.

**Usage:**

```bash
# On the terminal, while it is silent
/opt/clubbar-terminal/scripts/audio-diagnose.sh          # ~/audio-diagnose-<timestamp>.txt
/opt/clubbar-terminal/scripts/audio-diagnose.sh --no-play  # skip the noise-making tests
```

**What it collects:**
- The terminal process: start time, the environment it actually got, and which
  audio path it holds open (an ALSA device, or a socket to a sound server)
- Cards, playback substream owners, and who is holding `/dev/snd/*`
- Sound servers **with their start times** — one younger than the app is the
  usual cause
- Mixer state, the clips `audioplayers` extracted to the temp dir, `error.log`
- `dmesg`/journal lines for `vc4`, HDMI and ALSA
- Playback tests along three paths, including the one `audioplayers` really
  builds (`audiopanorama ! autoaudiosink`)

Read the result alongside
[docs/audio-dropout-debugging.md](../docs/audio-dropout-debugging.md), which
ranks the causes and says which capture section separates them.

---

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
