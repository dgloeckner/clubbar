# The terminal went silent by itself

*Sound worked, nothing was changed, sound is gone, a reboot brings it back.*

This is about that fault, not about a terminal that has never made a sound —
for a first-time setup go to [Audio setup on Raspberry Pi](audio-setup-raspberry-pi.md).

## 0. The first rule: do not reboot yet

A reboot is the one action that destroys every piece of evidence in a fault
that only shows up every few weeks. Nothing in the app records a failed sound
today (see [What the app knows](#what-the-app-knows-about-a-failed-sound-nothing)),
so the state of the machine *while it is silent* is the only witness there is.

While the fault is live, on the Pi:

```bash
/opt/clubbar-terminal/scripts/audio-diagnose.sh      # ~/audio-diagnose-<timestamp>.txt
```

It is read-only apart from the report it writes, takes a few seconds, and its
last section deliberately makes noise (`--no-play` skips that). Keep the file;
then reboot if you need the bar working.

## 1. Two questions that halve the search

Everything below hangs off these. The capture answers the first; you answer the
second by hand, in the 30 seconds before the reboot.

### Was there sound earlier in this same boot?

- **No, silent since the terminal started** → the app picked its audio path at
  startup and picked wrong: a race with the sound server or with HDMI coming
  up. Look at causes **A** and **B**.
- **Yes, it worked and then stopped** → something took the device away, or a
  playback failed and the app never recovered from it. Causes **A**, **B**,
  **C**, **D**.

### Does restarting *only the app* fix it?

```bash
pkill -f clubbar_terminal
# then start it again from the desktop / autostart entry, and tap a card
```

- **Sound is back without a reboot** → the fault lives inside the process:
  a wedged GStreamer pipeline or a sink bound to something that is gone.
  Cause **C**, or **A** in its "server restarted under us" form.
- **Still silent after an app restart** → the fault is below the app: the card,
  the HDMI link, the mixer, or another process holding the device. Causes
  **B**, **D**, **E**. Now run the playback tests from the capture again — with
  the app stopped they have the card to themselves, so a failure there is real
  and not just contention.

Write the answer down. It is worth more than the rest of this document, and
today nobody has ever recorded it.

## 2. What the app knows about a failed sound: nothing

Three facts, all verified in the sources, explain why this fault has produced
no evidence so far:

| Where | What it does |
|-------|--------------|
| `lib/services/sound_service.dart` (`play`) | Wraps the call in `try { … } catch (_) {}` with the comment *"Never let sound errors affect app functionality"*. Nothing is logged. |
| `audioplayers` 6.6.0, `AudioPlayer` constructor | A GStreamer failure is **not thrown** by `play()` — the Linux plugin sends it over the event channel, and the Dart side hands it to `AudioLogger.error`, which `print`s it. So the `catch` above never sees it, and the message that *would* name the cause goes to stdout. |
| `~/.config/autostart/clubbar-terminal.desktop` | Starts the kiosk with no redirection, so that stdout goes nowhere. |

The clip that fails, the GStreamer error domain, the ALSA message — all of it is
produced and then dropped on the floor.

### Fix this today, before the next occurrence

Keep the app's output. Edit `~/.config/autostart/clubbar-terminal.desktop`:

```ini
[Desktop Entry]
Type=Application
Name=Club Bar Terminal
Exec=sh -c 'exec env GST_AUDIO_SINK=alsasink GST_DEBUG=2 /opt/clubbar-terminal/clubbar_terminal >> "$HOME/.local/share/de.clubbar.clubbar_terminal/logs/stdout.log" 2>&1'
Hidden=false
X-GNOME-Autostart-enabled=true
```

`GST_DEBUG=2` adds GStreamer's own warnings and errors (level 2 is quiet enough
to leave on permanently; `GST_DEBUG=alsa*:5,autodetect:5` is the loud version
for a reproduction attempt). The next silence then leaves a line in
`stdout.log` that names the failing element and the reason. The diagnose script
already tails that file.

Rotate it if you leave it on: `logs/stdout.log` is not size-bounded the way
`error.log` is by its error-level filter.

## 3. The candidates, ranked

Ranked by how well each explains *"no change, gone, reboot fixes it"*. None is
confirmed — the capture is what tells them apart.

### A. The sink the app bound at startup is gone

The strongest candidate, and the least obvious one.

The `.desktop` entry sets `GST_AUDIO_SINK=alsasink`, but `audioplayers_linux`
does not use plain `playbin` defaults: for every player it builds its own
`audiopanorama ! autoaudiosink` bin and assigns it to `playbin`'s `audio-sink`
property (`audioplayers_linux/linux/audio_player.cc`, constructor). So the sink
is chosen by **autoaudiosink**, which probes once and caches its choice. On a
Raspberry Pi OS desktop with PipeWire running, that choice is very likely
`pulsesink` talking to `pipewire-pulse`, *not* the ALSA device the setup
document configures.

That matters because:

- If `wireplumber` / `pipewire-pulse` restarts (a crash, a session change, a
  logind seat event), every pipeline in the long-running app keeps pointing at
  a connection that no longer exists. It does not reconnect. A reboot restarts
  the app together with the server, so the fault "fixes itself".
- If the app wins the startup race against the sound server, the probe happens
  with no server present, and the choice is different for that whole boot.

**Confirm from the capture:** section 1 shows what the process has open — a
`/dev/snd/*` fd means ALSA directly, a `pipewire-0`/`pulse` socket means the
server path. Section 4 lists the servers *with their start times*: a server
younger than the terminal process is the smoking gun.

**Fixes, once confirmed:** either take the server out of the path for real
(`GST_AUDIO_SINK` alone is not enough given the above — the app would need to
build its sink from that variable, see [Follow-ups](#4-follow-ups-in-the-app)),
or accept the server and make the terminal survive its restart by recreating
players on error.

### B. The HDMI audio device disappeared or moved

HDMI audio only exists while the link does. A display that renegotiates, a
switch/AVR in between, or a monitor that cuts power can take `vc4-hdmi` down;
when it returns the card can come back at a different index, which silently
invalidates a `~/.asoundrc` written as `defaults.pcm.card 0` and any
`plughw:0,0`.

Idle blanking is handled in-app (`ScreenBlanker`, #763), so it
does not by itself drop the link — but the compositor's own idle handling, or
anything downstream of the Pi's HDMI port, still can.

**Confirm from the capture:** section 2 (`/proc/asound/cards`, `aplay -l`) —
is the card still there, still at the same index? Section 8 — `vc4`/`hdmi`
lines in `dmesg` around the time it went quiet.

**Fix:** address the card by name, never by number, so a reindex cannot silence
the terminal: `plughw:CARD=vc4hdmi0,DEV=0` in `~/.asoundrc` instead of
`defaults.pcm.card 0`.

### C. A wedged pipeline the app never resets

`SoundService.init()` creates **ten** `AudioPlayer`s, one per sound event —
ten independent GStreamer pipelines. Sounds overlap by design (a card scan
while a product tap is still ringing), and if those pipelines end up on an
exclusive ALSA device (`hw:`/`plughw:` — no `dmix`), the second one to open it
fails with *Device or resource busy*.

What makes that permanent rather than a one-off glitch:
`AudioPlayer::SetSourceUrl` in the Linux plugin short-circuits when the URL has
not changed — it reports "prepared" and returns *without* touching the
pipeline. A player whose pipeline was left in a bad state after a failed open
therefore keeps accepting `play()` calls, keeps reporting success, and never
makes a sound again. One event class at a time goes quiet; the app restart in
question 2 is what brings them all back.

**Confirm:** this is the cause if — and only if — restarting the app alone
restores sound. The `stdout.log` from §2 names it outright once enabled.

**Fixes:** mix in software so concurrent opens cannot collide (`dmix`, or a
sound server, rather than `plughw:`), and/or recreate a player after a failure
instead of reusing it (see [Follow-ups](#4-follow-ups-in-the-app)).

### D. Another process is holding the card

Anything that grabs an exclusive PCM — a leftover `aplay`, a browser, a second
copy of the terminal, a sound server that opened the device and never released
it — silences everything else until it exits.

**Confirm from the capture:** section 3 (`fuser -v /dev/snd/*`) and the
`owner_pid` in section 2's per-substream `status` files. A second
`clubbar_terminal` pid in section 1 is worth checking on its own.

### E. Muted or zeroed mixer

Cheap to exclude and occasionally the whole answer: ALSA state is restored at
boot from `/var/lib/alsa/asound.state`, and anything that writes a muted state
into it makes the fault survive… but *not* a reboot, which is why this ranks
low here. Section 5 of the capture settles it.

### F. The extracted clips vanished

`audioplayers` copies each asset out of the bundle into `<tmp>/<uuid>/sounds/`
on first play. `/tmp` is swept by `systemd-tmpfiles-clean` on a timer, so on a
terminal that idles for weeks the clips can be deleted underneath a running
app. Version 6.6.0 re-checks the file and re-extracts it, so this should heal
itself — section 6 confirms whether the files are there.

## 4. Follow-ups in the app

None of this is implemented yet; it is what would turn the next occurrence into
a one-line answer instead of another investigation. It follows the shape
[#370](https://github.com/dgloeckner/clubbar/issues/370) used for card taps
(`ScanLog` + a status-modal section + `error.log`).

1. **Stop swallowing the failure.** Subscribe to each player's `eventStream`
   errors and `onLog` in `SoundService.init()` and log them through `AppLog` at
   error level, so they land in `error.log` — the only sink that survives on a
   kiosk. The bare `catch (_)` in `play()` should log too, even though it is not
   where the interesting failures arrive.
2. **A `SoundLog`, mirroring `ScanLog`.** Last play per event, last failure
   with its GStreamer message, and a count — surfaced in the status modal next
   to *Letzte Chip-Erkennungen*, so staff can read out "no sound since 19:12,
   last error …" without a shell.
3. **Heal instead of wedging.** On a play error, dispose the player and build a
   new one before the next attempt, which sidesteps the `SetSourceUrl`
   short-circuit above. A terminal that recovers by itself is worth more than
   one that explains why it did not.
4. **Fewer pipelines.** Ten players for eight clips is ten devices to open. One
   player per priority tier, or a single player, would make concurrent-open
   failures structurally rare.
5. **Honour the documented sink.** If `GST_AUDIO_SINK` is meant to be the
   configuration knob, the app has to build its sink from it rather than leave
   `autoaudiosink` to guess — otherwise the setup document describes something
   that does not happen.

## 5. Reporting it

Attach to the issue: the capture file, the answers to both questions in §1,
`error.log` and (once enabled) `stdout.log`, and roughly when the terminal was
last known to make a sound.
