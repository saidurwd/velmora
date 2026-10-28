<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PriceListSeeder extends Seeder
{
    public function run(): void
    {
        $now = now('UTC');
        DB::table('price_lists')->updateOrInsert(['code' => 'retail'], ['currency' => 'BDT', 'is_default' => true, 'created_at' => $now, 'updated_at' => $now]);
    }
}
