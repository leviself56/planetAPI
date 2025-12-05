# System Network API

- **Method:** `GET`
- **Path:** `/api/v1/system/network/{switch_ip}`

## Inputs
- `switch_ip` (required)
- Optional overrides: `username`, `password`, `timeout`

## Response
Returns static and DHCP-assigned IPv4 details.
```json
{
  "success": true,
  "operation": "system.network",
  "data": {
    "static": {
      "ip": "10.15.100.2",
      "subnet": "255.255.255.0",
      "gateway": "10.15.100.1"
    },
    "dhcp": {
      "enabled": false,
      "ip": null,
      "subnet": null,
      "gateway": null
    }
  },
  "meta": {
    "timestamp": "2025-12-05T15:04:05Z"
  }
}
```

## Errors
Example of an invalid IP request:
```json
{
  "success": false,
  "error": {
    "operation": "system.network",
    "message": "Invalid switch_ip. Only IPv4/IPv6 literals are supported.",
    "details": {
      "value": "example.com"
    }
  }
}
```
