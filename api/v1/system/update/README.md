# System Update API

- **Method:** `POST`
- **Path:** `/api/v1/system/update/{switch_ip}`
- **Body:** JSON

## Inputs
URL / query parameters:
- `switch_ip` (required)
- Optional `username`, `password`, `timeout`

JSON body fields (at least one required):
- `devicename`
- `comment`
- `location`
- `contact`

Example request:
```json
{
  "devicename": "Roof Switch",
  "location": "Tower Closet",
  "contact": "NOC"
}
```

## Response
```json
{
  "success": true,
  "operation": "system.update",
  "data": true,
  "meta": {
    "timestamp": "2025-12-05T15:04:05Z"
  }
}
```
A boolean `true` indicates the switch accepted the change and the API saved the configuration.

## Errors
Missing body example:
```json
{
  "success": false,
  "error": {
    "operation": "system.update",
    "message": "Request body must be valid JSON."
  }
}
```
Validation failure example:
```json
{
  "success": false,
  "error": {
    "operation": "system.update",
    "message": "Provide at least one of: devicename, comment, location, contact."
  }
}
```
```