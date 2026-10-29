<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PLACEHOLDER shipping set-up so a fresh install can quote delivery: Dhaka ৳60, rest of Bangladesh ৳120, free from ৳3,000,
 * no cash-on-delivery fee. These numbers are the owner's decisions (editable in the admin panel); they are only seeded
 * when no zone exists yet, so re-running never overwrites what the owner has set.
 */
class ShippingSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('shipping_zones')->exists()) {
            return;
        }

        $now = now('UTC');
        $ulid = fn () => strtolower((string) Str::ulid());

        $dhaka = DB::table('shipping_zones')->insertGetId(['public_id' => $ulid(), 'is_fallback' => false, 'cod_available' => true, 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $rest = DB::table('shipping_zones')->insertGetId(['public_id' => $ulid(), 'is_fallback' => true, 'cod_available' => true, 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('shipping_zone_translations')->insert([
            ['zone_id' => $dhaka, 'locale' => 'en', 'name' => 'Dhaka'], ['zone_id' => $dhaka, 'locale' => 'bn', 'name' => 'ঢাকা'],
            ['zone_id' => $rest, 'locale' => 'en', 'name' => 'Rest of Bangladesh'], ['zone_id' => $rest, 'locale' => 'bn', 'name' => 'বাংলাদেশের অন্যান্য স্থান'],
        ]);

        $dhakaDistrict = DB::table('districts')->where('name_en', 'Dhaka')->value('id');
        if ($dhakaDistrict !== null) {
            DB::table('shipping_zone_districts')->insert(['zone_id' => $dhaka, 'district_id' => $dhakaDistrict]);
        }

        $standard = DB::table('shipping_methods')->insertGetId(['public_id' => $ulid(), 'code' => 'standard', 'cod_available' => true, 'cod_fee_minor' => 0, 'free_over_minor' => 300000, 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('shipping_method_translations')->insert([
            ['method_id' => $standard, 'locale' => 'en', 'name' => 'Standard delivery', 'delivery_estimate' => 'Dhaka 1–2 working days · elsewhere 2–5 working days'],
            ['method_id' => $standard, 'locale' => 'bn', 'name' => 'সাধারণ ডেলিভারি', 'delivery_estimate' => 'ঢাকায় ১–২ কর্মদিবস · অন্যত্র ২–৫ কর্মদিবস'],
        ]);

        DB::table('shipping_rates')->insert([
            ['zone_id' => $dhaka, 'method_id' => $standard, 'max_weight_g' => null, 'price_minor' => 6000, 'created_at' => $now, 'updated_at' => $now],
            ['zone_id' => $rest, 'method_id' => $standard, 'max_weight_g' => null, 'price_minor' => 12000, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
