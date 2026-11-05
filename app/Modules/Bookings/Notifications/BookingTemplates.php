<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Notifications;

use App\Modules\Notifications\DTOs\TemplateDefinition;

/** The messages the shop sends about a booking. The first words (English and Bangla); staff may change them on the messages screen. */
final class BookingTemplates
{
    /** @return list<TemplateDefinition> */
    public static function all(): array
    {
        return [
            new TemplateDefinition(
                'booking.reminder_long.sms', 'sms', 'transactional', ['shop', 'service', 'when', 'where', 'link'],
                [
                    'en' => [null, '{{ shop }}: Reminder: your booking for {{ service }} is on {{ when }} ({{ where }}). {{ link }}'],
                    'bn' => [null, '{{ shop }}: অনুস্মারক: {{ service }}-এর জন্য আপনার বুকিং {{ when }} ({{ where }})। {{ link }}'],
                ],
                ['shop' => 'Velmora', 'service' => 'Tailor fitting', 'when' => '1 Dec 2026, 10:00 AM', 'where' => 'at our place', 'link' => 'https://example.com/account/bookings/abc'],
            ),
            new TemplateDefinition(
                'booking.reminder_long.email', 'email', 'transactional', ['service', 'when', 'where', 'link'],
                [
                    'en' => ['Reminder: your booking for {{ service }}', "Your booking is coming up\n\nYour booking for {{ service }} is on {{ when }} ({{ where }}).\n\nSee, move or cancel this booking: {{ link }}\n\nYou are receiving this because you booked with us."],
                    'bn' => ['অনুস্মারক: {{ service }}-এর জন্য আপনার বুকিং', "আপনার বুকিং আসছে\n\n{{ service }}-এর জন্য আপনার বুকিং {{ when }} ({{ where }})।\n\nবুকিং দেখুন, সরান বা বাতিল করুন: {{ link }}\n\nআপনি আমাদের কাছে বুকিং করেছেন বলে এই বার্তা পাচ্ছেন।"],
                ],
                ['service' => 'Tailor fitting', 'when' => '1 Dec 2026, 10:00 AM', 'where' => 'at our place', 'link' => 'https://example.com/account/bookings/abc'],
            ),
            new TemplateDefinition(
                'booking.reminder_short.sms', 'sms', 'transactional', ['shop', 'service', 'when', 'where', 'link'],
                [
                    'en' => [null, '{{ shop }}: Your booking for {{ service }} starts soon: {{ when }} ({{ where }}). {{ link }}'],
                    'bn' => [null, '{{ shop }}: {{ service }}-এর জন্য আপনার বুকিং শীঘ্রই শুরু হবে: {{ when }} ({{ where }})। {{ link }}'],
                ],
                ['shop' => 'Velmora', 'service' => 'Tailor fitting', 'when' => '1 Dec 2026, 10:00 AM', 'where' => 'at our place', 'link' => 'https://example.com/account/bookings/abc'],
            ),
            new TemplateDefinition(
                'booking.reminder_short.email', 'email', 'transactional', ['service', 'when', 'where', 'link'],
                [
                    'en' => ['Your booking for {{ service }} starts soon', "Your booking starts soon\n\nYour booking for {{ service }} starts at {{ when }} ({{ where }}).\n\nSee, move or cancel this booking: {{ link }}\n\nYou are receiving this because you booked with us."],
                    'bn' => ['{{ service }}-এর জন্য আপনার বুকিং শীঘ্রই শুরু হবে', "আপনার বুকিং শীঘ্রই শুরু হবে\n\n{{ service }}-এর জন্য আপনার বুকিং {{ when }}-এ শুরু হবে ({{ where }})।\n\nবুকিং দেখুন, সরান বা বাতিল করুন: {{ link }}\n\nআপনি আমাদের কাছে বুকিং করেছেন বলে এই বার্তা পাচ্ছেন।"],
                ],
                ['service' => 'Tailor fitting', 'when' => '1 Dec 2026, 10:00 AM', 'where' => 'at our place', 'link' => 'https://example.com/account/bookings/abc'],
            ),
            new TemplateDefinition(
                'booking.review.sms', 'sms', 'transactional', ['shop', 'service', 'when', 'where', 'link'],
                [
                    'en' => [null, '{{ shop }}: Thank you for booking {{ service }}. How was it? {{ link }}'],
                    'bn' => [null, '{{ shop }}: {{ service }} বুক করার জন্য ধন্যবাদ। কেমন লেগেছে? {{ link }}'],
                ],
                ['shop' => 'Velmora', 'service' => 'Tailor fitting', 'when' => '1 Dec 2026, 10:00 AM', 'where' => 'at our place', 'link' => 'https://example.com/account/bookings/abc'],
            ),
            new TemplateDefinition(
                'booking.review.email', 'email', 'transactional', ['service', 'when', 'where', 'link'],
                [
                    'en' => ['How was {{ service }}?', "How was your booking?\n\nThank you for booking {{ service }}. We would love to hear how it went.\n\nOpen the page: {{ link }}\n\nYou are receiving this because you booked with us."],
                    'bn' => ['{{ service }} কেমন ছিল?', "আপনার বুকিং কেমন ছিল?\n\n{{ service }} বুক করার জন্য ধন্যবাদ। কেমন হলো তা জানালে আমরা খুশি হব।\n\nপাতাটি খুলুন: {{ link }}\n\nআপনি আমাদের কাছে বুকিং করেছেন বলে এই বার্তা পাচ্ছেন।"],
                ],
                ['service' => 'Tailor fitting', 'when' => '1 Dec 2026, 10:00 AM', 'where' => 'at our place', 'link' => 'https://example.com/account/bookings/abc'],
            ),
        ];
    }
}
