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
        // How a booking ended without being delivered, and what that means for the money. One row per booking, never changed, never deleted.
        Schema::create('booking_cancellations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->string('kind', 10);                                  // cancelled | no_show
            $table->string('by_type', 10);                               // customer | staff | system
            $table->unsignedBigInteger('by_id')->nullable();
            $table->integer('minutes_before');                           // before the start (negative once it had started)
            $table->bigInteger('paid_minor');                            // what was paid for this booking line
            $table->unsignedSmallInteger('fee_bp');                      // the policy's fee at that moment
            $table->boolean('fee_waived')->default(false);
            $table->bigInteger('fee_minor');
            $table->bigInteger('refund_due_minor');                      // only advice for staff: the refund itself goes through Payments and its approval
            $table->string('reason', 255)->nullable();
            $table->dateTime('created_at', 6);

            $table->foreign('booking_id')->references('id')->on('service_bookings')->restrictOnDelete();
            $table->unique('booking_id');                                // a booking ends once
        });
        DB::statement("ALTER TABLE booking_cancellations ADD CONSTRAINT booking_cancellations_check CHECK (kind IN ('cancelled','no_show') AND by_type IN ('customer','staff','system') AND fee_bp <= 10000 AND paid_minor >= 0 AND fee_minor >= 0 AND fee_minor <= paid_minor AND refund_due_minor >= 0 AND refund_due_minor <= paid_minor - fee_minor AND (fee_waived = 0 OR fee_minor = 0))");
        AppendOnly::protect('booking_cancellations');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `booking_cancellations_no_update`');
        DB::unprepared('DROP TRIGGER IF EXISTS `booking_cancellations_no_delete`');
        Schema::dropIfExists('booking_cancellations');
    }
};
