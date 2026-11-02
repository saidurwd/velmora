# DigitalProducts module

Digital goods (Phase 8, ADR P8-1..P8-4): files in the private bucket (versioned, checksummed), entitlements, downloads, license keys, refund policy.
Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11). Responsibilities and owned tables: docs/architecture/DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md.

## Built so far
* Slice 1: staff upload a file per digital product variant (`/admin/digital/assets`); permissions `digital.view` / `digital.manage`; config `config/digital.php`.

## Running it
* Disk: `DIGITAL_DISK` (default `private`, an S3 bucket; locally SeaweedFS, see `.env.example`). Never point it at a public disk.
* PHP must allow uploads as large as `DIGITAL_MAX_UPLOAD_MB` (`upload_max_filesize`, `post_max_size`).
