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
* Slice 11b: staff screens: bookings list, one booking with its actions, calendar (`Http/Controllers/Admin/BookingController`, `Services/StaffBookings`).
* Slice 11c: setup screens: services (with meeting link), people and rooms, weekly hours, days off, holidays, cancellation policies (`SaveCancellationPolicy`, `SetServiceOptions`, `Percent`, `SetupReader`).
* Next: the Phase 8 gate (browser and accessibility tests for the customer pages, final report).

## Running it
* All times are stored in UTC; availability is entered in Dhaka wall-clock time.
* The guard needs MariaDB >= 11.4 (`PERIOD FOR` + `WITHOUT OVERLAPS`).
* Phase 9, slice 1b: booking messages go through the notifications platform (`BookingMessenger` claims each (booking, kind, channel, start time) once, then asks `NotificationSender`; templates in `Notifications/BookingTemplates`). `BookingMail` and its retry loop are gone; the platform retries and staff see failures under /admin/notifications.
* Phase 9, slice 2: `Privacy/BookingPersonalData` exports a customer's bookings; a confirmed or in-progress booking blocks erasure.
* Phase 10, slice 2d: `Contracts/BookingFigures` gives the Reporting module per-resource bookings by outcome, booked minutes and open minutes; the opening-hours rules moved into `Services/OpeningHours`, shared by slot search and the figures. Index `service_bookings(starts_at, resource_id)`.
