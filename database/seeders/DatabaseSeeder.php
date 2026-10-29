<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Search\Database\Seeders\SearchSynonymSeeder;
use App\Modules\Shipping\Database\Seeders\ShippingSeeder;
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
            RolesAndPermissionsSeeder::class,
            TaxClassSeeder::class,
            PriceListSeeder::class,
            StockLocationSeeder::class,
            GeographySeeder::class,
            ShippingSeeder::class,
            SearchSynonymSeeder::class,
        ]);
    }
}
