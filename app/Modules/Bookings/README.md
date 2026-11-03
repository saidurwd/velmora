# Bookings module

Service bookings (Phase 8, ADR P8-1, P8-2, P8-17). Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11). Services never touch stock or shipments (a test checks this).

## Built so far
* Slice 6: the foundation — tables, the database guard against double booking (`UNIQUE (active_resource_id, slot WITHOUT OVERLAPS)`, MariaDB only), and the setup Actions (service, packages, resources, weekly availability, exceptions, holidays). No screens yet.
* Slice 7: slot search (`Services/SlotSearch`) and the fix that a running booking keeps its resource.
* Slice 8a: `HoldSlot`, `ExpireStaleHolds` (every minute), the booking line in the cart (`HeldCartLines`) and the page `/{locale}/book/{sku}`.
* Slice 8b: checkout claims the hold (`BookingHolds`, in the order's transaction), `OrderPaid` confirms it once, a payment after the time ran out is not confirmed (history `payment_after_expiry` + `bookings.alert` log), `OrderCancelled` gives held times back.
* Slice 9: cancellation policies as data (`cancellation_policies`, windows, translations, one default; seeded: free until 24 h, 50% inside, no-show 100%, move free until 24 h up to 2 times), frozen onto each booking when it is confirmed; `CancelBooking`, `MarkNoShow`, `RescheduleBooking`, `ReassignBooking`, `EndBookingsForRefundedOrder`; the money outcome is the append-only `booking_cancellations` (advice for staff, never an automatic refund); the policy words are on the product page and the checkout review.
* Slice 10: the lifecycle job (`bookings:lifecycle`, every five minutes): `StartBookings`, `AutoCompleteBookings`, `SendBookingReminders` (24 h and 2 h before), `SendReviewPrompts`; `CompleteBooking` (staff, or the customer for their own); messages go through `BookingMessenger` (claim first, `booking_notices`).
* Slice 11a: customer pages: "My bookings" (`MyBookingsController`, signed in) and the guest version (`GuestBookingsController`: signed link + SMS code + short session), `MyBookings` reader, the cost preview before cancelling.
* Next: staff screens (11b bookings and calendar, 11c setup), then the Phase 8 gate.

## Running it
* All times are stored in UTC; availability is entered in Dhaka wall-clock time.
* The guard needs MariaDB >= 11.4 (`PERIOD FOR` + `WITHOUT OVERLAPS`).
