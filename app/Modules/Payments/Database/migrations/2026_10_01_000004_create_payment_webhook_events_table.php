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
        // Every notification the gateway sends, kept exactly as received. The payload is never changed; only what we did with it is.
        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 30);
            $table->string('provider_event_id', 191);                 // the gateway's id, or a hash of provider + transaction + status + amount + time
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->json('payload');
            $table->boolean('verified')->nullable();                  // did the server-to-server check agree? (NULL = not checked yet)
            $table->string('status', 12)->default('received');
            $table->string('error', 255)->nullable();
            $table->dateTime('received_at', 6);
            $table->dateTime('processed_at', 6)->nullable();

            $table->unique(['provider', 'provider_event_id']);        // the same notification twice is stored once
            $table->index('payment_id');                              // the events of one payment
            $table->index(['status', 'received_at']);                 // events still waiting or failed; retention sweep
        });

        DB::statement("ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_events_status_check CHECK (status IN ('received','processed','ignored','error'))");

        AppendOnly::protect('payment_webhook_events', ['payment_id', 'verified', 'status', 'error', 'processed_at']);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS `payment_webhook_events_no_delete`');
        DB::unprepared('DROP TRIGGER IF EXISTS `payment_webhook_events_no_update`');
        Schema::dropIfExists('payment_webhook_events');
    }
};
