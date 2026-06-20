# AGENTS.md

## Build / test / lint

- There is **no build step**, no test suite, no linter, no CI. This is a single-entry-point PHP app deployed directly.
- The vendor directory is **pre-bundled** (no `composer.json`, no `composer install` needed). The sole dependency is `doctrine/cache` for filesystem caching.

## Architecture

- **Single entrypoint**: `index.php` — all requests are rewritten to it (Apache `.htaccess`, IIS `web.config`, nginx rules in `.htaccess` comments).
- **Platform detection** (`index.php:16-36`): auto-detects the serverless platform at runtime via environment variables (`SCF`, `FC`, `FG`, `CFC`, `Heroku`, `Vercel`, `Replit`, `Normal`).
- **Platform adapters** in `platform/`: each handles request parsing, config I/O, and response formatting for that specific hosting environment. `Normal.php` handles traditional PHP hosts/VPS. **Huawei FG detection works but the handler is non-functional** (`index.php:51-55` — just echoes `'FG'` with both platform files commented out).
- **Storage backends** in `disk/`: `Onedrive.php`, `OnedriveCN.php`, `Sharepoint.php`, `SharepointCN.php`, `Aliyundrive.php`, `AliyundriveOpen.php`, `Googledrive.php`, `BaiduDisk.php`, `Sharelink.php`. Disks are dynamically loaded by class name (`disk/{Name}.php`).
- **Multi-disk mode**: the first URL path segment is the `disktag` (e.g., `/mydisk/path/to/file`). Root path (`/`) lists all disks as folders. Config `autoJumpFirstDisk` optionally skips the disk selector.
- **Themes** in `theme/`: HTML template files. Admin always gets `classic.html`; guests use the configured theme. PHP-based themes are supported (included via `include`).

## Config system

- Config is stored as JSON wrapped in a PHP assignment: `.data/config.php` contains `<?php $configs = '<JSON>';`.
- `getConfig(key, [disktag])` reads from this file. `setConfig(arr)` writes back.
- Some platforms (SCF, Vercel, FG) support saving config in environment variables instead of the file, controlled by `ONEMANAGER_CONFIG_SAVE` env var.
- `.data/config.php` **must be writable** by the web server (chmod 666).
- The `$EnvConfigs` bitmask in `common.php:9-73` encodes per-key metadata: common vs inner (per-disk), shown vs hidden, base64-encoded, switch vs string.

## Key conventions

- Error reporting is suppressed (`error_reporting(0)` in `index.php:3`).
- `date_default_timezone_set('UTC')` is set at startup, then overridden per-request by the user's timezone config.
- All responses go through `output()` which returns a structured array `{isBase64Encoded, statusCode, headers, body}`. Platforms serialize this differently (Heroku/Vercel/Replit/SCF echo directly; FC returns a PSR-7 Response; CFC returns JSON).
- `main($path)` in `common.php:139` is the core router/dispatcher.
- Paths are normalized with `path_format()` to forward slashes, no double slashes.
- Admin auth uses a cookie + localStorage dual mechanism (`adminpass2cookie` / `adminpass2storage` / `compareadminmd5`) with a CSRF token (`_admin` form field). Default login URL is `?login=admin`; overridable via `adminloginpage` config. `sha1.min.js` is loaded client-side for password hashing.
- Disk visibility per-tag (`diskDisplay`): `""` = show, `"hidden"` = admin only, `"disable"` = redirect to first disk.
- JS libraries (`sha1.min.js`, `marked.js`, `Sortable.min.js`, `spark-md5.min.js`, `bignumber.min.js`) are served by the app itself via `?jsFile=name.js`.

## API (`api.php`)

- Requests with path starting with `/api` are intercepted early in `main()` and routed to `apiHandler()` in `api.php`.
- All API responses are JSON: `{"code": <http_status>, "data": ..., "message": "..."}`.
- Auth: set `api_key` in admin panel (PlatformConfig). Pass via `Authorization: Bearer <key>` header or `?api_key=<key>` parameter. If `api_key` is empty/unset, all endpoints are open. When set, all endpoints except `info` require auth.
- Endpoints: `list`, `file`, `download`, `upload`, `upload-session`, `delete`, `mkdir`, `rename`, `move`, `copy`. All require auth when `api_key` is set.
- Upload through server is limited to 4 MB. For larger files, use `upload-session` which returns a presigned URL for direct upload to the storage backend.
- Write operations automatically set `$_SERVER['admin'] = 1` to bypass guest restrictions, CSRF checks, and path traversal protections are skipped. This means the API has full admin access.

## File operations

- File moves/copies/renames/deletes go through the active disk driver (`$drive->Move()`, `$drive->Copy()`, etc.).
- Admin file operations require the `_admin` localStorage token to prevent CSRF.
- Guest upload directories (`guestup_path`) allow unauthenticated uploads but hide directory listings from non-admins.

## One-click update

- The update mechanism in `platform/Normal.php:OnekeyUpate()` downloads a zip from GitHub/Gitee, extracts it, copies `.data/config.php` to the new code, then replaces all files.
- Requires `php-zip` or `php-phar` extension on the server.
- The `version` file (root) tracks the current version; `needUpdate()` in `common.php` compares it against GitHub to detect available updates.
