# Firebase Cloud Messaging (HTTP v1) — saved reference

Fetched 2026-11-12 from the official pages listed at the end. Summarised (field names, URLs, scopes and status codes are exact). Gaps: the Message page was truncated, so the size limit is taken from the ErrorCode page; no HTTP error body shape was captured. Anything not here must be re-read from the official pages before it is used.

## Send
`POST https://fcm.googleapis.com/v1/projects/{project_id}/messages:send`, body `{"validate_only": bool, "message": {...}}`. Success: `{"name": "projects/<project>/messages/<id>"}`.
Header `Authorization: Bearer <access token>`, `Content-Type: application/json`.

Message fields used by Velmora: `token` (registration token; marked deprecated in favour of `fid`, still accepted), `notification {title, body}`, `data {string: string}` (values must be strings; keys must not be `from`, `message_type`, or start with `google.` / `gcm.notification.`), `android {priority NORMAL|HIGH, ttl "<seconds>s" (max 4 weeks)}`, `apns {headers {apns-priority, apns-expiration}}`.
Size limit: 4096 bytes (2048 for topics), counting keys and values.

## Errors (HTTP status — meaning)
* `INVALID_ARGUMENT` 400 — bad request or token, message too big, reserved data key.
* `UNREGISTERED` 404 — token no longer valid: **remove it and stop sending to it**.
* `SENDER_ID_MISMATCH` 403 — token belongs to another sender.
* `THIRD_PARTY_AUTH_ERROR` 401 — APNs/web push credentials missing or invalid.
* `QUOTA_EXCEEDED` 429 — retry with exponential backoff (initial wait at least 1 minute).
* `UNAVAILABLE` 503 — retry, honouring `Retry-After`.
* `INTERNAL` 500 — retry with backoff.

## Authorising a server
Short-lived OAuth 2.0 access token from a service account, scope `https://www.googleapis.com/auth/firebase.messaging`.
Without a client library: sign a JWT, header `{"alg":"RS256","typ":"JWT","kid":"<private_key_id>"}`, claims `iss` = service account `client_email`, `scope`, `aud` = `https://oauth2.googleapis.com/token`, `iat`, `exp` (at most 1 hour after `iat`). Signature = RS256 over `base64url(header).base64url(claims)` with the account's private key.
Exchange: `POST https://oauth2.googleapis.com/token`, form `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer`, `assertion=<jwt>`. Response `{access_token, token_type: "Bearer", expires_in: 3600}`. Reuse the token until it expires. Errors: `invalid_grant` (bad signature, or clock skew), `invalid_scope`, `invalid_client`.

## Sources
* https://firebase.google.com/docs/reference/fcm/rest/v1/projects.messages/send
* https://firebase.google.com/docs/reference/fcm/rest/v1/projects.messages
* https://firebase.google.com/docs/reference/fcm/rest/v1/ErrorCode
* https://firebase.google.com/docs/cloud-messaging/auth-server
* https://developers.google.com/identity/protocols/oauth2/service-account
