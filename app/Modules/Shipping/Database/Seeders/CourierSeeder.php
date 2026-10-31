<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Database\Seeders;

use App\Modules\Shipping\Couriers\ManualCourier;
use App\Modules\Shipping\Models\CourierAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** The manual courier account, so a shop can ship the day it opens. Idempotent; never changes an account the owner has edited. */
class CourierSeeder extends Seeder
{
    public function run(): void
    {
        CourierAccount::query()->firstOrCreate(
            ['courier' => ManualCourier::NAME, 'label' => 'Manual / own delivery'],
            ['public_id' => strtolower((string) Str::ulid()), 'is_active' => true, 'sort_order' => 0],
        );
    }
}
