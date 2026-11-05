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
        // One row per message to one person on one channel. It is the queue, the retry book and the record of what happened. The text of the message is never kept;
        // the recipient is kept masked. What is needed to try again (the real address and the values) is held ENCRYPTED in `payload` until the message is sent,
        // and cleared then (a dead message's is cleared after the retention period).
        Schema::create('notification_logs', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('idempotency_key', 120)->unique();         // template code + the caller's key: the same event can never send twice
            $table->string('template_code', 80);
            $table->string('channel', 5);
            $table->string('kind', 13);
            $table->string('locale', 5);
            $table->string('recipient_masked', 120);                  // +88017****5678 / r***@example.com
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('related', 80)->nullable();                // e.g. an order's public id
            $table->string('status', 10);                             // queued | sending | sent | failed | dead | skipped
            $table->string('skip_reason', 30)->nullable();            // no_address | no_consent
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at', 6)->nullable();
            $table->dateTime('claimed_at', 6)->nullable();
            $table->dateTime('sent_at', 6)->nullable();
            $table->unsignedInteger('template_version')->nullable();  // the version of the words that was sent
            $table->string('provider_ref', 64)->nullable();
            $table->string('last_error', 120)->nullable();            // the provider's code / our label, never a payload
            $table->text('payload')->nullable();                      // encrypted JSON; cleared once sent
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['status', 'next_attempt_at']);             // the delivery run: what is due now
            $table->index(['template_code', 'created_at']);           // staff: one message type over time
            $table->index(['user_id', 'created_at']);                 // a customer's messages (privacy export)
        });
        DB::statement("ALTER TABLE notification_logs ADD CONSTRAINT notification_logs_check CHECK (channel IN ('email','sms') AND kind IN ('transactional','marketing') AND status IN ('queued','sending','sent','failed','dead','skipped'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
