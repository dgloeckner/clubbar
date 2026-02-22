#!/usr/bin/env python3
"""Screen idle monitor for the Ruderbar terminal.

Watches all input devices in /dev/input/ for activity. After TIMEOUT seconds
of inactivity it launches blackscreen.py, which covers the display with a
full-screen black window. Any touch or click dismisses it.

This approach is used because many cheap touchscreens do not respond to DPMS
power management commands, making xset / vcgencmd unreliable.

Usage:
    python3 screen-idle.py                   # watch all /dev/input/event* devices
    python3 screen-idle.py /dev/input/event4 # watch a specific device

Requirements:
    User must be in the 'input' group:
        sudo usermod -aG input $USER   (then log out and back in)
"""

import glob
import os
import pathlib
import subprocess
import sys
import time

TIMEOUT = 300  # seconds of inactivity before blanking the screen
SCRIPTS_DIR = pathlib.Path(__file__).parent


def find_devices() -> list[str]:
    if len(sys.argv) > 1:
        return sys.argv[1:]
    devices = sorted(glob.glob("/dev/input/event*"))
    if not devices:
        raise SystemExit("No input devices found in /dev/input/")
    return devices


def show_blackscreen() -> subprocess.Popen:
    return subprocess.Popen(
        [sys.executable, str(SCRIPTS_DIR / "blackscreen.py")],
        stderr=subprocess.DEVNULL,
    )


def open_devices(paths: list[str]) -> list[int]:
    fds = []
    for path in paths:
        try:
            fds.append(os.open(path, os.O_RDONLY | os.O_NONBLOCK))
        except PermissionError:
            print(
                f"Warning: cannot open {path} — "
                "run: sudo usermod -aG input $USER  (then re-login)"
            )
    if not fds:
        raise SystemExit("Could not open any input device. Check permissions above.")
    return fds


def main() -> None:
    devices = find_devices()
    fds = open_devices(devices)
    print(f"Watching {len(fds)} input device(s). Idle timeout: {TIMEOUT}s")

    last_activity = time.monotonic()
    screen_off = False
    black_proc = None

    INPUT_EVENT_SIZE = 24  # struct input_event on 64-bit Linux

    while True:
        for fd in fds:
            try:
                data = os.read(fd, INPUT_EVENT_SIZE * 64)
                if data:
                    last_activity = time.monotonic()
                    if screen_off and black_proc:
                        black_proc.terminate()
                        black_proc.wait()
                        black_proc = None
                        screen_off = False
                        print("Screen restored")
            except BlockingIOError:
                pass

        idle = time.monotonic() - last_activity

        if not screen_off and idle >= TIMEOUT:
            print(f"Idle for {idle:.0f}s — blanking screen")
            black_proc = show_blackscreen()
            screen_off = True

        # Handle blackscreen.py exiting on its own (e.g. user tapped it directly)
        if screen_off and black_proc and black_proc.poll() is not None:
            last_activity = time.monotonic()
            black_proc = None
            screen_off = False
            print("Blackscreen dismissed — timer reset")

        time.sleep(0.5)


if __name__ == "__main__":
    main()
