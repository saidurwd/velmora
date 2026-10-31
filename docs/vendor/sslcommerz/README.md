# SSLCommerz official documentation (saved copy)

Saved **2026-10-30** from the provider's sandbox documentation site, because project rules (AGENTS.md) allow integration code only against endpoints present in saved official docs.

| File | Source |
|---|---|
| `docs.html` / `docs.txt` | https://sandbox-gw.sslcommerz.com/docs (getting started, sandbox credentials, transaction initiation, easy checkout, refund API, refund status query, common issues) |
| `ipn.html` / `ipn.txt` | https://sandbox-gw.sslcommerz.com/doc/v4/ipn.html |
| `order-validation.html` / `order-validation.txt` | https://sandbox-gw.sslcommerz.com/doc/v4/order-validation.html |
| `order-query.html` / `order-query.txt` | https://sandbox-gw.sslcommerz.com/doc/v4/order-query.html |

`.html` is the page as downloaded; `.txt` is a plain-text extraction of the same page for searching. These are third-party documents, kept as reference data: nothing here is executed.

## What the documentation says (the facts the integration relies on)
- Hosts: sandbox `https://sandbox.sslcommerz.com`, live `https://securepay.sslcommerz.com`.
- **Initiate**: `POST /gwprocess/v4/api.php`. Required: `store_id`, `store_passwd`, `total_amount` (10.00 to 500000.00), `currency`, `tran_id` (unique, ≤ 30), `success_url`, `fail_url`, `cancel_url`, customer fields (`cus_name`, `cus_email`, `cus_add1`, `cus_city`, `cus_postcode`, `cus_country`, `cus_phone`), `shipping_method`, `num_of_item`, `product_name`, `product_category`, `product_profile`; `ipn_url` optional but recommended; `value_a`..`value_d` echoed back. Response: `status` SUCCESS/FAILED, `sessionkey`, `GatewayPageURL`, `failedreason`.
- **IPN**: POST to the listener (`ipn_url` or merchant panel). `status` is VALID / FAILED / CANCELLED / UNATTEMPTED / EXPIRED. The IPN is not to be trusted on its own: **re-validate with the Order Validation API** and compare `tran_id`, `amount`, `currency` with our database.
- **Order validation**: `GET /validator/api/validationserverAPI.php` with `val_id`, `store_id`, `store_passwd` (`format=json`). Status VALID / VALIDATED (already validated before) / INVALID_TRANSACTION.
- **Transaction query**: `GET /validator/api/merchantTransIDvalidationAPI.php` by `sessionkey` or by `tran_id` (+ `store_id`, `store_passwd`). Status VALID / VALIDATED / PENDING / FAILED; by `tran_id` it returns a list (`no_of_trans_found`, `element[]`).
- **Refund**: same path, `bank_tran_id`, `refund_trans_id` (unique, required since 2025-02-24), `refund_amount`, `refund_remarks`; response `status` success / failed / processing, `refund_ref_id`. **Refund status** by `refund_ref_id`: refunded / processing / cancelled. For live, the server's public IP must be registered with SSLCommerz.

## What the documentation does NOT say (so the code must not assume it)
- **How `verify_sign` / `verify_key` in the IPN are computed.** We do not verify it; authenticity comes from the server-to-server Order Validation call.
- Whether **partial refunds** are supported (the request has a `refund_amount`, but partial refunds are not stated). Treated as unproven until shown in the sandbox.
- Nagad is not listed. bKash is listed as a gateway reached through SSLCommerz.
- IP ranges of the IPN sender, and any retry schedule for failed IPNs.

## Security notes that follow from the docs
- Credentials (`store_id`, `store_passwd`) are sent as **query-string parameters** on GET calls: the HTTP client and all logging/APM must redact query strings. Credentials live only in encrypted settings or environment variables, never in the repository.
- The sandbox page shows public test credentials and test cards; they are deliberately not copied into project files. Use your own sandbox registration (https://sandbox-gw.sslcommerz.com/register).

## How our adapter uses this documentation (`app/Modules/Payments/Gateways/SslCommerzGateway.php`)
- Fixtures in `app/Modules/Payments/tests/Fixtures/sslcommerz/` are the examples printed in these pages; nothing was recorded from an account.
- Authenticity of a payment comes only from the server-to-server calls (validation by `val_id`, or query by `tran_id`); the notification and the browser return only say which transaction to ask about.
- `amount` and `currency_amount` must agree for BDT; if not, the payment is held for review. Exact paisa only, never rounded.
- One reference can have several results (the query API returns a list). We take a successful one, and raise a duplicate-payment exception if more than one succeeded.
- `risk_level` 1: payment accepted, order held for a person (the documentation asks the shop to check the customer before delivering).
- Unverified, so unused: `verify_sign`; partial refunds; the IPN sender's IP addresses.

## Reference implementation: the provider's own sample app (read 2026-10-30)
https://github.com/sslcommerz/SSLCommerz-Laravel at commit `5a3e3d21647cc68f43c502d9bf073fca04837bce` (last pushed 2025-10-12). It is a sample application, not a package, and the repository has **no licence**, so nothing is copied from it and it is not a dependency; our adapter is written from the documentation above.

What it shows beyond the documentation pages:
- It validates a payment by calling the Order Validation API and then comparing `tran_id`, `amount` and `currency`, as documented. **It accepts amounts within 1 taka (`abs(a - b) < 1`) using floats. We do not: our comparison is exact to the paisa, in integers.**
- It contains a `verify_sign` check: take the fields listed in `verify_key`, add `store_passwd = md5(store password)`, sort by key, join as `k=v&k=v`, `md5` the result, compare with `verify_sign`. **This could not be confirmed**: applied to the example IPN printed on the saved IPN page with the public demo password and several likely variants, it did not reproduce the example's `verify_sign` (the example was probably produced with a different password). Because it cannot be verified against the documentation, the integration does **not** use `verify_sign`; authenticity rests on the server-to-server Order Validation call alone.

## Credentials check (2026-10-30)
The sandbox transaction-query endpoint answers `APIConnect: DONE` for the public demo store and `APIConnect: FAILED` for unknown or wrong credentials. Use this as a connectivity check for a newly registered sandbox store (see the opt-in sandbox suite, Phase 6).
