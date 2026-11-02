# DigitalProducts module

Digital goods (Phase 8, ADR P8-1..P8-4): files in the private bucket (versioned, checksummed), entitlements, downloads, license keys, refund policy.
Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11). Responsibilities and owned tables: docs/architecture/DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md.

## Built so far
* Slice 3: My downloads (`/account/library`), guest access (signed link + SMS code), `DownloadDigitalFile`, `download_logs`; probe `digital:probe download` for the limit race; opt-in end-to-end test against the real local bucket (`tests/Database/DigitalObjectStorageTest.php`).
* Slice 2: `OrderPaid` → one entitlement per digital order line (`GrantEntitlements`); defaults in `config/digital.php` / setting `digital.download_limit`; probe `digital:probe` for concurrency tests.
* Slice 1: staff upload a file per digital product variant (`/admin/digital/assets`); permissions `digital.view` / `digital.manage`; config `config/digital.php`.

## Running it
* Disk: `DIGITAL_DISK` (default `private`, an S3 bucket; locally SeaweedFS, see `.env.example`). Never point it at a public disk.
* PHP must allow uploads as large as `DIGITAL_MAX_UPLOAD_MB` (`upload_max_filesize`, `post_max_size`).
