# Many suspicious cash-on-delivery orders

**You see:** a burst of cash orders from new customers, odd phone numbers, repeat addresses, or high-value orders that nobody confirms by phone; many returned/undelivered parcels.

1. Open **Cash-order rules** (`/admin/orders/fraud/settings`) and tighten: *Open cash orders allowed per phone number* (refuses more), *Cash orders above this amount need an SMS code*, *Orders from one phone in a day before review*, *Different phones using one address before review*. Changes apply to the next order.
2. **Block list** (`/admin/orders/fraud/blocklist`): *Block this number* for phones that are clearly abusing (an address is blocked from an order's page). Only a scrambled form is kept; nothing is deleted, and *Stop blocking* undoes it.
3. Orders flagged **needs review** stay on hold: call the customer on the number from a trusted source, confirm, then release or cancel. Cancelling returns stock.
4. If the rules are not enough, a developer or owner can turn cash on delivery off in the payment methods setting; online payment keeps working. (Verify the exact screen when first used: this step is not rehearsed.)
5. Look at the delivery problems list and the courier statements for the same names; ask the courier to hold parcels not yet handed over.
6. Afterwards, loosen rules slowly; note what changed and why.
