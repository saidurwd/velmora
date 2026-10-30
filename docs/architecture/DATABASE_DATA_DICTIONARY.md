# Database Data Dictionary (Batch 3 draft)

Shorthand — **STD** = `id` BIGINT UNSIGNED PK AI, `public_id` CHAR(26) ascii_bin UNIQUE, `created_at`, `updated_at` DATETIME(6) · **M(x)** = `x_minor` BIGINT NOT NULL CHECK ≥0 + `currency` CHAR(3) · **SD** = `deleted_at` soft delete · **AO** = append-only (trigger + grants) · `FK→t` = foreign key RESTRICT · Types: `s`=VARCHAR, `ts`=DATETIME(6) UTC, `b`=TINYINT(1), `j`=JSON. Enum columns carry a CHECK with the listed values. Owner = module. Retention per `DATABASE_DESIGN.md` §8. Column lists show non-STD columns; indexes beyond FK indexes are listed.

## Platform
| Table | Purpose | Columns / constraints | Flags |
|---|---|---|---|
| languages | supported locales | code s5 PK, name, native_name, is_default b, is_active b, direction s3 (ltr/rtl) | — |
| settings | typed key/value | key s100 UNIQUE(scope,key), scope s50, value j, is_secret b | audit on change |
| audit_events | who did what | actor_type, actor_id, action s100, subject_type, subject_id, before j, after j, ip s45, user_agent s255, request_id s36, created_at; idx(subject_type,subject_id,created_at),(actor_id,created_at) | AO, no updated_at |
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
| personal_access_tokens | Sanctum | framework | — |
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
| Table | Columns / constraints | Flags |
|---|---|---|
| orders | STD; placement_key char(64) NULL UNIQUE (sha256 of shopper + checkout form token: one order per checkout submission), order_number s20 UNIQUE (`VM-YYMM-NNNNNN` from SEQUENCE `order_number_seq`), user_id NULL FK users (NULL = guest checkout), contact_name, contact_phone (E.164), contact_email NULL, status (pending/confirmed/processing/completed/cancelled), payment_status (unpaid/pending/paid/partially_refunded/refunded/failed), fulfillment_status (unfulfilled/partially_fulfilled/fulfilled/partially_returned/returned), needs_review b, channel (web/api/admin), locale, currency, subtotal_minor, discount_minor, shipping_minor, cod_fee_minor, tax_minor, grand_total_minor (all ≥0), tax_inclusive b, CHECK(grand_total_minor = subtotal_minor − discount_minor + shipping_minor + cod_fee_minor + IF(tax_inclusive,0,tax_minor)) (see BANGLADESH_LOCALIZATION §5), CHECK((status='cancelled') = (cancelled_at IS NOT NULL)), payment_method_code, shipping_method_code NULL, placed_at, cancelled_at, cancel_reason; idx(user_id,created_at),(status,created_at),(payment_status,created_at),(contact_phone),(needs_review). Trigger: only status, payment_status, fulfillment_status, needs_review, cancelled_at, cancel_reason, updated_at may change; DELETE refused | financial, no delete |
| order_items | order_id FK, variant_id FK (ref only), product_type, name_snapshot j(en,bn), sku_snapshot, options_snapshot j NULL, quantity CHECK>0, unit_price_minor, discount_minor (own + share of order discount), tax_rate_bp, tax_minor, line_total_minor CHECK(= quantity×unit_price_minor − discount_minor), fulfilled_qty ≤ quantity, returned_qty ≤ fulfilled_qty. Trigger: only fulfilled_qty / returned_qty change; DELETE refused | snapshot (BD-27) |
| order_addresses | order_id FK, type (shipping/billing) UNIQUE(order_id,type), recipient_name, phone (E.164), division_id/district_id/upazila_id NULL (reference, no FK), location_snapshot j {level:{en,bn}}, postal_code, area, address_line, landmark, delivery_note NULL, fingerprint char(64) NULL (sha256 of normalised street line + area + upazila; idx(fingerprint,created_at) answers "who else used this address recently"); idx(phone) | PII |
| order_status_history | order_id FK, axis (status/payment/fulfillment), from_state NULL (first entry), to_state, actor_type, actor_id NULL, reason NULL, created_at; idx(order_id,id) | **AO** |
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
| payments | STD; order_id FK, method_code, provider, amount_minor CHECK>0, currency, captured_minor, refunded_minor (CHECK 0 ≤ refunded ≤ captured ≤ amount; captured>0 ⇒ paid_at set), status (initiated/pending/paid/failed/cancelled/expired/needs_review), expires_at, paid_at; idx(order_id),(status,created_at),(status,expires_at). Triggers: only status, captured_minor, refunded_minor, expires_at, paid_at change; status moves only along the state machine; DELETE refused | financial |
| payment_transactions | STD; payment_id FK, provider, merchant_ref s64 UNIQUE (order number + attempt, e.g. `VM-2610-000123-2`; ≤ 30 chars because SSLCommerz `tran_id` allows 30), attempt (UNIQUE(payment_id,attempt)), provider_session, provider_validation_id, provider_txn_id (the bank transaction id; set once), status (initiated/pending/succeeded/failed/cancelled/expired; final states never change), amount_minor CHECK>0, request_summary j (secrets removed), verified_at, failure_code; UNIQUE(provider,provider_txn_id); DELETE refused | status forward-only trigger |
| payment_webhook_events | provider, provider_event_id (the gateway's id, or a hash of provider + transaction + status + amount + time), payment_id NULL, payload j (as received; frozen), verified b NULL (did the server-to-server check agree; replaces the draft's `signature_valid` because SSLCommerz's signature scheme is not documented), status (received/processed/ignored/error), error, received_at, processed_at; UNIQUE(provider,provider_event_id); DELETE refused | raw retention 18 m |
| refunds | STD; payment_id FK, order_id FK, amount_minor CHECK>0, currency, status (requested/approved/processing/succeeded/failed/rejected), method (gateway/manual), reason, refund_ref char(30) UNIQUE (sent to the gateway as `refund_trans_id`), provider_refund_id (set once), requested_by, approved_by, proof_ref, failure_reason; idx(payment_id,status) serves the over-refund guard. Triggers: amount, payment and requester frozen; status moves along the state machine; DELETE refused | financial |
| cod_collections | STD; order_id FK, shipment_id NULL UNIQUE (FK arrives with shipments), amount_minor CHECK>0, currency, source (courier/manual), collected_at, recorded_by, note | **AO** |
| cod_remittances | STD; courier, statement_ref (UNIQUE(courier,statement_ref)), amount_minor (what arrived), expected_minor (what the listed collections add up to), status (pending/reconciled/short/over), received_at; cod_remittance_items(remittance_id, cod_collection_id UNIQUE, amount_minor) | |
| reconciliation_exceptions | STD; type (unmatched_provider_txn/amount_mismatch/currency_mismatch/orphan_payment/long_pending/late_success/duplicate_payment/cod_short/cod_over/refund_stuck), payment_id NULL, order_id NULL, reference, details j, status (open/resolved/ignored), resolved_by, resolved_at, resolution; UNIQUE(type,reference) so one problem is raised once | |

## Shipping & Fulfilment
| Table | Columns / constraints | Flags |
|---|---|---|
| shipping_zones (+translations) / shipping_zone_districts | public_id, is_fallback b (exactly one: generated marker + UNIQUE), cod_available b, is_active, sort_order; zone name per language. A district belongs to at most one zone (UNIQUE district_id); districts no zone claims use the fallback zone. District-level only at MVP (no upazila zones) | |
| shipping_methods (+translations) | public_id, code UNIQUE, cod_available b, cod_fee_minor ≥0 (flat), free_over_minor NULL ≥0 (order value after discounts, before shipping), is_active, sort_order; name and delivery_estimate per language. Methods are global; prices are per zone | |
| shipping_rates | zone_id, method_id, max_weight_g NULL (upper limit of the band, NULL = and above), price_minor ≥0; UNIQUE(zone, method, max_weight_g) and at most one open band per zone+method (generated marker). The smallest band that fits the parcel weight applies; no band fits = method not offered | |
| courier_accounts | STD; provider, label, credentials (encrypted JSON), is_active, config j | secrets |
| shipments | STD; order_id FK, courier_account_id NULL, courier (manual/provider), courier_consignment_id NULL, tracking_code, status (created/handed_over/in_transit/out_for_delivery/delivered/failed/returned/cancelled/unknown), cod_amount_minor, attempts, shipped_at, delivered_at; UNIQUE(courier,courier_consignment_id) | |
| shipment_items | shipment_id, order_item_id, quantity CHECK>0 | |
| shipment_events | id, shipment_id, provider_status, internal_status, occurred_at, payload j, received_at; UNIQUE(shipment_id,provider_status,occurred_at) | **AO** |
| return_requests / return_items | STD; order_id, reason_code, status, inspection_outcome (restock/quarantine/write_off), photos | |

## Digital Products
| Table | Columns / constraints |
|---|---|
| digital_assets | STD; variant_id FK, disk (private), path, original_name, size_bytes, checksum_sha256, version, is_current b |
| license_key_pools / license_keys | pool_id, key_encrypted, status (available/allocated/revoked), allocated_to_item_id NULL, allocated_at; idx(pool_id,status) |
| entitlements | STD; order_item_id FK, customer_id NULL, guest_email/phone NULL, asset_id FK, status (active/revoked/expired), download_limit NULL, downloads_used DEFAULT 0 CHECK(≥0), expires_at |
| download_logs | entitlement_id, asset_id, ip, user_agent, at | **AO** |
| refund_policies (+translations) | scope, rule j, text per locale |

## Services & Bookings
| Table | Columns / constraints |
|---|---|
| service_definitions | STD; product_id UNIQUE FK, location_type (on_site/at_provider/online), lead_time_min, max_horizon_days, requires_deposit b |
| service_packages | service_id, variant_id FK, duration_min, buffer_before_min, buffer_after_min, capacity DEFAULT 1 |
| service_resources | STD; name, type (staff/room/vehicle), is_active; service_resource_links(service_id, resource_id) |
| availability_rules | resource_id, weekday 0–6, start_time, end_time, valid_from, valid_to; availability_exceptions(resource_id, date, is_closed, start/end) |
| service_bookings | STD; order_item_id FK, package_id FK, resource_id FK, customer_id, status (held/confirmed/in_progress/completed/cancelled/no_show/rescheduled), starts_at, ends_at CHECK(ends_at>starts_at), hold_expires_at NULL, active_resource_id AS (IF(status IN('held','confirmed'),resource_id,NULL)) PERSISTENT, `PERIOD FOR slot(starts_at,ends_at)`, **UNIQUE(active_resource_id, slot WITHOUT OVERLAPS)**, location_address_id NULL, meeting_url NULL |
| booking_requirements | booking_id, field_key, value, attachment_path |
| booking_events | booking_id, event, actor, at, note | **AO** |
| cancellation_policies | service_id, hours_before, fee_percent, refund_percent (+translations) |

## Engagement, Support, Notifications
| Table | Columns |
|---|---|
| reviews | STD; product_id, order_item_id UNIQUE, customer_id, rating 1–5 CHECK, title, body, locale, status (pending/published/rejected), helpful_count; review_votes UNIQUE(review_id,customer_id); product_rating_stats(product_id PK, count, sum, updated by event) |
| wishlists / wishlist_items | customer_id, variant_id, UNIQUE |
| support_tickets / support_messages / support_attachments | status, assignee_id, order_id NULL, sla_due_at; canned_replies(+translations) |
| notification_templates (+versions) | event_key, channel, locale, subject, body, is_active, version |
| notification_logs | channel, recipient_hash, template_key, status, provider_ref, cost_minor, attempts, error, sent_at (no PII body) |
| notification_preferences | customer_id, channel, category, opted_in |
| device_tokens | user_id, platform, token_hash (Phase 11) |
| data_requests | customer_id, type (export/delete), status, approved_by |

## CMS, Search, Integrations, Reporting
| Table | Columns |
|---|---|
| cms_pages (+translations) | STD; slug via translation, status, publish_at; SD |
| cms_page_versions / policy_versions | version, effective_from, body per locale; policy_acceptances(customer_id, policy_version_id, at) AO |
| banners (+translations), menus, menu_items | placement, sort, window |
| search_synonyms | locale, term, synonym; search_queries(normalised_query, locale, result_count, at; no PII) |
| integration_configs | provider, key, value encrypted, updated_by |
| report_daily_sales, report_payment_daily, … | summary tables refreshed by jobs (defined in Batch 6 with metric definitions) |

## Table ↔ module count
Platform 8 · Identity 9 · Customers/geo 5 · Catalog ~26 · Pricing 5 · Inventory 6 · Cart 2 · Orders 7 · Payments 9 · Shipping 10 · Digital 6 · Services 9 · Engagement/Support/Notif ~14 · CMS/Search/Integrations ~12 ≈ **128 tables**. The exact column-level list is produced as migrations in Phase 1–3 and the dictionary is regenerated/validated against `information_schema` in CI.
