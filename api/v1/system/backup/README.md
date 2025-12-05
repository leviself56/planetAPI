# System Backup API

- **Method:** `POST`
- **Path:** `/api/v1/system/backup/{switch_ip}`

## Inputs
Parameters:
- `switch_ip` (required)
- Optional `username`, `password`, `timeout`
- Optional `include_data` (`true`/`false`): When `true`, the response embeds the backup archive base64-encoded. Defaults to `false` to avoid large payloads.

Body is not required.

## Response
```json
{
  "success": true,
  "operation": "system.backup",
  "data": {
    "file_path": "/var/folders/.../planet_backup_1733422800.tar.gz",
    "filename": "planet_backup_1733422800.tar.gz",
    "file_size": 53248,
    "sha256": "7bb7...",
    "content_base64": "H4sIAAAAA..." // only when include_data=true
  },
  "meta": {
    "timestamp": "2025-12-05T15:04:05Z"
  }
}
```

## Errors
Example when switch IP is missing:
```json
{
  "success": false,
  "error": {
    "operation": "system.backup",
    "message": "Missing switch_ip. Provide it as a query segment or parameter."
  }
}
```
Switch failure while generating backup:
```json
{
  "success": false,
  "error": {
    "operation": "system.backup",
    "message": "Planet API responded with HTTP 500 ...",
    "details": {
      "endpoint": "/cgi-bin/back.cgi"
    }
  }
}
```
