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
        // What a customer agreed to receive. Only marketing needs a yes (transactional messages are about their own order and always go). A marketing message
        // is sent only with an opt-in on that channel, and not during quiet hours (Dhaka time; they wait until the quiet hours end).
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 5);                              // email | sms
            $table->boolean('marketing_opt_in')->default(false);
            $table->time('quiet_from')->nullable();
            $table->time('quiet_to')->nullable();
            $table->dateTime('opted_at', 6)->nullable();               // when the yes or no was given
            $table->string('source', 20)->nullable();                  // account | checkout | staff | link
            $table->timestamps();

            $table->unique(['user_id', 'channel']);                     // one answer per customer and channel
        });
        DB::statement("ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_check CHECK (channel IN ('email','sms') AND ((quiet_from IS NULL) = (quiet_to IS NULL)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
