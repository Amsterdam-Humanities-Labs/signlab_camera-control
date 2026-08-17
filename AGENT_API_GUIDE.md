# FX30 Multi-Camera Controller — REST API Guide for Agents

Instructions for an AI agent (or developer) building a web application against the
`fx30MultiRecord` camera controller. This document is self-contained — you do not
need the Sony SDK or the C++ source to use the API.

## What this service is

A single C++ process (`fx30MultiRecord`) that connects to multiple Sony FX30 cameras
over USB and exposes an HTTP server (default `http://localhost:8080`). It serves an
embedded HTML dashboard at `/` and a JSON REST API under `/api/*`. Your web app talks
ONLY to this HTTP API — never to the cameras directly.

Start it (if not already running):

```bash
cd simpleCli/build/Mac
./fx30MultiRecord --port 8080 --download-path /tmp/fx30_downloads
# optional: --preset <file.json>   (default: fx30_preset.json)
```

## Core rules

1. **`GET /api/status` is your single source of truth.** Poll it (1–2 s interval is
   fine) to drive all UI state. There are no websockets or push events.
2. **All responses are `application/json`** except `GET /` (HTML). HTTP status is
   always 200 — **errors are signaled by an `"error"` key in the JSON body**, not by
   HTTP status codes. Always check for `"error"` before assuming success.
3. **Long operations are asynchronous.** `POST /api/scan`, `/api/reset`,
   `/api/download`, and `/api/list-files` return immediately ("started"); track
   progress via the `scanning`/`scanStatus`, `downloading`/`downloadStatus`, and
   `listing`/`listStatus` fields of `/api/status`.
4. **The server rejects conflicting operations** with `{"error":"Download in progress"}`,
   `{"error":"Scan already in progress"}`, `{"error":"Listing in progress"}`, or
   `{"error":"Busy"}`. Disable the relevant UI buttons while `scanning`,
   `downloading`, or `listing` is true.
5. **Commands are broadcast.** Start/stop/format/preset-apply act on ALL connected
   cameras at once; there is no per-camera addressing in the API. Responses report
   aggregate counts: `{"ok":5,"failed":0}`.
6. **Every POST must include a JSON body** — send at least `{}` with
   `Content-Type: application/json`. A bare POST with no body returns HTTP 400 with
   an empty response. (`curl -X POST url` fails; `curl -X POST -H "Content-Type: application/json" -d '{}' url` works.)
7. No authentication. CORS headers are not set, so serve your web app from the same
   origin or proxy the API.
8. **Clip name semantics:** each camera's `clipName` field is the name of the NEXT
   clip to be recorded (it stays fixed while recording and increments after stop).
   To predict the filename of a capture, snapshot `clipName` immediately BEFORE
   `POST /api/start`; the recorded file will be `<clipName>.MP4`.

## Endpoints

### GET /api/status
Returns the full system state:

```json
{
  "cameras": [
    {
      "index": 0,
      "model": "ILME-FX30 (D4DA001EC952)",
      "connected": true,
      "recording": false,
      "battery": 100,
      "iso": "ISO 400",
      "shutterSpeed": "1/500",
      "fNumber": "F4",
      "whiteBalance": "ColorTemp",
      "colorTemp": 5600,
      "mediaSlot1Min": 7276,
      "mediaSlot2Min": 0,
      "movieFormat": "XAVC HS 4K",
      "recSetting": "100M 422 10bit",
      "frameRate": "59.94p",
      "clipName": "B20260610_4681",
      "heatState": 0
    }
  ],
  "downloading": false,
  "downloadStatus": "",
  "downloadPath": "/tmp/fx30_downloads",
  "scanning": false,
  "scanStatus": "Scan complete. 5 new camera(s), 5 total.",
  "listing": false,
  "listStatus": "Listing complete. 1060 file(s).",
  "presetPath": "fx30_preset.json",
  "hasPreset": false
}
```

