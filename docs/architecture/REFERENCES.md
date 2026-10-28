# References (accessed 2026-10-28)

Anything not read from an official provider page is `[UNVERIFIED]`. Save official docs under `docs/vendor/<provider>/` before Phase 6/7.

## Payment providers
| Provider | Webhook/IPN auth | Refund API | Sandbox | Status |
|---|---|---|---|---|
| SSLCommerz — [official v4 docs](https://developer.sslcommerz.com/doc/v4/) | IPN + **Order Validation API** (`val_id`); `verify_sign` computation not documented on page | Yes: `refund_trans_id` unique, `bank_tran_id`, `refund_amount`; status query; **partial refund not stated**; live needs registered IP | Yes (separate store id) | VERIFIED-official (page); Nagad not mentioned |
| ShurjoPay | — | — | — | official page 404 on access → UNVERIFIED |
| aamarPay — [overview](https://aamarpay.readme.io/reference/overview) | redirect-back + later notification; verification not stated | not stated | Yes (test creds via contact) | partially read; UNVERIFIED |
| bKash direct | official developer docs not retrievable (404); wrappers ([Packagist](https://packagist.org/packages/karim007/laravel-bkash-tokenize), [docs.rs](https://docs.rs/crate/bkash-rs/latest)) disagree on paths | refund + refund-status per wrappers | — | UNVERIFIED — need merchant-portal docs |
| Nagad direct | — | — | — | not researched |

## Couriers
| Provider | Finding | Status |
|---|---|---|
| Steadfast | community package: bearer-token webhook, base URLs differ by source ([GitHub](https://github.com/steadfast-it/SteadFast-Courier-Laravel-Package), [Packagist](https://packagist.org/packages/kbtonmoy/steadfast-for-bagisto)) | UNVERIFIED — need official spec from merchant portal |
| Pathao | merchant page fetched returned title only | UNVERIFIED |
| RedX, Paperfly | not researched | UNVERIFIED |

## Legal / tax (secondary sources — confirm with lawyer/tax adviser)
- Digital Commerce Operation Guidelines 2021: [Daily Star overview](https://www.thedailystar.net/law-our-rights/news/overview-the-digital-commerce-operation-guidelines-2021-2128871).
- Personal Data Protection Ordinance 2025: [Digital Policy Alert](https://digitalpolicyalert.org/change/18756-personal-data-protection-ordinance-2025-ordinance-no-61-of-2025); conflicting claim of a 2026 Act: [ConsentStack](https://www.consentstack.io/regulations/bd-pdpo).
- VAT / Mushak 6.3 / 9.1 (vendor/blog sources): [VATupdate booklet 2026](https://www.vatupdate.com/2026/08/28/bangladesh-e-invoicing-e-reporting-country-booklet/), [Mushak 6.3 guide](https://rakibhassan.eu/mushak-6-3-vat-invoice-bangladesh/). 15% online-commission VAT claim unconfirmed by NBR text.

## SMS
| Provider | Finding | Status |
|---|---|---|
| SSL Wireless ISMS Plus — [official API page](https://ismsplus.sslwireless.com/api-documentation) | every request needs API token + registered Sender ID (`sid`); requesting server IP must be whitelisted in portal | VERIFIED-official (thin page; endpoint/params to be saved to `docs/vendor/sslwireless/`) |
| Sender-ID approval time, transactional vs promotional, daytime limits for promotional SMS, masking vs non-masking, Do-Not-Call registry | only from third parties ([Botble docs](https://docs.botble.com/sms-gateways/drivers/sslwireless.html), [Telerivet](https://www.telerivet.com/blog/bangladesh-sms-compliance-btrc-masking)); ~1–3 business days claimed | UNVERIFIED — confirm in ISMS Plus portal / BTRC text |

## Platform (verified locally)
MariaDB 12.1.2 behaviours: `docs/spikes/S1_S2_MARIADB_SPIKES.md` and `DATABASE_DESIGN.md` §5.

## Still to research
Official NBR / Finance Act text; BTRC SMS sender-ID; Consumer Rights Act 2009; Bangladesh Bank PSP rules; geography dataset licence; Laravel + PHP current versions; hosting/backup options reachable from Bangladesh.
