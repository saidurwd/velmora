# Stock looks wrong

**You see:** the shop says "out of stock" for something on the shelf (or the opposite), a customer cannot add the last unit, an integrity alert says the **stock ledger** does not match, or `inventory:verify-ledger` (nightly 02:30) reports a difference.

Rules of the system: stock only moves through recorded steps (receive, reserve, ship, return, adjust, count); every step is in an append-only ledger; "available" = on hand minus reserved. Reservations expire by themselves (`inventory:expire-reservations`, every minute).

1. Look at the product's stock screen (`/admin/inventory`): on hand, reserved, and the ledger history. Reserved stuck high? Check open orders and carts: stock is held for unpaid online orders until their payment time ends.
2. If **reserved** is too high and nothing explains it: check **Scheduled tasks** that `inventory:expire-reservations` runs.
3. If **on hand** is wrong: do a **stock count** (`/admin/inventory/counts`) of the real shelf, or an **adjustment** with a reason. Both are recorded and cannot be deleted. Never edit numbers in the database.
4. If the integrity check says the ledger and the on-hand numbers disagree, run **Integrity checks** (`/admin/ops/integrity` → run) and read the check's detail. A disagreement means data was changed outside the normal steps or a bug: **stop selling the product** (unpublish it), keep the evidence, and ask a developer. Do not "fix" the number first.
5. The storefront listing is a copy that catches up within a minute (`catalog:reconcile-listing`); `php artisan catalog:reindex` rebuilds it if it looks stale.

**Done when:** on hand and available match the shelf and the integrity check is green.
