<?php

declare(strict_types=1);

return [
    'menu' => ['pages' => 'Pages', 'banners' => 'Banners'],
    'add' => 'Add', 'save' => 'Save', 'saved' => 'Saved.', 'delete' => 'Delete', 'actions' => 'Actions', 'status' => 'Status', 'languages' => 'Languages', 'empty' => 'Nothing here yet.',
    'active' => 'Active', 'inactive' => 'Inactive', 'confirm_delete' => 'Delete this item?', 'unpublish_first' => 'Unpublish the page before deleting it.',
    'statuses' => ['draft' => 'Draft', 'published' => 'Published'],
    'publishing' => 'Publishing', 'publish' => 'Publish now', 'unpublish' => 'Unpublish', 'schedule' => 'Schedule', 'publish_at' => 'Publish at (Dhaka time)', 'scheduled_for' => 'scheduled for',
    'fields' => ['title' => 'Title', 'slug' => 'URL slug (optional)', 'body' => 'Content (HTML)', 'seo_title' => 'SEO title', 'seo_description' => 'SEO description', 'sort_order' => 'Sort order'],
    'pages' => ['title' => 'Pages', 'add' => 'Add page', 'edit' => 'Edit page'],
    'banners' => [
        'title' => 'Banners', 'add' => 'Add banner', 'edit' => 'Edit banner', 'placement' => 'Where it appears', 'placements' => ['home_hero' => 'Home page — main banner', 'home_strip' => 'Home page — promotion strip'],
        'link' => 'Link', 'link_help' => 'A page on this site (e.g. /en/pages/delivery) or an https:// address.', 'subtitle' => 'Subtitle', 'cta' => 'Button text', 'alt' => 'Image description (alt text)',
        'images' => 'Images', 'images_help' => 'One image per language. The English image is required and is used where a language has none. At least 800 px wide; JPEG, PNG or WebP.', 'has_image' => 'image uploaded',
        'starts' => 'Show from', 'ends' => 'Show until', 'dhaka' => 'Dhaka time. Leave blank for no limit.',
    ],
    'errors' => [
        'default_language_required' => 'The :locale version is required.', 'unsupported_language' => 'Language “:locale” is not supported.',
        'not_publishable_content' => 'A page needs a title and content in the default language before it can be published.', 'not_publishable_future' => 'The scheduled time must be in the future.',
        'unsafe_link' => 'The link must be a page on this site (starting with /) or an https:// address.', 'bad_window' => 'The end time must be after the start time.', 'placement_invalid' => 'Choose where the banner appears.',
        'image_required' => 'An image in :locale is required.', 'alt_required' => 'Describe the :locale image (alt text) for people using screen readers.',
        'image_too_large' => 'The image is larger than 8 MB.', 'image_not_image' => 'The file is not a JPEG, PNG or WebP image.', 'image_too_small' => 'The image must be at least 800 pixels wide.',
    ],
];
