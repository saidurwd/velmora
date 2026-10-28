<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Database\Seeder;

/**
 * Reference data only (idempotent, safe in every environment). Demo data is a separate, local/staging-only seeder.
 * Never seed credentials or default admin accounts.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LanguageSeeder::class,
        ]);
    }
}
