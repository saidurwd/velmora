<?php

declare(strict_types=1);

namespace App\Modules\Orders\Notifications;

use App\Modules\Notifications\DTOs\TemplateDefinition;

/** The messages the shop sends about an order. The first words (English and Bangla); staff may change them on the messages screen. */
final class OrderTemplates
{
    /** @return list<TemplateDefinition> */
    public static function all(): array
    {
        return [
            new TemplateDefinition(
                'order.placed.sms', 'sms', 'transactional', ['shop', 'number', 'total'],
                [
                    'en' => [null, '{{ shop }}: Order {{ number }} received. Total {{ total }}. We will confirm it soon.'],
                    'bn' => [null, '{{ shop }}: অর্ডার {{ number }} পেয়েছি। মোট {{ total }}। শীঘ্রই নিশ্চিত করব।'],
                ],
                ['shop' => 'Velmora', 'number' => 'VM-2611-000001', 'total' => '৳ 1,300.00'],
            ),
            new TemplateDefinition(
                'order.placed_cod.sms', 'sms', 'transactional', ['shop', 'number', 'total'],
                [
                    'en' => [null, '{{ shop }}: Order {{ number }} received. Total {{ total }}. Pay cash on delivery. We will confirm it soon.'],
                    'bn' => [null, '{{ shop }}: অর্ডার {{ number }} পেয়েছি। মোট {{ total }}। পৌঁছালে নগদ দিন। শীঘ্রই নিশ্চিত করব।'],
                ],
                ['shop' => 'Velmora', 'number' => 'VM-2611-000001', 'total' => '৳ 1,300.00'],
            ),
            new TemplateDefinition(
                'order.shipped.sms', 'sms', 'transactional', ['shop', 'number'],
                [
                    'en' => [null, '{{ shop }}: your order {{ number }} is on its way.'],
                    'bn' => [null, '{{ shop }}: আপনার অর্ডার {{ number }} পথে আছে।'],
                ],
                ['shop' => 'Velmora', 'number' => 'VM-2611-000001'],
            ),
            new TemplateDefinition(
                'order.delivered.sms', 'sms', 'transactional', ['shop', 'number'],
                [
                    'en' => [null, '{{ shop }}: your order {{ number }} was delivered. Thank you for shopping with us!'],
                    'bn' => [null, '{{ shop }}: আপনার অর্ডার {{ number }} পৌঁছে গেছে। আমাদের সাথে কেনাকাটার জন্য ধন্যবাদ!'],
                ],
                ['shop' => 'Velmora', 'number' => 'VM-2611-000001'],
            ),
            new TemplateDefinition(
                'order.delivery_failed.sms', 'sms', 'transactional', ['shop', 'number'],
                [
                    'en' => [null, '{{ shop }}: we could not deliver your order {{ number }}. We will try again; please keep your phone on.'],
                    'bn' => [null, '{{ shop }}: আপনার অর্ডার {{ number }} পৌঁছে দেওয়া যায়নি। আমরা আবার চেষ্টা করব; অনুগ্রহ করে ফোন চালু রাখুন।'],
                ],
                ['shop' => 'Velmora', 'number' => 'VM-2611-000001'],
            ),
            new TemplateDefinition(
                'order.delivery_failed_final.sms', 'sms', 'transactional', ['shop', 'number'],
                [
                    'en' => [null, '{{ shop }}: we could not deliver your order {{ number }} after several tries. Please call us.'],
                    'bn' => [null, '{{ shop }}: কয়েকবার চেষ্টা করেও আপনার অর্ডার {{ number }} পৌঁছে দেওয়া যায়নি। অনুগ্রহ করে আমাদের কল করুন।'],
                ],
                ['shop' => 'Velmora', 'number' => 'VM-2611-000001'],
            ),
            new TemplateDefinition(
                'order.cancelled_stale.sms', 'sms', 'transactional', ['shop', 'number'],
                [
                    'en' => [null, '{{ shop }}: Order {{ number }} was cancelled because we could not confirm it in time. You are welcome to order again.'],
                    'bn' => [null, '{{ shop }}: অর্ডার {{ number }} সময়মতো নিশ্চিত করা যায়নি, তাই বাতিল হয়েছে। আপনি আবার অর্ডার করতে পারেন।'],
                ],
                ['shop' => 'Velmora', 'number' => 'VM-2611-000001'],
            ),
            new TemplateDefinition(
                'order.refunded.sms', 'sms', 'transactional', ['shop', 'number', 'amount'],
                [
                    'en' => [null, '{{ shop }}: {{ amount }} has been refunded for order {{ number }}. It can take a few days to show in your account.'],
                    'bn' => [null, '{{ shop }}: অর্ডার {{ number }}-এর {{ amount }} ফেরত দেওয়া হয়েছে। আপনার অ্যাকাউন্টে দেখাতে কয়েকদিন লাগতে পারে।'],
                ],
                ['shop' => 'Velmora', 'number' => 'VM-2611-000001', 'amount' => '৳ 500.00'],
            ),
            new TemplateDefinition(
                'order.placed.email', 'email', 'transactional', ['number', 'name', 'items', 'summary', 'link'],
                [
                    'en' => ['Your order {{ number }}', "Thank you! Order {{ number }}\n\nHello {{ name }}, we have received your order. Here is what you ordered:\n\n{{ items }}\n\n{{ summary }}\n\nView your order: {{ link }}\n\nYou are receiving this because an order was placed with this email address."],
                    'bn' => ['আপনার অর্ডার {{ number }}', "ধন্যবাদ! অর্ডার {{ number }}\n\nহ্যালো {{ name }}, আপনার অর্ডার পেয়েছি। যা অর্ডার করেছেন:\n\n{{ items }}\n\n{{ summary }}\n\nঅর্ডার দেখুন: {{ link }}\n\nএই ইমেইল ঠিকানায় একটি অর্ডার করা হয়েছে বলে আপনি এটি পাচ্ছেন।"],
                ],
                ['number' => 'VM-2611-000001', 'name' => 'Rahim', 'items' => 'Cotton Panjabi × 2 — ৳ 1,300.00', 'summary' => "Delivery: ৳ 60.00\nTotal: ৳ 1,360.00", 'link' => 'https://example.com/orders/abc'],
                ['items', 'summary'],
            ),
        ];
    }
}
