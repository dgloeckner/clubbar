# The terminal went silent by itself

*Sound worked, nothing was changed, sound is gone, a reboot brings it back.*

This is about that fault, not about a terminal that has never made a sound —
for a first-time setup go to [Audio setup on Raspberry Pi](audio-setup-raspberry-pi.md).

> **Check cause G first.** One occurrence of this fault has now been diagnosed
> end to end, and it was none of the candidates below: the sound was going to
> the empty 3.5 mm jack. It costs one command to exclude —
> `pactl get-default-sink` — or run `kiosk-doctor.sh`, which checks it along
> with everything else. Only if that comes back right is the rest of this
> document the place to be.

## 0. The first rule: do not reboot yet

A reboot is the one action that destroys every piece of evidence in a fault
that only shows up every few weeks. Nothing in the app records a failed sound
today (see [What the app knows](#what-the-app-knows-about-a-failed-sound-nothing)),
so the state of the machine *while it is silent* is the only witness there is.

While the fault is live, on the Pi:

```bash
/opt/clubbar-terminal/current/scripts/kiosk-doctor.sh        # seconds; names cause G outright
/opt/clubbar-terminal/current/scripts/audio-diagnose.sh      # ~/audio-diagnose-<timestamp>.txt
```

`kiosk-doctor.sh` changes nothing at all and answers in seconds. `audio-diagnose.sh`
is read-only apart from the report it writes, and its last section deliberately
makes noise (`--no-play` skips that). Keep the file; then reboot if you need the
bar working.

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

## 2. What the app knows about a failed sound

Until the player-renewal change, nothing — three facts, all verified in the
sources, explained why this fault had produced no evidence:

| Where | What it did |
|-------|-------------|
| `lib/services/sound_service.dart` (`play`) | Wrapped the call in `try { … } catch (_) {}` with the comment *"Never let sound errors affect app functionality"*. Nothing was logged. |
| `audioplayers` 6.x, `AudioPlayer` constructor | A GStreamer failure is **not thrown** by `play()` — the Linux plugin sends it over the event channel, and the Dart side hands it to `AudioLogger.error`, which `print`s it. So the `catch` above never saw it, and the message that *would* name the cause went to stdout. |
| The launcher | Started the kiosk with no redirection, so that stdout went nowhere. |

Now `SoundService` subscribes to each player's event stream and logs both a
thrown play error and a reported one through `AppLog` at warning level, so they
land in `error.log` — the sink that survives on a kiosk. Each line names the
event (`scanSuccess`, `productAdd`, …) and carries the plugin's message, which
includes the GStreamer error domain (`gst-resource-error-quark` is the sound
server or the card; `gst-stream-error-quark` is the clip or its decoder).

The plugin's own `print` still goes to stdout. Under the systemd user unit that
is the journal:

```bash
journalctl --user -u clubbar-terminal.service -n 200 --no-pager | grep -i audioplayers
```

### More detail for a reproduction attempt

Add GStreamer's own warnings and errors to that journal by setting `GST_DEBUG`
in the unit — a per-Pi customisation the updater never overwrites (see
*Supervised by systemd* in INSTALL.md):

```bash
systemctl --user edit clubbar-terminal.service
```

```ini
[Service]
Environment=GST_DEBUG=2
```

Level 2 is quiet enough to leave on permanently; `GST_DEBUG=alsa*:5,autodetect:5`
is the loud version for a reproduction attempt. The next silence then leaves a
line that names the failing element and the reason.

## 3. The candidates, ranked

Ranked by how well each explains *"no change, gone, reboot fixes it"*. **G** is
confirmed and belongs at the top of this list in practice — check it first. A
to F remain hypotheses; the capture is what tells them apart.

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
  logind seat event), a pipeline that was open at that moment points at a
  connection that no longer exists.
- If the app wins the startup race against the sound server, the probe happens
  with no server present.

**How far that actually reaches** (read from `audio_player.cc` at 4.3.0): the
players use the default release mode, so after every clip's end-of-stream the
plugin takes the pipeline back to `NULL`. `autoaudiosink` drops its chosen
child on `READY → NULL` and probes again on the next `NULL → READY`, and
`pulsesink` opens a new server connection each time it leaves `NULL`. A player
that *finished* its last sound therefore reconnects on its next play by itself;
a server restart between two sounds costs nothing. What does not recover is a
pipeline that errored *before it prerolled* — the server down, or not up yet,
at the moment a sound was asked for — because it never reaches end-of-stream
and is never released. That is cause **C**, and it is the form both bullets
above take in practice. Since the player-renewal change the app heals it at
every login (see C).

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
restores sound. The `error.log` lines from §2 name it outright.

**Healed since the player-renewal change.** `SoundService` no longer keeps a
player for life. Every login (`RfidProvider`, on a started session) marks all
ten players stale, and a stale player is disposed and rebuilt by the next sound
that needs it — the scan chime first, so a fresh pipeline is what greets the
member. A player whose `play()` throws, or whose event stream reports a
GStreamer error, is marked stale the same way, so a failure mid-evening costs
one silent sound rather than the rest of the session. The rebuild is lazy on
purpose: a login pays for one pipeline before its chime, not ten. A terminal
that shows this cause after the change is a new fault — record what
`error.log` says and reopen.

**Still worth doing at the stack level:** mix in software so concurrent opens
cannot collide (`dmix`, or a sound server, rather than `plughw:`).

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

### G. The default sink is an output nobody is listening to — **CONFIRMED**

The one cause on this page that has actually been caught in the act, on
`ruderbar`, 2026-08-30. It is listed last because it was found last, and first
in the box at the top because it is the one to exclude before reading any of
the others.

PipeWire's default sink was `alsa_output.platform-fe00b840.mailbox.stereo-fallback`
— the **3.5 mm analog jack**, which on this hardware has nothing plugged into
it. The speakers are in the HDMI display.

What makes it expensive to find is that **nothing fails**. Every layer reports
success, because playing into an unconnected port *is* success:

| Layer | What it said |
|-------|--------------|
| `pactl list short sinks` | `RUNNING` |
| `/proc/asound/card*/pcm0p/sub0/status` | `state: RUNNING`, `hw_ptr` advancing |
| GStreamer with `GST_DEBUG=2` | prerolled, played, EOS, no error |
| ALSA mixer | `100%`, unmuted |
| `audio-diagnose.sh` | a clean capture |
| `kiosk-doctor.sh` (before this fix) | **no failures, 0 warnings** |

There is no error anywhere to grep for. That is why it is a *check* now rather
than something to find in a log.

**Why it is intermittent.** `~/.local/state/wireplumber/default-nodes` did not
exist, so WirePlumber re-picked the default by priority on **every boot**. That
is precisely the reported shape — "it works for a while, then it does not work
on a freshly booted Pi". Nothing had to change for it to break; the pick simply
came out differently.

**Confirm:**

```bash
pactl get-default-sink        # want: ...fef00700.hdmi..., not ...mailbox...
ls ~/.local/state/wireplumber/default-nodes    # its absence is the mechanism
```

**Fix:** `sudo ./kiosk-session-setup.sh` installs
`~/.config/wireplumber/wireplumber.conf.d/50-clubbar-hdmi-priority.conf`, which
sinks the analog device below HDMI, and persists the default as well. Verified
by deleting `default-nodes` and restarting WirePlumber — HDMI still wins — and
again across a real reboot.

**Ruled out by experiment during the same session, so do not re-chase them:**

| Suspected | Measured |
|-----------|----------|
| Screen blanking (`mode: output-power`) | `wlopm --off` then `--on` leaves the HDMI sink, the default sink *and* audibility intact |
| Video mode | Audible at both 1280x800 (native, non-CTA) and 1280x720 (CTA). The panel's EDID advertises Basic audio, LPCM 2ch, FL/FR |
| USB or Bluetooth speakers | None exist on this terminal — only HDMI0 and the jack |
| The mass `apt-get install` of 2026-08-30 | Reinstalled pipewire/wireplumber but did not cause this; the rule survives it, which is the point of having a rule |
| Mixer mute, missing clips, held device | All clean in the capture |

## 4. Follow-ups in the app

What would turn the next occurrence into a one-line answer instead of another
investigation. It follows the shape
[#370](https://github.com/dgloeckner/clubbar/issues/370) used for card taps
(`ScanLog` + a status-modal section + `error.log`). Items 1 and 3 are done.

1. **Stop swallowing the failure — done.** `SoundService` subscribes to each
   player's `eventStream` errors and logs them, and a throwing `play()`, through
   `AppLog`, so they land in `error.log` — the only sink that survives on a
   kiosk. Still open: the plugin's `onLog` lines, which go to stdout (the
   journal) only.
2. **A `SoundLog`, mirroring `ScanLog`.** Last play per event, last failure
   with its GStreamer message, and a count — surfaced in the status modal next
   to *Letzte Chip-Erkennungen*, so staff can read out "no sound since 19:12,
   last error …" without a shell.
3. **Heal instead of wedging — done.** Players are renewed at every login and
   after any reported error (cause C above), which sidesteps the
   `SetSourceUrl` short-circuit. A terminal that recovers by itself is worth
   more than one that explains why it did not.
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
