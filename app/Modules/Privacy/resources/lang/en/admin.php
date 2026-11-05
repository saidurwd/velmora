<?php

declare(strict_types=1);

return [
    'menu' => 'Privacy requests',
    'title' => 'Privacy requests',
    'help' => 'Customers ask for a copy of their data or for their account to be erased. Erasure needs your approval; it removes the person\'s details and keeps the order and payment record.',
    'type' => 'Kind', 'status' => 'Status', 'any' => 'Any', 'filter' => 'Filter', 'asked' => 'Asked', 'customer' => 'Customer', 'none' => 'No requests.',
    'kinds' => ['export' => 'Copy of data', 'erasure' => 'Erasure'],
    'states' => ['requested' => 'Waiting', 'ready' => 'Ready', 'failed' => 'Failed', 'expired' => 'Expired', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Withdrawn', 'blocked' => 'Blocked', 'done' => 'Done'],
    'open' => 'Open',
    'detail' => 'Request',
    'back' => 'All requests',
    'customer_gone' => '(erased)',
    'their_reason' => 'The customer\'s reason',
    'blockers' => 'Still open',
    'no_blockers' => 'Nothing is open: this customer can be erased.',
    'approve' => 'Approve and erase',
    'approve_help' => 'This removes the customer\'s name, phone, email, addresses and sign-in and cannot be undone. Orders, payments and tax lines stay without their details.',
    'reject' => 'Reject',
    'reject_help' => 'The customer is told the reason below.',
    'reason' => 'Reason',
    'note' => 'Note (optional)',
    'approved' => 'Approved. The erasure is being carried out.',
    'rejected' => 'Rejected. The customer has been told.',
    'decided' => 'Decision',
    'failure' => 'What went wrong or is open',
    'retention' => 'Order and payment records stay for :years years (the shop\'s setting; confirm the number with your tax adviser).',
];
