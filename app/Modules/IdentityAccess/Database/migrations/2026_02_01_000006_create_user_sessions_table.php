<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('session_id_hash', 64)->unique();   // sha256 of the session id (lookup)
            $table->text('session_id_encrypted');            // lets "log out this device" destroy it
            $table->string('guard', 16);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('last_seen_at', 6);
            $table->dateTime('created_at', 6);

            $table->index(['user_id', 'last_seen_at']); // device list
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
