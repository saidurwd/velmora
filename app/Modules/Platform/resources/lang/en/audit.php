<?php

declare(strict_types=1);

return [
    'menu' => 'Audit log', 'title' => 'Audit log',
    'intro' => 'A record of changes that cannot be edited or deleted. Secrets are never shown. The export holds up to :max rows and is itself recorded.',
    'filters' => ['from' => 'From (Dhaka date)', 'to' => 'To (Dhaka date)', 'actor' => 'Who (name starts with, or number)', 'action' => 'Action starts with', 'subject_type' => 'Subject type', 'subject_id' => 'Subject id', 'request_id' => 'Request id'],
    'filter' => 'Filter', 'clear' => 'Clear', 'export' => 'Export CSV', 'older' => 'Older entries', 'empty' => 'Nothing matches.',
    'columns' => ['time' => 'Time', 'actor' => 'Who', 'action' => 'Action', 'subject' => 'About', 'ip' => 'Address'],
    'before' => 'Before', 'after' => 'After', 'redacted' => 'Passwords, tokens and similar values are hidden. Addresses are shown only roughly.',
];
