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
