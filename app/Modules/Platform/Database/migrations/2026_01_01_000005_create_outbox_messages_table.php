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
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('event_name', 100);
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('available_at', 6);
            $table->dateTime('processed_at', 6)->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('created_at', 6);

            // dispatcher: next pending messages that are due
            $table->index(['status', 'available_at']);
        });

        DB::statement("ALTER TABLE outbox_messages ADD CONSTRAINT outbox_status_check CHECK (status IN ('pending','done','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
