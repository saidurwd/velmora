# Shipping module

Responsibilities, owned tables, events emitted/consumed: see docs/architecture/DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md.
Cross-module use is limited to Contracts, DTOs, Enums and Events (BD-11).

What is here (Phase 7, see docs/architecture/SHIPPING_AND_FULFILLMENT_DESIGN.md §11a and ADR P7-1..P7-14):
parcels (make, book, hand over, cancel), courier events (webhook + polling, one Action), returns, courier statements, the delivery-problems queue,
the customer tracking page, staff screens under `/admin/shipping/*`, and the delivery signals.

## Running it
* Scheduler: `shipping:poll-couriers` (every 10 minutes), `shipping:check-health` (every 15 minutes) — both need `php artisan schedule:work` or cron.
* Couriers: `manual` is always there; `fake` exists outside production only. A real courier needs its official documents saved under `docs/vendor/<courier>/` first.

## Runbook: delivery signals (log line `shipping.alert`, banner on the parcel and problem screens)
| Signal | Meaning | What to do |
|---|---|---|
| `unknown_courier_words` | A courier used a status or named a parcel we do not know | Open Delivery problems; ask the courier what it means; add the word to the status map (`courier_status_map`) and close the item with a note |
| `stuck_parcels` | On its way, nothing from the courier for 7 days (`SHIPPING_STUCK_DAYS`) | Ask the courier; record the real state with the manual status form; if lost, return it and decide the refund in Payments |
| `unhanded_parcels` | Made 48 hours ago and never handed to the courier (stock is already out) | Hand it over, or cancel the parcel with a reason (the stock goes back) |
| `problems_waiting` | A delivery problem has been open 48 hours | Work the queue; closing needs an outcome and a note |
| `returns_waiting` | A customer's return has been undecided 72 hours | Allow or refuse it on the Returns screen |

Thresholds: `config/shipping.php` (`alerts.*`, `stuck_days`). Delivery attempts and the return window are database settings (Delivery rules screen).
