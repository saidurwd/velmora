# Parcels cannot be booked or tracked

**You see:** "the courier booking was tried" with an error on a parcel; parcels stop moving; *Delivery problems* (`/admin/shipping/issues`) fills up; an alert from `shipping:check-health`.

Today the only courier adapter is the manual one (and a test courier); the real adapters (Steadfast, Pathao) arrive when their official API documents are saved under `docs/vendor/<courier>/`. **Until then parcels are booked by hand with the courier** and this page is about that routine; when real adapters exist, step 2 applies.

1. Open the parcel (`/admin/shipping/shipments`). If booking with the courier failed, the error is on the parcel.
2. (Real adapter only) Retry **Book with the courier**. If the courier's service is down, wait; do not book twice (the parcel keeps its reference; ask the courier whether they already have it).
3. If the courier cannot be reached for long: book by phone/portal with the courier, then **Mark as handed over** on the parcel and record their tracking code. Use **Record where the parcel is** to keep the customer's tracking page truthful.
4. Customers see only what is recorded. Tell the customer if delivery will be late (the order page and the parcel note are visible to them).
5. Cash on delivery: the cash the courier collects is tracked per parcel. Do the **Courier statements** check (`/admin/shipping/statements`) once the courier sends their statement; differences show as exceptions.

**Done when:** every parcel on the Delivery problems list has an owner and a next step.
