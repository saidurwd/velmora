# Web UI/UX Guidelines (Batch 5 draft)

Stack [PINNED]: Blade components + Tailwind + Alpine via Vite; no SPA. Mobile-first, low-bandwidth, works without JS for browse/search/filter/checkout fallback. Targets: LCP ≤ 2.5 s p75 (throttled mobile), home ≤ 1.0 MB first load, WCAG 2.2 AA.

## 1. Design tokens (CSS variables → Tailwind theme)
| Token group | Values (proposal — finalise with brand) |
|---|---|
| Colour | `--c-primary` (brand, contrast ≥ 4.5:1 on white), `--c-primary-contrast`, `--c-accent`, neutrals 50–900, success/warning/danger/info each with bg/fg pairs; dark mode not in MVP |
| Type | Latin UI font (system or self-hosted Inter) + **Noto Sans Bengali** subset; scale 12/14/16/18/20/24/30/36; body 16px (never < 16 on inputs), **line-height 1.6 for bn**, 1.5 for en; weights 400/500/700 only |
| Spacing | 4px base: 4/8/12/16/24/32/48/64 |
| Radius/elevation | 4/8/16; 3 shadow levels |
| Breakpoints | 360 (design floor), 640, 768, 1024, 1280 |
| Touch targets | ≥ 44×44 px; 8px between |
| Motion | ≤ 200ms, respects `prefers-reduced-motion` |
| Direction | `dir` from `languages.direction`; logical CSS properties (margin-inline) so RTL languages later need no rewrite |

## 2. Component inventory (Blade `<x-…>`; living page `/dev/components`, non-prod)
Foundations: button (primary/secondary/ghost/danger, loading), link, icon, input, textarea, select, checkbox, radio, switch, phone input (digit normalisation), OTP input, quantity stepper, form field (label/help/error), error summary. Display: badge/status pill, price (regular/compare-at/sale/tax note), rating, product card, service card, digital card, breadcrumb, pagination, tabs (bn/en), table (sortable/filterable), card, empty state, skeleton, avatar. Overlay: modal, drawer (cart/filters), toast, tooltip, dropdown. Layout: storefront shell, admin shell (sidebar), account shell, page header, filter bar. Commerce: address form (cascading geography), shipping option, payment option (with fee/COD note), order summary, order timeline, stock indicator, coupon box, policy accordion.

Rules: every component has states (default/hover/focus/disabled/error/loading), bn+en text in lang files, snapshot tests in both locales.

## 3. Accessibility
Skip links; semantic landmarks; visible focus ring (≥ 3:1); labels always visible (no placeholder-only); error summary focuses on submit with links to fields; `aria-live` for cart/toast/autocomplete status; keyboard-complete autocomplete (combobox pattern); alt text per locale; `lang` attribute per page and for mixed-language inline text; no colour-only status; tables with headers; target size; axe automation on key templates in both locales + manual keyboard/screen-reader pass (Phase 12).

## 4. Localisation UX
Language switcher in header and footer, preserves path+query; **English is the default language (owner)**; Bangla one click away via a visible "বাংলা" link; choice persisted; bn numerals toggle; lakh/crore grouping in bn (owner-approved); dates `২৮ অক্টোবর ২০২৬` / `28 Oct 2026` in Asia/Dhaka; BDT symbol ৳ before amount.

