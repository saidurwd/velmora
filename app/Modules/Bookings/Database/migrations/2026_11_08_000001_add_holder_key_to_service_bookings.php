<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who is holding the slot before an order exists: a visitor key from the cart (never shown). Used to cap holds per visitor and to replace a visitor's own hold.
        Schema::table('service_bookings', function (Blueprint $table): void {
            $table->string('holder_key', 64)->nullable()->after('user_id');
            $table->index(['holder_key', 'status']);                       // a visitor's live holds: the cap, and replacing their own
        });
    }

    public function down(): void
    {
        Schema::table('service_bookings', function (Blueprint $table): void {
            $table->dropIndex(['holder_key', 'status']);
            $table->dropColumn('holder_key');
        });
    }
};
