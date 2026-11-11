<?php

declare(strict_types=1);

return [
    'dashboard' => [
        'tiles' => 'Things that need attention', 'unavailable' => 'Not available right now', 'open_list' => 'open the list', 'yesterday' => 'Yesterday', 'last_days' => 'Last :n days, oldest first',
    ],
    'search' => [
        'title' => 'Search', 'label' => 'Search orders, customers, products and tickets', 'placeholder' => 'Order number, phone, email, SKU or ticket reference', 'go' => 'Search',
        'too_short' => 'Type at least :n characters.', 'none' => 'Nothing found.',
    ],
    'saved' => [
        'title' => 'Saved filters', 'none' => 'You have not saved any filters for this list.', 'name' => 'Name this filter', 'save' => 'Save these filters', 'delete' => 'Delete :name',
        'saved' => 'Filter saved.', 'deleted' => 'Filter deleted.',
        'errors' => [
            'unknown_screen' => 'That list cannot have saved filters.', 'name_invalid' => 'Give the filter a name of up to 60 characters.', 'nothing_to_save' => 'Choose at least one filter first.',
            'invalid_filter' => 'One of the filters is not valid.', 'too_many' => 'You can keep up to 20 saved filters for one list. Delete one first.', 'duplicate' => 'You already have a filter with that name.',
        ],
    ],
];
