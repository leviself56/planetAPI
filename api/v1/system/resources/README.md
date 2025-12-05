# System Resources API

- **Method:** `GET`
- **Path:** `/api/v1/system/resources/{switch_ip}`

## Inputs
Same core parameters as other endpoints:
- `switch_ip` (required)
- `username`, `password`, `timeout` (optional overrides)

## Response
Reports CPU and memory utilization.
```json
{
  "success": true,
  "operation": "system.resources",
  "data": {
    "ram_usage_kb": 12345,
    "cpu_usage_percent": 27,
    "free_memory_display": "12345K",
    "cpu_usage_display": "27%"
  },
  "meta": {
    "timestamp": "2025-12-05T15:04:05Z"
  }
}
```

## Errors
Errors follow the shared format, e.g. when credentials are wrong:
```json
{
  "success": false,
  "error": {
    "operation": "system.resources",
    "message": "Planet API responded with HTTP 401 for lab-switch @ http://10.0.0.5 (endpoint /cgi-bin/cpuinfo.cgi)",
    "details": {
      "code": 401
    }
  }
}
```
