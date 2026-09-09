# studio_beta — Camera Control

The studio-floor page an operator keeps open while running an FX30 recording
session: pick what to record, roll all five cameras at once, log the take, and
review the day.

**Entry point: `opnameViewTest.html`.** There is no `index.html`, so the bare
directory returns 500 — link to
<https://signcollect.nl/studio_beta/opnameViewTest.html>.

## What it does

The page is a single ~4400-line Dutch-language operator UI. Its first heading
is a shouted reminder to check that every battery is at least 50% charged,
which tells you what kind of page it is.

A session runs roughly like this:

1. **Choose what to record** — a gloss, a sentence (`zin`), a non-manual marker
   (`nmm`), a health-content text (`hh`), a label set, or free text. The
   choices come from the endpoints below.
2. **Watch the cameras.** The page polls the `fx30MultiRecord` controller
   through `fx30proxy.php` and shows per-camera ISO, shutter, white balance,
   frame rate, battery, card minutes and heat state, plus warnings when a
   battery or card is low.
3. **Record.** Start/stop is broadcast to all connected cameras at once. Before
   the start the page snapshots each camera's `clipName` — that is what
   predicts the resulting `<clipName>.MP4` filename — and it also captures a
   webcam clip of the operator's view in the browser.
4. **Log the take.** `save_video_studio.php` inserts a `CameraRecords` row
   (gloss, five camera slots, start/stop, user, the clip-name snapshot in
   `clips`), stores the webcam blob under `uploads/` off the install root, and
   updates `form_data.videoTop`. `fx30capturelog.php` writes the same expected
   clip names into the controller Mac's `capture_logs/{date}.json` so the
   cameras' own sync verification can see recordings this page started.
5. **Review the day.** `reviewToday.php` returns today's (or `?date=`)
   `CameraRecords` rows; the page matches them by time against the
   controller's `GET /api/captures` to show which clips actually landed.

### Endpoints

| File | Does |
|---|---|
| `fx30proxy.php` | Same-origin proxy to the camera controller's `/api/*`. The controller sends no CORS headers, so every camera call goes through here. Default host is a Tailscale name (see Configuration); override per request with `?host=` |
| `fx30capturelog.php` | Appends the expected clip names to `logs/capture_clips/{date}.json` locally **and**, over ssh, to the controller Mac's `pyqtController/capture_logs/{date}.json` |
| `fx30debuglog.php` | One line per browser↔controller event into `logs/fx30_debug.log` (rotates at 5 MB). Disable with `?fxdebug=0` on the page URL |
| `save_video_studio.php` | Persist a take: `CameraRecords` insert, `form_data.videoTop` update, webcam blob to `uploads/` |
| `fetch_last_capture.php` | The `studio_data` row for today — per-camera take counters, ready/processed flags, format state. Maps camera serials to `camera1..camera5` |
| `reviewToday.php` | The database side of the day overview |
| `fetch_data.php` | The user list (`users`) for the "which user are you?" step |
| `fetch_all2.php` | Gloss lists for the studio: by theme, by label, by status |
| `uniqueThema.php`, `uniqueLabels.php` | The theme and label dropdowns |
| `helpScripts/emptyVideoTop.php` | Clear recorded-take markers for a user/theme |
| `zin/getRows.php`, `zin/getSenses.php` | Sentences and senses |
| `nmm/fetch_*.php` | Non-manual-marker gloss and theme lists |
| `hh/api.php`, `hh/get_begrippen.php` | Health-content texts and glossary |

`db.php` is the shared helper every endpoint above uses: `getConnection()`,
`queryAll`/`queryOne`/`execute`, and `jsonResponse`.

The `zin/`, `nmm/` and `hh/` subdirectories are copies of endpoints that also
exist in `signlab_zin` and `signlab_hh`. They live here so the page can call
them same-origin. They are copies, not links — a change upstream does not
reach this page.

**The camera API is documented in [`AGENT_API_GUIDE.md`](AGENT_API_GUIDE.md)** —
every endpoint, the status shape, and the coexistence rules (poll `/api/status`,
errors come back as an `"error"` key with HTTP 200, every POST needs a JSON
body, `clipName` is the *next* clip). Read that rather than this file before
touching anything under `fx30*`.

