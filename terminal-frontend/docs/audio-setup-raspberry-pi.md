# Audio Setup on Raspberry Pi (HDMI Touchscreen)

The Flutter `audioplayers` package requires GStreamer on Linux. By default, audio may not route to the correct HDMI output.

## 1. Identify the Correct Audio Device

List available playback devices and test each HDMI output:

```bash
aplay -l
aplay -D plughw:0,0 /usr/share/sounds/alsa/Front_Center.wav
aplay -D plughw:1,0 /usr/share/sounds/alsa/Front_Center.wav
```

## 2. Set ALSA Default Device

Once you know the working card number (e.g., card `0`), create `~/.asoundrc`:

```bash
cat > ~/.asoundrc << 'EOF'
defaults.pcm.card 0
defaults.ctl.card 0
EOF
```

## 3. Install GStreamer

```bash
sudo apt install -y gstreamer1.0-tools gstreamer1.0-alsa gstreamer1.0-plugins-base gstreamer1.0-plugins-good
```

## 4. Force GStreamer to Use ALSA

By default, GStreamer routes audio through PulseAudio. Force it to use ALSA directly:

```bash
# Verify it works
gst-launch-1.0 playbin uri=file:///usr/share/sounds/alsa/Front_Center.wav audio-sink="alsasink device=plughw:0,0"
```

Set as system-wide default:

```bash
echo 'GST_AUDIO_SINK=alsasink' | sudo tee -a /etc/environment
```

If the Flutter app runs as a systemd service, also add it to the service file:

```bash
sudo systemctl edit ruderbar-terminal.service
```

```ini
[Service]
Environment="GST_AUDIO_SINK=alsasink"
```

## 5. Reboot and Verify

```bash
sudo reboot
```

After reboot, confirm both ALSA and GStreamer produce sound:

```bash
aplay /usr/share/sounds/alsa/Front_Center.wav
gst-launch-1.0 playbin uri=file:///usr/share/sounds/alsa/Front_Center.wav
```
