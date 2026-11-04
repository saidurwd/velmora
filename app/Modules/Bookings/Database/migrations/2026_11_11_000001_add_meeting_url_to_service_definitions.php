<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_definitions', function (Blueprint $table): void {
            $table->string('meeting_url', 500)->nullable()->after('cancellation_policy_id');   // for an online service: where the customer joins (https only; shown to those who booked)
        });
    }

    public function down(): void
    {
        Schema::table('service_definitions', function (Blueprint $table): void {
            $table->dropColumn('meeting_url');
        });
    }
};
