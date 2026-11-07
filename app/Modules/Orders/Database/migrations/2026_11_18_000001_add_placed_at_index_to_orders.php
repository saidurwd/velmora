<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reports add up the orders placed in one day: where placed_at >= ? and placed_at < ? (without this index it reads the whole table).
        Schema::table('orders', function (Blueprint $table): void {
            $table->index('placed_at', 'orders_placed_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_placed_at_index');
        });
    }
};
