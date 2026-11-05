<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Notifications;

use App\Modules\Notifications\DTOs\TemplateDefinition;

/** What the shop says to customers who asked to be told. The first words (English and Bangla); staff may change them on the messages screen. */
final class EngagementTemplates
{
    /** @return list<TemplateDefinition> */
    public static function all(): array
    {
        $sample = ['shop' => 'Velmora', 'product' => 'Cotton Tee (M, Blue)', 'link' => 'https://example.com/en/p/cotton-tee'];

        return [
            new TemplateDefinition('engagement.back_in_stock.email', 'email', 'transactional', ['shop', 'product', 'link'], [
                'en' => ['{{ product }} is back in stock', "{{ product }} is back in stock\n\nYou asked us to tell you when it came back. It is available again, but stock may not last.\n\nSee it here: {{ link }}\n\n{{ shop }}"],
                'bn' => ['{{ product }} আবার স্টকে এসেছে', "{{ product }} আবার স্টকে এসেছে\n\nআপনি জানাতে বলেছিলেন পণ্যটি ফিরলে। এটি আবার পাওয়া যাচ্ছে, তবে স্টক বেশি দিন নাও থাকতে পারে।\n\nএখানে দেখুন: {{ link }}\n\n{{ shop }}"],
            ], $sample),
            new TemplateDefinition('engagement.back_in_stock.sms', 'sms', 'transactional', ['shop', 'product', 'link'], [
                'en' => [null, '{{ shop }}: {{ product }} is back in stock. {{ link }}'],
                'bn' => [null, '{{ shop }}: {{ product }} আবার স্টকে এসেছে। {{ link }}'],
            ], $sample),
        ];
    }
}
