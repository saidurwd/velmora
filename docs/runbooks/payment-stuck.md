# A payment is stuck

**You see:** a customer says they paid; the order shows "unpaid" / the payment is "pending" for a long time; or *Money today* shows a banner "payments pending too long".

**Why it happens:** the customer's browser did not come back, or the gateway's notification was lost. The shop never believes the customer or the browser: it asks the gateway itself.

1. Open the order, then its payment. Note the payment's state and the time of the last attempt.
2. The shop already asks the gateway about open payments every 10 minutes (`payments:reconcile-pending`). If it has been longer than that, check **Scheduled tasks**: is `payments:reconcile-pending` running? If the scheduler is dead, see [queue-backlog.md](queue-backlog.md).
3. On the payment screen use **Ask the payment service again**. It asks the gateway itself and records its answer; only the gateway's own answer can mark a payment paid, and it is safe to repeat.
4. Read the result:
   * **Paid and full amount** → the order moves on by itself.
   * **Failed / cancelled / expired** → tell the customer; they can pay again from the order page (the old attempt cannot be revived).
   * **Needs review** (amount differs, or the money arrived after the order was cancelled) → the payment goes to **Reconciliation** (`/admin/payments/reconciliation`). A finance person decides: match, refund, or keep. Do **not** mark an order paid by hand to calm a customer.
5. Still unclear after the gateway says "no record"? The customer may have paid someone else, or on a different order. Ask for the gateway's transaction id or bank/bKash receipt, and search for it in Reconciliation.

**Done when:** the order shows the true state and the payment alert banner clears.
**Ask for help when:** many payments are stuck at once (that is an outage, see [gateway-outage.md](gateway-outage.md)), or `forged_notifications` appears in the alerts (someone is posting fake gateway messages: tell a developer; nothing is trusted from them, but we want to know).
