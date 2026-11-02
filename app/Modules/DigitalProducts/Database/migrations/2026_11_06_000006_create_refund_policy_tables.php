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
        // What a customer may expect when they ask for their money back on a digital product. The words come from the translations; the rule is data, not code.
        Schema::create('refund_policies', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('code', 40)->unique();
            $table->string('rule', 20);                                // non_refundable | until_first_use | within_days
            $table->unsignedSmallInteger('days')->nullable();          // only for within_days
            $table->boolean('revoke_on_full_refund')->default(true);   // a fully refunded order loses its downloads and keys
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
        DB::statement("ALTER TABLE refund_policies ADD CONSTRAINT refund_policies_check CHECK (rule IN ('non_refundable','until_first_use','within_days') AND (rule <> 'within_days' OR days >= 1) AND (rule = 'within_days' OR days IS NULL))");
        DB::statement('ALTER TABLE refund_policies ADD COLUMN default_marker TINYINT GENERATED ALWAYS AS (IF(is_default, 1, NULL)) VIRTUAL');
        DB::statement('ALTER TABLE refund_policies ADD UNIQUE INDEX refund_policies_one_default (default_marker)');   // at most one default policy: the one a product uses when it has none of its own

        Schema::create('refund_policy_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_id')->constrained('refund_policies')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('title', 120);
            $table->text('text');
            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->unique(['policy_id', 'locale']);
        });

        Schema::table('digital_assets', function (Blueprint $table): void {
            $table->unsignedBigInteger('refund_policy_id')->nullable()->after('access_days');
            $table->foreign('refund_policy_id')->references('id')->on('refund_policies')->nullOnDelete();   // NULL = the default policy
        });
    }

    public function down(): void
    {
        Schema::table('digital_assets', function (Blueprint $table): void {
            $table->dropForeign(['refund_policy_id']);
            $table->dropColumn('refund_policy_id');
        });
        Schema::dropIfExists('refund_policy_translations');
        Schema::dropIfExists('refund_policies');
    }
};
