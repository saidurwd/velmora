# Opt-in SSLCommerz sandbox checks

These talk to the **real SSLCommerz sandbox** (never the live bank). They are in no test suite, so `composer check`, `php artisan test` and CI never run them.

Run them yourself, once you have registered a sandbox store at https://sandbox-gw.sslcommerz.com/register:

```
SSLCOMMERZ_SANDBOX_TESTS=1 \
SSLCOMMERZ_SANDBOX_STORE_ID='your-sandbox-store@ssl' \
SSLCOMMERZ_SANDBOX_STORE_PASSWORD='your-sandbox-password@ssl' \
vendor/bin/pest app/Modules/Payments/tests/Sandbox
```

They create a payment *session* (no money moves) and ask about references that do not exist. They refuse to run with a store id that does not end in `@ssl` (a sandbox store id normally does), so live credentials cannot be used by mistake. They do not complete a payment: that needs a person to pay with a sandbox test card on SSLCommerz's page; do that by hand and then check the order in the admin.
