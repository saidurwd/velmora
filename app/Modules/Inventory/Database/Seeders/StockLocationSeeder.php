<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One default warehouse. More locations are data, not code. */
class StockLocationSeeder extends Seeder
{
    public function run(): void
    {
        $now = now('UTC');

        if (! DB::table('stock_locations')->where('code', 'main')->exists()) {
            DB::table('stock_locations')->insert(['public_id' => strtolower((string) Str::ulid()), 'code' => 'main', 'name' => 'Main warehouse', 'type' => 'warehouse', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
