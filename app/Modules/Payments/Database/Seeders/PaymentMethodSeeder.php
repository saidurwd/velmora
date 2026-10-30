<?php

declare(strict_types=1);

namespace App\Modules\Payments\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The two ways to pay on a fresh install. A method that already exists is left exactly as the owner set it. "Pay online" starts
 * switched OFF: it is turned on in the admin panel once the gateway credentials are in place.
 */
class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $now = now('UTC');
        $methods = [
            ['code' => 'cod', 'provider' => 'cod', 'type' => 'cod', 'is_enabled' => true, 'sort_order' => 1,
                'labels' => ['en' => ['Cash on delivery', 'Pay in cash to the courier when your order arrives.'], 'bn' => ['ক্যাশ অন ডেলিভারি', 'অর্ডার হাতে পেয়ে কুরিয়ারকে নগদ টাকা দিন।']]],
            ['code' => 'online', 'provider' => 'sslcommerz', 'type' => 'gateway', 'is_enabled' => false, 'sort_order' => 2, 'min_minor' => 1000, 'max_minor' => 50_000_000,
                'labels' => ['en' => ['Pay online', 'bKash, cards and internet banking, through SSLCommerz.'], 'bn' => ['অনলাইনে পেমেন্ট', 'বিকাশ, কার্ড ও ইন্টারনেট ব্যাংকিং, SSLCommerz-এর মাধ্যমে।']]],
        ];

        foreach ($methods as $m) {
            if (DB::table('payment_methods')->where('code', $m['code'])->exists()) {
                continue;
            }

            $id = DB::table('payment_methods')->insertGetId([
                'code' => $m['code'], 'provider' => $m['provider'], 'type' => $m['type'], 'is_enabled' => $m['is_enabled'], 'min_minor' => $m['min_minor'] ?? null, 'max_minor' => $m['max_minor'] ?? null,
                'sort_order' => $m['sort_order'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($m['labels'] as $locale => [$label, $description]) {
                DB::table('payment_method_translations')->insert(['method_id' => $id, 'locale' => $locale, 'label' => $label, 'description' => $description]);
            }
        }
    }
}
