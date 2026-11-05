<?php

declare(strict_types=1);

return [
    // A customer's copy of their data is kept this many days, then the file is deleted (setting privacy.export_days overrides).
    'export_days' => 7,

    // A customer can ask for a new copy this many hours after the last one (setting privacy.export_cooldown_hours overrides).
    'export_cooldown_hours' => 24,

    // How long the financial record (orders, payments, refunds, tax lines) is kept after the customer is erased. Informational: those rows are never deleted. The
    // number of years is for the shop's tax adviser to confirm (setting privacy.financial_retention_years overrides).
    'financial_retention_years' => 6,
];
