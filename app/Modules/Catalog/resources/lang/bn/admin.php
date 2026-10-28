<?php

declare(strict_types=1);

return [
    'menu' => ['catalog' => 'ক্যাটালগ', 'categories' => 'ক্যাটাগরি', 'brands' => 'ব্র্যান্ড', 'tags' => 'ট্যাগ', 'collections' => 'কালেকশন', 'attributes' => 'অ্যাট্রিবিউট', 'products' => 'পণ্য'],
    'languages' => 'ভাষা',
    'missing' => 'অনুপস্থিত',
    'add' => 'যোগ করুন',
    'edit' => 'সম্পাদনা',
    'delete' => 'মুছুন',
    'save' => 'সংরক্ষণ',
    'saved' => 'সংরক্ষিত হয়েছে।',
    'actions' => 'কার্যক্রম',
    'status' => 'অবস্থা',
    'active' => 'সক্রিয়',
    'inactive' => 'নিষ্ক্রিয়',
    'empty' => 'এখানে এখনও কিছু নেই।',
    'confirm_delete' => 'এটি মুছে ফেলবেন?',
    'all' => 'সব',
    'filter' => 'ফিল্টার',
    'yes' => 'হ্যাঁ',
    'products' => [
        'title' => 'পণ্য', 'add_title' => 'পণ্য যোগ করুন', 'edit_title' => 'পণ্য সম্পাদনা', 'search' => 'নাম বা SKU খুঁজুন', 'type' => 'পণ্যের ধরন',
        'types' => ['physical' => 'ফিজিক্যাল', 'digital' => 'ডিজিটাল', 'service' => 'সার্ভিস'],
        'statuses' => ['draft' => 'খসড়া', 'published' => 'প্রকাশিত', 'archived' => 'আর্কাইভ'],
        'variants' => 'ভ্যারিয়েন্ট', 'tax_class' => 'কর শ্রেণি', 'primary_category' => 'প্রধান ক্যাটাগরি', 'first_variant' => 'প্রথম ভ্যারিয়েন্ট',
        'publishing' => 'প্রকাশনা', 'publish' => 'এখনই প্রকাশ করুন', 'unpublish' => 'প্রকাশ বন্ধ করুন', 'archive' => 'আর্কাইভ করুন', 'schedule' => 'সময়সূচি দিন', 'publish_at' => 'প্রকাশের সময় (ঢাকা সময়)',
        'scheduled_for' => 'নির্ধারিত সময়', 'unpublish_first' => 'মুছে ফেলার আগে পণ্যের প্রকাশ বন্ধ করুন।',
    ],
    'variants' => [
        'sku' => 'SKU', 'sku_help' => '৩–৬৪টি অক্ষর, সংখ্যা বা ড্যাশ।', 'barcode' => 'বারকোড', 'weight' => 'ওজন (গ্রাম)', 'track' => 'স্টক ট্র্যাক করুন', 'options' => 'অপশন', 'default' => 'ডিফল্ট',
        'statuses' => ['active' => 'সক্রিয়', 'inactive' => 'নিষ্ক্রিয়'], 'add' => 'ভ্যারিয়েন্ট যোগ করুন', 'generate' => 'ভ্যারিয়েন্ট তৈরি করুন', 'generate_help' => 'প্রতিটি অপশনের মান বাছুন; প্রতিটি সমন্বয়ের জন্য একটি ভ্যারিয়েন্ট তৈরি হবে।', 'sku_base' => 'SKU প্রিফিক্স',
    ],
    'fields' => [
        'name' => 'নাম',
        'slug' => 'URL স্লাগ (ঐচ্ছিক)',
        'description' => 'বিবরণ',
        'short_description' => 'সংক্ষিপ্ত বিবরণ',
        'seo_title' => 'SEO শিরোনাম',
        'seo_description' => 'SEO বিবরণ',
        'sort_order' => 'ক্রম',
    ],
    'categories' => ['title' => 'ক্যাটাগরি', 'add_title' => 'ক্যাটাগরি যোগ করুন', 'edit_title' => 'ক্যাটাগরি সম্পাদনা', 'parent' => 'প্যারেন্ট ক্যাটাগরি', 'root' => '— সর্বোচ্চ স্তর —'],
    'attributes' => ['title' => 'অ্যাট্রিবিউট', 'code' => 'কোড', 'kind' => 'ধরন', 'kinds' => ['option' => 'ভ্যারিয়েন্ট অপশন', 'spec' => 'স্পেসিফিকেশন'], 'values' => 'মান', 'values_help' => 'প্রতি লাইনে একটি মান: কোড | ইংরেজি লেবেল | বাংলা লেবেল', 'filterable' => 'স্টোরফ্রন্ট ফিল্টার হিসেবে ব্যবহার করুন'],
    'brands' => ['title' => 'ব্র্যান্ড'],
    'tags' => ['title' => 'ট্যাগ'],
    'collections' => ['title' => 'কালেকশন'],
];
