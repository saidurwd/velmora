<?php

declare(strict_types=1);

return [
    'errors' => [
        'location_unknown' => 'That stock location was not found or is switched off.',
        'same_location' => 'Choose two different places.',
        'no_lines' => 'Choose what to move.',
        'quantity_invalid' => 'Enter a whole number of at least 1.',
        'not_enough_stock' => 'Not enough of one product is free to move (the rest is promised to orders). Nothing was moved.',
        'transfer_not_found' => 'That transfer was not found.',
        'transfer_not_open' => 'This transfer is no longer on its way (it is :status).',
        'received_invalid' => 'The quantity received must be from 0 to :max.',
        'reason_required' => 'Please give a reason.',
        'nothing_to_count' => 'There is nothing to count there.',
        'variant_unknown' => 'That product was not found.',
        'count_already_open' => 'This place already has a stock-take in progress. Finish or cancel it first.',
        'count_not_found' => 'That stock-take was not found.',
        'count_not_open' => 'This stock-take can no longer be changed (it is :status).',
        'counted_invalid' => 'Enter the number counted (0 or more).',
        'lines_uncounted' => ':left products have not been counted yet.',
        'count_not_pending' => 'This stock-take is not waiting for a decision (it is :status).',
        'second_person_required' => 'A different person must decide on this stock-take.',
        'variance_conflicts' => 'The correction for one product would take stock below what is promised to orders. Nothing was changed; please look at it.',
    ],
];
