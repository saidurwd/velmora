# Privacy module

Customer privacy tools (Phase 9, slice 2): a copy of one's data, and erasure of one's account.

* Tables: `privacy_requests` (export and erasure requests; one open request of each kind per customer, enforced by `open_key UNIQUE`; status moves enforced by a trigger).
* Each module says which part of the export and erasure is its own: `Contracts/PersonalDataExporter` (only that user's data) and `Contracts/PersonalDataEraser` (idempotent; keeps the financial record; `blockers()` lists what must finish first). They register with `Contracts/PersonalDataRegistry` in their provider (erasers have a priority; the account itself, priority 100, goes last).
* Actions: `RequestExport` → `BuildExport` (queued) → `DownloadExport`; `RequestErasure` → `DecideErasure` (staff approve/reject) → `RunErasure` (queued, resumable); `CancelErasureRequest`; `ExpireExports` (daily).
* Customer pages `/account/privacy` (sign-in confirmation within 10 minutes needed to ask or download); staff `/admin/privacy` (`privacy.view`, `privacy.manage`).
* Messages: `Notifications/PrivacyTemplates` (data copy ready, erased, not approved), sent on the contact taken just before the details are erased.
* The settings `privacy.export_days` (7), `privacy.export_cooldown_hours` (24) and `privacy.financial_retention_years` (6, informational; ledger rows are never deleted) override `config/privacy.php`.

Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11).
