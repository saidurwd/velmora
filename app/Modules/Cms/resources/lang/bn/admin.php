<?php

declare(strict_types=1);

return [
    'menu' => ['pages' => 'পাতা', 'banners' => 'ব্যানার'],
    'add' => 'যোগ করুন', 'save' => 'সংরক্ষণ', 'saved' => 'সংরক্ষিত হয়েছে।', 'delete' => 'মুছুন', 'actions' => 'কার্যক্রম', 'status' => 'অবস্থা', 'languages' => 'ভাষা', 'empty' => 'এখানে এখনও কিছু নেই।',
    'active' => 'সক্রিয়', 'inactive' => 'নিষ্ক্রিয়', 'confirm_delete' => 'এটি মুছে ফেলবেন?', 'unpublish_first' => 'মুছে ফেলার আগে পাতার প্রকাশ বন্ধ করুন।',
    'statuses' => ['draft' => 'খসড়া', 'published' => 'প্রকাশিত'],
    'publishing' => 'প্রকাশনা', 'publish' => 'এখনই প্রকাশ করুন', 'unpublish' => 'প্রকাশ বন্ধ করুন', 'schedule' => 'সময়সূচি দিন', 'publish_at' => 'প্রকাশের সময় (ঢাকা সময়)', 'scheduled_for' => 'নির্ধারিত সময়',
    'fields' => ['title' => 'শিরোনাম', 'slug' => 'URL স্লাগ (ঐচ্ছিক)', 'body' => 'বিষয়বস্তু (HTML)', 'seo_title' => 'SEO শিরোনাম', 'seo_description' => 'SEO বিবরণ', 'sort_order' => 'ক্রম'],
    'pages' => ['title' => 'পাতা', 'add' => 'পাতা যোগ করুন', 'edit' => 'পাতা সম্পাদনা'],
    'banners' => [
        'title' => 'ব্যানার', 'add' => 'ব্যানার যোগ করুন', 'edit' => 'ব্যানার সম্পাদনা', 'placement' => 'কোথায় দেখাবে', 'placements' => ['home_hero' => 'হোম পেজ — প্রধান ব্যানার', 'home_strip' => 'হোম পেজ — প্রচারণার স্ট্রিপ'],
        'link' => 'লিংক', 'link_help' => 'এই সাইটের কোনো পাতা (যেমন /bn/pages/delivery) বা https:// ঠিকানা।', 'subtitle' => 'উপশিরোনাম', 'cta' => 'বাটনের লেখা', 'alt' => 'ছবির বিবরণ (অল্ট টেক্সট)',
        'images' => 'ছবি', 'images_help' => 'প্রতি ভাষায় একটি ছবি। ইংরেজি ছবি আবশ্যক এবং যে ভাষায় ছবি নেই সেখানে এটি দেখানো হবে। কমপক্ষে ৮০০ পিক্সেল চওড়া; JPEG, PNG বা WebP।', 'has_image' => 'ছবি আপলোড করা আছে',
        'starts' => 'কখন থেকে দেখাবে', 'ends' => 'কখন পর্যন্ত দেখাবে', 'dhaka' => 'ঢাকা সময়। সীমা না চাইলে ফাঁকা রাখুন।',
    ],
    'errors' => [
        'default_language_required' => ':locale সংস্করণ আবশ্যক।', 'unsupported_language' => '“:locale” ভাষা সমর্থিত নয়।',
        'not_publishable_content' => 'প্রকাশের আগে ডিফল্ট ভাষায় পাতার শিরোনাম ও বিষয়বস্তু থাকতে হবে।', 'not_publishable_future' => 'নির্ধারিত সময় ভবিষ্যতের হতে হবে।',
        'unsafe_link' => 'লিংক এই সাইটের কোনো পাতা (/ দিয়ে শুরু) বা https:// ঠিকানা হতে হবে।', 'bad_window' => 'শেষের সময় শুরুর সময়ের পরে হতে হবে।', 'placement_invalid' => 'ব্যানার কোথায় দেখাবে তা বাছুন।',
        'image_required' => ':locale ভাষায় একটি ছবি আবশ্যক।', 'alt_required' => 'স্ক্রিন রিডার ব্যবহারকারীদের জন্য :locale ছবির বিবরণ (অল্ট টেক্সট) দিন।',
        'image_too_large' => 'ছবি ৮ এমবির চেয়ে বড়।', 'image_not_image' => 'ফাইলটি JPEG, PNG বা WebP ছবি নয়।', 'image_too_small' => 'ছবি কমপক্ষে ৮০০ পিক্সেল চওড়া হতে হবে।',
    ],
];
