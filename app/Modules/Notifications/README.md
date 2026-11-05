# Notifications module

Responsibilities, owned tables, events emitted/consumed: see docs/architecture/DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md.
Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11).

## Phase 9 — the notifications platform

* `Contracts/NotificationSender` is the one way to send a message; `Contracts/TemplateRegistry` is how a module declares its messages (code, channel, kind, variables, first words, example values).
* Tables: `notification_templates` (+ append-only `notification_template_versions`), `notification_logs`, `notification_preferences`.
* `Actions/SendNotification` records; `Actions/DeliverNotification` delivers one record (claimed first); `Actions/DeliverDueNotifications` (every minute) delivers what is due; `Actions/RetryDeadNotification`; `Actions/PurgeNotificationPayloads` and `Actions/CheckNotificationHealth` (hourly).
* The words are filled in by `Services/TemplateRenderer` (declared `{{ variables }}` only, one pass, plain text). Staff screens under /admin/notifications.
* OTP codes do not go through here: `IdentityAccess` keeps calling the SMS gateway directly.
