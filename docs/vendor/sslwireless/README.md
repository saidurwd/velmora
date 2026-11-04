# SSL Wireless ISMS Plus — SMS API (v3)

**Official source:** https://ismsplus.sslwireless.com/api-documentation (the provider's own API documentation page; supplied by the shop owner on 2026-11-04).
**Retrieved:** 2026-11-04. The text below was restructured from that page by the fetch tool, with every URL, field name, code and limit kept as shown; it is **not** a
verbatim copy. Before going live, read the live page again and check nothing has changed. The adapter uses only what is written here.

(The GitHub repository `sslw/ismsplus_api` is a third-party PHP client, not documentation, and is not used as a source.)

## Base URL and endpoints

Base URL: `https://smsplus.sslwireless.com/api/v3` — JSON requests and responses, authentication in the JSON body, **at most 100 MSISDN per request**.

| Operation | Method | URL |
|---|---|---|
| Send single SMS | GET or POST | `/send-sms` |
| Secure OTP SMS (encrypted body + signature) | POST | `/secure/otp-sms` |
| Bulk SMS (one message, many recipients) | POST | `/send-sms/bulk` |
| Dynamic SMS (a message per recipient) | POST | `/send-sms/dynamic` |

## Authentication

- `api_token` — required on every request, given by SSL, in the JSON body (not a header). Alphanumeric, ≤ 50 characters.
- `sid` — required on every request: the registered Sender ID. Alphanumeric, ≤ 20 characters.
- The calling server's IP address must be whitelisted in the ISMS Plus portal (otherwise code 4003).
- Secure OTP only: also the `X-Signature` header and an encrypted `sms` field, using a Secret Key from the portal.

## Send single SMS — `POST /send-sms`

| Name | Required | Type | Max | Description |
|---|---|---|---|---|
| `api_token` | yes | alphanumeric | 50 | token |
| `sid` | yes | alphanumeric | 20 | Sender ID |
| `msisdn` | yes | numeric | 16 | recipient, with country code (e.g. `88019XXXXXXXX`) |
| `sms` | yes | text | 1000 | message body |
| `csms_id` | yes | alphanumeric | 20 | unique client reference id |

```json
{ "api_token": "XXXX-XXd2bbXX-XXfX-XXbf-XXXXX-XXXXXcXXXXX", "sid": "XXXXXXXXXX", "msisdn": "88019XXXXXXXX", "sms": "Message Body", "csms_id": "4473433434pZ684333392" }
```

## Response (all endpoints)

| Field | Type | Meaning |
|---|---|---|
| `status` | string | `SUCCESS` or `FAILED` |
| `status_code` | integer | see the table below (`200` = success) |
| `error_message` | string | detail on failure; empty on success |
| `smsinfo` | array | one object per recipient |

`smsinfo[]`: `sms_status` (`SUCCESS`, `INVALID`, `DUPLICATE`, `BLOCKED`), `status_message`, `msisdn`, `sms_type` (`EN` English or `BN` Unicode/Bangla), `csms_id` (our reference),
`reference_id` (server-made id, used for status checks). Some samples also show `sms_body`.

Failure example: `status` `FAILED`, `status_code` `4025`, `error_message` "Invalid MSISDN", `smsinfo[0].sms_status` `INVALID`.

## Status codes

| Code | Meaning |
|---|---|
| 200 | success |
| 4001 | unauthorized (authentication failed) |
| 4002 | SID not permitted |
| 4003 | IP blacklisted (not whitelisted) |
| 4004 | invalid request format (bad JSON or content type) |
| 4005 | endpoint not found |
| 4020 | invalid CSMS id (single and bulk) |
| 4022 | required parameter missing |
| 4023 | duplicate CSMS id (single and bulk) |
| 4024 | duplicate MSISDN (single) |
| 4025 | invalid MSISDN |
| 4026 | blocked MSISDN |
| 4027 | message length exceeded (single) |
| 4028 | invalid message data |
| 4029 | too many requests (rate limit) |
| 4030 | limit exceeded (more than 100 MSISDN in one request) |
| 4031 | TPS exceeded |
| 4032 | invalid SMS (body) |
| 4033 | too many OTP requests to one recipient in a time window |
| 4034 | unable to decrypt SMS |
| 4035 | client signature mismatch |
| 5000 | internal error |

Per recipient: `SUCCESS` "Success"; `INVALID` "Invalid MSISDN" (also a message that is too long); `DUPLICATE` "Duplicate CMS ID"; `BLOCKED` "Blocked MSISDN" (too many OTP requests, or blocked).

## Bulk — `POST /send-sms/bulk`

`api_token`, `sid`, `msisdn` (array, ≤ 100), `sms` (one body for all, ≤ 1000), `batch_csms_id` (≤ 20).

## Dynamic — `POST /send-sms/dynamic`

`api_token`, `sid`, `sms` (array, ≤ 100, each `{ "msisdn": ..., "text": ..., "csms_id": ... }`).

## Secure OTP — `POST /secure/otp-sms`

Same fields as single send, but `sms` is encrypted, and the header `X-Signature` is the lowercase hex HMAC-SHA256 (key = the raw Secret Key from the portal) of the sorted, URL-encoded
query string of `csms_id`, `msisdn`, `sid`, `sms` (the encrypted value). Encryption of `sms`: AES-256-CBC with PKCS7 padding; key = the first 32 characters of the 64-character hex SHA-256 of the
Secret Key; a random 16-byte IV; Base64 of the ciphertext; then Base64 of (raw IV + that Base64 ciphertext). Errors: 4034 (cannot decrypt), 4035 (signature mismatch).

## Not covered by the page (ask SSL Wireless / see the portal)

Delivery-report (DLR) callbacks, balance check, sender-id masking rules, exact rate limits (only 4029 / 4031 are named), Unicode segment counts and billing.

## What this project uses

Only **single send** (`POST /send-sms`). Bulk, dynamic and secure OTP are not used (secure OTP needs a Secret Key and is a possible later hardening). No delivery reports are used, so
"accepted" means the provider took the message (`status_code` 200 and `sms_status` `SUCCESS`), not that the handset received it.
