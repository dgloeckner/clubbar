# POS Daemon Design

Rust-based system daemon running on the Raspberry Pi. Manages hardware lifecycle,
health reporting, and frontend supervision. Runs as a systemd service, independent
of the Flutter UI.

---

## Responsibilities

```
systemd
 └─ pos-daemon (Rust, always-on)
     ├─ Display power management (PIR → backlight)
     ├─ Wemos health polling (GET /health)
     ├─ External health reporting (healthchecks.io)
     ├─ Frontend watchdog (restart Flutter if unresponsive)
     └─ System self-monitoring (disk, sync queue, temperature)
```

---

## Architecture

```
┌──────────────────────────────────────────────────────────┐
│  Raspberry Pi                                            │
│                                                          │
│  ┌────────────────────┐     ┌──────────────────────┐     │
│  │   Flutter UI        │     │   POS Daemon (Rust)  │     │
│  │   (pos-frontend)    │◄────│                      │     │
│  │                     │ping │   ┌─ PIR monitor     │     │
│  │  - member UI        │     │   ├─ health poller   │     │
│  │  - purchase flow    │     │   ├─ watchdog        │     │
│  │  - RFID reader      │     │   └─ system monitor  │     │
│  └────────┬───────────┘     └──────┬──────┬────────┘     │
│           │ HTTP                   │GPIO  │ HTTP         │
│           ▼                        ▼      ▼              │
│  ┌──────────────┐          ┌──────┐  ┌──────────┐       │
│  │   Wemos D1   │          │ PIR  │  │healthch. │       │
│  │  (dispenser) │          │sensor│  │   .io    │       │
│  └──────────────┘          └──────┘  └──────────┘       │
└──────────────────────────────────────────────────────────┘
```

The Flutter app owns the purchase flow and talks to the Wemos directly for
dispense transactions. The daemon handles everything that must keep running
regardless of the UI state.

---

## 1. Display Power Management

### Hardware

- **Sensor**: HC-SR505 mini PIR (10×24mm, 3.3V output, ~3m range)
- **Connection**: 5V (pin 2), GND (pin 6), OUT → GPIO17 (pin 11)
- **Display**: Official Raspberry Pi Touch Display 2, backlight via sysfs

### Control Interface

```
# Backlight on
/sys/class/backlight/rpi_backlight/brightness → "255"

# Backlight off
/sys/class/backlight/rpi_backlight/brightness → "0"
```

Fallback for HDMI displays:
```
vcgencmd display_power 0   # off
vcgencmd display_power 1   # on
```

### Logic

```rust
const DISPLAY_TIMEOUT: Duration = Duration::from_secs(120); // 2 min idle → off
const PIR_POLL_MS: u64 = 250;

let pir = gpio.get(17)?.into_input();
let mut last_motion = Instant::now();
let mut display_on = true;

loop {
    if pir.is_high() {
        last_motion = Instant::now();
        if !display_on {
            set_backlight(255)?;
            display_on = true;
        }
    } else if display_on && last_motion.elapsed() > DISPLAY_TIMEOUT {
        set_backlight(0)?;
        display_on = false;
    }
    thread::sleep(Duration::from_millis(PIR_POLL_MS));
}
```

### Configuration

| Parameter        | Default | Description                          |
|------------------|---------|--------------------------------------|
| `display_timeout`| 120s    | Idle time before display turns off   |
| `pir_gpio`       | 17      | GPIO pin for PIR sensor              |
| `brightness_on`  | 255     | Backlight level when active          |
| `brightness_dim` | 40      | Optional dimming before full off     |
| `dim_after`      | 60s     | Dim backlight after this idle time   |

Optional two-stage dimming: dim to `brightness_dim` after 60s, fully off after 120s.

---

## 2. Wemos Health Polling

### Poll Loop

Every 60 seconds, the daemon calls `GET http://<wemos-ip>/health` on the Wemos.

```rust
struct WemosHealth {
    status: String,       // "ok"
    uptime_s: u64,
    firmware: String,
    dispenser: String,    // "idle" | "dispensing" | "error"
    hopper_low: bool,
}
```

### Tracked Conditions

| Condition            | Detection                        | Severity |
|----------------------|----------------------------------|----------|
| Wemos unreachable    | HTTP timeout (3s) × 3 retries   | Critical |
| Hopper low           | `hopper_low: true`               | Warning  |
| Hopper empty         | Last dispense error: `empty`     | Critical |
| Dispenser jammed     | `dispenser: "error"`             | Critical |
| Wemos rebooted       | `uptime_s` decreased since last  | Info     |
| Firmware mismatch    | `firmware` != expected version   | Info     |

---

## 3. Health Reporting (healthchecks.io)

### Setup

Single healthchecks.io check per terminal. The daemon is the sole reporter.

```
https://hc-ping.com/<check-uuid>         → success
https://hc-ping.com/<check-uuid>/fail    → failure (with body)
https://hc-ping.com/<check-uuid>/log     → informational (with body)
```

### Report Payload

The daemon aggregates all health signals into one report:

```
wemos: ok | unreachable | error
hopper: ok | low | empty
dispenser: idle | error (jam)
frontend: running | restarted | unresponsive
sync_queue: <count> pending transactions
disk_free: <MB>
cpu_temp: <°C>
display: on | off | dimmed
```

### Reporting Logic

