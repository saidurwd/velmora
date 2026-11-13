# The payment service is down or failing

**You see:** customers cannot reach the payment page or get an error; *Money today* shows `callback_failure_rate` or `pending_too_long`; the log has repeated `payments.alert` lines.

1. Confirm it is the gateway, not us: try one test payment yourself in the sandbox if live, or check the provider's status page / dashboard.
2. **Pause online payments** (Payment settings → *Pause online payments*). Customers can still order with **cash on delivery**; payments already in flight keep being reconciled. This takes effect at once and is safe to undo.
3. Tell customers (a banner or the home page notice from the CMS; text from the support team): "online payment is unavailable, you can order with cash on delivery".
4. While paused, keep an eye on **Reconciliation** and **Money today**: late successes of payments started before the pause still arrive and are applied; unpaid online orders expire after their payment time (they are cancelled and stock returns).
5. When the provider says it is fixed: place one small real order yourself, check it shows paid, then **resume** online payments.
6. After: run reconciliation for the outage window and clear what is left in the exceptions list.

**Done when:** a test order pays and the alert banner has been quiet for an hour.
**Never:** switch the sandbox flag, change store credentials, or set `PAYMENTS_ALLOW_LIVE` during an incident without a second person (see `DEPLOYMENT_AND_DEVOPS.md` §5.2).
