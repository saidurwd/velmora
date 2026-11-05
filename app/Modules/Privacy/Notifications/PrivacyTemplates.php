<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Notifications;

use App\Modules\Notifications\DTOs\TemplateDefinition;

/** What the shop says about a customer's privacy requests. The first words (English and Bangla); staff may change them on the messages screen. */
final class PrivacyTemplates
{
    /** @return list<TemplateDefinition> */
    public static function all(): array
    {
        $sample = ['shop' => 'Velmora', 'link' => 'https://example.com/en/account/privacy', 'days' => '7', 'reason' => 'We still have an order on its way.'];

        return [
            new TemplateDefinition('privacy.export_ready.email', 'email', 'transactional', ['shop', 'link', 'days'], [
                'en' => ['Your data copy is ready', "Your data copy is ready\n\nThe copy of your data you asked for is ready. Sign in and open your privacy page to download it. It stays available for {{ days }} days.\n\nOpen my privacy page: {{ link }}\n\n{{ shop }}"],
                'bn' => ['আপনার তথ্যের কপি প্রস্তুত', "আপনার তথ্যের কপি প্রস্তুত\n\nআপনার চাওয়া তথ্যের কপি প্রস্তুত। সাইন ইন করে গোপনীয়তা পাতা থেকে ডাউনলোড করুন। এটি {{ days }} দিন থাকবে।\n\nআমার গোপনীয়তা পাতা খুলুন: {{ link }}\n\n{{ shop }}"],
            ], $sample),
            new TemplateDefinition('privacy.export_ready.sms', 'sms', 'transactional', ['shop', 'days'], [
                'en' => [null, '{{ shop }}: your data copy is ready. Sign in and open your privacy page to download it within {{ days }} days.'],
                'bn' => [null, '{{ shop }}: আপনার তথ্যের কপি প্রস্তুত। সাইন ইন করে {{ days }} দিনের মধ্যে গোপনীয়তা পাতা থেকে ডাউনলোড করুন।'],
            ], $sample),
            new TemplateDefinition('privacy.erasure_done.email', 'email', 'transactional', ['shop'], [
                'en' => ['Your account has been erased', "Your account has been erased\n\nAs you asked, your personal details have been removed from {{ shop }}. We keep only the record of past orders and payments (amounts, dates and items) that the law asks shops to keep, without your name, phone, email or address.\n\n{{ shop }}"],
                'bn' => ['আপনার অ্যাকাউন্ট মুছে ফেলা হয়েছে', "আপনার অ্যাকাউন্ট মুছে ফেলা হয়েছে\n\nআপনার অনুরোধ অনুযায়ী {{ shop }} থেকে আপনার ব্যক্তিগত তথ্য সরিয়ে ফেলা হয়েছে। আইন অনুযায়ী দোকানকে যে অতীত অর্ডার ও পেমেন্টের হিসাব (পরিমাণ, তারিখ ও পণ্য) রাখতে হয়, শুধু সেটুকু আপনার নাম, ফোন, ইমেইল বা ঠিকানা ছাড়া রাখা হয়েছে।\n\n{{ shop }}"],
            ], $sample),
            new TemplateDefinition('privacy.erasure_done.sms', 'sms', 'transactional', ['shop'], [
                'en' => [null, '{{ shop }}: as you asked, your account and personal details have been erased.'],
                'bn' => [null, '{{ shop }}: আপনার অনুরোধ অনুযায়ী আপনার অ্যাকাউন্ট ও ব্যক্তিগত তথ্য মুছে ফেলা হয়েছে।'],
            ], $sample),
            new TemplateDefinition('privacy.erasure_rejected.email', 'email', 'transactional', ['shop', 'reason', 'link'], [
                'en' => ['About your request to erase your account', "About your request to erase your account\n\nWe could not erase your account this time.\n\nReason: {{ reason }}\n\nYour account is unchanged. You can ask again at any time from your privacy page: {{ link }}\n\n{{ shop }}"],
                'bn' => ['আপনার অ্যাকাউন্ট মুছে ফেলার অনুরোধ প্রসঙ্গে', "আপনার অ্যাকাউন্ট মুছে ফেলার অনুরোধ প্রসঙ্গে\n\nএইবার আমরা আপনার অ্যাকাউন্ট মুছতে পারিনি।\n\nকারণ: {{ reason }}\n\nআপনার অ্যাকাউন্ট আগের মতোই আছে। যেকোনো সময় গোপনীয়তা পাতা থেকে আবার অনুরোধ করতে পারবেন: {{ link }}\n\n{{ shop }}"],
            ], $sample),
            new TemplateDefinition('privacy.erasure_rejected.sms', 'sms', 'transactional', ['shop'], [
                'en' => [null, '{{ shop }}: we could not erase your account this time. Your account is unchanged; see your privacy page for the reason.'],
                'bn' => [null, '{{ shop }}: এইবার আপনার অ্যাকাউন্ট মুছতে পারিনি। অ্যাকাউন্ট আগের মতোই আছে; কারণ গোপনীয়তা পাতায় দেখুন।'],
            ], $sample),
        ];
    }
}
