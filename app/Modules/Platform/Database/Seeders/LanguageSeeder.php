<?php

declare(strict_types=1);

namespace App\Modules\Platform\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Reference data, idempotent. English is the default language (owner decision). */
class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        $now = now('UTC');

        foreach ([
            ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'is_default' => true],
            ['code' => 'bn', 'name' => 'Bangla', 'native_name' => 'বাংলা', 'is_default' => false],
        ] as $language) {
            DB::table('languages')->updateOrInsert(
                ['code' => $language['code']],
                $language + ['is_active' => true, 'direction' => 'ltr', 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }
}
