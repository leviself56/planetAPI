# Port Bandwidth API

- **Method:** `POST`
- **Path:** `/api/v1/ports/bandwidth/{switch_ip}`
- **Body:** JSON

## Inputs
URL parameters:
- `switch_ip` (required)
- Optional `username`, `password`, `timeout`

JSON body (all required):
- `port` (integer >= 1)
- `ingress_kbps` (integer >= 0)
- `egress_kbps` (integer >= 0)

Example request:
```json
{
  "port": 1,
  "ingress_kbps": 50000,
  "egress_kbps": 100000
}
```

## Response
```json
{
  "success": true,
  "operation": "ports.bandwidth",
  "data": true,
  "meta": {
    "timestamp": "2025-12-05T15:04:05Z"
  }
}
```
`data: true` indicates the limit was written and saved on the switch.

## Errors
Invalid payload example:
```json
{
  "success": false,
  "error": {
    "operation": "ports.bandwidth",
    "message": "Port must be a positive integer.",
    "details": {
      "port": "A1"
    }
  }
}
```
Switch-side failure example:
```json
{
  "success": false,
  "error": {
    "operation": "ports.bandwidth",
    "message": "Planet API responded with HTTP 500 ...",
    "details": {
      "code": 500,
      "endpoint": "/cgi-bin/bandwidth.cgi"
    }
  }
}
```
