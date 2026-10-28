<?php

declare(strict_types=1);

return [
    'length' => 6,
    'ttl_seconds' => 300,          // 5 minutes
    'max_attempts' => 5,           // wrong codes before the challenge is dead
    'resend_cooldown_seconds' => 60,

    // SMS-pumping / cost-abuse guards (SECURITY_AND_PRIVACY §3)
    'max_sends_per_target' => 3,   // per phone number ...
    'target_window_seconds' => 600, // ... per 10 minutes
    'max_sends_per_ip_hour' => 10,
    'daily_cap' => (int) env('OTP_DAILY_CAP', 1000), // global SMS sends per UTC day; alert + refuse beyond this
];
