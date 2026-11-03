<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_bookings', function (Blueprint $table): void {
            $table->dateTime('completed_at', 6)->nullable();
            $table->string('completed_by', 10)->nullable();          // customer | staff | system
            $table->index(['status', 'ends_at']);                    // the lifecycle job: running bookings that ended long ago
            $table->index(['status', 'starts_at']);                  // the lifecycle job: confirmed bookings that start soon or have started
        });
    }

    public function down(): void
    {
        Schema::table('service_bookings', function (Blueprint $table): void {
            $table->dropIndex(['status', 'ends_at']);
            $table->dropIndex(['status', 'starts_at']);
            $table->dropColumn(['completed_at', 'completed_by']);
        });
    }
};
