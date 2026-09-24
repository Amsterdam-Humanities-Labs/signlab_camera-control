# signlab_camera-control
Camera Control: the page an operator keeps open during an FX30 recording session.

## What it does
The entry point is `opnameView.html`; there is no `index.html`. `opnameLR.html` is the QR screen (see below). The old name `opnameViewTest.html` redirects to it.
The operator picks what to record: a gloss, a sentence (`zin`), `nmm`, an `hh` text or a label set.
The page shows and starts the studio's cameras (five FX30s; see [Cameras](#cameras)) through the `fx30MultiRecord` controller, logs each take and lists the day's takes.
The controller (`fx30MultiRecord`, in [signlab_Sony-SDK-MACOS-API](https://github.com/Amsterdam-Humanities-Labs/signlab_Sony-SDK-MACOS-API)) is shared with the PyQt app: never start or restart it, and wait while `/api/status` shows `scanning`, `downloading` or `listing` (cameras disappear then; that is normal).
Commands are broadcast to all cameras; drive Start/Stop from the polled `recording` state, and log expected clip names before `/api/start` (`fx30capturelog.php`).

| File | Does |
|---|---|
| `fx30proxy.php` | same-origin proxy to the controller's `/api/*`, which sends no CORS headers. `?host=` overrides the host. Needs a login (`require_login.php`) |
| `fx30capturelog.php` | needs a login. Writes expected clip names to `logs/capture_clips/{date}.json`, and over ssh to `capture_logs/` on the DRS |
| `fx30debuglog.php` | browser and controller events to `logs/fx30_debug.log`. Turn off with `?fxdebug=0` |
| `save_video_studio.php` | saves a take: inserts into `CameraRecords`, sets `form_data.videoTop`, stores the webcam blob in `uploads/` |
| `fetch_last_capture.php` | today's `studio_data` row: counters and flags per camera (serial → column from `cameras.json`) |
| `reviewToday.php` | today's (or `?date=`) `CameraRecords` |
| `lookups.php?what=` | dropdowns: `thema`, `labels`, `users`, `nmm_themas` |
| `fetch_glosses.php`, `nmm/fetch_*.php` | gloss lists by theme, label or status; NMM lists |
| `helpScripts/emptyVideoTop.php` | clears the recorded-take markers for a user and theme |
| `zin/getRows.php`, `hh/get_begrippen.php` | sentences (with `videoTop`); the glossary of health terms |

The health texts come from `/hh/api.php` ([signlab_patient-info](https://github.com/Amsterdam-Humanities-Labs/signlab_patient-info)) on the same origin. `db.php` is the shared DB helper.

## QR screen (`opnameLR.html`)
The second screen in the studio, facing the cameras. It shows a full-screen QR code while a take records, so every camera films which gloss it is.
The QR holds `[glosId, selectedType, "HH:MM:SS"]`. Camera Control's webcam reads it back (jsQR) to check that the screen works.
The controller app (`pyqtController/fx30_controller.py` in signlab_Sony-SDK-MACOS-API) opens it full screen in a Chrome kiosk on the extended monitor ("QR-scherm openen"). The URL is `display_url` in that app's local `config.json`, not in git.
On production the page is served from the web root, <https://signcollect.nl/opnameLR.html> (`/web/opnameLR.html`). From this repo it deploys to `/studio_beta/opnameLR.html`; the deploy adds an alias `/opnameLR.html` so the kiosk URL keeps working.

It needs the `studioSupport` websocket server at `wss://signcollect.nl/studioSupport/` (node, port 3010, only on production in `/home/gomer/node_servers/studioSupport`, in no repo). Protocol, JSON:
- Camera Control sends `{glosId, selectedType, status}`, status `hello`, `glos`, `start` or `stop`. The server keeps the latest and broadcasts it to all clients, and replays it to each new connection.
- `start` shows the QR, `stop` hides it. On `glos` and `hello` the QR page answers `{glosId, callback: "callback"}`; the server broadcasts `{callback: "callback"}` and Camera Control closes its "waiting for QR screen" modal.

The websocket URL is a literal, like the ones in `opnameView.html`; the demo deploy rewrites it with `rewrite-urls.sh`.

## Where it runs
Core server, `/web/studio_beta`, <https://signcollect.nl/studio_beta/opnameView.html>.
The camera controller runs on the DRS (the studio Mac with the FX30 cameras), reached over Tailscale. On demo hosts the camera panel stays empty.

## Status
Production.

## How to run / deploy
There is no build step. The stack deploys it through `interface_deploy/scripts/repos.tsv` in
[signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack).

## Configuration
- DB credentials: `db.php` tries `./mysql_config.php`, then `../lib/compat/mysql_config.php`, then `/web/lib/compat/mysql_config.php`. Template for the first: `mysql_config.example.php`.
- `logs/` and `/web/uploads` must be writable by `www-data`.
- `fx30capturelog.php` hardcodes the ssh target `signlab@100.66.221.75` and the key `/home/gomer/.ssh/id_ed25519`.
- `fx30proxy.php` defaults to `signlabs-mini.taila8bdbd.ts.net:8080`. The page takes `?camhost=host:port` to override it.

## Cameras
The camera list is in `cameras.json` next to `opnameView.html`. It is not in git; copy `cameras.example.json` (the five FX30s of the studio) and edit it.
If `cameras.json` is missing, the page and `fetch_last_capture.php` use `cameras.example.json`.

```json
{ "D4DA001EACEA": "camera1", "D4DA001EAC65": "camera2", "D4DA001EAD5C": "camera3" }
```

- Key: the camera's serial. Value: `camera1` to `camera5`, the columns in `CameraRecords` and `studio_data`. There is no room for a sixth camera.
- The number of entries is the number of cameras that must be online. With fewer, the page shows a warning and asks before recording.
- The page loads the file at start-up (`no-store`) and only then starts polling the controller. Reload the page after a change.
- Finding a serial: the controller's `/api/status` lists each camera with `model` like `ILME-FX30 (D4DA001EC952)`; the serial is the part in brackets. With a login, open `fx30proxy.php?path=/api/status`.
- The file is served publicly. Serials are not secret; put nothing else in it.

## Dependencies
- `fx30MultiRecord` from [signlab_Sony-SDK-MACOS-API](https://github.com/Amsterdam-Humanities-Labs/signlab_Sony-SDK-MACOS-API), on port 8080 of the DRS, plus its PyQt app (`capture_logs/`).
- MySQL `admin_gebarenoverleg`; [signlab_signcollect-lib](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-lib) at `/web/lib`.
- signlab_patient-info (`/hh/api.php`), and the portal session cookie through `/userProtect.js`.
- Only `fx30proxy.php` and `fx30capturelog.php` check the login. The other PHP endpoints do not.

## License and citation

Apache License 2.0, copyright University of Amsterdam: see [LICENSE](LICENSE) and
[NOTICE](NOTICE). You may use it, also commercially, as long as you credit
Gomer Otterspeer / University of Amsterdam as the source. To cite it, use
[CITATION.cff](CITATION.cff) (the *Cite this repository* button on GitHub) or the DOI [10.21942/uva.33980314](https://doi.org/10.21942/uva.33980314).
