<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Notifications;

use App\Modules\Notifications\DTOs\TemplateDefinition;

/** What the shop tells a customer about their return. The first words (English and Bangla); staff may change them on the messages screen. */
final class ReturnTemplates
{
    /** @return list<TemplateDefinition> */
    public static function all(): array
    {
        $sample = ['shop' => 'Velmora', 'number' => 'VM-2611-000001', 'note' => 'Please keep the box.', 'link' => 'https://example.com/en/account/returns'];

        return [
            new TemplateDefinition('shipping.return_authorized.email', 'email', 'transactional', ['shop', 'number', 'link'], [
                'en' => ['Your return for order {{ number }} is allowed', "Your return for order {{ number }} is allowed\n\nPlease send the goods back as described on your returns page. We will look at them when they arrive and tell you what happens next.\n\nMy returns: {{ link }}\n\n{{ shop }}"],
                'bn' => ['অর্ডার {{ number }} এর ফেরত অনুমোদিত হয়েছে', "অর্ডার {{ number }} এর ফেরত অনুমোদিত হয়েছে\n\nআপনার ফেরত পাতার নির্দেশ অনুযায়ী পণ্য ফেরত পাঠান। পণ্য পৌঁছালে আমরা দেখে আপনাকে পরবর্তী ধাপ জানাব।\n\nআমার ফেরত: {{ link }}\n\n{{ shop }}"],
            ], $sample),
            new TemplateDefinition('shipping.return_authorized.sms', 'sms', 'transactional', ['shop', 'number'], [
                'en' => [null, '{{ shop }}: your return for order {{ number }} is allowed. See My returns in your account for how to send it back.'],
                'bn' => [null, '{{ shop }}: অর্ডার {{ number }} এর ফেরত অনুমোদিত হয়েছে। কীভাবে পাঠাবেন তা আপনার অ্যাকাউন্টের “আমার ফেরত” এ দেখুন।'],
            ], $sample),
            new TemplateDefinition('shipping.return_rejected.email', 'email', 'transactional', ['shop', 'number', 'note', 'link'], [
                'en' => ['About your return for order {{ number }}', "About your return for order {{ number }}\n\nWe could not accept this return.\n\nReason: {{ note }}\n\nIf you think this is a mistake, please contact us.\n\nMy returns: {{ link }}\n\n{{ shop }}"],
                'bn' => ['অর্ডার {{ number }} এর ফেরত প্রসঙ্গে', "অর্ডার {{ number }} এর ফেরত প্রসঙ্গে\n\nআমরা এই ফেরত গ্রহণ করতে পারিনি।\n\nকারণ: {{ note }}\n\nআপনি ভুল মনে করলে অনুগ্রহ করে আমাদের জানান।\n\nআমার ফেরত: {{ link }}\n\n{{ shop }}"],
            ], $sample),
            new TemplateDefinition('shipping.return_rejected.sms', 'sms', 'transactional', ['shop', 'number'], [
                'en' => [null, '{{ shop }}: we could not accept your return for order {{ number }}. See My returns in your account for the reason.'],
                'bn' => [null, '{{ shop }}: অর্ডার {{ number }} এর ফেরত আমরা গ্রহণ করতে পারিনি। কারণ আপনার অ্যাকাউন্টের “আমার ফেরত” এ দেখুন।'],
            ], $sample),
        ];
    }
}
