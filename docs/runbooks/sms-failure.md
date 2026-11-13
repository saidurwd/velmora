# Texts are not arriving

**You see:** customers say no code arrived; the **Delivery list** (`/admin/notifications/logs`) shows many "failed" or "gave up"; `notifications:health` raises the banner; sign-in by phone code fails.

1. Delivery list: filter by *Text message* and *Failed*. The error is a short code, never the number or the words. Typical: `not_configured`, `ip_not_whitelisted` (the SSL Wireless portal does not know this server's address), `unauthorized`, `limit_exceeded`, `sender_id_not_permitted`.
2. **Integrations → SMS** (`/admin/settings/integrations`): *Test connection* checks only that the token and sender id look right; it sends nothing, so it cannot prove delivery. To prove delivery, send yourself a phone-sign-in code or place a small test order.
3. Fix at the source: re-enter the token/sender id (owner), ask the provider to whitelist the server address, top up credit.
4. After it works, open each *Gave up* message that still matters (order confirmations) and use **Send again**; codes are useless after 5 minutes, so do not resend those.
5. While texts are down: customers can still sign in with email + password; order confirmations also go by email when an address exists. Support can read an order's status to a customer who calls.
6. The delivery system retries by itself with growing waits and shows what it gave up on; nothing is lost silently.

**Done when:** a code or test order reaches a real phone and new messages show "Sent".
