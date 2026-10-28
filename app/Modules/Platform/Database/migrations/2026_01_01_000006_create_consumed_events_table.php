<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumed_events', function (Blueprint $table): void {
            $table->id();
            $table->string('consumer', 100);
            $table->foreignId('outbox_id')->constrained('outbox_messages')->restrictOnDelete();
            $table->dateTime('consumed_at', 6);

            // exactly-once effect per consumer
            $table->unique(['consumer', 'outbox_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumed_events');
    }
};
