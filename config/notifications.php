<?php

declare(strict_types=1);

return [
    // After a failed try the next one waits this many minutes; after the last wait one more failure makes the message "dead" (staff can retry it by hand).
    'backoff_minutes' => [1, 5, 30, 120, 360],

    // A dead message keeps its (encrypted) address and values this many days so staff can retry it; then they are erased (setting notifications.dead_retention_days overrides).
    'dead_retention_days' => 14,

    // A message a worker took and never finished is taken over after this many minutes.
    'claim_minutes' => 10,

    // The longest a text may be, by channel (SSL Wireless accepts 1000 characters in a text message).
    'max_length' => ['sms' => 1000, 'email' => 8000, 'push' => 240],
];
