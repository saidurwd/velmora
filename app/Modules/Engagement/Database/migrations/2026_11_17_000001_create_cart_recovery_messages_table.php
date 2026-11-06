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
        // One row per reminder about a forgotten cart. The row is the claim: it is inserted before the message is recorded, so two workers cannot both remind the same
        // customer about the same cart episode. It holds no message text and no address. Erasure of the customer deletes it.
        Schema::create('cart_recovery_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('cart_stamp', 26);                              // when the newest line of the cart last changed: names the episode
            $table->string('channel', 5);                                // email | sms
            $table->dateTime('created_at', 6);

            $table->unique(['user_id', 'cart_stamp']);                   // one reminder per cart episode (and the claim)
            $table->index(['user_id', 'created_at']);                    // "when was this customer last reminded?" for the gap between reminders
        });
        DB::statement("ALTER TABLE cart_recovery_messages ADD CONSTRAINT cart_recovery_messages_channel_check CHECK (channel IN ('email','sms'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_recovery_messages');
    }
};
