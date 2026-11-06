<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Notifications;

use App\Modules\Notifications\DTOs\TemplateDefinition;

/** The reminder about a forgotten cart. Marketing: only customers who opted in receive it, and not in their quiet hours. It says nothing about prices (they may have changed). */
final class CartReminderTemplates
{
    /** @return list<TemplateDefinition> */
    public static function all(): array
    {
        $sample = ['shop' => 'Velmora', 'link' => 'https://example.com/en/cart', 'preferences' => 'https://example.com/en/account/preferences'];
        $variables = ['shop', 'link', 'preferences'];

        return [
            new TemplateDefinition('engagement.cart_reminder.email', 'email', 'marketing', $variables, [
                'en' => ['You left something in your cart', "You left something in your cart\n\nThe items you chose are still waiting. Stock and prices can change, so check your cart before you order.\n\nOpen your cart: {{ link }}\n\nYou get this because you agreed to hear from {{ shop }}. To stop these messages: {{ preferences }}"],
                'bn' => ['আপনার কার্টে কিছু রেখে এসেছেন', "আপনার কার্টে কিছু রেখে এসেছেন\n\nআপনার বেছে নেওয়া পণ্যগুলো এখনও অপেক্ষা করছে। স্টক ও দাম বদলাতে পারে, তাই অর্ডারের আগে কার্ট দেখে নিন।\n\nকার্ট খুলুন: {{ link }}\n\nআপনি {{ shop }}-এর বার্তা পেতে রাজি হয়েছিলেন বলে এটি পাচ্ছেন। এই বার্তা বন্ধ করতে: {{ preferences }}"],
            ], $sample),
            new TemplateDefinition('engagement.cart_reminder.sms', 'sms', 'marketing', $variables, [
                'en' => [null, '{{ shop }}: you left items in your cart. {{ link }} To stop: {{ preferences }}'],
                'bn' => [null, '{{ shop }}: আপনার কার্টে পণ্য রয়ে গেছে। {{ link }} বন্ধ করতে: {{ preferences }}'],
            ], $sample),
        ];
    }
}
