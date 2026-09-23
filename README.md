# signlab_studio_beta
Camera Control: the page an operator keeps open during an FX30 recording session.

## What it does
The entry point is `opnameViewTest.html`; there is no `index.html`.
The operator picks what to record: a gloss, a sentence (`zin`), `nmm`, an `hh` text or a label set.
The page shows and starts all five cameras through the `fx30MultiRecord` controller, logs each take and lists the day's takes.
Read [`AGENT_API_GUIDE.md`](AGENT_API_GUIDE.md) before you change anything `fx30*`.

| File | Does |
|---|---|
| `fx30proxy.php` | same-origin proxy to the controller's `/api/*`, which sends no CORS headers. `?host=` overrides the host. Needs a login (`require_login.php`) |
| `fx30capturelog.php` | needs a login. Writes expected clip names to `logs/capture_clips/{date}.json`, and over ssh to `capture_logs/` on the DRS |
| `fx30debuglog.php` | browser and controller events to `logs/fx30_debug.log`. Turn off with `?fxdebug=0` |
| `save_video_studio.php` | saves a take: inserts into `CameraRecords`, sets `form_data.videoTop`, stores the webcam blob in `uploads/` |
| `fetch_last_capture.php` | today's `studio_data` row: counters and flags per camera |
| `reviewToday.php` | today's (or `?date=`) `CameraRecords` |
| `lookups.php?what=` | dropdowns: `thema`, `labels`, `users`, `nmm_themas` |
| `fetch_glosses.php`, `nmm/fetch_*.php` | gloss lists by theme, label or status; NMM lists |
| `helpScripts/emptyVideoTop.php` | clears the recorded-take markers for a user and theme |
| `zin/getRows.php`, `hh/get_begrippen.php` | sentences (with `videoTop`); the glossary of health terms |

The health texts come from `/hh/api.php` ([signlab_hh](https://github.com/Amsterdam-Humanities-Labs/signlab_hh)) on the same origin. `db.php` is the shared DB helper.

## Where it runs
Core server, `/web/studio_beta`, <https://signcollect.nl/studio_beta/opnameViewTest.html>.
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

## Dependencies
- `fx30MultiRecord` from [signlab_Sony-SDK-MACOS-API](https://github.com/Amsterdam-Humanities-Labs/signlab_Sony-SDK-MACOS-API), on port 8080 of the DRS, plus its PyQt app (`capture_logs/`).
- MySQL `admin_gebarenoverleg`; [signlab_signcollect-lib](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-lib) at `/web/lib`.
- signlab_hh (`/hh/api.php`), and the portal session cookie through `/userProtect.js`.
- Only `fx30proxy.php` and `fx30capturelog.php` check the login. The other PHP endpoints do not.
