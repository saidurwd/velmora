<?php

declare(strict_types=1);

return [
    'menu' => 'Sales summary',
    'title' => 'Sales summary',
    'intro' => 'From the money and the orders to what the shop really sold. Days are Bangladesh days (midnight to midnight). Figures are worked out from the ledgers every 15 minutes.',
    'filter' => 'Show',
    'from' => 'From',
    'to' => 'To',
    'channel' => 'Where the order was placed',
    'method' => 'Payment method',
    'locale' => 'Language of the order',
    'any' => 'Any',
    'channels' => ['web' => 'Website', 'api' => 'App', 'admin' => 'Staff'],
    'languages' => ['en' => 'English', 'bn' => 'Bangla'],
    'invalid_day' => 'Please give real dates.',
    'to_before_from' => 'The last day cannot be before the first day.',
    'too_long' => 'Please choose at most :days days at a time.',
    'as_of' => 'Worked out at :time',
    'not_worked_out' => ':missing of :days day(s) in this period have not been worked out yet, so the figures are incomplete: :list',
    'nothing_worked_out' => 'Nothing has been worked out for this period yet.',
    'totals' => 'Totals for the period',
    'by_day' => 'Day by day',
    'day' => 'Day',
    'no_figures' => 'No figures',
    'finance_note' => 'Payments received, refunds and net sales are shown only to people who may see finance figures.',
    'how' => 'How it is worked out',
    'money_note' => 'All amounts are in taka.',
];
