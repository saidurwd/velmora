# Bookings module

Service bookings (Phase 8, ADR P8-1, P8-2, P8-17). Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11). Services never touch stock or shipments (a test checks this).

## Built so far
* Slice 6: the foundation — tables, the database guard against double booking (`UNIQUE (active_resource_id, slot WITHOUT OVERLAPS)`, MariaDB only), and the setup Actions (service, packages, resources, weekly availability, exceptions, holidays). No screens yet.
* Slice 7: slot search (`Services/SlotSearch`) and the fix that a running booking keeps its resource.
* Slice 8a: `HoldSlot`, `ExpireStaleHolds` (every minute), the booking line in the cart (`HeldCartLines`) and the page `/{locale}/book/{sku}`.
* Slice 8b: checkout claims the hold (`BookingHolds`, in the order's transaction), `OrderPaid` confirms it once, a payment after the time ran out is not confirmed (history `payment_after_expiry` + `bookings.alert` log), `OrderCancelled` gives held times back.
* Next: cancel and reschedule with policy rows (9), reminders and completion (10), staff and customer screens (11).

## Running it
* All times are stored in UTC; availability is entered in Dhaka wall-clock time.
* The guard needs MariaDB >= 11.4 (`PERIOD FOR` + `WITHOUT OVERLAPS`).
