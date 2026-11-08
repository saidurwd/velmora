<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The coupon performance report reads the uses in a period.
        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->index(['redeemed_at', 'coupon_id'], 'coupon_redemptions_redeemed_at_coupon_index');
        });
    }

    public function down(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->dropIndex('coupon_redemptions_redeemed_at_coupon_index');
        });
    }
};
