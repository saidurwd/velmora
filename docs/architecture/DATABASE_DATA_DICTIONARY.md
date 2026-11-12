# Database Data Dictionary (Batch 3 draft)

Shorthand — **STD** = `id` BIGINT UNSIGNED PK AI, `public_id` CHAR(26) ascii_bin UNIQUE, `created_at`, `updated_at` DATETIME(6) · **M(x)** = `x_minor` BIGINT NOT NULL CHECK ≥0 + `currency` CHAR(3) · **SD** = `deleted_at` soft delete · **AO** = append-only (trigger + grants) · `FK→t` = foreign key RESTRICT · Types: `s`=VARCHAR, `ts`=DATETIME(6) UTC, `b`=TINYINT(1), `j`=JSON. Enum columns carry a CHECK with the listed values. Owner = module. Retention per `DATABASE_DESIGN.md` §8. Column lists show non-STD columns; indexes beyond FK indexes are listed.

## Platform
| Table | Purpose | Columns / constraints | Flags |
|---|---|---|---|
| languages | supported locales | code s5 PK, name, native_name, is_default b, is_active b, direction s3 (ltr/rtl) | — |
| settings | typed key/value | key s100 UNIQUE(scope,key), scope s50, value j, is_secret b | audit on change |
| saved_filters | public_id ULID UNIQUE, user_id (the staff owner; no FK, identity owns users), screen s30 (allow-list in code), name s60, query s500 (sorted query string of the allowed keys only), created_at, updated_at; UNIQUE(user_id, screen, name) serves "a person's filters for one list" and stops a name twice; CHECK name and query not empty | personal, STD |
| audit_events | who did what | actor_type, actor_id, action s100, subject_type, subject_id, before j, after j, ip s45, user_agent s255, request_id s36, created_at; idx(subject_type,subject_id,created_at),(actor_id,created_at) | AO, no updated_at |
| audit_events (indexes) | Slice 4a adds (created_at) for the viewer's date range and (action, created_at) for "everything of this kind in a period". The rows stay append-only. |
| scheduled_task_runs | id, name (unique), last_started_at, last_finished_at, last_status (CHECK started/ok/failed/skipped), last_error (first line, secrets removed), last_runtime_ms, updated_at. Upserted by task name from the scheduler's events. |
| integrity_check_runs | id, code (unique), status (CHECK ok/problem/error), problems, samples (JSON, a few examples), note, duration_ms, ran_at. Upserted by check code; the only thing the data checks write. |
| idempotency_keys | replay protection | scope s50, key s100, request_hash CHAR(64), response_status, response_body j, expires_at; UNIQUE(scope,key) | purge on expiry |
| outbox_messages | reliable events | event_type s100, payload j, status (pending/done/failed), attempts, available_at, processed_at, last_error; idx(status,available_at) | purge done after 30 d |
| sequences | `order_number_seq` etc. | MariaDB SEQUENCE objects | — |
| jobs, failed_jobs, job_batches | Laravel queue (if DB queue) | framework | — |
| consumed_events | consumer idempotency | consumer s100, outbox_id FK→outbox_messages, UNIQUE(consumer,outbox_id) | — |

## IdentityAccess
| Table | Purpose | Columns / constraints | Flags |
|---|---|---|---|
| users | login identity | STD; email s254 NULL UNIQUE(lower via generated col), phone s16 NULL UNIQUE (E.164), password s255 NULL, account_type (customer/staff), locale s5 FK→languages, status (active/locked/disabled), email_verified_at, phone_verified_at, last_login_at; CHECK(email IS NOT NULL OR phone IS NOT NULL) | PII |
| otp_challenges | OTP | STD-lite; user_id NULL, target s254, purpose (login/register/verify/reset/guest_access), code_hash s255, attempts, max_attempts, expires_at, consumed_at, ip; idx(target,purpose,created_at) | purge 7 d |
| password_reset_tokens | reset | email/phone, token_hash, created_at | purge 1 d |
| personal_access_tokens | Sanctum | framework; app access tokens (ability `customer`, expiry 60 min), deleted when their sign-in ends | — |
| refresh_tokens | IdentityAccess | one row per app refresh token: user_id FK cascade, family_id (a sign-in; shown to the customer as `session_id`), token_hash (SHA-256, unique; the token itself is never stored), access_token_id FK set null, status active/used/revoked (CHECK), device_name, platform android/ios (CHECK), signed_in_at/created_at/last_used_at/used_at/revoked_at/expires_at (UTC DATETIME(6)); idx(token_hash unique) = refresh, idx(family_id) = end a sign-in, idx(user_id,status) = my devices / sign out everywhere, idx(expires_at) = clean-up | purge 7 d after expiry; erased with the account; ADR P11-2 |
| devices | Notifications | a phone that may be pushed to: public_id (ULID), user_id FK cascade, family_id (the app sign-in it belongs to), platform android/ios (CHECK), push_token_hash (SHA-256, unique) + push_token_encrypted, app_version, locale, last_seen_at, created_at; idx(user_id) = push to a person, idx(family_id) = remove with its sign-in | removed when the sign-in ends; in the data copy (no token), erased with the account; ADR P11-2 |
| roles / permissions | RBAC | name UNIQUE, guard; permission name `<resource>.<action>` | — |
| role_has_permissions, model_has_roles | pivots | composite PK | — |
| staff_mfa_credentials | TOTP | user_id UNIQUE FK, secret (encrypted), confirmed_at, recovery_codes_hash j | secret enc |
| user_sessions | device list | user_id, session_id_hash, ip, user_agent, last_seen_at | — |

