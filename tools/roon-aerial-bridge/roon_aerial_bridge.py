#!/usr/bin/env python3
"""Roon -> Aerial Views bridge.

Watches a Roon Core for what's playing and pushes it to the Aerial Views
screensaver's Message API on an Android TV device (e.g. NVIDIA Shield),
so the screensaver shows now-playing info from Roon the way it does for
on-device apps like Spotify.

Configuration is via environment variables (see README.md):

  SHIELD_HOST     IP/hostname of the Shield (required)
  SHIELD_PORT     Aerial Views Message API port (default: 8081)
  MESSAGE_SLOT    Message slot 1-4 to use (default: 1)
  TEXT_SIZE       Overlay text size (default: 18)
  ROON_ZONE       Only watch this Roon zone name (default: any playing zone)
  ROON_HOST       Roon Core address; skips discovery when set
  ROON_PORT       Roon Core API port (default: 9330, only with ROON_HOST)
  POLL_SECONDS    How often to check for changes (default: 5)
  STATE_DIR       Where the Roon auth token is stored
                  (default: ~/.config/roon-aerial-bridge)

First run: approve "Roon to Aerial Views Bridge" in Roon under
Settings -> Extensions, otherwise the script waits forever for authorisation.
"""

import json
import logging
import os
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

from roonapi import RoonApi, RoonDiscovery

APP_INFO = {
    "extension_id": "com.github.jameshiggins.roon-aerial-bridge",
    "display_name": "Roon to Aerial Views Bridge",
    "display_version": "1.0.0",
    "publisher": "jameshiggins",
    "email": "noreply@example.com",
}

SHIELD_HOST = os.environ.get("SHIELD_HOST")
SHIELD_PORT = int(os.environ.get("SHIELD_PORT", "8081"))
MESSAGE_SLOT = int(os.environ.get("MESSAGE_SLOT", "1"))
TEXT_SIZE = int(os.environ.get("TEXT_SIZE", "18"))
ROON_ZONE = os.environ.get("ROON_ZONE")
ROON_HOST = os.environ.get("ROON_HOST")
ROON_PORT = int(os.environ.get("ROON_PORT", "9330"))
POLL_SECONDS = float(os.environ.get("POLL_SECONDS", "5"))
STATE_DIR = Path(
    os.environ.get("STATE_DIR", Path.home() / ".config" / "roon-aerial-bridge")
)

log = logging.getLogger("roon-aerial-bridge")


def connect_to_roon():
    """Connect to the Roon Core, reusing a saved token when available."""
    STATE_DIR.mkdir(parents=True, exist_ok=True)
    core_id_file = STATE_DIR / "core_id"
    token_file = STATE_DIR / "token"
    core_id = core_id_file.read_text().strip() if core_id_file.exists() else None
    token = token_file.read_text().strip() if token_file.exists() else None

    if ROON_HOST:
        host, port = ROON_HOST, ROON_PORT
    else:
        log.info("Discovering Roon Core on the local network...")
        discovery = RoonDiscovery(core_id)
        host, port = discovery.first()
        discovery.stop()
        log.info("Found Roon Core at %s:%s", host, port)

    if not token:
        log.info(
            "No saved token - approve this extension in Roon: "
            "Settings -> Extensions -> Enable"
        )
    api = RoonApi(APP_INFO, token, host, port, blocking_init=True)

    core_id_file.write_text(api.core_id or "")
    token_file.write_text(api.token or "")
    log.info("Connected and authorised with Roon Core %s", api.core_id)
    return api


def now_playing_text(api):
    """Return overlay text for the watched zone, or '' when nothing plays."""
    zones = list(api.zones.values())
    if ROON_ZONE:
        zones = [z for z in zones if z.get("display_name") == ROON_ZONE]
    playing = [z for z in zones if z.get("state") == "playing"]
    if not playing:
        return ""

    now = playing[0].get("now_playing") or {}
    lines = now.get("three_line") or now.get("two_line") or now.get("one_line") or {}
    track = lines.get("line1", "")
    artist = lines.get("line2", "")
    if artist and track:
        return f"♪ {artist} — {track}"
    return f"♪ {track or artist}" if (track or artist) else ""


def push_to_shield(text):
    """POST text to the Aerial Views Message API. Empty text clears it."""
    payload = {"text": text}
    if text:
        payload["textSize"] = TEXT_SIZE
    url = f"http://{SHIELD_HOST}:{SHIELD_PORT}/message/{MESSAGE_SLOT}"
    request = urllib.request.Request(
        url,
        data=json.dumps(payload).encode(),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    urllib.request.urlopen(request, timeout=3).read()


def main():
    logging.basicConfig(
        level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s"
    )
    if not SHIELD_HOST:
        log.error("SHIELD_HOST is not set - point it at your Shield's IP address")
        return 1

    api = connect_to_roon()
    last_pushed = None  # None means "state unknown, push on next check"
    try:
        while True:
            text = now_playing_text(api)
            if text != last_pushed:
                try:
                    push_to_shield(text)
                    last_pushed = text
                    log.info("Pushed: %s", text or "(cleared)")
                except (urllib.error.URLError, OSError):
                    # The Message API only listens while the screensaver is
                    # running - stay quiet and retry on the next tick.
                    last_pushed = None
            time.sleep(POLL_SECONDS)
    except KeyboardInterrupt:
        log.info("Stopping")
    finally:
        api.stop()
    return 0


if __name__ == "__main__":
    sys.exit(main())
