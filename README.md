# studio_beta
Camera Control: the page an operator keeps open during an FX30 recording session.

## What it does
Entry point `opnameViewTest.html` (no `index.html`). Pick what to record (gloss, `zin`, `nmm`, `hh` text, label set),
watch and roll all five cameras via the `fx30MultiRecord` controller, log each take, review the day.
Camera API: see [`AGENT_API_GUIDE.md`](AGENT_API_GUIDE.md) before touching anything `fx30*`.

| File | Does |
|---|---|
| `fx30proxy.php` | same-origin proxy to the controller's `/api/*` (it sends no CORS headers); `?host=` override; login required (`require_login.php`) |
| `fx30capturelog.php` | (login required) expected clip names to `logs/capture_clips/{date}.json` and, over ssh, the controller Mac's `capture_logs/` |
| `fx30debuglog.php` | browser/controller events to `logs/fx30_debug.log`; off with `?fxdebug=0` |
| `save_video_studio.php` | persist a take: `CameraRecords` insert, `form_data.videoTop`, webcam blob to `uploads/` |
| `fetch_last_capture.php` | today's `studio_data` row: per-camera counters and flags |
| `reviewToday.php` | today's (or `?date=`) `CameraRecords` |
| `lookups.php?what=` | dropdowns: `thema`, `labels`, `users`, `nmm_themas` |
| `fetch_all2.php`, `nmm/fetch_*.php` | gloss lists by theme / label / status; NMM lists |
| `helpScripts/emptyVideoTop.php` | clear recorded-take markers for a user/theme |
| `zin/getRows.php`, `hh/get_begrippen.php` | sentences (with `videoTop`), health-content glossary |

Health-content texts come from `/hh/api.php` (signlab_hh) on the same origin. `db.php` is the shared DB helper.

## Where it runs
Core server, `/web/studio_beta` → <https://signcollect.nl/studio_beta/opnameViewTest.html>.
The camera controller runs on the studio Mac, reached over Tailscale; on demo hosts the camera panel stays empty.

## Status
Production.

## How to run / deploy
No build step. Deployed by `interface_deploy/scripts/repos.tsv` in
[signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack).

## Configuration
- DB credentials: `db.php` tries `./mysql_config.php`, then `../lib/compat/mysql_config.php`, then `/web/lib/compat/mysql_config.php`.
- `logs/` and `/web/uploads` writable by `www-data`.
- `fx30capturelog.php` hardcodes ssh target `signlab@100.66.221.75` and key `/home/gomer/.ssh/id_ed25519`.
- `fx30proxy.php` defaults to `signlabs-mini.taila8bdbd.ts.net:8080`; page override `?camhost=host:port`.

## Dependencies
- `fx30MultiRecord` (`signlab_Sony-SDK-MACOS-API`) on port 8080 of the controller Mac, plus its PyQt app (`capture_logs/`).
- MySQL `admin_gebarenoverleg`; `signlab_signcollect-lib` at `/web/lib`.
- `signlab_hh` (`/hh/api.php`); portal session cookie via `/userProtect.js`. The PHP endpoints here do not authenticate individually.
