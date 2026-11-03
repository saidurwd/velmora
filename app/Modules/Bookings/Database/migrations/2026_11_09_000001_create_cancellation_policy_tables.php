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
        // What a customer pays when they cancel, move or miss a booking. The numbers are data, not code; the words come from the translations.
        // Fees are in basis points (10000 = the whole price of that booking line).
        Schema::create('cancellation_policies', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('code', 40)->unique();
            $table->unsignedSmallInteger('late_fee_bp');               // cancelling closer to the start than every window below
            $table->unsignedSmallInteger('no_show_fee_bp');            // not turning up
            $table->unsignedSmallInteger('reschedule_hours');          // a customer may move a booking free of charge at least this long before it starts
            $table->unsignedTinyInteger('max_reschedules');            // how many times a customer may move one booking
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
        DB::statement('ALTER TABLE cancellation_policies ADD CONSTRAINT cancellation_policies_check CHECK (late_fee_bp <= 10000 AND no_show_fee_bp <= 10000)');
        DB::statement('ALTER TABLE cancellation_policies ADD COLUMN default_marker TINYINT GENERATED ALWAYS AS (IF(is_default, 1, NULL)) VIRTUAL');
        DB::statement('ALTER TABLE cancellation_policies ADD UNIQUE INDEX cancellation_policies_one_default (default_marker)');   // at most one default: the one a service uses when it has none of its own

        // "Cancel at least N hours before the start and the fee is X." The customer gets the row with the most hours that they still qualify for.
        Schema::create('cancellation_policy_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_id')->constrained('cancellation_policies')->cascadeOnDelete();
            $table->unsignedInteger('hours_before');
            $table->unsignedSmallInteger('fee_bp');
            $table->unique(['policy_id', 'hours_before']);              // the policy's windows are read together; one row per number of hours
        });
        DB::statement('ALTER TABLE cancellation_policy_windows ADD CONSTRAINT cancellation_policy_windows_check CHECK (fee_bp <= 10000 AND hours_before <= 8760)');

        Schema::create('cancellation_policy_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_id')->constrained('cancellation_policies')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('title', 120);
            $table->text('text');
            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->unique(['policy_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cancellation_policy_translations');
        Schema::dropIfExists('cancellation_policy_windows');
        Schema::dropIfExists('cancellation_policies');
    }
};
