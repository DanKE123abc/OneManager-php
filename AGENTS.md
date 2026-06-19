# AGENTS.md

## What is this project

OneManager-php: a single-entry-point PHP app that provides a web interface to manage files across multiple cloud drives (OneDrive, SharePoint, Aliyundrive, BaiduDisk, GoogleDrive, Sharelink). Deployed to serverless platforms (Vercel, Replit, Tencent SCF, Aliyun FC, Huawei FG, Baidu CFC, Heroku) or a normal VPS/PHP host.

## Run locally

```
php -S 0.0.0.0:8000 index.php
```

## Requirements

- PHP with `curl` extension enabled (hard requirement, checked at startup)
- URL rewrite support (`.htaccess` for Apache, `web.config` for IIS, nginx rewrite rule)
- `.data/config.php` must be writable by the web server (chmod 666 recommended)

## Architecture

All requests route to `index.php`, which auto-detects the deployment platform via environment variables (`$_SERVER` superglobals) and loads the matching adapter from `platform/`. The adapter normalizes the request, then calls `main($path)` in `common.php`.

### Directory structure

- `index.php` — entry point, platform detection, request dispatch
- `common.php` — core logic: `main()`, auth, file ops, rendering, config, curl helpers
- `conststr.php` — i18n strings and file extension mappings
- `disk/` — cloud drive drivers (one class per provider). `Onedrive.php` is the base; others extend or mirror it
- `platform/` — platform adapters (one per deployment target)
- `theme/` — HTML templates (`.html`). Loaded by `render_list()` in common.php
- `js/` — client-side libraries (sha1, Sortable, marked, spark-md5, bignumber)
- `.data/config.php` — runtime config (must be writable). Stores all settings and disk tokens
- `vendor/` — composer autoload + `doctrine/cache` (FilesystemCache for temp caching)

### Key flow

1. `index.php` detects platform → loads `platform/*.php`
2. Platform adapter sets `$_SERVER`, `$_GET`, `$_POST` → calls `getpath()` + `getGET()` + `main($path)`
3. `main()` handles: install wizard, admin login, disk operations (add/list/delete/rename/move/copy/encrypt), file listing, file download, thumbnails, random file, JSON API
4. Each disk driver class implements: `list_files()`, `AddDisk()`, `Rename()`, `Delete()`, `Move()`, `Copy()`, `Encrypt()`, `Create()`, `get_thumbnails_url()`, `bigfileupload()`, `smallfileupload()`

### Config system

Config is stored in `$EnvConfigs` array in `common.php`. Each key has bitfield flags controlling: inner/common, shown/hidden, base64-encoded, switch type. Config is read via `getConfig($key, $disktag)` and written via `setConfig()`. On Vercel/SCF, config can be saved in env vars or in file (`ONEMANAGER_CONFIG_SAVE`).

### Platform detection

Detected by checking `$_SERVER` / `$_ENV` constants in `checkPlatform()` at `index.php:16`. Each platform has a dedicated adapter file in `platform/`.

### REST API

All requests route to `index.php`, which auto-detects the deployment platform via environment variables (`$_SERVER` superglobals) and loads the matching adapter from `platform/`. The adapter normalizes the request, then calls `main($path)` in `common.php`.

#### Authentication
- Token-based: `Authorization: Bearer <token>` header
- Tokens generated/managed in admin panel (`?setup`)
- Stored in `.data/config.php` as `apitoken`

#### Endpoints
Base: `/api/{disktag}/{action}?path=...`

| Method | Endpoint | Body | Description |
|--------|----------|------|-------------|
| GET | `list` | - | List directory |
| GET | `info` | - | File metadata |
| GET | `download` | - | 302 redirect to download URL |
| POST | `upload` | multipart `file` | Upload file (≤4MB) |
| POST | `delete` | `{name, id?}` | Delete file/folder |
| POST | `rename` | `{oldname, newname, id?}` | Rename |
| POST | `move` | `{name, folder, id?}` | Move file |
| POST | `copy` | `{name, id?}` | Copy file |
| POST | `create` | `{type, name, content?}` | Create file/folder |
| POST | `edit` | `{name, content}` | Edit file content |
| POST | `diskspace` | - | Get disk space |

#### Response format
```json
{ "ok": true, "data": {...} }
{ "ok": false, "error": { "code": 404, "message": "..." } }
```

## Gotchas

- **No composer.json** — vendor is committed directly. Don't run `composer install`.
- **Vercel config limit** — env vars are <4KB. Config saved in file by default; add `ONEMANAGER_CONFIG_SAVE=env` to use env vars.
- **After Vercel update** — must reinstall (config in file gets overwritten). If using env vars, add the env var before updating.
- **SCF config** — also saved in file by default; reinstall after update.
- **Login page** — default is `?login=admin` (changed from `?admin` in v43). Customizable via `adminloginpage` setting.
- **Admin password** — SHA1-hashed client-side before submit. Cookie-based auth with 7-day expiry.
- **Themes** — admin always uses `classic.html`. Guest themes are selectable but only from `theme/` directory or a remote URL.
- **File caching** — uses `doctrine/cache` FilesystemCache in system temp dir (or `tmp/` fallback). Cache keys are host+disktag based.
- **PHP version** — targets PHP 7.2+. Some providers (SCF, CFC) use PHP 7.2/7.3.
- **Windows** — path separator auto-detected (`\` vs `/`) at `index.php:19`.

## Development notes

- There are no automated tests, linter, or typecheck.
- There is no build step — PHP is interpreted directly.
- The project uses `error_reporting(0)` — errors are suppressed by default.
- To debug, uncomment the `error_reporting(E_ALL & ~E_NOTICE)` line at `index.php:2`.
- Disk drivers follow a common interface pattern but are not formally abstracted (no interface/trait). `Onedrive.php` is the reference implementation.
- Themes use placeholder markers like `<!--targetStart-->` / `<!--targetEnd-->` / `/*--targetStart--*/` / `/*--targetEnd--*/` for content injection.
