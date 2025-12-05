# System Info API

- **Method:** `GET`
- **Path:** `/api/v1/system/info/{switch_ip}` (or `/api/v1/system/info?switch_ip={ip}`)

## Inputs
- `switch_ip` (required): IPv4/IPv6 address of the GT-915A.
- `username` (optional): Overrides the default admin username defined in `config.php`.
- `password` (optional): Overrides the default password.
- `timeout` (optional): Request timeout in seconds.

## Response
```json
{
  "success": true,
  "operation": "system.info",
  "data": {
    "mac": "00:1F:1D:AA:BB:CC",
    "devicename": "Demo Switch",
    "location": "Network Closet"
  },
  "meta": {
    "timestamp": "2025-12-05T15:04:05Z"
  }
}
```

## Errors
All failures use the common envelope:
```json
{
  "success": false,
  "error": {
    "operation": "system.info",
    "message": "Missing switch_ip. Provide it as a query segment or parameter.",
    "details": {
      "fields": ["switch_ip"]
    }
  }
}
```
