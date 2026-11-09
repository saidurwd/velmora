<?php

declare(strict_types=1);

return [
    'menu' => 'Home page', 'title' => 'Home page sections',
    'intro' => 'The blocks of the home page, in order. With none shown, the home page keeps its usual layout. A new block starts hidden; someone who may publish shows it.',
    'empty' => 'No blocks yet. The home page uses its usual layout.', 'sections' => 'Blocks', 'preview' => 'Preview',
    'preview_help' => 'The blocks in order as they stand now. Hidden, not-yet-started and ended ones are faded. Product lists are filled in on the real page.', 'preview_collection' => 'Shows the products of the chosen collection.',
    'add' => 'Add block', 'add_section' => 'Add a block', 'edit' => 'Edit', 'edit_section' => 'Edit a block', 'confirm_delete' => 'Delete this block?', 'saved' => 'Saved.', 'deleted' => 'Deleted.',
    'move_up' => 'Move up', 'move_down' => 'Move down', 'starts_hidden' => 'The block will start hidden. Someone who may publish can show it.',
    'types' => [
        'banner_hero' => 'Main banners', 'banner_strip' => 'Promotion strip', 'categories' => 'Categories', 'collection' => 'Collection', 'new_arrivals' => 'New arrivals', 'popular' => 'Popular products', 'text' => 'Text',
    ],
    'states' => ['live' => 'Showing now', 'hidden' => 'Hidden', 'scheduled' => 'Scheduled', 'ended' => 'Ended'],
    'fields' => [
        'type' => 'Type', 'type_help' => 'Banners are managed under Banners. Categories show the first eight shop categories. New arrivals and popular products show eight products.',
        'collection' => 'Collection (for a collection block)', 'collection_help' => 'Only active collections are listed. A collection with no products is not shown.',
        'title' => 'Heading (optional)', 'body' => 'Text (for a text block)', 'body_help' => 'Text blocks accept simple formatting and links. At most :max characters per language.',
        'starts' => 'Show from (Dhaka time)', 'ends' => 'Show until (Dhaka time)', 'window_help' => 'Leave blank for no limit. The end time is not included.', 'active' => 'Show this block',
    ],
    'errors' => [
        'home_invalid' => 'Choose a type from the list. A block keeps its type.', 'home_collection_missing' => 'Choose an active collection.',
        'home_body_required' => 'A text block needs its :locale text.', 'home_too_long' => 'The :locale text is too long.',
        'home_full' => 'The home page can have at most 20 blocks. Delete one first.', 'home_not_found' => 'That block was not found.',
        'bad_window' => 'The end time must be after the start time.',
    ],
];
