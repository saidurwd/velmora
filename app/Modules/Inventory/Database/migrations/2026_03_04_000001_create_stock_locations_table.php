<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_locations', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('type', 12)->default('warehouse');
            $table->boolean('is_active')->default(true);
            $table->timestamps(6);
        });

        DB::statement("ALTER TABLE stock_locations ADD CONSTRAINT stock_locations_type_check CHECK (type IN ('warehouse','store','transit'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_locations');
    }
};
