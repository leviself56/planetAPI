# Port Status API

- **Method:** `GET`
- **Path:** `/api/v1/ports/status/{switch_ip}`

## Inputs
- `switch_ip` (required)
- Optional `username`, `password`, `timeout`

## Response
Returns current link characteristics for all RJ-45 ports plus SFP diagnostics (if available).
```json
{
  "success": true,
  "operation": "ports.status",
  "data": {
    "ports": {
      "1": {
        "link_up": true,
        "speed_mbps": 1000,
        "duplex_full": true
      }
    },
    "sfp": {
      "port": "3",
      "type": "1000BASE-SX",
      "tx_power_dbm": "-2.1"
    }
  },
  "meta": {
    "timestamp": "2025-12-05T15:04:05Z"
  }
}
```

## Errors
Example of missing switch identifier:
```json
{
  "success": false,
  "error": {
    "operation": "ports.status",
    "message": "Missing switch_ip. Provide it as a query segment or parameter."
  }
}
```
