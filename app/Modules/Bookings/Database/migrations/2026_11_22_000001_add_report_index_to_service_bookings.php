<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The booking utilisation report adds up the bookings whose service starts in a period, for every resource at once (the existing index starts with the resource).
        Schema::table('service_bookings', function (Blueprint $table): void {
            $table->index(['starts_at', 'resource_id'], 'service_bookings_starts_at_resource_index');
        });
    }

    public function down(): void
    {
        Schema::table('service_bookings', function (Blueprint $table): void {
            $table->dropIndex('service_bookings_starts_at_resource_index');
        });
    }
};
