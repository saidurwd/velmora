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
        Schema::table('service_bookings', function (Blueprint $table): void {
            $table->text('policy_terms')->nullable();                          // the policy as it was when the booking was confirmed (JSON): a later edit never changes a customer's deal
            $table->unsignedTinyInteger('reschedule_count')->default(0);
        });
        DB::statement('ALTER TABLE service_bookings ADD CONSTRAINT service_bookings_terms_check CHECK (policy_terms IS NULL OR JSON_VALID(policy_terms))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE service_bookings DROP CONSTRAINT service_bookings_terms_check');
        Schema::table('service_bookings', function (Blueprint $table): void {
            $table->dropColumn(['policy_terms', 'reschedule_count']);
        });
    }
};