Field notes:
- `cameras` is empty until a scan has connected cameras.
- The serial number in parentheses in `model` is the stable per-camera identifier.
- `mediaSlot1Min`/`mediaSlot2Min`: remaining recording minutes per card slot
  (0 = no card or full).
- `heatState`: 0 = OK, 1 = pre-overheat warning, 2 = overheating. Surface 1/2
  prominently in the UI.
- `battery` is a percentage.
- `recording` flips on/off via the start/stop endpoints below; reflect it per camera.

### POST /api/start  /  POST /api/stop
Start/stop movie recording on all cameras. No body.
Response: `{"ok":<n>,"failed":<n>}` or `{"error":"Download in progress"}`.
After issuing, keep polling `/api/status` until every camera's `recording` reflects
the new state (it can lag a second or two).

### POST /api/scan
Rescan USB for new cameras (already-connected ones are kept). No body.
Response: `{"status":"scan started"}` then poll `scanning`/`scanStatus`.
A full scan with connection attempts can take **several minutes** if cameras are
unresponsive (3 connect attempts per camera with USB resets in between).

### POST /api/reset
Full USB reset of all cameras, then rescan. No body. Use when cameras show as
disconnected or a scan finds nothing. Response: `{"status":"reset started"}`.
Note: if cameras repeatedly fail with connect timeouts even after `/api/reset`,
they need a **physical power cycle** — tell the user; software cannot fix that state.

