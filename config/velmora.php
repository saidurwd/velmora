<?php

declare(strict_types=1);

return [
    /*
    | Defaults for typed business settings (Settings service). Admin-edited values in the `settings`
    | table override these. Rates and legal values are never hard-coded here (ADR-030).
    */
    'settings' => [
        'tax.enabled' => (bool) env('TAX_ENABLED', false),
        'tax.prices_include_tax' => (bool) env('TAX_PRICES_INCLUDE_TAX', true),
        'tax.shipping_taxable' => false,
        'tax.cod_fee_taxable' => false,
        'business.currency' => env('VELMORA_CURRENCY', 'BDT'),
        // Cash-on-delivery abuse rules (editable in the admin panel; Phase 5 slice 9). Money in minor units (paisa).
        'orders.pending_cancel_days' => 3,           // an order nobody confirmed (and that is not paid) is cancelled after this many days; 0 = never
        'fraud.enabled' => true,
        'fraud.max_open_cod_per_phone' => 3,          // open = pending, confirmed or processing
        'fraud.high_value_cod_minor' => 1_000_000,    // ৳10,000: a cash order ABOVE this needs an SMS code first (0 = never)
        'fraud.velocity_orders_per_day' => 5,         // this many orders from one phone in 24 h: the next one goes to review (0 = off)
        'fraud.address_max_phones' => 3,              // one address used by this many different phones in 30 days: review (0 = off)
    ],

    // Native names shown in the language switcher (each in its own script).
    'locale_labels' => ['en' => 'English', 'bn' => 'বাংলা'],

    'outbox' => [
        'batch_size' => 50,
        'max_attempts' => 10,
        'base_backoff_seconds' => 10,
    ],

    'idempotency' => [
        'ttl_hours' => 24,
        'max_body_bytes' => 1_000_000,
    ],
];
