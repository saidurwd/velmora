# Bangladesh Localisation and Commerce (Batch 4 draft)

Labels: [PINNED] · [RECOMMENDATION] · [ASSUMPTION] · [UNVERIFIED] · [NEEDS-LAWYER/TAX-ADVISER]. Nothing here is legal or tax advice.

## 1. Phone numbers
Single `PhoneNumber` class: strip spaces/dashes, map Bangla digits ০–৯→0–9, accept `01XXXXXXXXX`, `8801XXXXXXXXX`, `+8801XXXXXXXXX`; store E.164 `+8801XXXXXXXXX`. Mobile pattern `^\+8801[3-9]\d{8}$` [ASSUMPTION — confirm operator prefix list with current BTRC numbering]. Non-BD numbers accepted only where enabled (international expansion flag). Used for OTP, COD velocity, blocklist, courier payloads.

## 2. Address model
`division → district → upazila/thana` selects (cascading, JS-optional) + `area`, `address_line`, `landmark`, `delivery_note`, `postal_code` (optional). Dhaka City areas typed freely at MVP; an area dataset is post-MVP. Seed source: BBS / Local Government Division dataset [UNVERIFIED licence; record source+date in seed header]. Order keeps an address snapshot including both ids and bn/en names.

## 3. Language and typography
- Locale URL prefix `/bn`, `/en`; default language **English** (owner); `/` redirects by cookie → Accept-Language → `en`; `hreflang x-default` = en. hreflang + canonical; cookie/profile persistence.
- Fonts: self-hosted subset Noto Sans Bengali (or Hind Siliguri) + Latin UI font, `font-display: swap`, `line-height ≥ 1.6` for Bangla; PDFs and emails embed a Bangla-capable font.
- Digits: store ASCII; display option for Bangla digits; input normalised for phone, quantity, price, OTP, postal code.
- Text: NFC on all input; ZWJ/ZWNJ handled in search normalisation. DB collation `utf8mb4_uca1400_ai_ci` treated precomposed/decomposed ড় as equal in spike S2 [VERIFIED-12.1.2].
- Currency format: `৳ 1,250.00` / `৳ ১,২৫০.০০`. Grouping: confirm whether to use Western (1,250,000) or lakh/crore (12,50,000) grouping for bn [DECIDED; proposal: **approved by owner**: lakh/crore grouping in bn locale, international grouping in en].
- Missing translation: fall back bn→en; admin dashboard lists gaps; CI parity test on lang keys.

## 4. Bilingual content workflow
`en` mandatory, `bn` optional per field; product editor has en/bn tabs with completeness badges; CSV import/export has paired columns; machine translation not used silently (optional draft helper post-MVP).

## 5. VAT and tax feature (owner: "use VAT and tax feature")
**Design: configurable tax engine, tax-ready from day one, rates are data not code.** Owner is **not yet VAT-registered and will register**; the correct rates remain **[NEEDS-TAX-ADVISER]**. Until registration: `tax.enabled=false` (plain receipts, no Mushak invoices); everything below is settings-driven so switching on needs no code change.

Reports found 2026-10-28 (secondary sources, **not official NBR text**): VAT invoice form is Mushak 6.3; may be paper or electronic via ERP/prescribed VAT software (Finance Bill 2026 per one booklet); no structured e-invoice syntax mandated; monthly return Mushak 9.1 via NBR portal; invoice retention reported as ≥ 5 years; a blog claims VAT on online-sales commission rose from 5% to 15% in 2025 [UNVERIFIED]. These must be confirmed with NBR/Finance Act text and the adviser before launch.

### Data model
- `tax_classes` (code, name) assigned to products; `tax_rates(tax_class_id, rate_bp, valid_from, valid_to, jurisdiction)` — rate in basis points (15% = 1500). Rate changes add a row with a new `valid_from`; history is never edited.
- Settings: `tax.enabled`, `tax.prices_include_tax` (default **inclusive**, as BD retail prices are normally shown VAT-inclusive [ASSUMPTION]), `tax.shipping_taxable`, `tax.cod_fee_taxable`, `business.bin`, `business.tin`, rounding mode (half-up on line, allocated by `Money::allocate`).
- Order lines snapshot `tax_rate_bp`, `tax_minor`, `tax_class_code`; order stores `tax_inclusive b` flag. Shipping/COD-fee tax lines are separate `order_tax_lines(order_id, kind, rate_bp, taxable_minor, tax_minor)`.
- Calculation: exclusive → `tax = round(net × rate_bp / 10000)`; inclusive → `tax = round(gross × rate_bp / (10000 + rate_bp))`. Discounts allocated to lines first (tax on discounted price), allocation exact to the paisa.
- Order-total constraint (replaces Batch 3 draft): `grand_total_minor = subtotal_minor − discount_minor + shipping_minor + cod_fee_minor + IF(tax_inclusive, 0, tax_minor)` where `subtotal_minor` = Σ line totals as priced. Enforced by `CHECK` plus property tests.
- Invoices: `invoices(id, public_id, order_id, type[tax_invoice|credit_note], number UNIQUE from own MariaDB SEQUENCE, issued_at, seller_snapshot j (name, address, BIN), buyer_snapshot j, lines_snapshot j, totals, pdf_path)` — append-only; gap-free numbering policy per adviser (SEQUENCE may gap; if gap-free is required use a counter table with row lock). Credit notes for refunds/returns reference the original invoice. **Exact Mushak 6.3 field layout and credit/debit-note forms: [NEEDS-TAX-ADVISER].**
- If `tax.enabled=false` the system still stores `tax_minor=0`, issues plain receipts, and the UI hides tax lines — enabling later affects only new orders.
- Reporting: gross sales, discounts, delivery charges, **tax collected**, refunds (with tax reversals), net — defined in Batch 6.

## 6. Commerce rules to confirm
| Item | Proposal | Status |
|---|---|---|
| COD fee, limit, confirmation call | configurable (Q8) | open |
| Return window / who pays | 7 days physical; digital non-refundable after download (Q10) | open |
| Seller disclosures (trade licence, address, contact) on site | footer + policy pages | [NEEDS-LAWYER] |
| Terms, privacy, refund, shipping pages versioned with acceptance record | CMS policy_versions | MVP |
| Records retention | ≥ 6 y proposed (Guidelines reportedly 6 y; NBR invoices reportedly 5 y) | [NEEDS-LAWYER] |
| Consent before collecting personal data | consent records at registration/checkout/marketing | [NEEDS-LAWYER] |

## 7. Customer-service expectations
Phone/WhatsApp/Messenger click-to-contact links configurable; COD confirmation call script and canned bn/en replies (Phase 9); business hours shown; support SLA timers.

## 8. Compliance candidates (status)
| Item | Finding (2026-10-28) | Label |
|---|---|---|
| Digital Commerce Operation Guidelines 2021 | Issued 4 Jul 2021; reportedly requires ≥ 6-year records, buyer consent for data collection | secondary source; NEEDS-LAWYER |
| Personal Data Protection Ordinance 2025 (No. 61/2025) | reported in force 6 Nov 2025; one site claims a 2026 Act — conflicting | NEEDS-LAWYER; check gazette |
| VAT & SD Act 2012 / Mushak forms / rates | see §5 | UNVERIFIED; NEEDS-TAX-ADVISER |
| Consumer Rights Protection Act 2009 | not researched | UNVERIFIED |
| Bangladesh Bank PSP/MFS rules, gateway onboarding docs | not researched | UNVERIFIED |
| BTRC SMS sender-ID/masking | not researched | UNVERIFIED |
| Trade licence / TIN / BIN / e-CAB | owner paperwork | owner |
