<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reports add up one day of money: payments where paid_at is in the day, refunds where status = 'succeeded' and updated_at is in the day.
        Schema::table('payments', function (Blueprint $table): void {
            $table->index('paid_at', 'payments_paid_at_index');
        });
        Schema::table('refunds', function (Blueprint $table): void {
            $table->index(['status', 'updated_at'], 'refunds_status_updated_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_paid_at_index');
        });
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropIndex('refunds_status_updated_at_index');
        });
    }
};
