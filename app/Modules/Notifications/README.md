# Notifications module

Responsibilities, owned tables, events emitted/consumed: see docs/architecture/DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md.
Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11).

## Phase 9 — the notifications platform

* `Contracts/NotificationSender` is the one way to send a message; `Contracts/TemplateRegistry` is how a module declares its messages (code, channel, kind, variables, first words, example values).
* Tables: `notification_templates` (+ append-only `notification_template_versions`), `notification_logs`, `notification_preferences`.
* `Actions/SendNotification` records; `Actions/DeliverNotification` delivers one record (claimed first); `Actions/DeliverDueNotifications` (every minute) delivers what is due; `Actions/RetryDeadNotification`; `Actions/PurgeNotificationPayloads` and `Actions/CheckNotificationHealth` (hourly).
* The words are filled in by `Services/TemplateRenderer` (declared `{{ variables }}` only, one pass, plain text). Staff screens under /admin/notifications.
* OTP codes do not go through here: `IdentityAccess` keeps calling the SMS gateway directly.
* Slice 1b: the order, shipping, cancellation, refund, new-version and booking messages are declared as templates by their own modules (`Orders/Notifications/OrderTemplates`, `DigitalProducts/Notifications/DigitalTemplates`, `Bookings/Notifications/BookingTemplates`) and sent through `NotificationSender`. A template may mark variables `multiline` (item lists keep their line breaks). **Production needs the queue worker and the scheduler running**: delivery is queued after commit, and the per-minute sweep picks up anything missed.
* Phase 9, slice 2: `/account/preferences` (`PreferenceController`, `SaveMessagePreferences`, `PreferenceReader`) is the one place a customer's marketing yes/no and quiet hours are set; each change is also written to the append-only `notification_consent_events`. `Privacy/NotificationPersonalData` exports and erases the customer's part.
* Phase 9, slice 7: `Contracts/MarketingConsent` says whether a customer agreed to marketing on a channel; a module that sends marketing asks before it records anything, so people who said no leave no trace.
* Phase 10, slice 4c: the SMS gateway reads its token and sender id from the credentials in force. `Sms/SmsFormatTest` checks their format only and sends nothing.
* Phase 11, slice 7: push is a third channel (`PushGateway`: log, fake, FCM HTTP v1 from `docs/vendor/fcm/`). Set `PUSH_DRIVER=fcm` and `FCM_SERVICE_ACCOUNT_FILE` (an absolute path outside the web root) to send for real; until then production pushes fail visibly on the delivery list. A push is addressed to a person and delivered to their signed-in phones; templates are `*.push` (no subject; the title is the shop's name). See ADR P11-8.
