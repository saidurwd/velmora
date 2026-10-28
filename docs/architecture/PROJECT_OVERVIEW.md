# Project Overview — Velmora

Status: **Phase 0 draft — awaiting owner sign-off** · Date: 2026-10-28

Velmora is a bilingual (en/bn) e-commerce platform for Bangladesh selling **physical products, digital products and services**. Stack (pinned in `AI_Prompts/02_BASELINE_DECISIONS.md`): Laravel + Blade + Tailwind/Alpine + **MariaDB/MySQL** (ADR-000, overrides PostgreSQL), modular monolith, versioned REST API for future Android/iOS apps.

Currency: BDT (৳), minor units. Timezone display: Asia/Dhaka. Languages: `en`, `bn` (extensible).

## Personas

| # | Persona | Jobs-to-be-done | Top frustrations |
|---|---|---|---|
| P1 | First-time Bangla-speaking mobile shopper on 3G | Find a product in Bangla, trust the shop, order with COD | Heavy pages, English-only UI, fear of fake shops/non-delivery, forced account creation, wrong Bangla rendering |
| P2 | Repeat urban shopper | Reorder fast, track parcel, pay by bKash/card | Re-typing addresses, no tracking, slow refunds, surprise delivery fees |
| P3 | Digital-goods buyer | Pay once, download immediately, re-download later | Broken links, unclear refund rules, lost access after device change |
| P4 | Service customer | Book a slot, know price/duration, reschedule | Double-booking, no reminders, unclear cancellation fee |
| P5 | Shop owner / admin | See sales, cash owed, stock, exceptions in one place | Reports that don't tie to cash, fraud/fake COD orders, dependency on developers |
| P6 | Warehouse / packing staff | Pick, pack, print label, hand to courier | Overselling, wrong stock, manual tracking-id entry |
| P7 | Support agent | Answer "where is my order/refund?" quickly; confirm COD by phone | Switching tools, no order context, no canned bn replies |
| P8 | Accountant | Daily close: collected vs refunded vs COD outstanding; VAT invoices | Gateway/courier statements not matching, manual reconciliation |

## Operational process maps (order-to-cash)

```mermaid
flowchart LR
  subgraph COD[Physical COD]
    A1[Cart/Checkout] --> A2[Order pending + stock reserved] --> A3{Phone confirm / fraud rules}
    A3 -->|ok| A4[Confirmed] --> A5[Pick-pack] --> A6[Courier shipment] --> A7{Delivered?}
    A7 -->|yes| A8[COD collected by courier] --> A9[Courier remits] --> A10[Reconcile]
    A7 -->|no| A11[Retry → Return-to-origin → restock]
    A3 -->|reject/timeout| A12[Cancel + release stock]
  end
```
```mermaid
flowchart LR
  subgraph Prepaid[Physical prepaid]
    B1[Checkout] --> B2[Order pending/unpaid + reserve TTL] --> B3[Gateway redirect]
    B3 --> B4[Server-to-server verify via IPN/status] --> B5{amount+currency+ref match?}
    B5 -->|yes| B6[PaymentSucceeded → paid → fulfil as COD flow]
    B5 -->|no| B7[Reject + alert]
    B3 -->|expiry| B8[Expire + release stock; late success → needs_review]
  end
```
```mermaid
flowchart LR
  subgraph Digital
    C1[Checkout prepaid only] --> C2[OrderPaid] --> C3[Entitlement + email/SMS link] --> C4[Signed short-lived download, logged]
    C5[Refund] --> C6[Policy check → revoke entitlement]
  end
  subgraph Service
    D1[Pick slot] --> D2[Hold TTL] --> D3[Checkout/pay] --> D4[Confirmed + reminders] --> D5[Performed] --> D6[Completed + review prompt]
    D4 --> D7[Cancel/reschedule per policy window]
  end
```
```mermaid
flowchart LR
  R1[Customer return request] --> R2[Eligibility by policy] --> R3[RMA approved] --> R4[Reverse shipment/drop-off] --> R5[Receive + inspect]
  R5 -->|restock| R6[Stock movement] --> R8[Refund suggested → human approves → gateway or manual]
  R5 -->|quarantine/write-off| R7[Stock movement] --> R8
  M1[Courier COD statement] --> M2[Match to cod_collections] --> M3{Match?} -->|yes| M4[cod_remittance reconciled]
  M3 -->|short/over| M5[Exception queue]
```