### POST /api/format
Formats media in **slot 1** of every connected camera. The request body is ignored
(the README's `{"slot":N}` parameter is not implemented). Response: `{"ok":n,"failed":n}`
or `{"error":"Busy"}`. **Destructive — always require explicit user confirmation in
your UI before calling this.**

### POST /api/list-files  /  GET /api/files
`POST /api/list-files` enumerates every file on every camera WITHOUT downloading
(body `{}`). Async like download: cameras briefly switch out of Remote mode
(~1 min for ~1000 files); track via `listing`/`listStatus` in `/api/status`.
While listing, all other camera operations return `{"error":"Listing in progress"}`.
When done, `GET /api/files` returns the last result:
`{"files":{"cameras":{"ILME-FX30 (SERIAL)":["A20260610_0001.MP4",...]},"total":N,"errors":N}}`
(`files` is `null` if no listing has run yet). Use this to verify clips are
safely backed up before calling `/api/format`.

### POST /api/download
Download clips from all cameras to the server-side download path. Optional body
`{"path":"/some/dir"}` overrides the path for this and future downloads.
Response: `{"status":"download started"}`; progress in `downloading`/`downloadStatus`.
Files land on the machine running the controller, not in the browser. Cameras cannot
record while a download is in progress.

### POST /api/set-download-path
Body: `{"path":"/some/dir"}`. Response: `{"downloadPath":"..."}` or
`{"error":"Missing path"}`.

### Presets (camera settings snapshots)
- `POST /api/preset/save` — read settings from the first connected camera and write
  them to the preset file. Response: `{"status":"Preset saved to ..."}` or
  `{"error":"No cameras connected"}`.
- `POST /api/preset/apply` — apply the preset file to all cameras. Response:
  `{"applied":<count>}` or `{"error":"No preset file found at ..."}`. Applying is
  slow (~0.5 s per property per camera); poll status to watch values change.
- `GET /api/preset` — returns `{"preset":<json-or-null>,"path":"..."}`.

## Multiple clients — coexistence rules

This server is shared by more than one client (a PyQt desktop app at
`pyqtController/fx30_controller.py` and a web app). The server itself is
single-instance and stateless toward clients: it does not know or care who
calls it. To keep clients from interfering with each other, follow these rules:

1. **Never start your own server instance.** Exactly one `fx30MultiRecord`
   process can exist — the cameras are exclusive USB devices, and a second
   instance will fight over them and break both. Connect to the existing server
   on port 8080. Do not kill or restart the server process; the PyQt app owns
   process lifecycle (its "Restart Sony SDK" button). If the API is unreachable,
   surface that to the user instead of relaunching it yourself.

2. **Treat the busy flags as a shared lock.** Before triggering any long
   operation (`/api/download`, `/api/list-files`, `/api/scan`, `/api/reset`),
   check `/api/status`: if `scanning`, `downloading`, or `listing` is true,
   ANOTHER CLIENT may have started it — wait, don't retry-hammer. A `Busy`-type
   error response means exactly this. Never assume a long operation you observe
   was started by you.

3. **Expect camera "disappearances" you didn't cause.** During a download or
   listing (possibly triggered by the other client), `cameras` goes empty and
   recording is impossible for minutes. Don't alarm the user or fire resets when
   `downloading`/`listing` is true — that is normal operation. Only treat empty
   `cameras` as a fault when ALL THREE busy flags are false.

4. **Recording is a broadcast-shared state.** If you show Start/Stop buttons,
   drive them from the polled `recording` fields, not from your own click
   history — the other client (or the embedded dashboard at `/`) can start or
   stop recording at any time.

5. **Capture bookkeeping convention.** The PyQt app records expected clip
   filenames (snapshot of each camera's `clipName` BEFORE `/api/start`) into
   `pyqtController/capture_logs/{YYYY-MM-DD}.json` — one JSON array of
   `{"time": iso, "clips": {"<serial>": "<clipName>.MP4"}}` entries. If your
   client triggers recordings, append to the same files in the same format
   (atomic read-modify-write), otherwise those captures are invisible to the
   sync-verification workflow.

6. **Don't call `/api/format` directly.** Formatting erases camera media. The
   established safety flow is: `/api/list-files` → compare every camera clip
   against the research drive (`rclone lsf` per recording date parsed from the
   filename) → sync anything missing → only then format. If your client offers
   formatting, implement the same check; never expose a bare format button.

7. **Downloads land server-side.** `/api/download` writes to a path on the Mac
   running the server. The established convention: download to the staging
   inbox (`/Volumes/cacheDisk/fx30_staging/inbox`), sort clips into
   `fx30_staging/{date}/raw/` by the date embedded in each filename, then
   `rclone copy` each date folder to
   `signcollect:…/studioFiles/{date}/raw`. Do NOT download directly into the
   `~/signCollect` FUSE mount — it reports 0 bytes free (personal quota) and the
   SDK will error out on every file. If both clients implement sync, they must
   not run it concurrently (the `downloading` flag is your lock for step 1;
   rclone tolerates concurrent copies but you'll waste bandwidth).

8. **Verify against the remote, not the mount.** The FUSE mount's directory
   cache is up to 2 hours stale. Use `rclone lsf -R` on the remote for any
   "is the file really on the drive?" decision.

## Recommended app flow

1. On load: `GET /api/status`. If `cameras` is empty and `scanning` is false,
   offer a "Scan" button (`POST /api/scan`).
2. Poll `/api/status` every 1–2 s; render camera cards from `cameras[]`.
3. Record controls: one global Start/Stop pair driven by whether any camera has
   `recording:true`. Disable while `scanning || downloading`.
4. Show `scanStatus` / `downloadStatus` strings verbatim — they are human-readable
   progress messages.
5. Treat `connected:false` (or an empty `cameras` list) as a fault state ONLY
   when `scanning`, `downloading`, and `listing` are all false; offer
   `POST /api/reset`, and if that fails repeatedly, instruct the user to
   power-cycle the cameras (USB-visible-but-unresponsive cameras cannot be
   recovered in software).

## Quick smoke test

```bash
curl -s localhost:8080/api/status | python3 -m json.tool                                  # state
curl -s -X POST -H "Content-Type: application/json" -d '{}' localhost:8080/api/start      # {"ok":5,"failed":0}
curl -s -X POST -H "Content-Type: application/json" -d '{}' localhost:8080/api/stop       # {"ok":5,"failed":0}
```
