<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The shop's starting refund policy for digital products: refundable until first use. Idempotent; never changes a policy the owner has edited. */
class RefundPolicySeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('refund_policies')->where('code', 'default')->exists()) {
            return;
        }

        $id = DB::table('refund_policies')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'code' => 'default', 'rule' => 'until_first_use', 'days' => null, 'revoke_on_full_refund' => true, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([
            'en' => ['Refunds for digital products', 'You can ask for a refund until you first download the file or look at your key. After that, digital products cannot be refunded, because you already have them. If something is wrong with the file, contact us and we will put it right.'],
            'bn' => ['ডিজিটাল পণ্যের রিফান্ড', 'ফাইল প্রথমবার ডাউনলোড করা বা কী দেখার আগ পর্যন্ত আপনি রিফান্ড চাইতে পারবেন। এরপর ডিজিটাল পণ্য রিফান্ড করা যায় না, কারণ আপনি ইতিমধ্যে সেটি পেয়ে গেছেন। ফাইলে কোনো সমস্যা থাকলে আমাদের জানান, আমরা ঠিক করে দেব।'],
        ] as $locale => [$title, $text]) {
            DB::table('refund_policy_translations')->insert(['policy_id' => $id, 'locale' => $locale, 'title' => $title, 'text' => $text]);
        }
    }
}
