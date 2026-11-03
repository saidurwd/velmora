<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The shop's starting cancellation policy. Numbers are data: staff can change them; the shop owner confirms them before opening bookings. */
class CancellationPolicySeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('cancellation_policies')->where('code', 'default')->exists()) {
            return;
        }

        $id = DB::table('cancellation_policies')->insertGetId([
            'public_id' => strtolower((string) Str::ulid()), 'code' => 'default', 'late_fee_bp' => 5000, 'no_show_fee_bp' => 10000, 'reschedule_hours' => 24, 'max_reschedules' => 2,
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cancellation_policy_windows')->insert(['policy_id' => $id, 'hours_before' => 24, 'fee_bp' => 0]);
        foreach ([
            'en' => ['Cancelling or moving a booking', 'You can cancel for free up to 24 hours before your booking starts. Closer than that, half of the price is kept. If you do not turn up, the full price is kept. You can move a booking up to 2 times, free of charge, up to 24 hours before it starts.'],
            'bn' => ['বুকিং বাতিল বা সরানো', 'বুকিং শুরুর ২৪ ঘণ্টা আগে পর্যন্ত বিনা খরচে বাতিল করা যাবে। এর চেয়ে কাছে হলে মূল্যের অর্ধেক রাখা হবে। আপনি উপস্থিত না হলে পুরো মূল্য রাখা হবে। শুরুর ২৪ ঘণ্টা আগে পর্যন্ত বিনা খরচে একটি বুকিং সর্বোচ্চ ২ বার সরানো যাবে।'],
        ] as $locale => [$title, $text]) {
            DB::table('cancellation_policy_translations')->insert(['policy_id' => $id, 'locale' => $locale, 'title' => $title, 'text' => $text]);
        }
    }
}
