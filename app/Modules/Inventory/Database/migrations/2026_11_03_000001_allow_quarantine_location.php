<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A place for returned goods to wait for inspection (the location itself is created by the stock location seeder, like the main warehouse).
        // Stock held there is never counted as available and never reserved for an order.
        DB::statement('ALTER TABLE stock_locations DROP CONSTRAINT stock_locations_type_check');
        DB::statement("ALTER TABLE stock_locations ADD CONSTRAINT stock_locations_type_check CHECK (type IN ('warehouse','store','transit','quarantine'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_locations DROP CONSTRAINT stock_locations_type_check');
        DB::statement("ALTER TABLE stock_locations ADD CONSTRAINT stock_locations_type_check CHECK (type IN ('warehouse','store','transit'))");
    }
};
