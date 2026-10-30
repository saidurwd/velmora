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
        // The ways a customer can pay, as the shop configures them (labels in both languages, limits, on/off).
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 30)->unique();                     // cod, online
            $table->string('provider', 30);                           // cod | sslcommerz | fake
            $table->string('type', 10);                               // cod | gateway | mfs | bank
            $table->boolean('is_enabled')->default(false);
            $table->bigInteger('min_minor')->nullable();              // not offered below this order total
            $table->bigInteger('max_minor')->nullable();              // nor above this one
            $table->json('restrictions')->nullable();                // {"product_types": ["physical", ...]}
            $table->json('fee_rule')->nullable();                     // reserved: the shop absorbs gateway fees today
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps(6);
        });

        DB::statement("ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_type_check CHECK (type IN ('cod','gateway','mfs','bank'))");
        DB::statement('ALTER TABLE payment_methods ADD CONSTRAINT payment_methods_limits_check CHECK ((min_minor IS NULL OR min_minor >= 0) AND (max_minor IS NULL OR max_minor > 0) AND (min_minor IS NULL OR max_minor IS NULL OR min_minor <= max_minor))');

        Schema::create('payment_method_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('method_id')->constrained('payment_methods')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('label', 80);
            $table->string('description', 255)->nullable();
            $table->foreign('locale')->references('code')->on('languages')->restrictOnDelete();
            $table->unique(['method_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_method_translations');
        Schema::dropIfExists('payment_methods');
    }
};
