# Roon → Aerial Views bridge

Shows what's playing on your **Roon** server in the **Aerial Views** screensaver
on an Android TV device (NVIDIA Shield, Google TV, Fire TV).

Aerial Views' native "Now Playing" overlay can only see media sessions from apps
running *on the TV itself* (which is why Spotify shows up). Roon plays elsewhere
on your network, so this bridge polls the Roon Core over its extension API and
pushes the current track to Aerial Views'
[Message API](https://github.com/theothernt/AerialViews) instead:

```
Roon Core ──(pyroon extension API)──► bridge script ──(HTTP POST :8081)──► Aerial Views overlay
```

## 1. Set up Aerial Views on the Shield

1. Open Aerial Views → **Settings → Overlays → Message Overlay**.
2. Enable the **Message API** and note the port (default `8081`) and the
   Shield's IP address shown on screen.
3. In the overlay layout settings, place the **Message 1** overlay in a corner.
4. Verify from another machine while the screensaver is running:

   ```bash
   curl http://<shield-ip>:8081/status
   ```

## 2. Run the bridge

Runs on anything always-on with Python 3.9+ — the Roon Core machine, a Pi, a NAS.

```bash
pip install -r requirements.txt
SHIELD_HOST=<shield-ip> python3 roon_aerial_bridge.py
```

On **first run**, open Roon → **Settings → Extensions** and enable
**"Roon to Aerial Views Bridge"**. The script blocks until you approve it, then
saves the token to `~/.config/roon-aerial-bridge/` so you only do this once.

### Configuration (environment variables)

| Variable       | Default                        | Purpose                                       |
| -------------- | ------------------------------ | --------------------------------------------- |
| `SHIELD_HOST`  | — (required)                   | Shield's IP or hostname                        |
| `SHIELD_PORT`  | `8081`                         | Aerial Views Message API port                  |
| `MESSAGE_SLOT` | `1`                            | Which message overlay slot (1–4) to write to   |
| `TEXT_SIZE`    | `18`                           | Overlay text size                              |
| `ROON_ZONE`    | any playing zone               | Only watch this Roon zone (its display name)   |
| `ROON_HOST`    | auto-discovery                 | Roon Core address, if discovery doesn't work   |
| `ROON_PORT`    | `9330`                         | Roon Core API port (used with `ROON_HOST`)     |
| `POLL_SECONDS` | `5`                            | How often to check for track changes           |
| `STATE_DIR`    | `~/.config/roon-aerial-bridge` | Where the Roon auth token is stored            |

## 3. Run it at boot (systemd)

```bash
sudo mkdir -p /opt/roon-aerial-bridge
sudo cp roon_aerial_bridge.py /opt/roon-aerial-bridge/
sudo cp roon-aerial-bridge.service /etc/systemd/system/
sudo systemctl edit roon-aerial-bridge   # or edit the unit: set SHIELD_HOST etc.
sudo systemctl enable --now roon-aerial-bridge
journalctl -u roon-aerial-bridge -f
```

## Notes

- The Message API only listens **while the screensaver is actually running**.
  The bridge treats connection failures as "screensaver not active" and quietly
  retries, re-pushing the current track as soon as the screensaver starts.
- When playback stops or pauses, the bridge clears the overlay.
- This uses the message overlay, not Aerial Views' native music overlay, so the
  text is plain (no track animation) but the format is yours: edit
  `now_playing_text()` in `roon_aerial_bridge.py` to change it.
