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
        Schema::create('order_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('axis', 12);                    // status | payment | fulfillment
            $table->string('from_state', 24)->nullable();  // NULL for the first entry of an axis
            $table->string('to_state', 24);
            $table->string('actor_type', 12);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->dateTime('created_at', 6);

            $table->index(['order_id', 'id']);             // the timeline of one order
        });

        DB::statement("ALTER TABLE order_status_history ADD CONSTRAINT order_status_history_axis_check CHECK (axis IN ('status','payment','fulfillment'))");

        AppendOnly::protect('order_status_history');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `order_status_history_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `order_status_history_no_update`');
        Schema::dropIfExists('order_status_history');
    }
};
