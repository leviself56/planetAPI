# System Credentials API

- **Method:** `POST`
- **Path:** `/api/v1/system/credentials/{switch_ip}`
- **Body:** JSON

## Inputs
URL / query parameters:
- `switch_ip` (required)
- Optional `username`, `password`, `timeout` for authenticating to the current switch (old credentials)

JSON body:
- `username` (required): New username to set on the switch.
- `password` (required): New password.

Example request:
```json
{
  "username": "netops",
  "password": "Str0ngPass!"
}
```

## Response
```json
{
  "success": true,
  "operation": "system.credentials",
  "data": true,
  "meta": {
    "timestamp": "2025-12-05T15:04:05Z"
  }
}
```
`data: true` means the credentials were applied, saved, and retried with the new values.

## Errors
Missing fields:
```json
{
  "success": false,
  "error": {
    "operation": "system.credentials",
    "message": "Username and password cannot be empty.",
    "details": {
      "fields": ["username", "password"]
    }
  }
}
```
Switch failure:
```json
{
  "success": false,
  "error": {
    "operation": "system.credentials",
    "message": "Planet API responded with HTTP 500 ...",
    "details": {
      "code": 500
    }
  }
}
```
