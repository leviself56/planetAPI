# Planet GT-915A PHP API

A lightweight PHP 7+ client for managing Planet GT-915A fiber converters/switches via their undocumented CGI interface. The `PlanetAPI` class wraps authentication, session cookies, data retrieval, configuration writes, backups, and safe reboot/save flows while presenting consistent, structured error information.

## Features
- **Session-aware HTTP layer** built on cURL with automatic SEID handshake, cookie jars, and optional debug logging.
- **Read endpoints** for system info, resource utilization, IPv4 configuration, bandwidth profiles, port status, SFP diagnostics, and VLAN tables.
- **Write helpers** for updating device metadata, shaping bandwidth per port, saving configuration, rotating credentials, performing backups, and rebooting.
- **Automatic persistence**: write operations trigger the switch’s `save.cgi` flow and retry after credential changes to ensure settings stick.
- **Consistent error payloads**: every public method returns either data/`true` or an array like `{ success: false, operation: '...', error: '...' }`—no fatal errors leak out.
- **Utility scripts**: `test.php` for quick smoke checks and `examples.php` for a documented tour of the API surface.

## Requirements
- PHP 7.2+ with the cURL and JSON extensions enabled (default on most installations).
- Network access to a Planet GT-915A running the standard web firmware.
- CLI access to run the provided scripts (`php test.php`).


## Getting Started
1. **Clone / copy** this repository onto a machine that can reach the switch.
2. **Install dependencies** – none beyond PHP 7+cURL.
3. **Update credentials** in `test.php` or `examples.php` (`base_url`, `username`, `password`).
4. **Run a quick check**:
   ```bash
   php test.php
   ```
   You should see a JSON dump of `printSwitchData()` plus a backup archive saved in your system temp directory.

## Usage
### Basic include
```php
require_once __DIR__ . '/class.planet.php';

$config = [
    'base_url' => 'http://192.168.188.140',
    'username' => 'admin',
    'password' => 'admin',
    'timeout'  => 15,
];

$result = PlanetAPI::getSystemInfo($config);
if (isset($result['success']) && $result['success'] === false) {
    var_dump($result); // structured error payload
} else {
    print_r($result);
}
```

### Examples script
`examples.php` walks through every public method with helpful comments and formatted output. Run it with:
```bash
php examples.php
```
Sensitive actions (credential rotation, reboot) are commented out by default—uncomment only when needed.

### Debugging
Enable verbose logging to STDERR to inspect requests/responses:
```php
PlanetAPI::setDebug(true);
```
You can also provide a custom logger callback if you want to pipe messages elsewhere.

## Experience API Endpoints
The repository includes an HTTP-friendly Experience API under `api/v1/`. Each endpoint lives in its own folder (for example, `api/v1/system/info`) with an `index.php` entry point and a `README.md` describing method, inputs, responses, and common errors.

- **Routing**: place the `api/v1/.htaccess` file on your server (or copy the rewrite rules into your Apache/Nginx config) so URLs such as `/api/v1/system/info/10.15.100.2` map to the correct PHP script and expose the `switch_ip` via `PATH_INFO`. You can also pass `?switch_ip=` when rewrites are unavailable.
- **Authentication**: per-request overrides are supported through query parameters (`username`, `password`, `timeout`). When omitted, defaults from `api/v1/config.php` apply.
- **Response shape**: every endpoint returns pretty-printed JSON using the same `{ success, operation, data, meta }` envelope, with structured error objects when something fails.

Refer to each endpoint’s README (e.g., `api/v1/system/summary/README.md`) for exact payloads covering system info, resources, network config, summary snapshots, port status, bandwidth writes, credential changes, backups, and reboots.

## Error Handling
All public methods wrap their work in `guardOperation()`. On failure you get:
```php
[
    'success'   => false,
    'operation' => 'getSystemInfo',
    'error'     => 'Planet API responded with HTTP 401...',
    'code'      => 401,
    'context'   => ['endpoint' => '/cgi-bin/sysinfo.cgi', 'system' => 'lab-switch @ http://...']
]
```
This makes it easy to bubble errors back to higher-level services or APIs without fatal exceptions.

## Backup Archives
`PlanetAPI::makeBackup()` mirrors the browser workflow: it triggers `/cgi-bin/back.cgi`, waits for a successful `bktar` status, downloads `/tmp/current.tar.gz`, and writes the archive to `sys_get_temp_dir()` (e.g., `/var/folders/.../planet_backup_<timestamp>.tar.gz`). Capture the return value to know exactly where the file landed.

## Project Layout
- `class.planet.php` – the core client library.
- `test.php` – minimal smoke test dumping `printSwitchData()` and forcing a backup.
- `examples.php` – comprehensive cookbook covering every public method.

## Contributing
Issues and PRs are welcome. If you add new endpoints, follow the existing pattern:
1. Create a helper that performs the raw request/parse.
2. Wrap the public method in `guardOperation()`.
3. Return structured errors and, for writes, auto-save when appropriate.

## License
Specify your license of choice here (MIT, Apache 2.0, etc.).
