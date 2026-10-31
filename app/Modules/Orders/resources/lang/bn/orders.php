<?php

declare(strict_types=1);

return [
    'menu' => 'অর্ডার', 'title' => 'অর্ডার', 'order' => 'অর্ডার :number', 'back' => 'সব অর্ডার', 'updated' => 'অর্ডার হালনাগাদ হয়েছে।', 'note_added' => 'নোট যোগ হয়েছে।',
    'search' => 'অর্ডার নম্বর, ফোন বা নাম', 'filter' => 'ফিল্টার', 'all' => 'সব', 'empty' => 'কোনো অর্ডার মেলেনি।', 'needs_review' => 'পর্যালোচনা দরকার', 'only_review' => 'শুধু পর্যালোচনা-দরকার অর্ডার',
    'cols' => ['number' => 'অর্ডার', 'placed' => 'অর্ডারের সময়', 'customer' => 'ক্রেতা', 'total' => 'মোট', 'status' => 'অবস্থা', 'payment' => 'পেমেন্ট', 'fulfillment' => 'ডেলিভারি'],
    'axis' => ['status' => 'অর্ডারের অবস্থা', 'payment' => 'পেমেন্ট', 'fulfillment' => 'ডেলিভারি'],
    'status' => ['pending' => 'অপেক্ষমাণ', 'confirmed' => 'নিশ্চিত', 'processing' => 'প্রস্তুত হচ্ছে', 'completed' => 'সম্পন্ন', 'cancelled' => 'বাতিল'],
    'payment' => ['unpaid' => 'অপরিশোধিত', 'pending' => 'পেমেন্ট অপেক্ষমাণ', 'partially_paid' => 'আংশিক পরিশোধিত', 'paid' => 'পরিশোধিত', 'partially_refunded' => 'আংশিক ফেরত', 'refunded' => 'ফেরত দেওয়া হয়েছে', 'failed' => 'ব্যর্থ'],
    'fulfillment' => ['unfulfilled' => 'পাঠানো হয়নি', 'partially_fulfilled' => 'আংশিক পাঠানো হয়েছে', 'fulfilled' => 'পাঠানো হয়েছে', 'partially_returned' => 'আংশিক ফেরত এসেছে', 'returned' => 'ফেরত এসেছে'],
    'items' => 'পণ্য', 'item' => ['product' => 'পণ্য', 'sku' => 'SKU', 'qty' => 'পরিমাণ', 'unit' => 'একক দাম', 'discount' => 'ছাড়', 'tax' => 'কর', 'total' => 'লাইন মোট'],
    'totals' => ['subtotal' => 'উপমোট', 'discount' => 'ছাড়', 'shipping' => 'ডেলিভারি চার্জ', 'cod_fee' => 'ক্যাশ অন ডেলিভারি ফি', 'tax_added' => 'কর', 'tax_included' => 'দামের মধ্যে কর অন্তর্ভুক্ত', 'grand' => 'সর্বমোট'],
    'customer' => 'ক্রেতা', 'guest' => 'অতিথি হিসেবে অর্ডার', 'phone_hidden' => 'লুকানো: ফোন নম্বর দেখার অনুমতি দরকার', 'shipping_address' => 'ডেলিভারির ঠিকানা', 'billing_address' => 'বিলের ঠিকানা',
    'payment_method' => 'পেমেন্ট পদ্ধতি', 'methods' => ['cod' => 'ক্যাশ অন ডেলিভারি'], 'channel' => 'যেভাবে অর্ডার হয়েছে', 'channels' => ['web' => 'ওয়েবসাইট', 'api' => 'অ্যাপ', 'admin' => 'স্টাফ'],
    'history' => 'ইতিহাস', 'history_axis' => 'কী বদলেছে', 'history_by' => 'কে', 'history_reason' => 'কারণ', 'system' => 'সিস্টেম', 'placed' => 'অর্ডার হয়েছে', 'staff' => 'স্টাফ',
    'change' => 'অবস্থা বদলান', 'move_to' => 'যাবে', 'reason' => 'কারণ', 'reason_hint' => 'বাতিল করলে আবশ্যক', 'apply' => 'প্রয়োগ করুন', 'no_moves' => 'আর কোনো পরিবর্তন সম্ভব নয়।', 'cancel' => 'অর্ডার বাতিল করুন',
    'notes' => 'নোট', 'note_body' => 'নোট লিখুন', 'note_internal' => 'অভ্যন্তরীণ (শুধু স্টাফ)', 'note_customer' => 'ক্রেতা দেখতে পাবেন', 'note_add' => 'নোট যোগ করুন', 'no_notes' => 'এখনও কোনো নোট নেই।',
    'cancelled_because' => 'বাতিল: :reason',
    'review_title' => 'পর্যালোচনা দরকার', 'review_help' => 'অর্ডারটি নেওয়া হয়েছে, কিন্তু নিশ্চিত করার আগে একজনকে দেখে নিতে হবে।', 'review_flags' => 'কেন', 'review_reason' => 'আপনি কী যাচাই করেছেন (আবশ্যক)', 'review_clear' => 'যাচাই সম্পন্ন',
    'review_cleared' => 'যাচাই সম্পন্ন হিসেবে চিহ্নিত হয়েছে। এখন অর্ডার নিশ্চিত করতে পারেন।', 'review_cleared_note' => 'যাচাই সম্পন্ন: :reason', 'block_title' => 'এই ক্রেতাকে আবার অর্ডার করা থেকে আটকান', 'block_phone' => 'এই ফোন নম্বর ব্লক করুন', 'block_address' => 'এই ঠিকানা ব্লক করুন',
    'block_reason' => 'কারণ (ঐচ্ছিক)', 'block_apply' => 'ব্লক করুন', 'blocked' => 'ব্লক করা হয়েছে। তিনি আর নতুন অর্ডার করতে পারবেন না।', 'block_nothing' => 'কী ব্লক করবেন বেছে নিন।', 'flags_none' => 'কোনো সতর্কতা নেই।',
    'flag' => ['velocity_phone' => 'একদিনে একই ফোন থেকে অনেক অর্ডার (এখন পর্যন্ত :orders_24h; সীমা :limit)', 'address_many_phones' => 'একই ঠিকানা অনেক ফোনে ব্যবহার (৩০ দিনে :phones_30d; সীমা :limit)'],
    'errors' => [
        'illegal_transition' => '“:from” থেকে “:to”-তে যাওয়া অনুমোদিত নয়।', 'already_in_state' => 'অর্ডার আগেই ওই অবস্থায় আছে।', 'cancel_after_shipping' => 'পাঠানো হয়ে গেছে এমন অর্ডার বাতিল করা যায় না। বরং ফেরত লিখে রাখুন।',
        'cancel_needs_refund' => 'এই অর্ডারের টাকা নেওয়া হয়েছে। আগে ফেরত দিন, তারপর বাতিল করুন।', 'reason_required' => 'বাতিলের কারণ লিখুন।',
        'complete_needs_delivery_and_payment' => 'ডেলিভারি ও পেমেন্ট দুটোই হলে তবেই অর্ডার সম্পন্ন করা যায়।', 'order_cancelled' => 'এই অর্ডার বাতিল হয়েছে।', 'confirm_before_shipping' => 'পাঠানোর আগে অর্ডার নিশ্চিত করুন।',
        'review_pending' => 'অর্ডারটি পর্যালোচনার জন্য চিহ্নিত। নিশ্চিত করার আগে দেখে নিয়ে “যাচাই সম্পন্ন” করুন।', 'not_under_review' => 'এই অর্ডার পর্যালোচনার অপেক্ষায় নেই।',
        'changed_meanwhile' => 'অন্য কেউ অর্ডারটি বদলেছেন। পাতা নতুন করে খুলে আবার চেষ্টা করুন।', 'breakdown_mismatch' => 'অর্ডারের হিসাব মিলছে না।', 'lines_mismatch' => 'অর্ডারের পণ্যের সাথে দামের লাইন মিলছে না।', 'address_required' => 'ডেলিভারির ঠিকানা দিতে হবে।',
    ],
];
