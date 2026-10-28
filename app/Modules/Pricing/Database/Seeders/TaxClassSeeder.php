<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Tax classes only. Rates are NOT seeded: they need the owner's tax adviser (ADR-030). */
class TaxClassSeeder extends Seeder
{
    public function run(): void
    {
        $now = now('UTC');

        foreach ([['standard', 'Standard rate', true], ['zero_rated', 'Zero-rated', false], ['exempt', 'Exempt', false]] as [$code, $name, $default]) {
            DB::table('tax_classes')->updateOrInsert(['code' => $code], ['name' => $name, 'is_default' => $default, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
