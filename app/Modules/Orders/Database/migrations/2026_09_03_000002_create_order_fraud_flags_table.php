<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Why an order was sent to review. What was seen at the time is kept as it was; clearing the review never rewrites it.
        Schema::create('order_fraud_flags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('rule_code', 40);
            $table->unsignedSmallInteger('score');
            $table->json('details');
            $table->dateTime('created_at', 6);

            $table->index('order_id');                          // the flags of one order
        });

        AppendOnly::protect('order_fraud_flags');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `order_fraud_flags_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `order_fraud_flags_no_update`');
        Schema::dropIfExists('order_fraud_flags');
    }
};
