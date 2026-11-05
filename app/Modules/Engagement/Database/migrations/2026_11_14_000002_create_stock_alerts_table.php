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
        // "Tell me when it is back": one row per customer and variant. It waits (pending), is told once when stock returns (notified), and can be asked for again.
        Schema::create('stock_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('status', 10)->default('pending');           // pending | notified
            $table->char('locale', 2)->default('en');                   // the language the customer asked in: the message uses it
            $table->dateTime('created_at', 6);
            $table->dateTime('notified_at', 6)->nullable();
            $table->dateTime('updated_at', 6);

            $table->unique(['user_id', 'variant_id']);                  // one alert per customer and variant; asking again after being told makes it pending again
            $table->index(['status', 'variant_id', 'id']);              // the five-minute run: which variants have waiting alerts, and the oldest first
        });
        DB::statement("ALTER TABLE stock_alerts ADD CONSTRAINT stock_alerts_check CHECK (status IN ('pending','notified') AND ((status = 'notified') = (notified_at IS NOT NULL)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_alerts');
    }
};
