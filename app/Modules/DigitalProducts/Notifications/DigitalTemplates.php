<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Notifications;

use App\Modules\Notifications\DTOs\TemplateDefinition;

/** The messages the shop sends about digital products. The first words (English and Bangla); staff may change them on the messages screen. */
final class DigitalTemplates
{
    /** @return list<TemplateDefinition> */
    public static function all(): array
    {
        return [
            new TemplateDefinition(
                'digital.new_version.email', 'email', 'transactional', ['product', 'version', 'link'],
                [
                    'en' => ['A new version of {{ product }} is ready', "A new version of {{ product }} is ready\n\nVersion {{ version }} of what you bought is now in your downloads. You can download it again with your existing downloads.\n\nOpen my downloads: {{ link }}\n\nYou are getting this because you bought this product from us."],
                    'bn' => ['{{ product }} এর নতুন সংস্করণ প্রস্তুত', "{{ product }} এর নতুন সংস্করণ প্রস্তুত\n\nআপনার কেনা পণ্যের সংস্করণ {{ version }} এখন আপনার ডাউনলোডে আছে। আপনার বর্তমান ডাউনলোডের সুযোগেই আবার ডাউনলোড করতে পারবেন।\n\nআমার ডাউনলোড খুলুন: {{ link }}\n\nআপনি আমাদের কাছ থেকে এই পণ্যটি কিনেছেন বলেই এটি পাচ্ছেন।"],
                ],
                ['product' => 'Cooking e-book', 'version' => '2', 'link' => 'https://example.com/account/library'],
            ),
        ];
    }
}
