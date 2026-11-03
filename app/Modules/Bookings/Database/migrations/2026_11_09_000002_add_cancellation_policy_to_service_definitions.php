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
            $table->unsignedBigInteger('cancellation_policy_id')->nullable()->after('hold_minutes');
            $table->foreign('cancellation_policy_id')->references('id')->on('cancellation_policies')->nullOnDelete();   // NULL = the default policy
        });
    }

    public function down(): void
    {
        Schema::table('service_definitions', function (Blueprint $table): void {
            $table->dropForeign(['cancellation_policy_id']);
            $table->dropColumn('cancellation_policy_id');
        });
    }
};
