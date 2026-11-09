<?php

declare(strict_types=1);

return [
    'menu' => 'Menus', 'title' => 'Menus', 'intro' => 'The links in the header and the footer. A new link starts hidden; someone who may publish shows it.',
    'menus' => ['header' => 'Header menu', 'footer' => 'Footer menu'],
    'when_empty' => ['header' => 'With nothing here, the header has no menu.', 'footer' => 'With nothing shown here, the footer lists all published pages.'],
    'add' => 'Add link', 'add_item' => 'Add a link', 'edit' => 'Edit', 'edit_item' => 'Edit a link', 'empty' => 'No links yet.', 'confirm_delete' => 'Delete this link (and the links under it)?',
    'shown' => 'Shown', 'hidden' => 'Hidden', 'move_up' => 'Move up', 'move_down' => 'Move down', 'saved' => 'Saved.', 'deleted' => 'Deleted.',
    'starts_hidden' => 'The link will start hidden. Someone who may publish can show it.',
    'kinds' => ['page' => 'Page', 'url' => 'Address', 'group' => 'Heading'],
    'fields' => [
        'menu' => 'Menu', 'kind' => 'Type', 'kind_help' => 'A page follows the page. An address is a path on this site or an https:// address. A heading holds links under it.',
        'page' => 'Page (for a page link)', 'page_help' => 'A page link disappears while its page is not published.',
        'parent' => 'Under heading', 'parent_help' => 'Only a heading can hold links, one level deep.',
        'label' => 'Text', 'url' => 'Address', 'url_help' => 'For an address link: a path like /en/shop or an https:// address. A language without an address uses the English one.',
        'active' => 'Show this link',
    ],
    'header_label' => 'Main menu', 'footer_label' => 'Footer menu',
    'errors' => [
        'menu_invalid' => 'Choose a menu and a type from the list.', 'menu_default_required' => 'The :locale text is required.',
        'menu_parent_invalid' => 'Choose a heading in the same menu. A heading cannot sit under another heading.', 'menu_has_children' => 'This heading has links under it, so it must stay a heading.',
        'menu_page_missing' => 'Choose a page for a page link.', 'menu_not_found' => 'That link was not found.',
        'unsafe_link' => 'The address must be a path on this site (starting with /) or an https:// address.',
    ],
];