```
Every 60s:
  1. Poll Wemos /health
  2. Check frontend heartbeat
  3. Read system metrics (disk, temp, sync queue)
  4. If ALL ok → ping /success with summary body
  5. If ANY warning/critical → ping /fail with details
  6. If daemon itself can't reach healthchecks.io → retry next cycle
```

healthchecks.io grace period should be set to 5 minutes — if no ping arrives
in 5 minutes, the admin gets alerted (covers daemon crash, Pi offline, network down).

---

## 4. Frontend Watchdog

### Supervision Strategy

The Flutter app runs as a separate systemd unit (`pos-frontend.service`).
The daemon monitors it at two levels:

**Level 1: Process alive**
```rust
// Check systemd unit status
Command::new("systemctl")
    .args(["is-active", "pos-frontend"])
    .output()?;
```

If dead → `systemctl restart pos-frontend`. Log event, report in next health ping.

**Level 2: Application responsive**

The Flutter app exposes a local HTTP endpoint:
```
GET http://localhost:8080/ping → 200 {"ok": true, "uptime_s": 3421}
```

If no response within 5s on 3 consecutive checks (15s total) → force restart.

**Level 3: Heartbeat file (optional fallback)**

Flutter writes a timestamp to `/tmp/pos-frontend-heartbeat` every 10 seconds.
Daemon checks file freshness. If stale > 30s → restart.
This catches edge cases where the HTTP server is up but the UI is frozen.

### Rate Limiting

- Max 3 restarts within 10 minutes
- After 3rd restart, stop retrying and report critical failure to healthchecks.io
- Manual intervention required (admin gets alert)

---

## 5. System Self-Monitoring

### Metrics Collected

| Metric          | Source                              | Alert threshold |
|-----------------|-------------------------------------|-----------------|
| Disk free       | `statvfs("/")`                      | < 500 MB        |
| CPU temperature | `/sys/class/thermal/thermal_zone0`  | > 80°C          |
| Sync queue      | SQLite `SELECT COUNT(*) WHERE synced=false` | > 50    |
| Memory free     | `/proc/meminfo`                     | < 100 MB        |
| Uptime          | `/proc/uptime`                      | Info only        |

### Sync Queue Monitoring

The daemon reads the Flutter app's SQLite database (read-only) to count
unsynced transactions. A growing backlog indicates backend connectivity issues.

---

## 6. Configuration

Single TOML config file at `/etc/pos-daemon/config.toml`:

```toml
[display]
pir_gpio = 17
timeout_s = 120
dim_after_s = 60
brightness_on = 255
brightness_dim = 40

[wemos]
host = "192.168.4.1"
port = 80
poll_interval_s = 60
timeout_ms = 3000
retries = 3

[healthcheck]
url = "https://hc-ping.com/<uuid>"
interval_s = 60

[watchdog]
frontend_unit = "pos-frontend"
ping_url = "http://localhost:8080/ping"
ping_timeout_ms = 5000
ping_retries = 3
max_restarts = 3
restart_window_s = 600

[system]
db_path = "/var/lib/pos/transactions.db"
disk_warn_mb = 500
temp_warn_c = 80
sync_warn_count = 50
```

---

## 7. Systemd Integration

### pos-daemon.service

```ini
[Unit]
Description=POS Terminal Daemon
After=network.target
Wants=pos-frontend.service

[Service]
Type=notify
ExecStart=/usr/local/bin/pos-daemon
Restart=always
RestartSec=5
WatchdogSec=30
Environment=RUST_LOG=info

[Install]
WantedBy=multi-user.target
```

The daemon uses `sd_notify` (via the `systemd` Rust crate) to:
- Signal `READY=1` after initialization
- Pet the watchdog every 15s (`WATCHDOG=1`)
- Report status: `STATUS=wemos:ok frontend:ok sync:3`

### pos-frontend.service

```ini
[Unit]
Description=POS Frontend (Flutter)
After=pos-daemon.service

[Service]
Type=simple
ExecStart=/usr/local/bin/pos-frontend
Restart=on-failure
RestartSec=3
Environment=DISPLAY=:0

[Install]
WantedBy=graphical.target
```

Note: `Restart=on-failure` only — the daemon handles intentional restarts
via `systemctl restart`, but systemd catches unexpected crashes immediately.

---

## 8. Rust Crate Dependencies

| Crate        | Purpose                          |
|--------------|----------------------------------|
| `rppal`      | GPIO access for PIR sensor       |
| `reqwest`    | HTTP client (Wemos + healthchecks.io), blocking |
| `rusqlite`   | Read-only access to transaction DB |
| `toml`       | Config file parsing              |
| `systemd`    | sd_notify integration            |
| `log` + `env_logger` | Structured logging        |
| `serde`      | JSON deserialization             |

### Build Target

Cross-compile for `armv7-unknown-linux-gnueabihf` (Pi 4/5).
Single static binary, no runtime dependencies beyond libc.

---

## 9. Startup Sequence

```
1. Read config from /etc/pos-daemon/config.toml
2. Initialize GPIO (PIR sensor)
3. Verify Wemos connectivity (GET /health, retry up to 30s)
4. Verify SQLite DB accessible (read-only)
5. Signal sd_notify READY=1
6. Enter main loop:
   a. PIR poll (every 250ms)
   b. Wemos health check (every 60s)
   c. Frontend watchdog (every 10s)
   d. System metrics (every 60s)
   e. healthchecks.io report (every 60s)
   f. systemd watchdog pet (every 15s)
```

All timers are non-blocking, driven by a single-threaded event loop
(or a small thread pool: one for PIR, one for the rest).
