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
