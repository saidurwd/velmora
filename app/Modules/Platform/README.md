# Platform module

The shared kernel: audit log, settings, outbox, localisation helpers, storefront cache, branding and the admin shell. Every module may use it; it uses no other module (see `tests/Architecture`).

* Phase 10, slice 4a: `/admin/settings` (business profile, tax switches, read-only currency and language), `/admin/features` (a registry in `Support/FeatureFlags`; `features.manage`), `/admin/maintenance` (`Actions/MaintenanceSwitch`; `settings.manage` plus the staff member's own password through `Contracts/StaffReauthentication`, implemented by IdentityAccess) and `/admin/audit` (`audit.view`; `Services/AuditLogQuery`, filters, masked addresses, capped CSV export). `Support/SettingsWriter` saves a group as one audited, versioned, locked step; `Support/BusinessProfile` feeds `<x-platform::business-block />` on receipts and packing slips. Set `APP_MAINTENANCE_DRIVER=cache` in production when there are several servers.
