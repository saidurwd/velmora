<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A line that is a booking carries the public id of the held slot it stands for. NULL for every ordinary product. The cart does not look inside it.
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->char('hold_ref', 26)->nullable()->after('added_price_minor');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropColumn('hold_ref');
        });
    }
};
