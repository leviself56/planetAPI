# System Summary API

- **Method:** `GET`
- **Path:** `/api/v1/system/summary/{switch_ip}`

## Inputs
Same as other read endpoints:
- `switch_ip` (required)
- Optional `username`, `password`, `timeout`

## Response
Aggregates system info, resources, network config, VLANs, bandwidth, port state, and SFP insights using `PlanetAPI::printSwitchData()`.
```json
{
  "success": true,
  "operation": "system.summary",
  "data": {
    "system": {
      "details": { "devicename": "Demo" },
      "resources": { "cpu_usage_percent": 21 },
      "network": { "ipv4": {"ip": "10.0.0.5"}, "vlans": [] }
    },
    "ports": {
      "1": {
        "bandwidth_control": {"ingress_rate": 50000},
        "port_link_status": {"link_up": true},
        "sfp_info": null
      }
    }
  },
  "meta": {
    "timestamp": "2025-12-05T15:04:05Z"
  }
}
```

## Errors
Failures bubble up from any sub-call:
```json
{
  "success": false,
  "error": {
    "operation": "system.summary",
    "message": "Planet API responded with HTTP 401 for ...",
    "details": {
      "code": 401,
      "endpoint": "/cgi-bin/sysinfo.cgi"
    }
  }
}
```