## Customers & geography
| Table | Purpose | Columns / constraints | Flags |
|---|---|---|---|
| customers | profile (also guests) | STD; user_id NULL UNIQUE FK, name s150, phone s16, email NULL, birth_date NULL, marketing_consent b, consent_at, anonymised_at NULL; idx(phone) | PII |
| customer_addresses | address book | public_id, customer_id FK, label s40 NULL, recipient s120, phone s16 (E.164), division_id/district_id/upazila_id FK, area s120 NULL, address_line s255, landmark s160 NULL, delivery_note s255 NULL, postal_code char(4) NULL, is_default b; generated `default_marker` + UNIQUE = at most one default per customer; max 10 per customer (Action); hard delete (orders keep their own snapshot) | PII |
| divisions, districts, upazilas | BD geography | id (the dataset's own, so seeding is an idempotent upsert), name_en, name_bn, parent FK (district→division, upazila→district); Source, licence and date: `app/Modules/Customers/Database/data/geography/SOURCE.md` (nuhil/bangladesh-geocode, MIT, 494 upazilas) | reference |
| consents | consent records | customer_id, type, granted b, text_version, at, ip | AO |

## Catalog
| Table | Purpose | Columns / constraints | Flags |
|---|---|---|---|
| products | catalog item | STD; product_type (physical/digital/service), brand_id FK NULL, tax_class_id FK, status (draft/published/archived), published_at, publish_at NULL, created_by; idx(status,published_at); SD | SD |
| product_translations | localized content | product_id FK, locale FK, name, slug, short_description, description (sanitised HTML), seo_title, seo_description; UNIQUE(product_id,locale), UNIQUE(locale,slug); FULLTEXT(name,short_description) | SD via product |
| product_variants | sellable unit | STD; product_id FK, sku s64 UNIQUE, barcode NULL, is_default b, track_inventory b, weight_g, length_mm, width_mm, height_mm, min_qty, max_qty, qty_step, status; CHECK(track_inventory=0 OR product type physical) via trigger | SD |
| product_categories | M:N | product_id, category_id, is_primary; PK(product_id,category_id) | — |
| categories | taxonomy | STD; parent_id NULL, sort_order, is_active; SD | SD |
| category_translations | | category_id, locale, name, slug, description, seo_*; UNIQUE(category_id,locale), UNIQUE(locale,slug) | |
| category_closure | hierarchy | ancestor_id, descendant_id, depth; PK(ancestor,descendant) | — |
| brands, brand_translations | | same pattern | SD |
| attributes, attribute_translations, attribute_values, attribute_value_translations | spec & option definitions | attribute: code UNIQUE, is_filterable b, is_variant_option b; value: attribute_id, code | |
| variant_option_values | variant ↔ option value | variant_id, attribute_value_id; PK(variant_id,attribute_value_id) | |
| product_attribute_values | specs | product_id, attribute_id, attribute_value_id NULL, value_text NULL | |
| tags, collections (+translations), collection_products | merchandising | collection: type (manual/rule), rule j NULL | SD |
| product_media | images | product_id, variant_id NULL, disk, path, mime, width, height, sort_order; product_media_translations(alt text per locale) | SD |
| tax_classes | | code UNIQUE, name; tax_rates(tax_class_id, rate_bp INT, jurisdiction s20, valid_from, valid_to NULL; no overlaps per class — rows never edited) | rate values 🔎 verify |
| catalog_imports | CSV import jobs | public_id ULID UNIQUE, created_by, filename, stored_path (private disk), dry_run b, status (queued/running/done/failed; CHECK), total/created/updated/error counts, results JSON (per-row outcome + error keys), failure, started_at, finished_at; idx(created_at) | |
| slug_redirects | 301 on slug change | locale, old_slug, entity_type, entity_id; UNIQUE(locale,old_slug) | |

## Pricing & Promotions
| Table | Columns / constraints | Flags |
|---|---|---|
| price_lists | STD; code UNIQUE, currency (B2B-ready, default list seeded) | |
| prices | variant_id FK, price_list_id FK, M(amount), compare_at_minor NULL, starts_at NULL, ends_at NULL, tax_inclusive b; CHECK(ends_at IS NULL OR starts_at IS NULL OR ends_at>starts_at); idx(variant_id,price_list_id,starts_at) | audit |
| coupons | STD; code s64 UNIQUE (upper case), type (percent/fixed/free_shipping), value (basis points for percent, minor units for fixed), max_discount_minor NULL, min_spend_minor, scope (all/products/categories), starts_at NULL, ends_at NULL (exclusive), usage_limit NULL, used_count CHECK(≥0 and ≤usage_limit), per_customer_limit NULL, is_active; CHECKs on type, scope, value range, window | redemption by one conditional UPDATE |
| coupon_products / coupon_categories | coupon_id FK, product_id / category_id; PK(both) — what a products/categories coupon applies to (categories include sub-categories at evaluation) | |
| coupon_redemptions | coupon_id FK, order_id (FK to orders added with the orders table), redeemer (`u:<user id>` or `p:<sha256 of phone>`), amount_minor, redeemed_at; UNIQUE(coupon_id,order_id) makes redemption idempotent per order | AO |
| promotions | STD; type (percent/fixed), value, max_discount_minor NULL, min_spend_minor, starts_at/ends_at, is_active; the best qualifying one applies automatically | |
| promotion_translations | promotion_id FK, locale, label; PK(promotion_id,locale) | |

## Inventory
| Table | Columns / constraints | Flags |
|---|---|---|
| stock_locations | STD; name, type (warehouse/store/transit), is_active, address | |
| stock_levels | variant_id, location_id, on_hand INT, reserved INT, low_stock_threshold NULL; PK(variant_id,location_id); CHECK on_hand≥0, reserved≥0, reserved≤on_hand | updated in place; ledger is truth |
| stock_movements | id, variant_id, location_id, delta INT (signed), reason (receipt/shipment/adjustment/return_restock/transfer_in/transfer_out/count/write_off), ref_type, ref_id, note, created_by, created_at; idx(variant_id,location_id,id) | **AO** |
| stock_reservations | STD; variant_id, location_id, quantity CHECK>0, owner_type (cart/order), owner_id, status (active/committed/released/expired), expires_at NULL; idx(status,expires_at),(owner_type,owner_id) | status transitions only |
| stock_transfers(+items), stock_counts(+lines) | Phase 7 ops; approval fields | |

## Cart & Checkout
| Table | Columns / constraints | Flags |
|---|---|---|
| carts | public_id, user_id NULL UNIQUE FK users (one cart per signed-in customer), guest_token_hash char(64) NULL UNIQUE (sha256 of the cookie token), currency, expires_at (refreshed on change; guests 30 d, customers 90 d), merged_into_id NULL; CHECK(user_id or guest_token_hash present)| purge expired |
| cart_coupons | cart_id FK cascade, code (normalised upper case); UNIQUE(cart_id,code); only the typed code is kept, validity is re-decided each time the cart is shown | |
| cart_items | cart_id FK cascade, variant_id FK, quantity CHECK>0 (cap 100 per line, 50 lines per cart, in the Action), added_price_minor CHECK≥0 (unit price last accepted: drives the change notice); UNIQUE(cart_id,variant_id) | |

## Orders

> Phase 9 slice 5: `order_cancellation_requests` — public_id, order_id FK, user_id FK, status (requested/approved/rejected/withdrawn; moves enforced by trigger; a refusal needs its decision_note), open_key UNIQUE (the order id while the request waits or is approved, NULL once refused or withdrawn: one open request per order), note (the customer's words, cleared on erasure), decided_by/at, decision_note, created/updated_at. Approving records the shop's agreement only; it never cancels or refunds.

> Erasure (Phase 9): `orders.anonymised_at` (set once). The three contact columns of an order may be replaced by the fixed placeholders `Deleted customer` / `erased` / NULL only in the same update that sets it; every other column stays frozen (trigger `orders_erasure_scrub_only`). `order_addresses` of an erased customer are scrubbed to the same placeholders; the district snapshot stays.
| Table | Columns / constraints | Flags |
|---|---|---|
| orders | STD; placement_key char(64) NULL UNIQUE (sha256 of shopper + checkout form token: one order per checkout submission), order_number s20 UNIQUE (`VM-YYMM-NNNNNN` from SEQUENCE `order_number_seq`), user_id NULL FK users (NULL = guest checkout; may be set ONCE from NULL when the shopper adds the order to their account — trigger `orders_owner_set_once`; never changed afterwards), contact_name, contact_phone (E.164), contact_email NULL, status (pending/confirmed/processing/completed/cancelled), payment_status (unpaid/pending/paid/partially_refunded/refunded/failed), fulfillment_status (unfulfilled/partially_fulfilled/fulfilled/partially_returned/returned), needs_review b, channel (web/api/admin), locale, currency, subtotal_minor, discount_minor, shipping_minor, cod_fee_minor, tax_minor, grand_total_minor (all ≥0), tax_inclusive b, CHECK(grand_total_minor = subtotal_minor − discount_minor + shipping_minor + cod_fee_minor + IF(tax_inclusive,0,tax_minor)) (see BANGLADESH_LOCALIZATION §5), CHECK((status='cancelled') = (cancelled_at IS NOT NULL)), payment_method_code, shipping_method_code NULL, placed_at, cancelled_at, cancel_reason; idx(user_id,created_at),(status,created_at),(payment_status,created_at),(contact_phone),(needs_review). Trigger: only status, payment_status, fulfillment_status, needs_review, cancelled_at, cancel_reason, updated_at may change; DELETE refused | financial, no delete |
| order_items | order_id FK, variant_id FK (ref only), product_type, name_snapshot j(en,bn), sku_snapshot, options_snapshot j NULL, quantity CHECK>0, unit_price_minor, discount_minor (own + share of order discount), tax_rate_bp, tax_minor, line_total_minor CHECK(= quantity×unit_price_minor − discount_minor), fulfilled_qty ≤ quantity, returned_qty ≤ fulfilled_qty. Trigger: only fulfilled_qty / returned_qty change; DELETE refused | snapshot (BD-27) |
| order_addresses | order_id FK, type (shipping/billing) UNIQUE(order_id,type), recipient_name, phone (E.164), division_id/district_id/upazila_id NULL (reference, no FK), location_snapshot j {level:{en,bn}}, postal_code, area, address_line, landmark, delivery_note NULL, fingerprint char(64) NULL (sha256 of normalised street line + area + upazila; idx(fingerprint,created_at) answers "who else used this address recently"); idx(phone) | PII |
| order_status_history | order_id FK, axis (status/payment/fulfillment), from_state NULL (first entry), to_state, actor_type, actor_id NULL, reason NULL, reason_code NULL (only on a status→cancelled entry; CHECK limits it to customer_request/out_of_stock/payment_failed/address_problem/fraud_or_risk/duplicate_order/other), created_at; idx(order_id,id), idx(axis,to_state,created_at) (cancellations in a period, for reports) | **AO** |
| order_tax_lines | order_id FK, kind (shipping/cod_fee), rate_bp, taxable_minor, tax_minor, created_at (item tax lives on order_items) | AO, snapshot |
| invoices | STD; order_id FK, type (tax_invoice/credit_note), number s30 UNIQUE (own SEQUENCE), original_invoice_id NULL FK, issued_at, seller_snapshot j, buyer_snapshot j, lines_snapshot j, M(total), tax_minor, pdf_path | **AO**, retention ≥ 6 y proposed |
| order_fraud_flags | order_id FK, rule_code (velocity_phone / address_many_phones), score, details j, created_at; idx(order_id) | **AO** |
| order_blocklist | kind (phone/address), value_hash char(64) (sha256 of the E.164 phone or the address fingerprint), hint (masked number / area, never the street), reason NULL, created_by NULL FK users, is_active, expires_at NULL; UNIQUE(kind,value_hash) | not deleted: switched off |
| order_notes | order_id FK, author_id NULL FK users (nulled on staff deletion), visibility (internal/customer), body, created_at; idx(order_id,id) | **AO** (author_id excepted) |
| blocklist | type (phone/address/ip), value_hash, reason | |

## Payments
| Table | Columns / constraints | Flags |
|---|---|---|
| payment_methods | code UNIQUE (cod, online), provider (cod/sslcommerz/fake), type (cod/gateway/mfs/bank), is_enabled, min_minor / max_minor NULL (CHECK min ≤ max), restrictions j NULL, fee_rule j NULL (reserved: the shop absorbs gateway fees), sort_order; payment_method_translations(method_id, locale, label, description) | seeded once, never overwritten |
| payments | STD; order_id FK, method_code, provider, amount_minor CHECK>0, currency, captured_minor, refunded_minor (CHECK 0 ≤ refunded ≤ captured ≤ amount; captured>0 ⇒ paid_at set), status (initiated/pending/paid/failed/cancelled/expired/needs_review), expires_at, paid_at; idx(order_id),(status,created_at),(status,expires_at); generated open_marker + UNIQUE = at most one OPEN (initiated/pending/needs_review) payment per order. Triggers: only status, captured_minor, refunded_minor, expires_at, paid_at change; status moves only along the state machine; DELETE refused | financial |
| payment_transactions | STD; payment_id FK, provider, merchant_ref s64 UNIQUE (order number + attempt, e.g. `VM-2610-000123-2`; ≤ 30 chars because SSLCommerz `tran_id` allows 30), attempt (UNIQUE(payment_id,attempt)), provider_session, provider_validation_id, provider_txn_id (the bank transaction id; set once), status (initiated/pending/succeeded/failed/cancelled/expired; `succeeded` never changes; failed/cancelled/expired may still become `succeeded` if the gateway later confirms the money), amount_minor CHECK>0, request_summary j (secrets removed; includes the gateway page URL; filled once, never rewritten), verified_at, failure_code; UNIQUE(provider,provider_txn_id); DELETE refused | status forward-only trigger |
| payment_webhook_events | provider, provider_event_id (the gateway's id, or a hash of provider + transaction + status + amount + time), payment_id NULL, payload j (as received; frozen), verified b NULL (did the server-to-server check agree; replaces the draft's `signature_valid` because SSLCommerz's signature scheme is not documented), status (received/processed/ignored/error), error, received_at, processed_at; UNIQUE(provider,provider_event_id); DELETE refused | raw retention 18 m |
| refunds | STD; payment_id FK, order_id FK, payment_transaction_id (nullable FK: the attempt the money goes back through; idx(payment_transaction_id,status)), amount_minor CHECK>0, reason (free text), reason_code NULL (CHECK: customer_changed_mind/item_returned/item_damaged/wrong_item/not_delivered/price_adjustment/duplicate_payment/fraud_or_risk/other; set when asked for and frozen; NULL before codes existed), currency, status (requested/approved/processing/succeeded/failed/rejected), method (gateway/manual), reason, refund_ref char(30) UNIQUE (sent to the gateway as `refund_trans_id`), provider_refund_id (set once), requested_by, approved_by, proof_ref, failure_reason; idx(payment_id,status) serves the over-refund guard. Triggers: amount, payment and requester frozen; status moves along the state machine; DELETE refused | financial |
| cod_collections | STD; order_id FK, manual_marker (generated: order_id when shipment_id IS NULL) **UNIQUE** (one hand-recorded collection per order; each shipment collects its own), payment_id FK UNIQUE (the paid `payments` row it created: provider `cod`, one succeeded attempt whose bank id is this row's public id), shipment_id NULL UNIQUE (FK arrives with shipments), amount_minor CHECK>0, currency, source (courier/manual), collected_at, recorded_by, note | **AO** |
| cod_remittances | STD; courier, statement_ref (UNIQUE(courier,statement_ref)), amount_minor (what arrived), expected_minor (what the listed collections add up to), status (pending/reconciled/short/over), received_at; cod_remittance_items(remittance_id, cod_collection_id UNIQUE, amount_minor) | |
| settlement_imports | STD; provider, file_hash char(64) (sha256 of the normalised file), period_from/period_to, row_count, matched_count, problem_count, imported_by, created_at; UNIQUE(provider, file_hash) so a file is checked once | **AO** |
| reconciliation_exceptions | STD; type (unmatched_provider_txn/amount_mismatch/currency_mismatch/orphan_payment/long_pending/late_success/duplicate_payment/cod_short/cod_over/refund_stuck), payment_id NULL, order_id NULL, reference, details j, status (open/resolved/ignored), resolved_by, resolved_at, resolution; UNIQUE(type,reference) so one problem is raised once | |

## Shipping & Fulfilment

> Phase 9 slice 5: `return_requests.reason` (the customer's words) may be CLEARED to NULL and never changed to anything else (trigger `return_requests_reason_clear_only`); every other frozen column stays frozen.
| Table | Columns / constraints | Flags |
|---|---|---|
| shipping_zones (+translations) / shipping_zone_districts | public_id, is_fallback b (exactly one: generated marker + UNIQUE), cod_available b, is_active, sort_order; zone name per language. A district belongs to at most one zone (UNIQUE district_id); districts no zone claims use the fallback zone. District-level only at MVP (no upazila zones) | |
| shipping_methods (+translations) | public_id, code UNIQUE, cod_available b, cod_fee_minor ≥0 (flat), free_over_minor NULL ≥0 (order value after discounts, before shipping), is_active, sort_order; name and delivery_estimate per language. Methods are global; prices are per zone | |
| shipping_rates | zone_id, method_id, max_weight_g NULL (upper limit of the band, NULL = and above), price_minor ≥0; UNIQUE(zone, method, max_weight_g) and at most one open band per zone+method (generated marker). The smallest band that fits the parcel weight applies; no band fits = method not offered | |
| courier_accounts | STD; public_id, courier (adapter name, lower-case), label, is_active, credentials (encrypted by the app, never in arrays/JSON/logs), settings j (non-secret), sort_order; UNIQUE(courier,label) | secrets |
| courier_status_map | courier, provider_status, internal_status (handed_over/in_transit/out_for_delivery/delivered/failed/returned), note; UNIQUE(courier,provider_status). An unmapped word becomes `unknown` + alert | |
| shipments | STD; public_id, order_id FK, courier_account_id FK, courier (copied), courier_consignment_id NULL (set once), tracking_code, tracking_url, status (created/handed_over/in_transit/out_for_delivery/delivered/failed/returned/cancelled/unknown; moves enforced by trigger `shipments_status_flow`, same rules as `ShipmentStatus`), cod_amount_minor ≥0 (cash this parcel collects), currency, attempts (failed deliveries), handed_over_at, delivered_at, created_by; UNIQUE(courier,courier_consignment_id); CHECK delivered ⇒ delivered_at, left the shop ⇒ handed_over_at; only status/ids/tracking/attempts/times may change; DELETE refused | **AO-status** |
| (stock_movements) | reason also `shipment_cancelled` (a parcel cancelled before handover puts its stock back; positive delta, ref_type `shipment`, ref_id the parcel) | |
| shipment_exceptions | STD; type (unknown_status/attempts_exhausted/stuck/unknown_consignment/returned_to_origin), shipment_id NULL, reference, details j, status (open/resolved/ignored), resolved_by/at, resolution; UNIQUE(type,reference) so a problem is raised once | |
| (shipments) | also `polled_at` (when the courier was last asked; index status,polled_at) | |
| (shipments) | (the first delivery-report index `shipments_handed_over_at_courier_index` was dropped 2026-12-10; the covering index below replaces it) | |
| (shipments) | also covering index `shipments_delivery_report_cover_index(handed_over_at, courier, status, attempts, delivered_at, currency, cod_amount_minor)` = the delivery performance report read from the index alone; the older `shipments_handed_over_at_courier_index` (handed_over_at, courier) was dropped by the contract step 2026_12_10_000001 | |
| (return_requests) | also index `return_requests_created_at_kind_reason_index` (created_at, kind, reason_code) = the returns-by-reason report | |
| (coupon_redemptions) | also index `coupon_redemptions_redeemed_at_coupon_index` (redeemed_at, coupon_id) = the coupon performance report | |
| (service_bookings) | also index `service_bookings_starts_at_resource_index` (starts_at, resource_id) = the booking utilisation report | |
| (orders) | also `delivered_at` (set once by trigger when the last parcel arrived); payment_status also `partially_paid` (cash collected parcel by parcel) | |
| return_requests | STD; public_id, order_id FK, shipment_id NULL FK, kind (customer/origin), status (requested/authorized/rejected/received/closed/cancelled; moves enforced by trigger `return_requests_status_flow`, same rules as `ReturnStatus`), reason_code, reason, requested_by_type/requested_by, decided_by/decided_at/decision_note, shipping_cost_payer (shop/customer/none: recorded, never charged), refund_suggested_minor (a suggestion, never executed), currency, received_at/by, closed_at; UNIQUE(origin_marker) so an undelivered parcel opens one return; CHECK closed ⇒ closed_at, received_at, refund_suggested_minor; only the decision/receipt columns may change | **AO-status** |
| return_items | return_id FK, order_item_id FK, quantity>0 (asked), received_qty NULL ≤ quantity and outcome (restock/quarantine/write_off/not_received) both set once by trigger, inspected_by/at, note; UNIQUE(return_id,order_item_id); never deleted | **AO** |
| (stock_locations) | type also `quarantine` (returned goods waiting or written off; never available, never reserved) | |
| stock_transfers | STD; public_id, from_location_id, to_location_id (different), status (in_transit/received/cancelled; trigger, same rules as `TransferStatus`), note, created_by, received_by/at, cancelled_at; only status and receipt columns may change | **AO-status** |
| stock_transfer_items | transfer_id, variant_id, sent_qty>0, received_qty and lost_qty (set once, together equal to sent_qty); UNIQUE(transfer_id,variant_id) | **AO** |
| stock_counts | STD; public_id, location_id, status (open/submitted/approved/rejected/cancelled; trigger, same rules as `CountStatus`), created_by, submitted_by/at, decided_by/at, decision_note; UNIQUE(active_marker) = one open or submitted count per place; trigger: decided_by never equals submitted_by | **AO-status** |
| stock_count_lines | count_id, variant_id, expected_qty (books at start), counted_qty, system_qty and variance (books at submit; CHECK variance = counted − system); UNIQUE(count_id,variant_id) | **AO** |
| stock_imports | public_id ULID UNIQUE, location_id FK, mode `set`\|`add`, status `previewed`→`applied`→`rolled_back` (state flow in the database, CHECK on the values), file_name, note (written next to every ledger row), row_count, error_count, unchanged_count (CHECK: never more than row_count), created_by, applied_by/at, rolled_back_by/at, timestamps. Append-only: only the state columns can change. INDEX (created_at, id) serves the list of stock files, newest first. |
| stock_import_rows | import_id FK, line (the file's line; UNIQUE(import_id, line) serves the rows of one file in order), sku, variant_id FK (null when unknown), raw_quantity (as typed, for the error report), quantity, status `ok`\|`unchanged`\|`error`, error_code (CHECK: present exactly for errors), before_on_hand and delta (the dry run), applied_delta and after_on_hand (what really happened), rollback_state `reverted`\|`kept_changed`\|`kept_blocked`. Append-only: only applied_delta, after_on_hand and rollback_state are ever written later. INDEX (variant_id) serves "which files touched this product". |
| courier_statements | STD; public_id, courier, statement_ref, file_hash, period_from/to, received_minor (paid over), fee_minor (kept by the courier), line/matched/problem counts, imported_by; UNIQUE(courier,statement_ref), UNIQUE(courier,file_hash); never changed or deleted | **AO** |
| courier_statement_lines | statement_id FK, shipment_id NULL FK, consignment_id, courier_status, cod_minor, fee_minor, delivered_at, result (matched/unknown_parcel/status_mismatch/cod_mismatch/already_remitted); UNIQUE(statement_id,consignment_id) | **AO** |
| (cod_remittances) | also `fee_minor` (what the courier kept); status compares received + fee with the cash we collected | |
| shipment_items | shipment_id FK, order_item_id FK, quantity CHECK>0, created_at; UNIQUE(shipment_id,order_item_id); never changed or deleted. Over-shipping is prevented by `order_items.fulfilled_qty` (CHECK ≤ quantity, conditional UPDATE) | **AO** |
| shipment_events | shipment_id FK, provider_status (the courier's word), internal_status, occurred_at (courier's time), received_at, source (webhook/poll/manual/system), actor_id, note, summary j; UNIQUE(shipment_id,provider_status,occurred_at); the parcel's state follows event time, not arrival order | **AO** |
| return_requests / return_items | STD; order_id, reason_code, status, inspection_outcome (restock/quarantine/write_off), photos | |

## Digital Products

> Erasure (Phase 9): `download_logs.ip` and `user_agent` may be cleared to NULL and never changed to anything else (trigger `download_logs_clear_only`).
| Table | Columns / constraints |
|---|---|
| digital_assets | **As built (Phase 8, slice 1):** id, public_id, variant_id FK UNIQUE (one asset per variant), download_limit NULL (NULL = shop default), access_days NULL (NULL = no expiry), created_by; CHECKs ≥1 |
| digital_asset_versions | **As built:** asset_id FK, version (UNIQUE with asset_id; newest = current), storage_key UNIQUE (random, private bucket), original_filename, mime (from the file's bytes), size_bytes CHECK≥1, sha256, uploaded_by, created_at; never changed or deleted | **AO** |
| license_key_pools | **As built (Phase 8, slice 4):** public_id, variant_id FK UNIQUE (one pool per variant), created_by |
| license_keys | **As built:** pool_id FK, key_encrypted (app-key encryption; never in the clear), fingerprint (HMAC; UNIQUE with pool_id), status (available/allocated/revoked; trigger: available → allocated/revoked, allocated → revoked), entitlement_id NULL FK (set once), allocated_at (set once), created_at; the key, fingerprint and pool never change and no row is deleted; idx(pool_id,status,id), idx(entitlement_id). Also `entitlements.keys_needed` (keys owed, one per unit) |
| entitlements | **As built (Phase 8, slice 2):** public_id, order_id FK, order_item_id FK UNIQUE (one right per order line), variant_id FK, status (active/revoked; trigger: active → revoked only; "expired" is derived from expires_at, never stored), download_limit NULL (unlimited), downloads_used CHECK ≤ limit, expires_at NULL, granted_at, revoked_at (required when revoked), revoked_reason. No owner column: the owner is the order's owner, resolved through Orders |
| download_logs | **As built (Phase 8, slice 3):** entitlement_id FK, version_id NULL FK (set when granted), user_id NULL (NULL = a guest), outcome (granted/not_owner/revoked/expired/limit_reached/no_file; granted needs a version), ip, user_agent (≤255), created_at(6); idx(entitlement_id, created_at), idx(created_at); never changed or deleted. The signed address is never stored | **AO** |
| refund_policies | **As built (Phase 8, slice 5):** public_id, code UNIQUE, rule (non_refundable/until_first_use/within_days; CHECK days ≥ 1 only for within_days), days, revoke_on_full_refund, is_default (generated marker UNIQUE: at most one default), created_by |
| refund_policy_translations | policy_id FK, locale FK languages, title, text; UNIQUE(policy_id, locale) |
| (digital_assets) | also `refund_policy_id` NULL FK (NULL = the default policy) |
| (entitlements) | also `first_used_at` (set once: first download or first look at the keys) |
| entitlement_version_notices | entitlement_id FK, version_id FK, created_at; UNIQUE(entitlement_id, version_id): one message per customer and version |

## Services & Bookings
| Table | Columns / constraints |
|---|---|
| service_definitions | **As built (Phase 8, slice 6):** public_id, product_id UNIQUE FK, location_type (at_provider/online), lead_time_min, max_horizon_days (1–365), hold_minutes (5–120), is_active, created_by; no deposit, no on-site (later) |
| service_packages | **As built:** service_id FK, variant_id UNIQUE FK (a package is a variant), duration_min (5–1440), buffer_before_min / buffer_after_min (0–240), is_active; no capacity column (groups later) |
| service_resources | STD; name, type (staff/room/vehicle), is_active; service_resource_links(service_id, resource_id) |
| availability_rules | resource_id, weekday 0–6, start_time, end_time, valid_from, valid_to; availability_exceptions(resource_id, date, is_closed, start/end) |
| service_bookings | **As built:** public_id, package_id FK, resource_id FK, order_item_id NULL UNIQUE FK, user_id NULL, status (held/confirmed/in_progress/completed/cancelled/no_show; trigger-enforced moves), starts_at/ends_at, **blocks_from/blocks_until (service plus buffers)**, hold_expires_at (required while held), active_resource_id (generated PERSISTENT), `PERIOD FOR slot(blocks_from, blocks_until)` + `UNIQUE(active_resource_id, slot WITHOUT OVERLAPS)`; CHECKs on times and status. Earlier draft follows: order_item_id FK, package_id FK, resource_id FK, customer_id, status (held/confirmed/in_progress/completed/cancelled/no_show/rescheduled), starts_at, ends_at CHECK(ends_at>starts_at), hold_expires_at NULL, active_resource_id AS (IF(status IN('held','confirmed'),resource_id,NULL)) PERSISTENT, `PERIOD FOR slot(starts_at,ends_at)`, **UNIQUE(active_resource_id, slot WITHOUT OVERLAPS)**, location_address_id NULL, meeting_url NULL |
| (service_bookings) | also `holder_key` (the visitor who holds the slot before an order exists; never shown; idx(holder_key, status)) |
| (service_bookings) | also `policy_terms` (JSON copy of the cancellation terms at confirmation; CHECK JSON_VALID) and `reschedule_count` |
| cancellation_policies | public_id, code UNIQUE, late_fee_bp, no_show_fee_bp (CHECK ≤ 10000), reschedule_hours, max_reschedules, is_default (one default: generated marker UNIQUE), created_by |
| cancellation_policy_windows | policy_id FK, hours_before, fee_bp (CHECK ≤ 10000), UNIQUE (policy_id, hours_before): "cancel at least N hours before → fee X" |
| cancellation_policy_translations | policy_id FK, locale FK, title, text, UNIQUE (policy_id, locale) |
| (service_definitions) | also `cancellation_policy_id` NULL FK (NULL = the default policy) |
| (service_definitions) | also `meeting_url` NULL varchar(500): where an online service is joined (https only; checked by the action) |
| booking_cancellations | booking_id UNIQUE FK, kind (cancelled/no_show), by_type/by_id, minutes_before, paid_minor, fee_bp, fee_waived, fee_minor, refund_due_minor (advice only), reason, created_at; CHECKs: fee ≤ paid, refund_due ≤ paid − fee, waived ⇒ fee 0 | **AO** |
| (service_bookings) | also `completed_at`, `completed_by` (customer/staff/system); idx(status, starts_at), idx(status, ends_at) for the lifecycle job |
| booking_notices | booking_id FK, kind (reminder_long/reminder_short/review), channel (sms/email/push), for_starts_at, status (claimed/sent/failed/gave_up), attempts, last_error (class name only), created_at/updated_at; UNIQUE (booking_id, kind, channel, for_starts_at); **AO** except the outcome columns |
| notification_templates | code UNIQUE, channel (email/sms), kind (transactional/marketing), is_active; CHECKs on channel and kind |
| notification_template_versions | template_id FK, locale FK, version, subject (email), body, created_by, created_at; UNIQUE (template_id, locale, version); **AO**; the current words of a language are its highest version |
| notification_logs | public_id, idempotency_key UNIQUE (code + event key), template_code, channel (email/sms/push; a push's recipient_masked is `app`, its payload holds the user id), kind, locale, recipient_masked, user_id NULL, related, status (queued/sending/sent/failed/dead/skipped), skip_reason, attempts, next_attempt_at, claimed_at, sent_at, template_version, provider_ref, last_error (a code, never a payload), payload (ENCRYPTED address + values; erased once sent, or after the retention period when dead); idx (status, next_attempt_at) |
| notification_preferences | user_id FK, channel (email/sms/push), marketing_opt_in, quiet_from/quiet_to (both or neither), opted_at, source; UNIQUE (user_id, channel) |
| notification_consent_events | user_id FK, channel (email/sms/push), granted, source (account/checkout/staff/link), created_at; **append-only**; one row each time a customer's marketing yes/no CHANGES (the current answer is in notification_preferences); identifies nobody once the account is erased |
| privacy_requests | public_id, user_id FK, type (export/erasure), status (export: requested/ready/failed/expired; erasure: requested/approved/rejected/cancelled/blocked/done; moves enforced by trigger), open_key UNIQUE (`<user>:<type>` while open, NULL when closed: one open request of each kind per customer), locale, reason, decision_reason, decided_by FK, failure (codes only), file_key (encrypted export on the private disk), requested/decided/completed/expires_at |
| (cart_items) | also `hold_ref` (the held slot a booking line stands for; NULL for products) |
| booking_requirements | booking_id, field_key, value, attachment_path |
| booking_events | booking_id, event, actor, at, note | **AO** |
| cancellation_policies | service_id, hours_before, fee_percent, refund_percent (+translations) |

## Engagement, Support, Notifications
| Table | Columns |
|---|---|
| reviews | public_id, product_id FK, variant_id FK, order_item_id FK UNIQUE (one review per order line), user_id FK, rating 1–5 CHECK, title ≤100, body, locale (en/bn, the language written), author_name ("Amina R.", a snapshot), status (pending/published/rejected/withdrawn; moves enforced by trigger; rejected ⇔ rejection_reason set), moderated_by/at, created/updated_at; deleted on the author's erasure |
| product_rating_stats | product_id PK FK, stars_1 … stars_5 counts of PUBLISHED reviews; changed with conditional updates in the same transaction as the decision that publishes or removes a review (never recomputed over all reviews); count and average are derived |
| wishlist_items | user_id FK, variant_id FK, created_at; UNIQUE (user_id, variant_id); a signed-in customer only (no guest wishlist); capped by `engagement.wishlist_max` (200) |
| stock_alerts | user_id FK, variant_id FK, status (pending/notified; CHECK ties it to notified_at), locale, created_at, notified_at; UNIQUE (user_id, variant_id); index (status, variant_id, id) serves the five-minute run |
| cart_recovery_messages | user_id FK cascade, cart_stamp char(26) (when the newest cart line last changed: names the episode), channel (email/sms CHECK), created_at. UNIQUE(user_id, cart_stamp) is the claim: one reminder per customer and cart episode; index (user_id, created_at) serves "when was this customer last reminded". No message text, no address. Deleted on erasure. |
| report_daily_sales | day DATE, channel, payment_method, locale, currency, orders, gross_minor, discounts_minor, shipping_minor, cod_fee_minor, tax_minor, billed_minor, cancelled_orders, cancelled_billed_minor, refreshed_at. UNIQUE(day, channel, payment_method, locale, currency) is one group per day and serves `where day between ? and ?`. CHECK all amounts ≥ 0. Replaced a whole day at a time by `RefreshReportDay`; never added to. |
| report_daily_payments | same key; collected_minor, collected_count (payments paid that day), refunded_minor, refunded_live_minor (refunds of orders that were not cancelled; CHECK ≤ refunded_minor), refund_count, refreshed_at. Filed under the channel and language of the ORDER the money belongs to. |
| report_day_status | day DATE PK, refreshed_at, trigger (schedule/nightly/rebuild CHECK), sales_rows, payment_rows. Its row is the day's lock while the day is worked out, and the screens use it to say which days are missing. |
| report_exports | public_id ULID UNIQUE, requested_by FK users (restrict), report_code, filters JSON (period and choices), locale, with_finance, with_pii (what the requester could see when asking), status (requested/running/ready/failed/expired; CHECK, and a trigger allows only requested→running\|failed, running→ready\|failed, ready→expired), row_count, size_bytes, file_key (random name on the private disk; CHECK: ready needs it, with expires_at, finished_at, row_count), error_code (CHECK: failed needs it; a word, never exception text), downloads, requested_at, started_at, finished_at, expires_at, last_downloaded_at. Indexes (requested_by, id) = "my files, newest first"; (status, expires_at) = the hourly clean-up. |
| report_daily_products | day DATE, variant_id (no FK: outlives the variant), sku (as written on the order line), channel, payment_method, locale, currency, orders, units, returned_units (CHECK ≤ units), gross_minor, discounts_minor (CHECK ≥ 0), refreshed_at. UNIQUE(day, variant_id, channel, payment_method, locale, currency) serves `where day between ? and ?`. Replaced a whole day at a time. |
| report_daily_tax | day DATE, kind (goods/shipping/cod_fee CHECK), rate_bp, currency, tax_inclusive, `lines`, taxable_minor, tax_minor (CHECK ≥ 0), refreshed_at. UNIQUE(day, kind, rate_bp, currency, tax_inclusive). Tax billed on that day's non-cancelled orders; replaced a whole day at a time. |
| report_cohort_months | cohort_month CHAR(7) (Y-m, Dhaka month of a signed-in customer's first live order), months_since (0 = that month), customers, orders, sales_minor (goods price after discounts; CHECK customers > 0, orders ≥ customers, sales ≥ 0). UNIQUE(cohort_month, months_since). No customer id. Replaced whole every night. |
| report_cohort_status | id = 1 (CHECK), refreshed_at, cohort_rows, customers. The rebuild locks this row first. |
| (orders) | also index `orders_placed_at_index(placed_at)`: one day of orders for reports |
| (orders) | also indexes `orders_status_fulfillment_created_at_index(status, fulfillment_status, created_at)` = the dashboard count and the list filter of orders to ship, and `orders_cod_awaiting_index(payment_method_code, payment_status, status, created_at)` = cash on delivery aging (Phase 10 scale test) |
| (payments) | also index `payments_paid_at_index(paid_at)`; (refunds) `refunds_status_updated_at_index(status, updated_at)`: one day of money for reports |
| support_tickets | public_id, reference (a short random code the customer quotes, UNIQUE), user_id FK (customers only), order_id NULL FK (one of the customer's own), subject, category (order/delivery/payment/return/account/other), status (open/answered/closed; moves enforced by trigger; closed ⇔ closed_at), assignee_id NULL FK, locale, sla_due_at (first answer due), first_responded_at, last_message_at, closed_at; deleted on the customer's erasure |
| support_messages | public_id, ticket_id FK, author_type (customer/staff), author_id FK, body (≤5,000), is_internal (a note for staff only: never shown to the customer; CHECK staff-only), created_at; never edited; deleted with the ticket on erasure |
| support_attachments | public_id, message_id FK, original_name (cleaned), mime (image/webp re-encoded, or application/pdf), size_bytes, storage_key UNIQUE (random key on the private disk); deleted with its file on erasure |
| canned_replies / canned_reply_translations | public_id, is_active, sort_order; per language title (≤80) and plain-text body (≤2,000) with {{ customer }} and {{ order }} filled in when chosen; UNIQUE (reply, locale) |
| notification_templates (+versions) | event_key, channel, locale, subject, body, is_active, version |
| notification_logs | channel, recipient_hash, template_key, status, provider_ref, cost_minor, attempts, error, sent_at (no PII body) |
| notification_preferences | customer_id, channel, category, opted_in |
| device_tokens | user_id, platform, token_hash (Phase 11) |
| data_requests | customer_id, type (export/delete), status, approved_by |

## CMS, Search, Integrations, Reporting

> Phase 9 slice 6: `faq_entries` — public_id, category (orders/delivery/payment/returns/account/other), sort_order, is_published, created_by; `faq_entry_translations` — per language question (≤200) and plain-text answer (≤4,000), UNIQUE (entry, locale). Shown on `/help`; a new entry starts hidden and showing it needs `cms.publish`.
| Table | Columns |
|---|---|
| cms_pages (+translations) | STD; slug via translation, status, publish_at; SD |
| cms_pages (+translations) | STD; slug via translation, status, publish_at (= when the scheduled version goes live; CHECK pairs it with scheduled_version_id), draft_version_id / live_version_id / scheduled_version_id; SD. Translations are the live copy, written only on publish. Index publish_at serves the every-minute due job. |
| cms_page_versions | id, page_id, number (unique per page, CHECK >= 1), created_by, created_at. Append-only (triggers). |
| cms_page_version_translations | version_id, locale, title, slug, body, seo_title, seo_description, created_at; unique (version_id, locale); index (locale, slug) serves the "is this draft slug taken" check. Append-only (triggers). |
| cms_policy_releases | id, page_id, version_id, kind (CHECK), effective_at, acceptance_required, released_by, created_at. One row each time a policy version goes live; append-only. Index (kind, effective_at, id) serves "the policy in force for a kind". `cms_pages` gains `kind` (unique, nullable, CHECK) and `schedule_requires_acceptance`. |
| policy_acceptances | id, release_id, user_id OR order_id (CHECK: not both; neither only when erased_at is set), channel (CHECK signup/checkout/account), accepted_at, erased_at. Unique (user_id, release_id) and (order_id, release_id); index (release_id, channel) for staff counts. Append-only: the one allowed update is erasure (user_id to NULL with erased_at set). No IP, phone or device. |
| cms_menu_items | id, public_id, menu (CHECK header/footer), parent_id (self, cascade), kind (CHECK page/url/group), page_id (CHECK: set exactly for kind page), sort_order, is_active (starts false), created_by, timestamps. CHECK: a heading is top-level. Triggers (insert, update): a parent is a top-level heading of the same menu. Index (menu, is_active, parent_id, sort_order) serves the storefront render. |
| cms_menu_item_translations | item_id, locale, label, url (kind url only; a language without one uses the default language's); unique (item_id, locale). |
| cms_home_sections | id, public_id, type (CHECK seven types), collection_ref (a collection's public id; CHECK: set exactly for type collection), sort_order, is_active (starts false), starts_at / ends_at (UTC, end exclusive; CHECK end after start), created_by, timestamps. Index (is_active, sort_order) serves the home page. |
| cms_home_section_translations | section_id, locale, title, body (sanitised HTML, text blocks only); unique (section_id, locale). |
| cms_page_versions / policy_versions | version, effective_from, body per locale; policy_acceptances(customer_id, policy_version_id, at) AO |
| banners (+translations), menus, menu_items | placement, sort, window |
| search_synonyms | locale, term, synonym; search_queries(normalised_query, locale, result_count, at; no PII) |
| integration_configs | provider, key, value encrypted, updated_by |
| report_daily_sales, report_payment_daily, … | summary tables refreshed by jobs (defined in Batch 6 with metric definitions) |

## Table ↔ module count
Platform 8 · Identity 9 · Customers/geo 5 · Catalog ~26 · Pricing 5 · Inventory 6 · Cart 2 · Orders 7 · Payments 9 · Shipping 10 · Digital 6 · Services 9 · Engagement/Support/Notif ~14 · CMS/Search/Integrations ~12 ≈ **128 tables**. The exact column-level list is produced as migrations in Phase 1–3 and the dictionary is regenerated/validated against `information_schema` in CI.