## Where it runs

- **Production:** the signcollect core server (production VPS), served from
  `/web/studio_beta`.
- **Demo hosts:** dev2 under `/web/studio_beta`, dev-1 under
  `/srv/signcollect/web/studio_beta`.

The camera controller does **not** run on the web server. It runs on the
studio's controller Mac, next to the cameras; the web server reaches it over
Tailscale. On a demo host, which is firewalled off from the studio and from
production, every `fx30*` call fails — the page loads, the camera panel does
not fill in.

Filesystem paths are resolved through the vendored `sc_paths.php`
(`sc_dir('uploads')` etc.), which finds signcollect-lib at `../lib` or
`/web/lib` and otherwise falls back to a hardcoded `/web`. That vendored file
is byte-identical across repos — edit it in `signlab_signcollect-lib` and
re-copy, never here.

## Status

Production, and actively used during recording sessions.

## Deploying it

No build step: PHP and one HTML page, served as-is.

Deployment is driven by `interface_deploy/scripts/repos.tsv` in
[signlab_signcollect-stack](https://github.com/Amsterdam-Humanities-Labs/signlab_signcollect-stack):

```
studio_beta	signlab_studio_beta	main
```

The host clones this repo itself; the checkout *is* the docroot directory, and
each deploy is `fetch → reset --hard → clean → rewrite-urls.sh`. Locally,
serving the tree with PHP 8 and giving it the config below is enough.

## Configuration

Per-host, never in git:

- **Database credentials.** `db.php` looks, in order, for
  `mysql_config.php` in this directory, then `../lib/compat/mysql_config.php`,
  then `/web/lib/compat/mysql_config.php`. A deployed host wants the second or
  third — signcollect-lib at `/web/lib`, reading `/web/.env`, which
  `provision.sh` writes once. `mysql_config.example.php` is a stub for a host
  that is not on the library yet; copy it to `mysql_config.php` and fill in the
  four variables. Without any of the three, every endpoint 500s before it reads
  the request.
- **`logs/`** must exist and be writable by `www-data` (created on demand by
  `fx30capturelog.php`, not by `fx30debuglog.php`). Its contents are
  gitignored.
- **The `uploads/` directory** off the install root (`/web/uploads` by default)
  must be writable — that is where the webcam blobs land.
- **An ssh key readable by the web server user**, for `fx30capturelog.php`.
  Target and key path are hardcoded at the top of that file
  (`signlab@100.66.221.75`, `/home/gomer/.ssh/id_ed25519`). If ssh fails the
  local log still succeeds and the endpoint answers `logged-local-only`.
- **Camera controller host.** `fx30proxy.php` defaults to
  `signlabs-mini.taila8bdbd.ts.net:8080` — the controller Mac over Tailscale.
  Per-request override: `opnameViewTest.html?camhost=192.168.1.50:8080`.
  Note this differs from the guide's `localhost:8080`, which is the address on
  the controller Mac itself. TODO: confirm whether that default should be
  configuration rather than a hardcoded constant.

## Dependencies

- **`fx30MultiRecord`** (repo `signlab_Sony-SDK-MACOS-API`) — the C++ camera
  controller. It listens on port 8080 on the controller Mac (`localhost:8080`
  from that machine's own point of view) and drives the Sony FX30s over USB.
  Without it the page cannot see or start a camera. See `AGENT_API_GUIDE.md`.
- **The PyQt controller app** on the same Mac, which owns
  `pyqtController/capture_logs/` — the reason `fx30capturelog.php` exists.
- **MySQL `admin_gebarenoverleg`** — `CameraRecords`, `studio_data`,
  `form_data`, `sentences`, `users`, `nmm_data`, and the `hh_*` tables behind
  `hh/api.php`.
- **`signlab_signcollect-lib`** at `/web/lib` for credentials and path
  resolution.
- **The portal session cookie** — the page loads `/userProtect.js` from the
  docroot root. The PHP endpoints do not authenticate individually.