## 5. Storefront wireframe descriptions
**Home:** top bar (language, help, track order) → header (logo, search-first field, account, cart badge) → category strip (horizontal scroll on mobile) → hero banner (single, lazy next) → value props (COD available, delivery time, easy return) → featured collections → best-sellers/new carousels (CSS scroll-snap) → services & digital teasers → trust (payment logos, support contacts) → footer (policies, seller info/BIN where applicable, contacts). No autoplaying carousels.
**Listing:** breadcrumb, title+count, sticky filter/sort bar; mobile: filters in drawer; facets: category, brand, price range, attributes, availability, type; URL-stateful; grid 2-col mobile/4-col desktop; page-based pagination; empty state with suggestions; works with JS off (form GET).
**Product detail — physical:** gallery (swipe, zoom), title, rating, price block (sale/compare-at, "inclusive of VAT" note when tax on), variant selectors (size/colour swatches, unavailable disabled), stock state, delivery estimate by district selector, COD badge, qty stepper, sticky mobile add-to-cart bar, tabs (description/specs/reviews/policies), related products.
**PDP — digital:** same shell; replace delivery with "Instant download after payment", format/size/version, licence terms, refund policy snippet, no COD.
**PDP — service:** package selector (duration/price), date picker + slot grid (bn/en), location type, requirements form preview, cancellation policy summary, "Book" CTA → hold timer.
**Cart (drawer + page):** lines with image/variant/price/qty/remove, change notices ("price changed"), coupon box, shipping estimate by district, totals (subtotal, discount, shipping, COD fee n/a yet, tax line when enabled), checkout CTA; guest cart token persists.
**Checkout (single page, accordion steps):** 1 contact (phone required, email optional, login link) → 2 address (saved/new, cascading selects) → 3 shipping method → 4 payment method (COD with fee/limit text, bKash/cards via gateway; unavailable shown with reason) → 5 review & place (totals incl. tax mode, policy acceptance, idempotent button disables on submit). Digital/service-only skips shipping. Inline validation, Bangla digit acceptance, progress summary on mobile.
**Payment return:** neutral "We are confirming your payment…" with spinner/aria-live, polls server status ≤ 60 s, then success / failed (retry or switch to COD) / still pending (we'll notify you). Never says "paid" before verification.
**Order confirmation & tracking:** order number, summary, next steps, signed link; tracking timeline (placed → confirmed → packed → shipped → out for delivery → delivered), courier link, COD amount due, support contacts, invoice download.
**Account:** orders (filter, reorder), order detail with `available_actions`, addresses, library, bookings, wishlist, notification prefs, profile/security, privacy requests.
**Service booking flow:** slot → hold timer (visible countdown, aria-live at 60 s) → requirements form → pay → confirmation with calendar file (.ics) and reminders.

## 6. Admin wireframes
Shell: left sidebar (permission-aware sections: Dashboard, Orders, Catalog, Inventory, Shipping, Payments, Customers, Promotions, CMS, Reports, Settings, Audit), top bar (global search, language, user menu, env badge), breadcrumbs.
**Dashboard:** KPI tiles (today's orders, revenue collected, pending actions: to confirm / to ship / failed payments / unremitted COD / low stock / reviews / tickets), each linking to a pre-filtered list; trend chart; role-aware.
**Order detail:** header (number, three status pills, actions by permission), customer & address (PII masked unless `customers.view_pii`), items, totals with tax breakdown, payment panel (transactions, refund button, guard messages), shipments panel (create shipment, partial), timeline (history + audit + notes), COD panel.
**Product editor:** type selector, **en | bn tabs** with missing-translation badges, general, pricing (incl. tax class), variants matrix, media manager (alt per locale), inventory by location, type-specific section (digital asset upload / service definition), SEO, publish scheduling, preview, audit trail.
Lists: filter bar + saved filters, bulk actions with confirm, column chooser, export (permissioned, async), pagination, empty/loading/error states.

## 7. Content & microcopy
Plain, polite Bangla (not transliterated English); error messages say what happened + what to do; COD/delivery/return info shown before commitment; prices always include what is known (shipping/tax shown or "calculated at next step").

## 8. Performance rules
Critical CSS inline, Vite code splitting, Alpine only where needed, images WebP/AVIF with `srcset/sizes` and fixed aspect ratios (no CLS), lazy-load below fold, font preload (subset, `swap`), no third-party scripts without approval, HTTP caching + fragment caching for anonymous pages, Lighthouse CI budget gate.

## 9. Open
Brand colours/logo · icon set · image art direction · help-centre content.
