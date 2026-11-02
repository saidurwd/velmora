# Bookings module

Service bookings (Phase 8, ADR P8-1, P8-2, P8-17). Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11). Services never touch stock or shipments (a test checks this).

## Built so far
* Slice 6: the foundation — tables, the database guard against double booking (`UNIQUE (active_resource_id, slot WITHOUT OVERLAPS)`, MariaDB only), and the setup Actions (service, packages, resources, weekly availability, exceptions, holidays). No screens yet.
* Next: slot search (7), hold and confirm through checkout (8), cancel and reschedule with policy rows (9), reminders and completion (10), staff and customer screens (11).

## Running it
* All times are stored in UTC; availability is entered in Dhaka wall-clock time.
* The guard needs MariaDB >= 11.4 (`PERIOD FOR` + `WITHOUT OVERLAPS`).
