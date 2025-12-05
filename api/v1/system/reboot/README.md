# System Reboot API

- **Method:** `POST`
- **Path:** `/api/v1/system/reboot/{switch_ip}`

## Inputs
Parameters:
- `switch_ip` (required)
- Optional: `username`, `password`, `timeout`
- `confirm` (required): Must be `true`, `1`, or `yes` to proceed. Prevents accidental reboots.

Body is not required.

Example request:
```
POST /api/v1/system/reboot/10.15.100.2?confirm=true
```

## Response
Returns HTTP 202 to signal that reboot was accepted.
```json
{
  "success": true,
  "operation": "system.reboot",
  "data": true,
  "meta": {
    "timestamp": "2025-12-05T15:04:05Z"
  }
}
```

## Errors
Missing confirmation flag:
```json
{
  "success": false,
  "error": {
    "operation": "system.reboot",
    "message": "Confirmation required. Pass confirm=true to reboot the switch."
  }
}
```
Planet API failure (e.g., cannot reach switch):
```json
{
  "success": false,
  "error": {
    "operation": "system.reboot",
    "message": "Planet API responded with HTTP 404 ...",
    "details": {
      "endpoint": "/cgi-bin/reboot.cgi"
    }
  }
}
```
